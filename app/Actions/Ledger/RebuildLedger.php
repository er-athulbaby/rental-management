<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Jobs\RebuildLedgerJob;
use App\Models\LedgerSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Spec §5 (plan ruling 4): Finance or Admin rebuild whole months in the background. The range is clamped to open days:
 * from the later of the first day, the start date and the day after the lock, to the earlier of the last day and today.
 */
final class RebuildLedger
{
    /** @return array{from: string, to: string} the days actually rebuilt */
    public function handle(User $actor, mixed $fromMonth, mixed $toMonth): array
    {
        if (! $actor->can(PermissionName::LedgerManage) && ! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        Validator::make(['fromMonth' => $fromMonth, 'toMonth' => $toMonth], [
            'fromMonth' => ['required', 'string', 'date_format:Y-m'],
            'toMonth' => ['required', 'string', 'date_format:Y-m', 'after_or_equal:fromMonth'],
        ])->validate();

        return DB::transaction(function () use ($actor, $fromMonth, $toMonth) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $first = $settings->firstOpenDay();
            if ($first === null) {
                throw ValidationException::withMessages(['rebuild' => __('Set the ledger start date first.')]);
            }
            if ($settings->rebuilding()) {
                throw ValidationException::withMessages(['rebuild' => __('A rebuild is already running.')]);
            }
            $from = CarbonImmutable::parse($fromMonth.'-01', 'Asia/Bahrain')->max($first)->toDateString();
            $to = CarbonImmutable::parse($toMonth.'-01', 'Asia/Bahrain')->endOfMonth()->min(now('Asia/Bahrain'))->toDateString();
            if ($from > $to) {
                throw ValidationException::withMessages(['rebuild' => __('Nothing to rebuild: those months are locked, before the start date or still to come.')]);
            }

            self::queue($settings, $from, $to, $actor);
            Audit::log('ledger.rebuild_requested', $settings, new: ['from' => $from, 'to' => $to], causer: $actor);

            return ['from' => $from, 'to' => $to];
        });
    }

    /** Marks a rebuild queued and dispatches it after commit. Internal: the caller holds the settings lock and checked rebuilding(). */
    public static function queue(LedgerSetting $locked, string $from, string $to, User $actor): void
    {
        $locked->forceFill(['rebuild_status' => 'queued', 'rebuild_from' => $from, 'rebuild_to' => $to, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null])->save();
        $userId = $actor->id;
        DB::afterCommit(fn () => RebuildLedgerJob::dispatch($from, $to, $userId));
    }
}
