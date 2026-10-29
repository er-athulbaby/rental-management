<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Ledger\RecordCashMovement;
use App\Actions\Ledger\RecordVatReturn;
use App\Ledger\LedgerHealth;
use App\Ledger\Poster;
use App\Livewire\Reports\TrialBalanceReport;
use App\Models\Approval;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('the money screens worked example: a vendor bill, a transfer, a bank charge, bank interest and a VAT return', function () {
    $f = vendorBillFixture();
    $id = fn (string $code) => (string) LedgerAccount::query()->where('code', $code)->value('id');

    $bill = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'vendor_bill', 'payee_type' => 'vendor', 'payee_id' => $f['vendor']->id,
        'expense_ids' => [$f['companyExpense']->id, $f['ownerExpense']->id], 'method' => 'bank_transfer']);
    app(DecideApproval::class)->handle($f['management'], Approval::query()->latest('id')->firstOrFail(), true);
    app(PayDisbursement::class)->handle($f['finance'], $bill->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-10-20']);
    $move = fn (array $data) => app(RecordCashMovement::class)->handle($f['finance'], ['date' => '2026-10-20', ...$data]);
    $move(['kind' => 'transfer', 'from_account_id' => $id('1000'), 'to_account_id' => $id('1010'), 'amount' => '500', 'reference' => 'DEP-1']);
    $move(['kind' => 'bank_charge', 'from_account_id' => $id('1010'), 'amount' => '2.500', 'reference' => 'CHG-OCT']);
    $move(['kind' => 'bank_interest', 'to_account_id' => $id('1010'), 'amount' => '1.200', 'reference' => 'INT-OCT']);
    app(RecordVatReturn::class)->handle($f['finance'], ['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'date' => '2026-10-20', 'method' => 'bank_transfer',
        'reference' => 'NBR-Q4', 'amount' => '42']);

    expect(ledgerBalances())->toBe([
        1000 => '340.000', 1010 => '76.700', 1020 => '300.000', 2000 => '-400.000', 2100 => '-348.000', 4000 => '-300.000', 4200 => '-20.000',
        4300 => '-1.200', 5000 => '300.000', 5100 => '50.000', 5180 => '2.500',
    ])->and(Poster::pendingCount())->toBe(0)
        ->and(TrialBalanceReport::sheet($f['finance'], ['to' => '2026-10-20'])['rows'])->toBe([
            ['1000', 'Cash on hand', '340.000', ''],
            ['1010', 'Bank', '76.700', ''],
            ['1020', 'Card clearing', '300.000', ''],
            ['2000', 'Tenant deposits held', '', '400.000'],
            ['2100', 'Owner payable', '', '348.000'],
            ['4000', 'Rent income', '', '300.000'],
            ['4200', 'Management fee income', '', '20.000'],
            ['4300', 'Bank interest income', '', '1.200'],
            ['5000', 'Lease rent to owners', '300.000', ''],
            ['5100', 'Maintenance and repairs', '50.000', ''],
            ['5180', 'Bank charges', '2.500', ''],
            ['', 'Total', '1069.200', '1069.200'],
        ]);

    LedgerHealth::store();
    $health = collect(LedgerSetting::current()->health)->keyBy('check');

    expect($health->every(fn (array $r) => $r['ledger'] === $r['app']))->toBeTrue()
        ->and([$health['Vendor payable']['ledger'], $health['Vendor payable']['app']])->toBe([0, 0]);
});
