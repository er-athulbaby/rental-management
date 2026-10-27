<?php

use App\Actions\Ledger\DeleteLedgerAccount;
use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\SaveLedgerAccount;
use App\Actions\Ledger\SaveOpeningBalances;
use App\Actions\Ledger\SetLedgerAccountActive;
use App\Actions\Ledger\SetLedgerStart;
use App\Actions\Payments\RecordPayment;
use App\Ledger\Poster;
use App\Ledger\Rules;
use App\Livewire\Ledger\Accounts;
use App\Livewire\Ledger\Setup;
use App\Models\Journal;
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
    $this->id = fn (string $code) => (int) LedgerAccount::query()->where('code', $code)->value('id');
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'rebuild_status' => null, 'rebuild_from' => null, 'rebuild_to' => null,
        'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null]);
});

test('Admin adds and renames accounts; an account with postings keeps its type; codes are unique; Finance cannot', function () {
    $f = $this->f;
    $parking = app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '4300', 'name_en' => 'Parking income', 'name_ar' => 'إيرادات المواقف', 'type' => 'income']);
    app(SaveLedgerAccount::class)->handle($f['admin'], $parking, ['code' => '4300', 'name_en' => 'Car park income', 'name_ar' => 'إيرادات المواقف', 'type' => 'expense']);
    $cash = LedgerAccount::query()->where('code', '1000')->firstOrFail();

    expect($parking->fresh()->name_en)->toBe('Car park income')
        ->and($parking->fresh()->type->value)->toBe('expense')
        ->and(fn () => app(SaveLedgerAccount::class)->handle($f['admin'], $cash, ['code' => '1000', 'name_en' => 'Cash', 'name_ar' => 'نقد', 'type' => 'expense']))->toThrow(ValidationException::class, 'postings')
        ->and(fn () => app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '1000', 'name_en' => 'Twice', 'name_ar' => 'مكرر', 'type' => 'asset']))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '9 9', 'name_en' => 'Bad', 'name_ar' => 'سيء', 'type' => 'asset']))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveLedgerAccount::class)->handle($f['finance'], null, ['code' => '9999', 'name_en' => 'X', 'name_ar' => 'س', 'type' => 'asset']))->toThrow(AuthorizationException::class);
});

test('a rule\'s account cannot be deactivated or deleted; an inactive account takes no postings; an unused one can go', function () {
    $f = $this->f;
    $cash = LedgerAccount::query()->where('code', '1000')->firstOrFail();
    $petty = app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '1090', 'name_en' => 'Petty cash', 'name_ar' => 'نثرية', 'type' => 'asset']);
    $line = fn (int $account, int $amount) => ['account' => $account, 'amount' => $amount, 'customer_id' => null, 'owner_id' => null, 'building_id' => null, 'unit_id' => null, 'memo' => null];

    expect(fn () => app(SetLedgerAccountActive::class)->handle($f['admin'], $cash, false))->toThrow(ValidationException::class, 'Posting rules')
        ->and(fn () => app(DeleteLedgerAccount::class)->handle($f['admin'], $cash))->toThrow(ValidationException::class);

    app(SetLedgerAccountActive::class)->handle($f['admin'], $petty, false);
    expect(fn () => Poster::write('manual', '2026-10-20', 'Test', [$line($petty->id, 1_000), $line($cash->id, -1_000)]))->toThrow(LogicException::class, 'inactive');

    app(DeleteLedgerAccount::class)->handle($f['admin'], $petty);
    expect(LedgerAccount::query()->whereKey($petty->id)->exists())->toBeFalse();
});

test('a rule change applies to new postings, and rebuilding the open months restates the old ones', function () {
    $f = $this->f;
    $terminal = app(SaveLedgerAccount::class)->handle($f['admin'], null, ['code' => '1030', 'name_en' => 'Card terminal', 'name_ar' => 'جهاز البطاقات', 'type' => 'asset']);
    $index = array_search('method.card', Rules::keys(), true);
    $page = Livewire::actingAs($f['admin'])->test(Accounts::class)
        ->set("ruleAccounts.{$index}", (string) $terminal->id)->call('saveRules')->assertHasNoErrors()->assertSet('offerRebuild', true);

    $later = app(RecordPayment::class)->handle($f['finance'], $f['omar'], ['received_on' => '2026-10-20', 'method' => 'card', 'amount' => '10.000']);
    expect(postedLines('payment', $later->id))->toBe(['1030 10.000 0.000', '2300 0.000 10.000'])
        ->and(ledgerBalances()[1020])->toBe('300.000');

    $page->call('rebuildOpen')->assertHasNoErrors();
    expect(ledgerBalances())->not->toHaveKey(1020)
        ->and(ledgerBalances()[1030])->toBe('310.000');
});

