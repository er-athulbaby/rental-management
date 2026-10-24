<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Ledger\Poster;
use App\Models\Approval;
use App\Models\OwnerCharge;
use App\Models\OwnerPayable;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

test('the worked example: every record posts and the books show the plan\'s balances', function () {
    expect(ledgerBalances())->toBe([
        1000 => '840.000', 1010 => '-300.000', 1020 => '300.000', 2000 => '-400.000', 2100 => '-348.000', 2200 => '-80.000',
        2400 => '-42.000', 4000 => '-300.000', 4200 => '-20.000', 5000 => '300.000', 5100 => '50.000',
    ])->and(Poster::pendingCount())->toBe(0);
});

test('expenses, head-lease rent, the payout, the management fee and the credit refund each post their own journal', function () {
    $f = $this->f;

    expect(postedLines('expense', $f['companyExpense']->id))->toBe(['5100 50.000 0.000', '2200 0.000 50.000'])
        ->and(postedLines('expense', $f['ownerExpense']->id))->toBe(['2100 30.000 0.000', '2200 0.000 30.000'])
        ->and(journalFor('expense', $f['ownerExpense']->id)->lines->first()->only(['owner_id', 'building_id', 'unit_id']))
        ->toBe(['owner_id' => $f['ali']->id, 'building_id' => $f['tower']->id, 'unit_id' => $f['t1']->id])
        ->and(postedLines('owner_payable', $f['payable']->id))->toBe(['5000 300.000 0.000', '2100 0.000 300.000'])
        ->and(journalFor('owner_payable', $f['payable']->id)->date->toDateString())->toBe('2026-10-01')
        ->and(postedLines('disbursement', $f['headLease']->id))->toBe(['2100 300.000 0.000', '1010 0.000 300.000'])
        ->and(postedLines('owner_charge', $f['fee']->id))->toBe(['2100 22.000 0.000', '4200 0.000 20.000', '2400 0.000 2.000'])
        ->and(postedLines('disbursement', $f['refund']->id))->toBe(['2300 60.000 0.000', '1000 0.000 60.000'])
        ->and(journalFor('disbursement', $f['refund']->id)->date->toDateString())->toBe('2026-10-18');
});

test('a reversed expense and a reversed payment out post the exact opposite on the reversal day', function () {
    $f = $this->f;
    app(ReverseExpense::class)->handle($f['finance'], $f['companyExpense'], 'Entered twice');
    app(DecideApproval::class)->handle($f['management'], app(RequestDisbursementReversal::class)->handle($f['finance'], $f['refund'], 'Tenant never collected it'), true);

    expect(postedLines('expense_reversal', $f['companyExpense']->id))->toBe(['5100 0.000 50.000', '2200 50.000 0.000'])
        ->and(journalFor('expense_reversal', $f['companyExpense']->id)->date->toDateString())->toBe('2026-10-20')
        ->and(postedLines('disbursement_reversal', $f['refund']->id))->toBe(['2300 0.000 60.000', '1000 60.000 0.000']);
});

test('an expense charged to the tenant is the company\'s cost, and its recharge invoice is other charges income', function () {
    $f = $this->f;
    $f['t1']->forceFill(['default_tax_category' => 'standard'])->save();
    $expense = app(RecordExpense::class)->handle($f['finance'], ['building_id' => $f['tower']->id, 'unit_id' => $f['t1']->id, 'category' => 'cleaning',
        'description' => 'Carpet clean', 'expense_date' => '2026-10-20', 'net' => '25.000', 'charge_to' => 'tenant']);

    expect(postedLines('expense', $expense->id))->toBe(['5120 25.000 0.000', '2200 0.000 25.000'])
        ->and(postedLines('invoice', $expense->invoice_id))->toBe(['1100 27.500 0.000', '4100 0.000 25.000', '2400 0.000 2.500']);
});

