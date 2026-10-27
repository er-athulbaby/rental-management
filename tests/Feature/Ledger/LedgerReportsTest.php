<?php

use App\Livewire\Reports\BalanceSheetReport;
use App\Livewire\Reports\GeneralLedgerReport;
use App\Livewire\Reports\Index;
use App\Livewire\Reports\JournalListReport;
use App\Livewire\Reports\ProfitAndLossReport;
use App\Livewire\Reports\TrialBalanceReport;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null]);
});

test('the trial balance at 20/10/2026 lists the worked example, debits equal to credits, and downloads', function () {
    $f = $this->f;

    expect(TrialBalanceReport::sheet($f['finance'], ['to' => '2026-10-20'])['rows'])->toBe([
        ['1000', 'Cash on hand', '840.000', ''],
        ['1010', 'Bank', '', '300.000'],
        ['1020', 'Card clearing', '300.000', ''],
        ['2000', 'Tenant deposits held', '', '400.000'],
        ['2100', 'Owner payable', '', '348.000'],
        ['2200', 'Vendor payable', '', '80.000'],
        ['2400', 'VAT payable', '', '42.000'],
        ['4000', 'Rent income', '', '300.000'],
        ['4200', 'Management fee income', '', '20.000'],
        ['5000', 'Lease rent to owners', '300.000', ''],
        ['5100', 'Maintenance and repairs', '50.000', ''],
        ['', 'Total', '1490.000', '1490.000'],
    ])->and(TrialBalanceReport::sheet($f['finance'], ['to' => '2026-10-04'])['rows'])->toBe([
        ['1100', 'Tenant receivables', '1140.000', ''],
        ['2000', 'Tenant deposits held', '', '400.000'],
        ['2100', 'Owner payable', '', '700.000'],
        ['2400', 'VAT payable', '', '40.000'],
        ['4000', 'Rent income', '', '300.000'],
        ['5000', 'Lease rent to owners', '300.000', ''],
        ['', 'Total', '1440.000', '1440.000'],
    ]);

    Livewire::actingAs($f['finance'])->test(TrialBalanceReport::class)->set('to', '2026-10-20')
        ->assertSeeInOrder(['Cash on hand', '840.000', 'Total', '1490.000'])
        ->call('export')->assertFileDownloaded('trial_balance-2026-10-20.xlsx');
});

test('the balance sheet puts the current-year loss inside equity and balances', function () {
    expect(BalanceSheetReport::sheet($this->f['finance'], ['to' => '2026-10-20'])['rows'])->toBe([
        ['', 'Assets', ''],
        ['1000', 'Cash on hand', '840.000'],
        ['1010', 'Bank', '-300.000'],
        ['1020', 'Card clearing', '300.000'],
        ['', 'Total assets', '840.000'],
        ['', 'Liabilities', ''],
        ['2000', 'Tenant deposits held', '400.000'],
        ['2100', 'Owner payable', '348.000'],
        ['2200', 'Vendor payable', '80.000'],
        ['2400', 'VAT payable', '42.000'],
        ['', 'Total liabilities', '870.000'],
        ['', 'Equity', ''],
        ['', "Earlier years' profit", '0.000'],
        ['', 'Current-year profit', '-30.000'],
        ['', 'Total equity', '-30.000'],
        ['', 'Total liabilities and equity', '840.000'],
    ]);
});

test('on a date in a later year the loss moves to earlier years and the sheet still balances', function () {
    $rows = BalanceSheetReport::sheet($this->f['finance'], ['to' => '2027-03-01'])['rows'];

    expect($rows)->toContain(['', "Earlier years' profit", '-30.000'])
        ->toContain(['', 'Current-year profit', '0.000'])
        ->toContain(['', 'Total assets', '840.000'])
        ->toContain(['', 'Total liabilities and equity', '840.000']);
});

