<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Spec §5 (plan ruling 4): deletes the range's automatic journals 500 at a time, then posts every pending source, writing
 * progress on the settings row. Manual and opening journals are never touched. Each deletion chunk takes the settings
 * lock, so live posting, locking and the rebuild never interleave inside a chunk.
 *
 * Time-boxed: a leg that has run `$budget` seconds dispatches the next leg and stops, so no leg outlives the queue's
 * retry_after (330 s). Each leg resumes from what is left (deletions are idempotent, the catch-up walks pending sources).
 */
class RebuildLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /** @param  bool  $deleted  the deletion phase is finished (set for the later legs, which also never reset the total) */
    public function __construct(public string $from, public string $to, public int $userId, public int $budget = 240, public bool $deleted = false) {}

    public function handle(Poster $poster): void
    {
        $until = microtime(true) + $this->budget;
        try {
            self::progress(['rebuild_status' => 'running']);
            if (! $this->deleted) {
                do {
                    $count = DB::transaction(function () {
                        LedgerSetting::query()->lockForUpdate()->firstOrFail();
                        $ids = Journal::query()->where('kind', 'auto')->whereBetween('date', [$this->from, $this->to])->orderBy('id')->limit(500)->pluck('id');
                        JournalLine::query()->whereIn('journal_id', $ids)->delete();
                        Journal::query()->whereKey($ids)->delete();

                        return $ids->count();
                    });
                    if ($count > 0 && microtime(true) >= $until) {
                        $this->next(false);

                        return;
                    }
                } while ($count > 0);

                self::progress(['rebuild_total' => Poster::pendingCount()]);
            }

            $base = LedgerSetting::current()->rebuild_done;
            $pending = Poster::pendingCount();
            $tried = $poster->catchUp(fn (int $done) => self::progress(['rebuild_done' => $base + $done]), $until);
            self::progress(['rebuild_done' => $base + $tried]);
            if (microtime(true) >= $until && ($left = Poster::pendingCount()) > 0 && $left < $pending) { // no progress means only failing sources are left: stop, Ledger health shows them
                $this->next(true);

                return;
            }
            self::progress(['rebuild_status' => 'done']);
            Audit::log('ledger.rebuilt', LedgerSetting::current(), new: ['from' => $this->from, 'to' => $this->to], causer: User::query()->find($this->userId));
        } catch (Throwable $e) {
            report($e);
            $this->failed($e);
        }
    }

    /** Also called by the queue on a timeout or a killed worker, so the status never stays "running". */
    public function failed(Throwable $e): void
    {
        self::progress(['rebuild_status' => 'failed', 'rebuild_message' => mb_substr($e->getMessage(), 0, 500)]);
    }

    private function next(bool $deleted): void
    {
        self::dispatch($this->from, $this->to, $this->userId, $this->budget, $deleted);
    }

    /** @param  array<string, mixed>  $values */
    private static function progress(array $values): void
    {
        LedgerSetting::query()->whereKey(LedgerSetting::current()->id)->update($values);
    }
}