test('the start date moves only while nothing is locked, and moving it rebuilds from the earlier date', function () {
    $f = $this->f;
    $before = ledgerBalances();
    app(SetLedgerStart::class)->handle($f['admin'], '2026-10-06');

    expect(journalFor('invoice', $f['saraRent']->id))->toBeNull()
        ->and(journalFor('payment', $f['saraPayment']->id))->toBeNull()
        ->and(postedLines('expense', $f['companyExpense']->id))->toBe(['5100 50.000 0.000', '2200 0.000 50.000']);

    app(SetLedgerStart::class)->handle($f['admin'], '2026-10-01');
    expect(ledgerBalances())->toBe($before);

    app(LockLedger::class)->handle($f['finance'], '2026-10-05');
    expect(fn () => app(SetLedgerStart::class)->handle($f['admin'], '2026-10-02'))->toThrow(ValidationException::class, 'no month is locked')
        ->and(fn () => app(SetLedgerStart::class)->handle($f['finance'], '2026-10-02'))->toThrow(AuthorizationException::class);
});

test('opening balances balance through Opening balance equity, are replaced on each save, and freeze once a month is locked', function () {
    $f = $this->f;
    $id = $this->id;
    app(SaveOpeningBalances::class)->handle($f['admin'], [$id('1010') => ['debit' => '1000.000', 'credit' => ''], $id('2100') => ['debit' => '', 'credit' => '600.000']]);
    $journal = Journal::query()->where('kind', 'opening')->sole();

    expect($journal->date->toDateString())->toBe('2026-10-01')
        ->and(linesOf($journal))->toBe(['1010 1000.000 0.000', '2100 0.000 600.000', '3000 0.000 400.000']);

    app(SaveOpeningBalances::class)->handle($f['admin'], [$id('1010') => ['debit' => '500.000', 'credit' => '']]);
    expect(Journal::query()->where('kind', 'opening')->count())->toBe(1)
        ->and(ledgerBalances()[3000])->toBe('-500.000')
        ->and(fn () => app(SaveOpeningBalances::class)->handle($f['admin'], [$id('1010') => ['debit' => '1.000', 'credit' => '1.000']]))->toThrow(ValidationException::class, 'not both');

    app(LockLedger::class)->handle($f['finance'], '2026-10-01');
    expect(fn () => app(SaveOpeningBalances::class)->handle($f['admin'], []))->toThrow(ValidationException::class, 'locked');
});

test('the setup pages are Admin only and shrug off tampered input', function () {
    $f = $this->f;
    $bank = ($this->id)('1010');
    Livewire::actingAs($f['finance'])->test(Accounts::class)->assertForbidden();
    Livewire::actingAs($f['finance'])->test(Setup::class)->assertForbidden();
    Livewire::actingAs($f['admin'])->test(Accounts::class)->call('edit', 'abc')->assertNotFound();
    Livewire::actingAs($f['admin'])->test(Accounts::class)->set('ruleAccounts', 'oops')->call('saveRules')->assertHasErrors('rules');
    Livewire::actingAs($f['admin'])->test(Accounts::class)->set('code', ['x'])->call('save')->assertHasErrors('code');
    Livewire::actingAs($f['admin'])->test(Setup::class)->set('balances', 'oops')->call('saveOpening')->assertHasNoErrors();
    Livewire::actingAs($f['admin'])->test(Setup::class)->set("balances.{$bank}.debit", '1.2345')->call('saveOpening')->assertHasErrors("balances.{$bank}.debit");
    Livewire::actingAs($f['admin'])->test(Setup::class)->set('startDate', ['x'])->call('setStart')->assertHasErrors('startDate');
});
