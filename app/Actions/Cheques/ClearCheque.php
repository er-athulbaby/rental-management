<?php

namespace App\Actions\Cheques;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Payments\AllocationPlan;
use App\Actions\Payments\PostPayment;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §7.4: clearing creates the payment — target invoice first (issued first if still scheduled), then oldest-first. */
final class ClearCheque
{
    public function __construct(private IssueInvoice $issue, private PostPayment $post) {}

    public function handle(User $actor, Cheque $cheque, string $clearedOn): Payment
    {
        if (! $actor->can('manage', Cheque::class) || ! $actor->can('view', $cheque)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $cheque, $clearedOn) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($cheque->customer_id); // first lock (spec §7.2)
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($cheque->id);

            if ($cheque->status !== ChequeStatus::Deposited) {
                throw ValidationException::withMessages(['cleared_on' => __('Only a deposited cheque can be cleared.')]);
            }
            Validator::make(['cleared_on' => $clearedOn], [
                'cleared_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$cheque->deposited_on?->toDateString(), 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            ])->validate();

            $target = $cheque->invoice_id ? Invoice::query()->lockForUpdate()->find($cheque->invoice_id) : null;
            if ($target?->status === InvoiceStatus::Scheduled) {
                $this->issue->handle($target, $actor); // false when held back (spec §4.6): the money then goes oldest-first
            }

            $amount = Fils::fromDecimal($cheque->amount);
            $payment = $this->post->handle($customer, [
                'received_on' => $clearedOn,
                'method' => PaymentMethod::Cheque,
                'amount' => $cheque->amount,
                'reference' => $cheque->bank_name.' #'.$cheque->cheque_no,
                'cheque_id' => $cheque->id,
            ], AllocationPlan::oldestFirst($customer->id, $amount, $cheque->invoice_id), $actor);

            $cheque->forceFill(['status' => ChequeStatus::Cleared, 'cleared_on' => $clearedOn, 'payment_id' => $payment->id])->save();

            return $payment;
        }, attempts: 3);
    }
}
