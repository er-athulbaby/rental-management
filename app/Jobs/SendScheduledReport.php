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

    public int $timeout = 300;

    public function __construct(public int $deliveryId) {}

    public function handle(Engine $engine): void
    {
        $delivery = ReportDelivery::find($this->deliveryId);
        $schedule = $delivery === null ? null : ReportSchedule::with(['report', 'recipients', 'creator'])->find($delivery->report_schedule_id);
        if ($delivery === null || $delivery->status !== 'sending' || $schedule === null) {
            return;
        }
        $report = $schedule->report;
        if ($report === null) {
            $delivery->update(['status' => 'failed', 'error' => __('The report no longer exists.')]);

            return;
        }
        $sent = [];
        $skipped = [];
        $failed = [];
        $period = null;
        foreach ($schedule->recipients as $user) {
            if (! $user->active || $user->isVendorSupport() || ! isset(Registry::forUser($user)[$report->subject])) {
                $skipped[] = $user->name;

                continue;
            }
            $path = storage_path('app/private/report-mail/'.Str::uuid().'.xlsx');
            try {
                $result = $engine->run($user, ReportDefinition::fromArray($report->definition, $user), limit: null);
                @mkdir(dirname($path), 0775, true);
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
                $period = (string) $result->periodLabel;
                $user->notify(new ScheduledReport($report->name, $period, $result->rowCount, $totals, $path,
                    filesize($path) <= self::ATTACH_LIMIT, route('reports.builder', $report)));
                $sent[] = $user->id;
            } catch (Throwable $e) {
                report($e);
                $failed[] = $user->name.' ('.Str::limit($e->getMessage(), 80).')';
            } finally {
                @unlink($path); // the mail is built synchronously; on Windows the file can be locked, hence @
            }
        }
        $notes = array_filter([
            $skipped === [] ? null : (string) __('No access or inactive, skipped: :names', ['names' => implode(', ', $skipped)]),
            $failed === [] ? null : (string) __('Failed: :names', ['names' => implode('; ', $failed)]),
        ]);
        $note = $notes === [] ? null : Str::limit(implode(' | ', $notes), 490);
        if ($sent === [] && $failed !== []) {
            $this->fail($delivery, $schedule, $note ?? '');

            return;
        }
        $delivery->update(['status' => $sent !== [] ? 'sent' : 'skipped', 'recipients_count' => count($sent), 'sent_at' => now(), 'error' => $note]);
        if ($failed === []) { // a partial send neither counts as a failure nor clears the count
            $schedule->update(['consecutive_failures' => 0]);
        }
        if ($sent !== []) {
            Audit::log('report.sent', $report, properties: ['schedule' => $schedule->id, 'recipients' => $sent, 'period' => $period, 'due_at' => $delivery->due_at->toIso8601String()]);
        }
    }

    /** A killed or timed-out run must not leave the delivery stuck in `sending`. */
    public function failed(Throwable $e): void
    {
        $delivery = ReportDelivery::find($this->deliveryId);
        $schedule = $delivery === null ? null : ReportSchedule::with(['report', 'creator'])->find($delivery->report_schedule_id);
        if ($delivery !== null && $schedule !== null && $delivery->status === 'sending') {
            $this->fail($delivery, $schedule, Str::limit($e->getMessage(), 490));
        }
    }

    private function fail(ReportDelivery $delivery, ReportSchedule $schedule, string $detail): void
    {
        $failures = $schedule->consecutive_failures + 1;
        $delivery->update(['status' => 'failed', 'error' => $detail]);
        $schedule->update(['consecutive_failures' => $failures] + ($failures >= 3 ? ['paused_at' => now()] : []));
        $schedule->creator?->notify(new ScheduledReportFailed($schedule->report->name ?? '', $failures >= 3
            ? __('It failed 3 times in a row and is paused. Open the report, fix it and schedule it again.')
            : __('This send failed; the details are on the delivery record.')));
    }
}
