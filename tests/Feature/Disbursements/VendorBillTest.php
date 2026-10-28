<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\Expenses\ReverseExpense;
use App\Models\Approval;
use App\Models\Expense;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    $this->f = vendorBillFixture();
    $this->bill = fn (mixed $ids, array $data = []) => app(RecordDisbursement::class)->handle($this->f['finance'], ['purpose' => 'vendor_bill', 'payee_type' => 'vendor',
        'payee_id' => $this->f['vendor']->id, 'expense_ids' => $ids, 'method' => 'bank_transfer', ...$data]);
    $this->decide = fn (bool $approve) => app(DecideApproval::class)->handle($this->f['management'], Approval::query()->latest('id')->firstOrFail(), $approve,
        $approve ? null : 'Not this one');
});

test('a vendor bill pays the full totals of the ticked expenses and waits for Management', function () {
    $f = $this->f;
    $out = ($this->bill)([(string) $f['ownerExpense']->id, $f['companyExpense']->id]);

    expect($out->status->value)->toBe('pending_approval')
        ->and([$out->payee_type->value, $out->purpose->value, $out->amount])->toBe(['vendor', 'vendor_bill', '80.000'])
        ->and($out->payeeName())->toBe('Fix-It Co')
        ->and($out->expenses()->orderBy('expenses.id')->pluck('expenses.id')->all())->toBe([$f['companyExpense']->id, $f['ownerExpense']->id])
        ->and(Approval::query()->latest('id')->firstOrFail()->reason)->toContain('Lobby lights (50.000)')
        ->and(Expense::query()->unpaid()->count())->toBe(0)
        ->and($f['companyExpense']->fresh()->activePayment()?->id)->toBe($out->id)
        ->and(journalFor('disbursement', $out->id))->toBeNull();
});

test('an expense is on one active payment out at a time; a rejection frees it', function () {
    $f = $this->f;
    $first = ($this->bill)([$f['companyExpense']->id]);

    expect(fn () => ($this->bill)([$f['companyExpense']->id, $f['ownerExpense']->id]))->toThrow(ValidationException::class, 'Lobby lights is already on payment out');

    ($this->decide)(false);

    expect($first->fresh()->status->value)->toBe('rejected')
        ->and(journalFor('disbursement', $first->id))->toBeNull()
        ->and(Expense::query()->unpaid()->whereKey($f['companyExpense']->id)->exists())->toBeTrue()
        ->and(($this->bill)([$f['companyExpense']->id])->status->value)->toBe('pending_approval');
});

test('paying posts Vendor payable against the payout account and marks the expenses paid; a reversal posts the opposite and unpays them', function () {
    $f = $this->f;
    $out = ($this->bill)([$f['companyExpense']->id, $f['ownerExpense']->id]);
    ($this->decide)(true);

    expect(journalFor('disbursement', $out->id))->toBeNull()
        ->and(fn () => app(PayDisbursement::class)->handle($f['finance'], $out->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-10-07']))
        ->toThrow(ValidationException::class, 'latest expense (08/10/2026)');

    $paid = app(PayDisbursement::class)->handle($f['finance'], $out->fresh(), ['method' => 'cheque', 'paid_on' => '2026-10-20', 'cheque_no' => '000777',
        'bank_name' => 'NBB', 'cheque_date' => '2026-10-20']);

    expect($paid->status->value)->toBe('paid')
        ->and([$paid->cheque->vendor_id, $paid->cheque->owner_id, $paid->cheque->customer_id])->toBe([$f['vendor']->id, null, null])
        ->and($f['companyExpense']->fresh()->activePayment()?->status->value)->toBe('paid')
        ->and(postedLines('disbursement', $paid->id))->toBe(['2200 80.000 0.000', '1010 0.000 80.000'])
        ->and(journalFor('disbursement', $paid->id)->date->toDateString())->toBe('2026-10-20')
        ->and(fn () => app(ReverseExpense::class)->handle($f['finance'], $f['companyExpense'], 'Duplicate'))
        ->toThrow(ValidationException::class, 'Reject or reverse that payment out first');

    app(DecideApproval::class)->handle($f['management'], app(RequestDisbursementReversal::class)->handle($f['finance'], $paid, 'Cheque lost'), true);

    expect($paid->fresh()->status->value)->toBe('reversed')
        ->and($paid->fresh()->cheque->status->value)->toBe('cancelled')
        ->and($f['companyExpense']->fresh()->activePayment())->toBeNull()
        ->and(Expense::query()->unpaid()->count())->toBe(2)
        ->and(postedLines('disbursement_reversal', $paid->id))->toBe(['2200 0.000 80.000', '1010 80.000 0.000']);
});

test('wrong or tampered input gives a message, never a 500', function () {
    $f = $this->f;

    expect(fn () => ($this->bill)([]))->toThrow(ValidationException::class, 'Tick at least one expense')
        ->and(fn () => ($this->bill)('oops'))->toThrow(ValidationException::class)
        ->and(fn () => ($this->bill)([['x']]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->bill)([999999]))->toThrow(ValidationException::class, 'Choose expenses from the list')
        ->and(fn () => ($this->bill)([$f['companyExpense']->id], ['payee_id' => 999999]))->toThrow(ValidationException::class, 'Choose the vendor')
        ->and(fn () => ($this->bill)([$f['companyExpense']->id], ['payee_type' => 'owner']))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'other', 'payee_type' => 'vendor', 'payee_id' => $f['vendor']->id,
            'amount' => '5', 'method' => 'cash', 'reason' => 'Key cutting']))->toThrow(ValidationException::class);

    app(ReverseExpense::class)->handle($f['finance'], $f['ownerExpense'], 'Entered twice');

    expect(fn () => ($this->bill)([$f['ownerExpense']->id]))->toThrow(ValidationException::class, 'T1 tap is reversed');
});
