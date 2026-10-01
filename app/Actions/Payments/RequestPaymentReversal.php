<?php

namespace App\Actions\Payments;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Approval;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §7.3: Finance asks, with a reason; nothing changes until Management approves. */
final class RequestPaymentReversal
{
    public function __construct(private RequestApproval $request) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(User $actor, Payment $payment, string $reason, array $payload = []): Approval
    {
        if (! $actor->can('reverse', $payment)) {
            throw new AuthorizationException;
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reversalReason' => __('Give the reason for the reversal.')]);
        }

        return DB::transaction(function () use ($actor, $payment, $reason, $payload) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status === PaymentStatus::Reversed) {
                throw ValidationException::withMessages(['reversalReason' => __('This payment is already reversed.')]);
            }
            if ($payment->method === PaymentMethod::DepositApplied) {
                throw ValidationException::withMessages(['reversalReason' => __('A deposit applied by a settlement is not reversed on its own.')]);
            }

            // Plan ruling 3: a cheque payment is undone by bouncing its cheque, so cheque and payment never disagree.
            if ($payment->method === PaymentMethod::Cheque && ! isset($payload['bounced_on'])) {
                throw ValidationException::withMessages(['reversalReason' => __('This payment came from a cheque: mark the cheque as bounced instead.')]);
            }

            return $this->request->handle($actor, $payment, ApprovalAction::PaymentReversal, trim($reason), $payload);
        }, attempts: 3);
    }
}
