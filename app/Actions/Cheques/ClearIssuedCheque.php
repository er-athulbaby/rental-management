<?php

namespace App\Actions\Cheques;

use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §7.4: the payee presented the company's cheque. Nothing else moves; the payment out was already paid. */
final class ClearIssuedCheque
{
    public function handle(User $actor, Cheque $cheque, string $clearedOn): void
    {
        if (! $actor->can('manage', Cheque::class)) {
            throw new AuthorizationException;
        }
        Validator::make(['cleared_on' => $clearedOn], ['cleared_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()]])->validate();

        DB::transaction(function () use ($cheque, $clearedOn) {
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($cheque->id);
            if ($cheque->direction !== ChequeDirection::Issued || $cheque->status !== ChequeStatus::Issued) {
                throw ValidationException::withMessages(['cleared_on' => __('Only an issued cheque that has not cleared can be marked cleared.')]);
            }
            $cheque->forceFill(['status' => ChequeStatus::Cleared, 'cleared_on' => $clearedOn])->save();
        }, attempts: 3);
    }
}