test('a remittance debits Owner payable and an approved other payment out debits Other payments out', function () {
    $f = $this->f;
    DB::table('owner_statements')->where('id', $f['statement']->id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $f['statement']->id)->update(['status' => 'finalised', 'number' => 'OS-LEDGER-1', 'finalised_at' => now(), 'finalised_by' => $f['finance']->id]);
    $remittance = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'owner_remittance', 'owner_statement_id' => $f['statement']->id,
        'amount' => '100.000', 'method' => 'bank_transfer', 'paid_on' => '2026-10-20']);
    $other = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'other', 'payee_type' => 'owner', 'payee_id' => $f['ali']->id,
        'amount' => '15.000', 'method' => 'cash', 'reason' => 'Key cutting']);
    app(DecideApproval::class)->handle($f['management'], Approval::query()->latest('id')->firstOrFail(), true);
    app(PayDisbursement::class)->handle($f['finance'], $other->fresh(), ['method' => 'cash', 'paid_on' => '2026-10-20']);

    expect(postedLines('disbursement', $remittance->id))->toBe(['2100 100.000 0.000', '1010 0.000 100.000'])
        ->and(postedLines('disbursement', $other->id))->toBe(['5190 15.000 0.000', '1000 0.000 15.000']);
});

test('a deposit refund debits the deposits held for each unit it refunds', function () {
    $f = $this->f;
    $au = $f['saraAgreement']->agreementUnits->sole();
    $au->forceFill(['move_out_date' => '2026-10-19'])->save();
    $settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($f['saraAgreement'], [$au->id], $f['finance']));
    app(DecideApproval::class)->handle($f['management'], app(SubmitDepositSettlement::class)->handle($f['finance'], $settlement->fresh()), true);
    $refund = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'deposit_refund', 'deposit_settlement_id' => $settlement->id,
        'amount' => '400.000', 'method' => 'bank_transfer', 'paid_on' => '2026-10-20']);

    expect(postedLines('disbursement', $refund->id))->toBe(['2000 400.000 0.000', '1010 0.000 400.000']);
});

test('an owner opening balance after the start date moves Opening balance equity to Owner payable', function () {
    $f = $this->f;
    $owed = OwnerCharge::create(['owner_contract_id' => $f['managed']->id, 'type' => 'opening_balance', 'net' => '75.000', 'tax_amount' => '0.000', 'amount' => '75.000',
        'posted_at' => '2026-10-20 08:00:00', 'created_by' => $f['finance']->id]);
    $owing = OwnerCharge::create(['owner_contract_id' => $f['managed']->id, 'type' => 'opening_balance', 'net' => '-5.000', 'tax_amount' => '0.000', 'amount' => '-5.000',
        'posted_at' => '2026-10-20 08:00:00', 'created_by' => $f['finance']->id]);

    expect(postedLines('owner_charge', $owed->id))->toBe(['3000 75.000 0.000', '2100 0.000 75.000'])
        ->and(postedLines('owner_charge', $owing->id))->toBe(['3000 0.000 5.000', '2100 5.000 0.000']);
});

test('head-lease rent posts on its due date; cancelled before then it never posts, cancelled after it is reversed', function () {
    $f = $this->f;
    $payable = function (string $from, string $to) use ($f): OwnerPayable {
        $p = (new OwnerPayable)->forceFill(['owner_contract_id' => $f['leased']->id, 'period_start' => $from, 'period_end' => $to, 'due_date' => $from,
            'amount' => '300.000', 'status' => 'scheduled']);
        $p->save();

        return $p;
    };
    $november = $payable('2026-11-01', '2026-11-30');
    $december = $payable('2026-12-01', '2026-12-31');
    expect(journalFor('owner_payable', $november->id))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30', 'Asia/Bahrain'));
    app(Poster::class)->catchUp();
    $december->forceFill(['status' => 'cancelled'])->save(); // before its due date
    expect(postedLines('owner_payable', $november->id))->toBe(['5000 300.000 0.000', '2100 0.000 300.000']);

    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Asia/Bahrain'));
    $november->forceFill(['status' => 'cancelled'])->save(); // after its due date
    app(Poster::class)->catchUp();

    expect(journalFor('owner_payable', $december->id))->toBeNull()
        ->and(journalFor('owner_payable_cancel', $december->id))->toBeNull()
        ->and(postedLines('owner_payable_cancel', $november->id))->toBe(['5000 0.000 300.000', '2100 300.000 0.000'])
        ->and(journalFor('owner_payable_cancel', $november->id)->date->toDateString())->toBe('2026-12-03');
});
