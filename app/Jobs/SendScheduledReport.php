<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Notifications\ScheduledReport;
use App\Notifications\ScheduledReportFailed;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\Formatter;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/** Runs the report as each recipient (their permissions and buildings) and emails it (spec §7). */
class SendScheduledReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int ATTACH_LIMIT = 10 * 1024 * 1024;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(Engine $engine): void
    {
        $delivery = ReportDelivery::findOrFail($this->deliveryId);
        if ($delivery->status !== 'sending') {
            return;
        }
        $schedule = ReportSchedule::with(['report', 'recipients', 'creator'])->findOrFail($delivery->report_schedule_id);
        $report = $schedule->report;
        if ($report === null) {
            $delivery->update(['status' => 'failed', 'error' => __('The report no longer exists.')]);

            return;
        }
        $sent = 0;
        $skipped = [];
        try {
            foreach ($schedule->recipients as $user) {
                if (! $user->active || $user->isVendorSupport() || ! isset(Registry::forUser($user)[$report->subject])) {
                    $skipped[] = $user->name;

                    continue;
                }
                $def = ReportDefinition::fromArray($report->definition, $user);
                $result = $engine->run($user, $def, limit: null);
                $path = storage_path('app/private/report-mail/'.Str::uuid().'.xlsx');
                @mkdir(dirname($path), 0775, true);
                try {
                    ExcelWriter::write($result, $path);
                    $totals = [];
                    $byKey = collect($result->columns)->keyBy('key');
                    foreach ($result->grandTotals as $key => $value) {
                        if ($key === 'count') {
                            $totals[__('Rows')] = Formatter::total(null, 'count', $value);

                            continue;
                        }
                        [$field, $fn] = explode(':', $key, 2);
                        $f = $byKey[$field] ?? null;
                        $totals[($f->label ?? $field).' ('.__($fn).')'] = Formatter::total($f, $fn, $value);
                    }
                    $user->notify(new ScheduledReport($report->name, (string) $result->periodLabel, $result->rowCount, $totals, $path,
                        filesize($path) <= self::ATTACH_LIMIT, route('reports.builder', $report)));
                } finally {
                    @unlink($path); // the mail is built synchronously; on Windows the file can be locked, hence @
                }
                $sent++;
            }
            $note = $skipped === [] ? null : Str::limit(__('No access, skipped: :names', ['names' => implode(', ', $skipped)]), 490);
            $delivery->update(['status' => $sent > 0 ? 'sent' : 'skipped', 'recipients_count' => $sent, 'sent_at' => now(), 'error' => $note]);
            $schedule->update(['consecutive_failures' => 0]);
            Audit::log('report.sent', $report, properties: ['schedule' => $schedule->id, 'recipients' => $sent, 'due_at' => $delivery->due_at->toIso8601String()]);
        } catch (Throwable $e) {
            $failures = $schedule->consecutive_failures + 1;
            $delivery->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 490)]);
            $schedule->update(['consecutive_failures' => $failures] + ($failures >= 3 ? ['paused_at' => now()] : []));
            $schedule->creator?->notify(new ScheduledReportFailed($report->name, $failures >= 3
                ? __('It failed 3 times in a row and is paused. Open the report, fix it and schedule it again.')
                : __('This send failed: :e', ['e' => Str::limit($e->getMessage(), 200)])));
        }
    }
}
