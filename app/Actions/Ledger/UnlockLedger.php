<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\LedgerSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §5: Admin moves "Locked up to" back to an earlier date (on or after the start date), or clears it. Audited. */
final class UnlockLedger
{
    public function handle(User $actor, mixed $date): LedgerSetting
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        Validator::make(['unlockDate' => $date], ['unlockDate' => ['nullable', 'string', 'date_format:Y-m-d']])->validate();
        $date = filled($date) ? (string) $date : null;

        return DB::transaction(function () use ($actor, $date) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $fail = fn (string $message) => throw ValidationException::withMessages(['unlockDate' => $message]);
            if ($settings->locked_until === null || $settings->start_date === null) {
                $fail(__('The ledger is not locked.'));
            }
            if ($date !== null
                && ($date >= $settings->locked_until->toDateString() || $date < $settings->start_date->toDateString())) {
                $fail(__('Choose a date before :locked and on or after the start date, or leave it empty to unlock everything.', ['locked' => $settings->locked_until->format('d/m/Y')]));
            }
            if ($settings->rebuilding()) {
                $fail(__('Wait for the rebuild to finish.'));
            }

            $old = $settings->locked_until->toDateString();
            $settings->forceFill(['locked_until' => $date])->save();
            Audit::log('ledger.unlocked', $settings, ['locked_until' => $old], ['locked_until' => $date], causer: $actor);

            return $settings;
        });
    }
}
