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
 */
class RebuildLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $from, public string $to, public int $userId) {}

    public function handle(Poster $poster): void
    {
        try {
            self::progress(['rebuild_status' => 'running']);
            do {
                $deleted = DB::transaction(function () {
                    LedgerSetting::query()->lockForUpdate()->firstOrFail();
                    $ids = Journal::query()->where('kind', 'auto')->whereBetween('date', [$this->from, $this->to])->orderBy('id')->limit(500)->pluck('id');
                    JournalLine::query()->whereIn('journal_id', $ids)->delete();
                    Journal::query()->whereKey($ids)->delete();

                    return $ids->count();
                });
            } while ($deleted > 0);

            self::progress(['rebuild_total' => Poster::pendingCount()]);
            $poster->catchUp(fn (int $done) => self::progress(['rebuild_done' => $done]));
            self::progress(['rebuild_status' => 'done']);
            Audit::log('ledger.rebuilt', LedgerSetting::current(), new: ['from' => $this->from, 'to' => $this->to], causer: User::query()->find($this->userId));
        } catch (Throwable $e) {
            report($e);
            self::progress(['rebuild_status' => 'failed', 'rebuild_message' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /** @param  array<string, mixed>  $values */
    private static function progress(array $values): void
    {
        LedgerSetting::query()->whereKey(LedgerSetting::current()->id)->update($values);
    }
}