test('profit and loss for October comes from the ledger', function () {
    expect(ProfitAndLossReport::sheet($this->f['finance'], ['from' => '2026-10-01', 'to' => '2026-10-20'])['rows'])->toBe([
        ['', 'Income', ''],
        ['4000', 'Rent income', '300.000'],
        ['4200', 'Management fee income', '20.000'],
        ['', 'Total income', '320.000'],
        ['', 'Expenses', ''],
        ['5000', 'Lease rent to owners', '300.000'],
        ['5100', 'Maintenance and repairs', '50.000'],
        ['', 'Total expenses', '350.000'],
        ['', 'Profit for the period', '-30.000'],
    ]);
});

test('the general ledger for Owner payable runs from opening to closing, each line linking to its source', function () {
    $f = $this->f;
    $account = (string) LedgerAccount::query()->where('code', '2100')->value('id');
    $rows = GeneralLedgerReport::sheet($f['finance'], ['from' => '2026-10-01', 'to' => '2026-10-20', 'account' => $account])['rows'];

    expect(array_map(fn (array $r) => [$r[0], $r[1], $r[3], $r[4], $r[5]], $rows))->toBe([
        ['', '', '', '', '0.000'],
        ['01/10/2026', 'Invoice', '', '400.000', '-400.000'],
        ['01/10/2026', 'Head-lease rent due', '', '300.000', '-700.000'],
        ['08/10/2026', 'Expense', '30.000', '', '-670.000'],
        ['10/10/2026', 'Payment out', '300.000', '', '-370.000'],
        ['15/10/2026', 'Owner charge', '22.000', '', '-348.000'],
        ['', '', '', '', '-348.000'],
    ])->and($rows[2][2])->toBe("{$f['leased']->number} 01/10/2026–31/10/2026");

    Livewire::actingAs($f['finance'])->test(GeneralLedgerReport::class)->set('account', $account)->set('from', '2026-10-01')->set('to', '2026-10-20')
        ->assertSeeHtml(route('invoices.show', $f['saraRent']->id))
        ->assertSeeHtml(route('disbursements.show', $f['headLease']->id))
        ->assertSeeHtml(route('expenses.show', $f['ownerExpense']->id))
        ->call('export')->assertFileDownloaded('general_ledger-2026-10-20.xlsx');
});

test('the journal list filters by source and account and totals its lines', function () {
    $f = $this->f;
    $expenses = JournalListReport::sheet($f['finance'], ['from' => '2026-10-01', 'to' => '2026-10-20', 'source' => 'expense', 'account' => 'all'])['rows'];
    $cash = (string) LedgerAccount::query()->where('code', '1000')->value('id');
    $onCash = JournalListReport::sheet($f['finance'], ['from' => '2026-10-01', 'to' => '2026-10-20', 'source' => 'all', 'account' => $cash])['rows'];

    expect($expenses)->toHaveCount(5)
        ->and($expenses[0][2])->toBe('Expense')
        ->and($expenses[4])->toBe(['', '', '', '', 'Total', '80.000', '80.000'])
        ->and($onCash[array_key_last($onCash)])->toBe(['', '', '', '', 'Total', '900.000', '60.000']);
});

test('tampered filters give an empty table or a validation error, never a 500', function () {
    $f = $this->f;

    Livewire::actingAs($f['finance'])->test(GeneralLedgerReport::class)->set('account', 'x; DROP')->assertOk()->call('export')->assertHasErrors('account');
    Livewire::actingAs($f['finance'])->test(JournalListReport::class)->set('source', '<script>')->set('account', '999999')->assertOk();
    Livewire::actingAs($f['finance'])->test(TrialBalanceReport::class)->set('to', 'not-a-date')->assertOk();
    expect(fn () => JournalListReport::sheet($f['finance'], ['from' => ['x'], 'to' => '2026-10-20']))->toThrow(ValidationException::class);
});

test('the ledger reports are for Finance and Admin only, and are listed with the other reports', function () {
    $f = $this->f;
    Livewire::actingAs($f['management'])->test(TrialBalanceReport::class)->assertForbidden();
    Livewire::actingAs($f['admin'])->test(BalanceSheetReport::class)->assertSee('Balance sheet');
    Livewire::actingAs($f['finance'])->test(Index::class)->assertSee('Trial balance')->assertSee('Journal list');
    Livewire::actingAs($f['management'])->test(Index::class)->assertDontSee('Trial balance');
});
