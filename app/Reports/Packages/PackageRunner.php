<?php

namespace App\Reports\Packages;

use App\Models\ReportPackage;
use App\Models\ReportPackageItem;
use App\Models\SavedReport;
use App\Models\User;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\FieldType;
use App\Reports\Builder\Formatter;
use App\Reports\Builder\Period;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-17 §4: runs each item of a package, in order, as one user. Read-only. An item that cannot run is
 * skipped with a reason and never stops the others.
 *
 * @phpstan-import-type Form from PackageReport
 */
class PackageRunner
{
    public const int MAX_ITEMS = 15;

    public function __construct(private Engine $engine) {}

    public function run(ReportPackage $package, User $as, CarbonImmutable $now): PackageResult
    {
        $sheets = [];
        foreach ($package->items()->get() as $item) {
            $sheets[] = $item->kind === 'saved' ? $this->saved($item, $as, $now) : $this->readyMade($item, $as, $now);
        }

        return new PackageResult($package->name, $sheets);
    }

    /** @return array<string, string> Period::presets() without "Custom dates": a package period is always relative (plan ruling 19) */
    public static function presets(): array
    {
        return array_diff_key(Period::presets(), ['custom' => true]);
    }

    /**
     * Only the settings this report takes (spec §8 whitelist). An empty building means all; a numeric one is an int.
     *
     * @param  Form  $form
     * @param  array<array-key, mixed>  $settings
     * @return array<array-key, mixed>
     */
    public static function clean(array $form, array $settings): array
    {
        $keys = [...($form['building'] ? ['building'] : []), ...($form['dates'] === 'none' ? [] : ['period']), ...array_keys($form['options'])];
        $clean = array_intersect_key($settings, array_flip($keys));
        if (array_key_exists('building', $clean)) {
            $b = $clean['building'];
            $clean['building'] = $b === '' || $b === null ? null : (is_string($b) && ctype_digit($b) ? (int) $b : $b);
        }

        return $clean;
    }

    /**
     * Spec §3: the item's relative period as the report's own dates at $now (Asia/Bahrain), plus the sheet's period row
     * (plan rulings 6, 7).
     *
     * @param  Form  $form
     * @param  array<array-key, mixed>  $settings  cleaned item settings
     * @return array{settings: array<array-key, mixed>, period: string}
     *
     * @throws ValidationException when the period is not a relative preset
     */
    public static function resolve(array $form, array $settings, CarbonImmutable $now): array
    {
        $today = $now->timezone('Asia/Bahrain');
        $out = array_diff_key($settings, ['period' => true]);
        if ($form['dates'] === 'none') {
            return ['settings' => $out, 'period' => __('As at :date', ['date' => $today->format('d/m/Y')])];
        }
        $preset = $settings['period'] ?? null;
        if (! is_string($preset) || ! isset(self::presets()[$preset])) {
            throw ValidationException::withMessages(['period' => __('Choose a period.')]);
        }
        [$from, $to] = Period::resolve($preset, null, null, $today);

        if ($form['dates'] === 'single') {
            return ['settings' => [...$out, 'to' => $to->toDateString()], 'period' => __('As at :date', ['date' => $to->format('d/m/Y')])];
        }
        if ($form['dates'] === 'months') {
            $last = $to->startOfMonth()->min($today->startOfMonth()); // a month not yet begun has no figures
            $first = $from->startOfMonth()->max($last->subMonths(11)); // ponytail: at most 12 months, most recent kept; no preset reaches it

            return ['settings' => [...$out, 'from' => $first->format('Y-m'), 'to' => $last->format('Y-m')],
                'period' => Period::label($first, $last->endOfMonth()->startOfDay())];
        }

        return ['settings' => [...$out, 'from' => $from->toDateString(), 'to' => $to->toDateString()], 'period' => Period::label($from, $to)];
    }

    private function readyMade(ReportPackageItem $item, User $as, CarbonImmutable $now): PackageSheet
    {
        $key = (string) $item->report;
        if (! isset(ReadyMade::REPORTS[$key])) {
            return PackageSheet::skip($key, __('This report is no longer available.'));
        }
        $report = ReadyMade::get($key);
        $form = $report::packageForm($as);
        if (! $form['allowed']) {
            return PackageSheet::skip($form['title'], __('You do not have access to this report.'));
        }
        try {
            $run = self::resolve($form, self::clean($form, $item->settings), $now);
            $sheet = $report::sheet($as, $run['settings']);
        } catch (AuthorizationException) {
            return PackageSheet::skip($form['title'], __('You do not have access to this report.'));
        } catch (ValidationException $e) {
            return PackageSheet::skip($form['title'], self::invalid($e));
        }

        return new PackageSheet($form['title'], $run['period'], $sheet['columns'], $sheet['rows'], count($sheet['rows']), $sheet['headline']);
    }

    /** Plan ruling 11: subject access, as the scheduled job; fields this user may not see are dropped and noted. */
    private function saved(ReportPackageItem $item, User $as, CarbonImmutable $now): PackageSheet
    {
        $report = SavedReport::withTrashed()->find($item->saved_report_id);
        if ($report === null || $report->trashed()) {
            return PackageSheet::skip($report->name ?? __('Saved report'), __('Report deleted'));
        }
        if (! isset(Registry::forUser($as)[$report->subject])) {
            return PackageSheet::skip($report->name, __('You do not have access to this report.'));
        }
        [$data, $dropped] = ReportDefinition::forViewer($report->definition, $as);
        try {
            $result = $this->engine->run($as, ReportDefinition::fromArray($data, $as), limit: null);
        } catch (ValidationException $e) {
            return PackageSheet::skip($report->name, self::invalid($e));
        }
        $sheet = ExcelWriter::sheet($result);

        return new PackageSheet($report->name, $result->periodLabel ?? __('As at :date', ['date' => $now->timezone('Asia/Bahrain')->format('d/m/Y')]),
            $sheet['columns'], $sheet['rows'], $result->rowCount, self::headline($result),
            note: $dropped === [] ? null : (string) __('Some fields aren\'t available to you and were left out: :list', ['list' => implode(', ', $dropped)]));
    }

    /** The first money column's grand total, e.g. "Amount: 1,250.000" (spec §5). */
    private static function headline(ReportResult $result): ?string
    {
        foreach ($result->columns as $field) {
            if ($field->type !== FieldType::Money) {
                continue;
            }
            foreach ($result->grandTotals as $key => $value) {
                if (str_starts_with((string) $key, $field->key.':')) {
                    return $field->label.': '.Formatter::total($field, explode(':', (string) $key, 2)[1], $value);
                }
            }
        }

        return null;
    }

    private static function invalid(ValidationException $e): string
    {
        return __('Its settings are no longer valid: :reason', ['reason' => (string) collect($e->errors())->flatten()->first()]);
    }
}
