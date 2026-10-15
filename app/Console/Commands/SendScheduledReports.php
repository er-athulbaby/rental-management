<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledReport;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Reports\Builder\NextRun;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Spec §7: claim every due, unpaused schedule once (unique schedule + due time), queue its send, move it on. */
class SendScheduledReports extends Command
{
    protected $signature = 'rms:reports:send-scheduled';

    protected $description = 'Send saved reports whose email schedule is due.';

    public function handle(): int
    {
        // Attachments are removed after each send; this sweeps any a crashed run left behind.
        collect(glob(storage_path('app/private/report-mail/*.xlsx')) ?: [])
            ->filter(fn ($f) => filemtime($f) < now()->subDay()->getTimestamp())->each(fn ($f) => @unlink($f));

        $due = ReportSchedule::query()->whereNull('paused_at')->where('next_run_at', '<=', now())->with(['creator', 'report'])->get();
        foreach ($due as $schedule) {
            if ($schedule->report === null || ! $schedule->creator?->active) {
                $schedule->update(['paused_at' => now()]);

                continue;
            }
            $dueAt = $schedule->next_run_at;
            try {
                $delivery = DB::transaction(function () use ($schedule, $dueAt) {
                    $delivery = ReportDelivery::create(['report_schedule_id' => $schedule->id, 'due_at' => $dueAt, 'status' => 'sending']);
                    $schedule->update(['next_run_at' => NextRun::after($schedule, now()->toImmutable())]);

                    return $delivery;
                });
            } catch (UniqueConstraintViolationException) {
                // Already claimed for this due time: never send twice. Move on so it is not found again every run.
                $schedule->update(['next_run_at' => NextRun::after($schedule, now()->toImmutable())]);

                continue;
            }
            SendScheduledReport::dispatch($delivery->id);
        }
        $this->info(sprintf('%d schedule(s) due.', $due->count()));

        return self::SUCCESS;
    }
}
