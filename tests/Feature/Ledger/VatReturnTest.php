<?php

use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\PostManualJournal;
use App\Actions\Ledger\RecordVatReturn;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Actions\Ledger\SaveOpeningBalances;
use App\Ledger\Balances;
use App\Livewire\Ledger\VatReturns;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\VatReturn;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
    $this->id = fn (string $code) => (string) LedgerAccount::query()->where('code', $code)->value('id');
    $this->vat = fn (array $data) => app(RecordVatReturn::class)->handle($this->f['finance'], ['date' => '2026-10-20', 'method' => 'bank_transfer', 'reference' => 'NBR-1', ...$data]);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('the period figure is VAT payable credits less debits in the period, with VAT return and opening journals left out', function () {
    $f = $this->f;
    app(SaveOpeningBalances::class)->handle($f['admin'], [($this->id)('2400') => ['debit' => '', 'credit' => '100.000']]);

    expect(Balances::vatDue('2026-10-01', '2026-10-20'))->toBe(42_000)
        ->and(Balances::vatDue('2026-10-02', '2026-10-20'))->toBe(2_000)
        ->and(Balances::vatDue('2026-09-01', '2026-09-30'))->toBe(0);

    ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'amount' => '42']);

    expect(Balances::vatDue('2026-10-01', '2026-10-20'))->toBe(42_000);
});

test('a payment posts VAT payable against the method account; a debit figure records a refund', function () {
    $f = $this->f;
    $payment = ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-14', 'amount' => '40']);

    expect([$payment->direction, $payment->amount])->toBe(['payment', '40.000'])
        ->and(postedLines('vat_return', $payment->id))->toBe(['2400 40.000 0.000', '1010 0.000 40.000'])
        ->and(journalFor('vat_return', $payment->id)->description)->toBe('NBR-1 01/10/2026–14/10/2026');

    app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-16', 'description' => 'Input VAT on the lift contract', 'lines' => [
        ['account_id' => ($this->id)('2400'), 'debit' => '10.000', 'credit' => ''], ['account_id' => ($this->id)('1010'), 'debit' => '', 'credit' => '10.000']]]);
    expect(Balances::vatDue('2026-10-15', '2026-10-20'))->toBe(-8_000);

    $refund = ($this->vat)(['period_from' => '2026-10-15', 'period_to' => '2026-10-20', 'method' => 'cheque', 'amount' => '8', 'reference' => 'NBR-R1']);

    expect($refund->direction)->toBe('refund')
        ->and(postedLines('vat_return', $refund->id))->toBe(['2400 0.000 8.000', '1010 8.000 0.000']);
});

test('periods may not overlap a standing return; a reversal posts the opposite once and frees the period', function () {
    $f = $this->f;
    $first = ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'amount' => '42']);

    expect(fn () => ($this->vat)(['period_from' => '2026-10-20', 'period_to' => '2026-10-20', 'amount' => '1']))
        ->toThrow(ValidationException::class, 'overlaps the return for 01/10/2026 – 20/10/2026');

    $reversal = app(ReverseMoneyEntry::class)->handle($f['finance'], $first, '2026-10-20', 'Wrong amount');

    expect($reversal->reverses_id)->toBe($first->id)
        ->and(postedLines('vat_return_reversal', $reversal->id))->toBe(['2400 0.000 42.000', '1010 42.000 0.000'])
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $first, '2026-10-20', 'Again'))->toThrow(ValidationException::class, 'already reversed')
        ->and(($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'amount' => '42'])->direction)->toBe('payment');
});

test('the date is open and the period ends by today; only Finance records; tampered input gives a message', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');

    expect(fn () => ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-09', 'amount' => '40', 'date' => '2026-10-10']))
        ->toThrow(ValidationException::class, '11/10/2026')
        ->and(fn () => ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-21', 'amount' => '40']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->vat)(['period_from' => '2026-10-09', 'period_to' => '2026-10-01', 'amount' => '40']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->vat)(['period_from' => '2026-10-01', 'period_to' => '2026-10-09', 'amount' => '40', 'method' => 'card']))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordVatReturn::class)->handle($f['finance'], 'oops'))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordVatReturn::class)->handle($f['admin'], []))->toThrow(AuthorizationException::class);
});

test('the page prefills the figure, records, lists the difference and reverses; Admin only views', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(VatReturns::class)
        ->set('period_from', '2026-10-01')->set('period_to', '2026-10-20')
        ->assertSet('amount', '42.000')->assertSee('VAT payable for the period: 42.000 BHD')
        ->set('reference', 'NBR-Q4')->call('save')->assertHasNoErrors()
        ->assertSee('01/10/2026 – 20/10/2026')->assertSee('Paid to NBR')->assertSee('0.000')->assertSee('NBR-Q4')
        ->call('startReverse', (string) VatReturn::query()->value('id'))->set('reverseReason', 'Wrong period')->call('reverse')->assertHasNoErrors()
        ->assertSee('Reversed')
        ->set('period_from', ['x'])->call('save')->assertHasErrors('period_from')
        ->call('startReverse', 'abc')->assertNotFound();

    Livewire::actingAs($f['admin'])->test(VatReturns::class)->assertOk()->assertDontSee('Record return')->call('save')->assertForbidden();
    Livewire::actingAs($f['management'])->test(VatReturns::class)->assertForbidden();
});
