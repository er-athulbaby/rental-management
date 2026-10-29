<?php

use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\RecordCashMovement;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Actions\Ledger\SaveLedgerAccount;
use App\Livewire\Ledger\CashMovements;
use App\Models\CashMovement;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
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
    $this->move = fn (array $data) => app(RecordCashMovement::class)->handle($this->f['finance'], ['date' => '2026-10-20', 'reference' => 'REF-1', ...$data]);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('each kind posts exactly, on its own date, described by its reference', function () {
    $transfer = ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1000'), 'to_account_id' => ($this->id)('1010'), 'amount' => '500', 'reference' => 'DEP-1']);
    $charge = ($this->move)(['kind' => 'bank_charge', 'from_account_id' => ($this->id)('1010'), 'amount' => '2.5', 'date' => '2026-10-19']);
    $interest = ($this->move)(['kind' => 'bank_interest', 'to_account_id' => ($this->id)('1010'), 'amount' => '1.200', 'note' => 'October']);

    expect(postedLines('cash_movement', $transfer->id))->toBe(['1010 500.000 0.000', '1000 0.000 500.000'])
        ->and(postedLines('cash_movement', $charge->id))->toBe(['5180 2.500 0.000', '1010 0.000 2.500'])
        ->and(postedLines('cash_movement', $interest->id))->toBe(['1010 1.200 0.000', '4300 0.000 1.200'])
        ->and(journalFor('cash_movement', $charge->id)->date->toDateString())->toBe('2026-10-19')
        ->and(journalFor('cash_movement', $transfer->id)->description)->toBe('DEP-1')
        ->and([$charge->amount, $charge->to_account_id, $interest->from_account_id, $interest->note])->toBe(['2.500', null, null, 'October']);
});

test('a transfer needs two different active money accounts; a new money account works once Admin flags it', function () {
    $f = $this->f;

    expect(fn () => ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1010'), 'to_account_id' => ($this->id)('1010'), 'amount' => '5']))
        ->toThrow(ValidationException::class, 'two different money accounts')
        ->and(fn () => ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1100'), 'to_account_id' => ($this->id)('1010'), 'amount' => '5']))
        ->toThrow(ValidationException::class, 'active money account')
        ->and(fn () => ($this->move)(['kind' => 'bank_charge', 'from_account_id' => ($this->id)('5100'), 'amount' => '5']))
        ->toThrow(ValidationException::class, 'active money account')
        ->and(fn () => ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1000'), 'to_account_id' => ($this->id)('1010'), 'amount' => '0']))
        ->toThrow(ValidationException::class);

    $petty = app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '1030', 'name_en' => 'Petty cash', 'name_ar' => 'النثرية', 'type' => 'asset', 'is_money' => true]);
    $move = ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1000'), 'to_account_id' => (string) $petty->id, 'amount' => '20']);

    expect(postedLines('cash_movement', $move->id))->toBe(['1030 20.000 0.000', '1000 0.000 20.000']);
});

test('a movement is reversed once, by its exact opposite, never before its own date', function () {
    $f = $this->f;
    $transfer = ($this->move)(['kind' => 'transfer', 'from_account_id' => ($this->id)('1000'), 'to_account_id' => ($this->id)('1010'), 'amount' => '500', 'date' => '2026-10-19']);

    expect(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $transfer, '2026-10-18', 'Typo'))->toThrow(ValidationException::class, 'before the original (19/10/2026)')
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $transfer, '2026-10-20', ''))->toThrow(ValidationException::class);

    $reversal = app(ReverseMoneyEntry::class)->handle($f['finance'], $transfer, '2026-10-20', 'Typo');

    expect([$reversal->reverses_id, $reversal->kind->value, $reversal->amount, $reversal->reference, $reversal->note])->toBe([$transfer->id, 'transfer', '500.000', 'REF-1', 'Typo'])
        ->and(postedLines('cash_movement_reversal', $reversal->id))->toBe(['1010 0.000 500.000', '1000 500.000 0.000'])
        ->and(journalFor('cash_movement_reversal', $reversal->id)->date->toDateString())->toBe('2026-10-20')
        ->and(journalFor('cash_movement', $reversal->id))->toBeNull()
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $transfer, '2026-10-20', 'Again'))->toThrow(ValidationException::class, 'already reversed')
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $reversal, '2026-10-20', 'Again'))->toThrow(ValidationException::class, 'cannot be reversed');
});

test('nothing is dated on or before the lock or after today, and only Finance records or reverses', function () {
    $f = $this->f;
    $charge = fn (string $date) => ($this->move)(['kind' => 'bank_charge', 'from_account_id' => ($this->id)('1010'), 'amount' => '1', 'date' => $date]);
    $kept = $charge('2026-10-11');
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');

    expect(fn () => $charge('2026-10-10'))->toThrow(ValidationException::class, '11/10/2026')
        ->and(fn () => $charge('2026-10-21'))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordCashMovement::class)->handle($f['admin'], ['kind' => 'bank_charge']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['admin'], $kept, '2026-10-20', 'x'))->toThrow(AuthorizationException::class)
        ->and(postedLines('cash_movement', $kept->id))->toBe(['5180 1.000 0.000', '1010 0.000 1.000']);
});

test('the page records, filters and reverses; Admin only views; tampered input gives messages', function () {
    $f = $this->f;
    $page = Livewire::actingAs($f['finance'])->test(CashMovements::class)
        ->set('kind', 'transfer')->set('from_account_id', ($this->id)('1000'))->set('to_account_id', ($this->id)('1010'))
        ->set('amount', '500')->set('reference', 'DEP-1')->call('save')->assertHasNoErrors()
        ->set('kind', 'bank_interest')->set('to_account_id', ($this->id)('1010'))->set('amount', '1.200')->set('reference', 'INT-OCT')->call('save')->assertHasNoErrors()
        ->set('filterKind', 'bank_interest')->assertSee('INT-OCT')->assertDontSee('DEP-1')
        ->set('filterKind', '')->set('filterAccount', ($this->id)('1000'))->assertSee('DEP-1')->assertDontSee('INT-OCT')
        ->set('filterAccount', '')->set('filterFrom', '2026-10-21')->assertDontSee('DEP-1')
        ->set('filterFrom', '2026-02-31')->assertSee('DEP-1')
        ->call('startReverse', (string) CashMovement::query()->where('reference', 'DEP-1')->value('id'))->set('reverseReason', 'Wrong day')->call('reverse')->assertHasNoErrors()
        ->assertSee('Reversal of #')
        ->set('kind', ['x'])->call('save')->assertHasErrors('kind')
        ->set('kind', 'transfer')->set('amount', ['x'])->call('save')->assertHasErrors('amount')
        ->set('filterKind', ['x'])->set('filterAccount', ['x'])->assertOk();
    $page->call('startReverse', 'abc')->assertNotFound();

    expect(CashMovement::query()->count())->toBe(3);

    Livewire::actingAs($f['admin'])->test(CashMovements::class)->assertOk()->assertDontSee('Record movement')->call('save')->assertForbidden();
    Livewire::actingAs($f['management'])->test(CashMovements::class)->assertForbidden();
});
