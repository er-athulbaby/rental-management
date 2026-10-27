<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Spec §3 (plan ruling 6): Admin sets or moves the start date, only while nothing is locked. The opening journal moves
 * with it, and the ledger is rebuilt from the earlier of the old and new dates to today.
 */
final class SetLedgerStart
{
    public function handle(User $actor, mixed $date): void
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        Validator::make(['startDate' => $date], ['startDate' => ['required', 'string', 'date_format:Y-m-d']])->validate();
        $date = (string) $date;
        if ($date > now('Asia/Bahrain')->toDateString()) {
            throw ValidationException::withMessages(['startDate' => __('The start date cannot be in the future.')]);
        }

        DB::transaction(function () use ($actor, $date) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $fail = fn (string $message) => throw ValidationException::withMessages(['startDate' => $message]);
            if ($settings->locked_until !== null) {
                $fail(__('The start date can move only while no month is locked.'));
            }
            if ($settings->rebuilding()) {
                $fail(__('Wait for the rebuild to finish.'));
            }
            $manual = Journal::query()->where('kind', 'manual')->where('date', '<', $date)->min('date');
            if ($manual !== null) {
                $fail(__('A manual journal is dated :date, before that start date.', ['date' => CarbonImmutable::parse((string) $manual)->format('d/m/Y')]));
            }
            $old = $settings->start_date?->toDateString();
            if ($old === $date) {
                return;
            }

            $settings->forceFill(['start_date' => $date])->save();
            self::moveOpening($date);
            Audit::log('ledger.start_set', $settings, ['start_date' => $old], ['start_date' => $date], causer: $actor);

            $from = $old === null ? $date : min($old, $date);
            $today = now('Asia/Bahrain')->toDateString();
            if ($from <= $today) {
                RebuildLedger::queue($settings, $from, $today, $actor);
            }
        });
    }

    /** The opening journal is dated the start date; journals never change, so it is written again at the new date. */
    private static function moveOpening(string $date): void
    {
        $opening = Journal::query()->where('kind', 'opening')->with('lines')->first();
        if ($opening === null) {
            return;
        }
        $lines = array_values($opening->lines->map(fn (JournalLine $l) => ['account' => $l->ledger_account_id, 'amount' => Fils::fromDecimal($l->debit) - Fils::fromDecimal($l->credit),
            'customer_id' => null, 'owner_id' => null, 'building_id' => null, 'unit_id' => null, 'memo' => $l->memo])->all());
        JournalLine::query()->where('journal_id', $opening->id)->delete();
        $opening->delete();
        Poster::write('opening', $date, $opening->description, $lines, createdBy: $opening->created_by);
    }
}
