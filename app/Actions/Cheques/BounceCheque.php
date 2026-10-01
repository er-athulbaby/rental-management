<?php

namespace App\Actions\Cheques;

use App\Actions\Payments\RequestPaymentReversal;
use App\Enums\ChequeStatus;
use App\Models\Approval;
use App\Models\Cheque;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §7.4: before clearing, bounced directly (no payment exists); after clearing, a payment reversal request. */
final class BounceCheque
{
    public function __construct(private RequestPaymentReversal $reversal) {}

    public function handle(User $actor, Cheque $cheque, string $bouncedOn, string $reason): ?Approval
    {
        if (! $actor->can('manage', Cheque::class) || ! $actor->can('view', $cheque)) {
            throw new AuthorizationException;
        }
        Validator::make(['bounced_on' => $bouncedOn, 'bounce_reason' => $reason], [
            'bounced_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'bounce_reason' => ['required', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $cheque, $bouncedOn, $reason) {
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($cheque->id);

            if ($cheque->status === ChequeStatus::Deposited) {
                $cheque->forceFill(['status' => ChequeStatus::Bounced, 'bounced_on' => $bouncedOn, 'bounce_reason' => $reason])->save();

                return null;
            }

            if ($cheque->status === ChequeStatus::Cleared) {
                // The cheque becomes bounced when Management approves the reversal (PaymentReversal handler).
                return $this->reversal->handle($actor, Payment::query()->findOrFail($cheque->payment_id), __('Cheque :n returned by the bank: :r', ['n' => $cheque->cheque_no, 'r' => $reason]),
                    ['cheque_id' => $cheque->id, 'bounced_on' => $bouncedOn, 'bounce_reason' => $reason]);
            }

            throw ValidationException::withMessages(['bounced_on' => __('Only a deposited or cleared cheque can bounce.')]);
        }, attempts: 3);
    }
}
