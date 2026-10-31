<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\Ledger\FinishBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\RecordBankOnlyLine;
use App\Actions\Ledger\RecordVatReturn;
use App\Actions\Ledger\ReverseManualJournal;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Actions\Ledger\StartBankReconciliation;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Enums\PaymentStatus;
use App\Ledger\BankEntries;
use App\Models\Approval;
use App\Models\BankReconciliation;
use App\Models\LedgerAccount;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = bankRecFixture();
});

/** Matches statement 1 of bankRecFixture() plus $extra (description => entry keys) and finishes Bank's reconciliation to 20/10. */
function finishedBankRec(array $f, string $closing, array $extra): BankReconciliation
{
    $rec = bankRec($f['finance'], '2026-10-20', $closing, '1000');
    $match = fn (string $line, array $keys) => app(MatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine($line)->id, $keys);
    $match('TRANSFER '.$f['headLease']->number, ['disbursement:'.$f['headLease']->id]);
    $match('CASH DEPOSIT DEP-1 DEP-2', ['cash_movement:'.$f['dep1']->id, 'cash_movement:'.$f['dep2']->id]);
    app(RecordBankOnlyLine::class)->handle($f['finance'], $rec, (string) bankLine('BANK CHARGES OCT')->id, 'bank_charge', null);
    app(RecordBankOnlyLine::class)->handle($f['finance'], $rec, (string) bankLine('CREDIT INTEREST')->id, 'bank_interest', null);
    foreach ($extra as $line => $keys) {
        $match($line, $keys);
    }

    return app(FinishBankReconciliation::class)->handle($f['finance'], $rec);
}

test('a reconciled entry is not reversed on or before the end date; a later reversal and an unreconciled entry are fine', function () {
    $f = $this->f;
    $pay = fn (string $amount, string $reference) => app(RecordPayment::class)->handle($f['finance'], $f['omar'], ['received_on' => '2026-10-19',
        'method' => 'bank_transfer', 'amount' => $amount, 'reference' => $reference]);
    $early = $pay('50', 'OM-50'); // its reversal is asked for before the reconciliation is finished
    $late = $pay('30', 'OM-30');  // and this one's after
    $vat = app(RecordVatReturn::class)->handle($f['finance'], ['period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'date' => '2026-10-20',
        'method' => 'bank_transfer', 'reference' => 'NBR-Q4', 'amount' => '42']);
    bankStatement($f['finance'], '1010', [['17/10/2026', 'CHQ 7781', '', '-40'], ['19/10/2026', 'OMAR OM-50', '', '50'], ['19/10/2026', 'OMAR OM-30', '', '30'],
        ['20/10/2026', 'NBR VAT', '', '-42']]);
    $askedEarly = app(RequestPaymentReversal::class)->handle($f['finance'], $early, 'Sent back by the bank');
    $askedOut = app(RequestDisbursementReversal::class)->handle($f['finance'], $f['headLease'], 'Paid twice');
    finishedBankRec($f, '1196.700', ['CHQ 7781' => ['manual:'.$f['manual']->id], 'OMAR OM-50' => ['payment:'.$early->id],
        'OMAR OM-30' => ['payment:'.$late->id], 'NBR VAT' => ['vat_return:'.$vat->id]]);
    $refused = 'This entry is reconciled with the bank statement up to 20/10/2026, so it cannot be reversed on or before that day.';
    $decide = fn (Approval $approval) => app(DecideApproval::class)->handle($f['management'], $approval, true);

    expect(fn () => $decide($askedEarly))->toThrow(ValidationException::class, $refused)
        ->and(fn () => $decide($askedOut))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(RequestPaymentReversal::class)->handle($f['finance'], $late, 'Wrong tenant'))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(RequestDisbursementReversal::class)->handle($f['finance'], $f['headLease']->fresh(), 'Paid twice'))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $f['dep1'], '2026-10-20', 'Typo'))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $vat, '2026-10-20', 'Typo'))->toThrow(ValidationException::class, $refused)
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $f['manual'], '2026-10-20'))->toThrow(ValidationException::class, $refused)
        ->and($early->fresh()->status)->not->toBe(PaymentStatus::Reversed);

    // Not reconciled: DEP-3 is still in transit, and an expense never touches a money account.
    app(ReverseMoneyEntry::class)->handle($f['finance'], $f['dep3'], '2026-10-20', 'Never banked');
    app(ReverseExpense::class)->handle($f['finance'], $f['companyExpense'], 'Entered twice');

    // The next day is after the end date.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $decide($askedEarly);
    app(ReverseMoneyEntry::class)->handle($f['finance'], $f['dep1'], '2026-10-21', 'Typo');
    app(ReverseManualJournal::class)->handle($f['finance'], $f['manual'], '2026-10-21');

    expect($early->fresh()->status)->toBe(PaymentStatus::Reversed)
        ->and(journalFor('payment_reversal', $early->id)?->date->toDateString())->toBe('2026-10-21')
        ->and(journalFor('cash_movement_reversal', $f['dep1']->reversal()->sole()->id)?->date->toDateString())->toBe('2026-10-21');

    // A payment and its reversal are two separate entries on the money account.
    $bank = (int) LedgerAccount::query()->where('code', '1010')->value('id');
    $entries = BankEntries::query($bank, '2026-10-31')->get()->filter(fn ($e) => $e->entry_id == $early->id && in_array($e->entry_type, ['payment', 'payment_reversal'], true));
    expect($entries->pluck('entry_type')->sort()->values()->all())->toBe(['payment', 'payment_reversal'])
        ->and($entries->pluck('amount')->map(fn ($a) => (int) round($a * 1000))->sort()->values()->all())->toBe([-50000, 50000]);
});

test('a credit note does not reverse a reconciled receipt: no money-account entry changes, so it is allowed', function () {
    $f = $this->f;
    $card = (string) LedgerAccount::query()->where('code', '1020')->value('id');
    $omarCard = Payment::query()->where('customer_id', $f['omar']->id)->sole(); // 300.000 by card on 06/10
    bankStatement($f['finance'], '1020', [['07/10/2026', 'CARD SETTLEMENT', '', '300']]);
    $rec = app(StartBankReconciliation::class)->handle($f['finance'], ['ledger_account_id' => $card, 'end_date' => '2026-10-20', 'closing_balance' => '300',
        'opening_balance' => '0']);
    app(MatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine('CARD SETTLEMENT')->id, ['payment:'.$omarCard->id]);
    app(FinishBankReconciliation::class)->handle($f['finance'], $rec);
    $before = BankEntries::figures($rec->fresh());

    $cn = app(SaveCreditNote::class)->handle($f['finance'], $f['omarRent'], null, ['reason' => 'Goodwill',
        'lines' => [['credited_line_id' => $f['omarRent']->lines->sole()->id, 'amount' => '100.000']]]);
    app(DecideApproval::class)->handle($f['management'], app(SubmitCreditNote::class)->handle($f['finance'], $cn), true);

    expect($cn->fresh()->status->value)->toBe('issued')
        ->and($omarCard->fresh()->status)->not->toBe(PaymentStatus::Reversed)
        ->and(BankEntries::figures($rec->fresh()))->toEqual($before)
        ->and($before['difference'])->toBe(0);
});
