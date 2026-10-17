<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Models\ReportDelivery;
use App\Models\ReportPackage;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use App\Notifications\ScheduledPackage;
use App\Notifications\ScheduledReport;
use App\Notifications\ScheduledReportFailed;
use App\Reports\Builder\Comparison;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\Formatter;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Packages\PackageRunner;
use App\Reports\Packages\PackageWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/** Runs the saved report or package as each recipient (their permissions and buildings) and emails it (spec §7; packages: spec 2026-10-17 §5). */
class SendScheduledReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int ATTACH_LIMIT = 10 * 1024 * 1024;

    public int $timeout = 300;

    /** One attempt only: a retry would email the recipients who already got it. The worker's --tries does not override this. */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId) {}

    public function handle(Engine $engine, PackageRunner $runner): void
    {
        $delivery = ReportDelivery::find($this->deliveryId);
        $schedule = $delivery === null ? null : ReportSchedule::with(['report', 'package', 'recipients', 'creator'])->find($delivery->report_schedule_id);
        if ($delivery === null || $delivery->status !== 'sending' || $schedule === null) {
            return;
        }
        $target = $schedule->report ?? $schedule->package;
        if ($target === null) {
            $delivery->update(['status' => 'failed', 'error' => __('The report no longer exists.')]);

            return;
        }
        $sent = [];
        $skipped = [];
        $empty = [];
        $failed = [];
        $period = null;
        foreach ($schedule->recipients as $user) {
            if (! $user->active || $user->isVendorSupport() || ($target instanceof SavedReport && ! isset(Registry::forUser($user)[$target->subject]))) {
                $skipped[] = $user->name;

                continue;
            }
            $path = storage_path('app/private/report-mail/'.Str::uuid().'.xlsx');
            try {
                @mkdir(dirname($path), 0775, true);
                $label = $target instanceof SavedReport ? $this->sendReport($engine, $target, $user, $path) : $this->sendPackage($runner, $target, $user, $path);
                if ($label === null) {
                    $empty[] = $user->name;
                } else {
                    $period = $label;
                    $sent[] = $user->id;
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = $user->name.' ('.Str::limit($e->getMessage(), 80).')';
            } finally {
                @unlink($path); // the mail is built synchronously; on Windows the file can be locked, hence @
            }
        }
        $notes = array_filter([
            $skipped === [] ? null : (string) __('No access or inactive, skipped: :names', ['names' => implode(', ', $skipped)]),
            $empty === [] ? null : (string) __('Every item skipped, nothing sent: :names', ['names' => implode(', ', $empty)]),
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
            Audit::log('report.sent', $target, properties: ['schedule' => $schedule->id, 'recipients' => $sent, 'period' => $period, 'due_at' => $delivery->due_at->toIso8601String()]);
        }
    }

    /** @return string the period sent */
    private function sendReport(Engine $engine, SavedReport $report, User $user, string $path): string
    {
        [$data, $dropped] = ReportDefinition::forViewer($report->definition, $user);
        $result = $engine->run($user, ReportDefinition::fromArray($data, $user), limit: null);
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
            filesize($path) <= self::ATTACH_LIMIT, route('reports.builder', $report),
            $result->comparison === null ? [] : Comparison::lines($result->comparison, $result->columns),
            $dropped === [] ? null : (string) __('Some fields aren\'t available to you and were left out: :list', ['list' => implode(', ', $dropped)])));

        return $period;
    }

    /** @return string|null the period sent; null when every item was skipped for this recipient, so nothing is sent */
    private function sendPackage(PackageRunner $runner, ReportPackage $package, User $user, string $path): ?string
    {
        $result = $runner->run($package, $user, CarbonImmutable::now('Asia/Bahrain'));
        if ($result->allSkipped()) {
            return null;
        }
        PackageWorkbook::write($result, $path);
        $user->notify(new ScheduledPackage($package->name, $result->periodLabel(), $result->lines(), $result->skippedLines(), $path,
            filesize($path) <= self::ATTACH_LIMIT, route('reports.package', $package)));

        return $result->periodLabel();
    }

    /** A killed or timed-out run must not leave the delivery stuck in `sending`. */
    public function failed(Throwable $e): void
    {
        $delivery = ReportDelivery::find($this->deliveryId);
        $schedule = $delivery === null ? null : ReportSchedule::with(['report', 'package', 'creator'])->find($delivery->report_schedule_id);
        if ($delivery !== null && $schedule !== null && $delivery->status === 'sending') {
            $this->fail($delivery, $schedule, Str::limit($e->getMessage(), 490));
        }
    }

    private function fail(ReportDelivery $delivery, ReportSchedule $schedule, string $detail): void
    {
        $failures = $schedule->consecutive_failures + 1;
        $delivery->update(['status' => 'failed', 'error' => $detail]);
        $schedule->update(['consecutive_failures' => $failures] + ($failures >= 3 ? ['paused_at' => now()] : []));
        $schedule->creator?->notify(new ScheduledReportFailed(($schedule->report ?? $schedule->package)->name ?? '', $failures >= 3
            ? __('It failed 3 times in a row and is paused. Open the report, fix it and schedule it again.')
            : __('This send failed; the details are on the delivery record.')));
    }
}
