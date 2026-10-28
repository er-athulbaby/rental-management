<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Ledger\PostManualJournal;
use App\Ledger\LedgerHealth;
use App\Models\Approval;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = vendorBillFixture();
    $this->row = function (): array {
        LedgerHealth::store();
        $row = collect(LedgerSetting::current()->health)->firstWhere('check', 'Vendor payable');

        return [$row['ledger'], $row['app']];
    };
    $this->record = fn (array $ids) => app(RecordDisbursement::class)->handle($this->f['finance'], ['purpose' => 'vendor_bill', 'payee_type' => 'vendor',
        'payee_id' => $this->f['vendor']->id, 'expense_ids' => $ids, 'method' => 'bank_transfer']);
    $this->decide = fn (bool $approve) => app(DecideApproval::class)->handle($this->f['management'], Approval::query()->latest('id')->firstOrFail(), $approve,
        $approve ? null : 'Not yet');
    $this->pay = function (array $ids) {
        $out = ($this->record)($ids);
        ($this->decide)(true);

        return app(PayDisbursement::class)->handle($this->f['finance'], $out->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-10-20']);
    };
    $this->reverse = fn ($out) => app(DecideApproval::class)->handle($this->f['management'],
        app(RequestDisbursementReversal::class)->handle($this->f['finance'], $out, 'Bank returned it'), true);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('Vendor payable is the unpaid bills: all of them, none once paid, all again once the payment is reversed', function () {
    $f = $this->f;
    expect(($this->row)())->toBe([80_000, 80_000]);

    $out = ($this->pay)([$f['companyExpense']->id, $f['ownerExpense']->id]);
    expect(($this->row)())->toBe([0, 0])
        ->and(ledgerBalances())->not->toHaveKey(2200);

    ($this->reverse)($out);
    expect(($this->row)())->toBe([80_000, 80_000]);
});

test('a part payment leaves the other bill; a waiting or rejected bill changes nothing; a manual vendor journal is left out', function () {
    $f = $this->f;
    ($this->pay)([$f['companyExpense']->id]);
    expect(($this->row)())->toBe([30_000, 30_000]);

    ($this->record)([$f['ownerExpense']->id]);
    expect(($this->row)())->toBe([30_000, 30_000]);
    ($this->decide)(false);
    expect(($this->row)())->toBe([30_000, 30_000]);

    $id = fn (string $code) => (string) LedgerAccount::query()->where('code', $code)->value('id');
    app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-20', 'description' => 'T1 tap paid before vendor bills existed', 'lines' => [
        ['account_id' => $id('2200'), 'debit' => '30.000', 'credit' => ''], ['account_id' => $id('1010'), 'debit' => '', 'credit' => '30.000']]]);

    expect(($this->row)())->toBe([30_000, 30_000])
        ->and(ledgerBalances())->not->toHaveKey(2200);
});

test('a bill from before the start date counts only for what happens to it from the start date', function () {
    $f = $this->f;
    $old = app(RecordExpense::class)->handle($f['finance'], ['building_id' => $f['tower']->id, 'category' => 'utilities', 'description' => 'September power',
        'expense_date' => '2026-09-20', 'net' => '40.000', 'charge_to' => 'company']);
    expect(journalFor('expense', $old->id))->toBeNull()
        ->and(($this->row)())->toBe([80_000, 80_000]);

    $out = ($this->pay)([$old->id]);
    expect(postedLines('disbursement', $out->id))->toBe(['2200 40.000 0.000', '1010 0.000 40.000'])
        ->and(($this->row)())->toBe([40_000, 40_000]);

    ($this->reverse)($out);
    expect(($this->row)())->toBe([80_000, 80_000]);
});

test('a missing vendor-bill journal shows as a difference', function () {
    $f = $this->f;
    $journal = journalFor('disbursement', ($this->pay)([$f['companyExpense']->id])->id);
    DB::table('journal_lines')->where('journal_id', $journal->id)->delete();
    DB::table('journals')->where('id', $journal->id)->delete();

    expect(($this->row)())->toBe([80_000, 30_000]);
});
