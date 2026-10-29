<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Sources;
use App\Models\CashMovement;
use App\Models\LedgerSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Ledger money screens spec §3 (plan rulings 8 and 10): a movement is reversed once, by a row of its own that posts the
 * exact opposite, on an open date on or after the original's. A reversal is never itself reversed.
 */
final class ReverseMoneyEntry
{
    public function handle(User $actor, CashMovement $entry, mixed $date, mixed $reason): CashMovement
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        Validator::make(['reverseDate' => $date, 'reverseReason' => $reason], [
            'reverseDate' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'reverseReason' => ['required', 'string', 'max:1000'],
        ])->validate();
        $date = (string) $date;

        return DB::transaction(function () use ($actor, $entry, $date, $reason) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $original = CashMovement::query()->lockForUpdate()->findOrFail($entry->id);
            if ($original->reverses_id !== null) {
                throw ValidationException::withMessages(['reverseDate' => __('A reversal cannot be reversed.')]);
            }
            if ($original->reversal()->exists()) {
                throw ValidationException::withMessages(['reverseDate' => __('This entry is already reversed.')]);
            }
            if ($date < $original->date->toDateString()) {
                throw ValidationException::withMessages(['reverseDate' => __('The reversal cannot be dated before the original (:d).', ['d' => $original->date->format('d/m/Y')])]);
            }
            PostManualJournal::openDate($settings, $date, 'reverseDate');

            $reversal = $original->replicate();
            $reversal->forceFill(['date' => $date, 'note' => trim((string) $reason), 'reverses_id' => $original->id, 'created_by' => $actor->id])->save();
            Sources::assertPostable('cash_movement_reversal', $reversal->id, 'reverseDate');
            Audit::log('ledger.money_entry_reversed', $original, properties: ['reversal' => $reversal->id, 'date' => $date], causer: $actor);

            return $reversal;
        });
    }
}
