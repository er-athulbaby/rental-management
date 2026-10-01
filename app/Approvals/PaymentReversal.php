<?php

namespace App\Approvals;

use App\Actions\Payments\ReverseAllocations;
use App\Billing\CustomerCredit;
use App\Enums\PaymentStatus;
use App\Models\Approval;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Spec §8.3 item 6 (payments in; payments out arrive in M3b). Runs inside DecideApproval's transaction. */
final class PaymentReversal implements ApprovalHandler
{
    public function __construct(private ReverseAllocations $reverse) {}

    public function creatorId(Approval $approval): int
    {
        return Payment::query()->findOrFail($approval->approvable_id)->recorded_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $payment = Payment::query()->findOrFail($approval->approvable_id);
        Customer::query()->lockForUpdate()->findOrFail($payment->customer_id); // first lock (spec §7.2)
        $payment = Payment::query()->findOrFail($payment->id); // unlocked: payments lock last (spec §7.2); the customer and approval locks serialise reversals

        if ($payment->status === PaymentStatus::Reversed) {
            throw ValidationException::withMessages(['approval' => __('This payment is already reversed.')]);
        }

        $cuts = [];
        foreach ($payment->allocations()->whereNull('reverses_allocation_id')->orderBy('id')->get() as $a) {
            $cuts[] = ['allocation' => $a, 'amount' => $a->liveFils()];
        }

        $this->reverse->handle($cuts, $approver);
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
        if ($payment->status === PaymentStatus::Reversed) {
            throw ValidationException::withMessages(['approval' => __('This payment is already reversed.')]);
        }
        $payment->forceFill(['status' => PaymentStatus::Reversed, 'reversed_at' => now()])->save();

        // Spec §7.2. ponytail: always true until credit refunds exist (M3b); then a refunded payment can't be reversed.
        if (CustomerCredit::fils($payment->customer_id) < 0) {
            throw ValidationException::withMessages(['approval' => __('Reversing this payment would leave the customer\'s credit below zero.')]);
        }
        // Task 7 adds: a cleared cheque behind this payment becomes bounced.
    }

    /** A request about an existing record: rejecting leaves it unchanged (spec §8.3). */
    public function reject(Approval $approval, User $approver): void {}

    public function summary(Approval $approval): string
    {
        $payment = Payment::query()->findOrFail($approval->approvable_id);
        $customer = Customer::query()->findOrFail($payment->customer_id);

        return __('Reverse payment :n: :amount BHD from :customer, received :date by :method. Reason: :reason', [
            'n' => $payment->number, 'amount' => $payment->amount, 'customer' => $customer->name_en,
            'date' => $payment->received_on->format('d/m/Y'), 'method' => $payment->method->label(), 'reason' => $approval->reason,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('payments.show', $approval->approvable_id);
    }
}
