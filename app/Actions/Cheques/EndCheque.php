<?php

namespace App\Actions\Cheques;

use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §7.4: returned to the customer (held, or bounced and not replaced) or cancelled (held, entered in error). */
final class EndCheque
{
    public function handle(User $actor, Cheque $cheque, string $outcome, ?string $on = null): void
    {
        if (! $actor->can('manage', Cheque::class) || ! $actor->can('view', $cheque)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($cheque, $outcome, $on) {
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($cheque->id);

            $allowed = match ($outcome) {
                'returned' => in_array($cheque->status, [ChequeStatus::Held, ChequeStatus::Bounced], true),
                'cancelled' => $cheque->status === ChequeStatus::Held,
                default => false,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['outcome' => __('This cheque cannot be :o now.', ['o' => $outcome])]);
            }

            $cheque->forceFill([
                'status' => ChequeStatus::from($outcome),
                'returned_on' => $outcome === 'returned' ? ($on ?? now('Asia/Bahrain')->toDateString()) : null,
            ])->save();
        }, attempts: 3);
    }
}
