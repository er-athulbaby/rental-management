<?php

namespace App\Actions\Disbursements;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\DisbursementMethod;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\PayeeType;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Spec §7.5. With a source (a payment holding credit; a deposit settlement from Task 6): paid at once, within the
 * source's limit. Without one (purpose other): pending → Management (§8.3 item 9) → approved → PayDisbursement.
 */
final class RecordDisbursement
{
    public function __construct(private MarkDisbursementPaid $paid, private RequestApproval $request) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, array $data): Disbursement
    {
        if (! $actor->can('create', Disbursement::class)) {
            throw new AuthorizationException;
        }

        $today = now('Asia/Bahrain')->toDateString();
        // A source-less payment out (purpose other) gets its cheque details when Finance pays it, not now.
        $chequeNow = ($data['method'] ?? null) === DisbursementMethod::Cheque->value && ($data['purpose'] ?? null) === DisbursementPurpose::CreditRefund->value;
        $v = Validator::make($data, [
            'purpose' => ['required', Rule::in([DisbursementPurpose::CreditRefund->value, DisbursementPurpose::Other->value])], // Task 6 adds deposit_refund
            'amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'method' => ['required', Rule::enum(DisbursementMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'paid_on' => ['required_unless:purpose,other', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'payment_id' => ['required_if:purpose,credit_refund', 'nullable', 'integer', Rule::exists('payments', 'id')],
            'payee_type' => ['required_if:purpose,other', 'nullable', Rule::enum(PayeeType::class)],
            'payee_id' => ['required_if:purpose,other', 'nullable', 'integer'],
            'cheque_no' => [Rule::requiredIf($chequeNow), 'nullable', 'string', 'max:30'],
            'bank_name' => [Rule::requiredIf($chequeNow), 'nullable', 'string', 'max:100'],
            'cheque_date' => [Rule::requiredIf($chequeNow), 'nullable', 'date_format:Y-m-d'],
            'reason' => ['required_if:purpose,other', 'nullable', 'string', 'max:2000'],
        ])->validate();

        $amount = Fils::fromDecimal((string) $v['amount']);

        return DB::transaction(function () use ($actor, $v, $amount) {
            return match ($v['purpose']) {
                DisbursementPurpose::CreditRefund->value => $this->creditRefund($actor, $v, $amount),
                default => $this->other($actor, $v, $amount),
            };
        }, attempts: 3);
    }

    /** @param  array<string, mixed>  $v */
    private function creditRefund(User $actor, array $v, int $amount): Disbursement
    {
        $payment = Payment::query()->findOrFail((int) $v['payment_id']);
        $customer = Customer::query()->lockForUpdate()->findOrFail($payment->customer_id); // first lock (spec §7.2)
        if (! Customer::visibleTo($actor)->whereKey($customer->id)->exists()) {
            throw new AuthorizationException;
        }
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

        if ($payment->status !== PaymentStatus::Confirmed || $amount > $payment->unallocatedFils()) {
            throw ValidationException::withMessages(['amount' => __('At most :c BHD of this payment is credit that can be refunded.', ['c' => Fils::toDecimal(max(0, $payment->unallocatedFils()))])]);
        }

        $out = (new Disbursement)->forceFill([
            'payee_type' => PayeeType::Customer,
            'payee_id' => $customer->id,
            'purpose' => DisbursementPurpose::CreditRefund,
            'amount' => Fils::toDecimal($amount),
            'method' => $v['method'],
            'source_type' => Disbursement::SOURCE_PAYMENT,
            'source_id' => $payment->id,
            'status' => DisbursementStatus::Approved, // within its source's limit (spec §7.5): straight on to paid
            'notes' => $v['notes'] ?? null,
            'created_by' => $actor->id,
        ]);
        $out->save();
        $this->paid->handle($out, [
            'method' => $v['method'], 'paid_on' => $v['paid_on'], 'reference' => $v['reference'] ?? null,
            ...array_intersect_key($v, array_flip(['cheque_no', 'bank_name', 'cheque_date'])),
        ], $actor);

        return $out->refresh();
    }

    /** @param  array<string, mixed>  $v */
    private function other(User $actor, array $v, int $amount): Disbursement
    {
        $payeeExists = $v['payee_type'] === PayeeType::Customer->value
            ? Customer::visibleTo($actor)->whereKey($v['payee_id'])->exists()
            : Owner::query()->whereKey($v['payee_id'])->exists();
        if (! $payeeExists) {
            throw ValidationException::withMessages(['payee_id' => __('Choose the payee.')]);
        }

        $out = (new Disbursement)->forceFill([
            'payee_type' => $v['payee_type'],
            'payee_id' => (int) $v['payee_id'],
            'purpose' => DisbursementPurpose::Other,
            'amount' => Fils::toDecimal($amount),
            'method' => $v['method'],
            'reference' => $v['reference'] ?? null,
            'status' => DisbursementStatus::PendingApproval,
            'reason' => trim((string) $v['reason']),
            'notes' => $v['notes'] ?? null,
            'created_by' => $actor->id,
        ]);
        $out->save();
        $this->request->handle($actor, $out, ApprovalAction::PaymentOut, $out->reason);

        return $out;
    }
}
