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

/** Spec §5 (plan ruling 5): Finance moves "Locked up to" forward, to a closed day (before today). Nothing on or before it is posted, rebuilt or changed. */
final class LockLedger
{
    public function handle(User $actor, mixed $date): LedgerSetting
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        Validator::make(['lockDate' => $date], [
            'lockDate' => ['required', 'string', 'date_format:Y-m-d', 'before:'.now('Asia/Bahrain')->toDateString()],
        ])->validate();
        $date = (string) $date;

        return DB::transaction(function () use ($actor, $date) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $fail = fn (string $message) => throw ValidationException::withMessages(['lockDate' => $message]);
            if ($settings->start_date === null) {
                $fail(__('Set the ledger start date first.'));
            }
            if ($date < $settings->start_date->toDateString()) {
                $fail(__('Lock a date on or after the start date, :date.', ['date' => $settings->start_date->format('d/m/Y')]));
            }
            if ($settings->locked_until !== null && $date <= $settings->locked_until->toDateString()) {
                $fail(__('The ledger is already locked up to :date.', ['date' => $settings->locked_until->format('d/m/Y')]));
            }
            if ($settings->rebuilding()) {
                $fail(__('Wait for the rebuild to finish.'));
            }

            $old = $settings->locked_until?->toDateString();
            $settings->forceFill(['locked_until' => $date])->save();
            Audit::log('ledger.locked', $settings, ['locked_until' => $old], ['locked_until' => $date], causer: $actor);

            return $settings;
        });
    }
}
