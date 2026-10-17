<?php

namespace App\Livewire\Reports\Concerns;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Plan ruling 1, spec §10: every report filters by building and date, respects building assignment, and exports to
 * Excel (audited, §8.4). Filters are validated outside render(): an invalid one shows an error and an empty table.
 */
trait ReportPage
{
    use WithActor;

    #[Url]
    public ?int $building = null;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    abstract protected function title(): string;

    abstract protected function permission(): bool;

    /** @return array<string, string> */
    abstract protected function columns(): array;

    /** @return list<array<string, string|int|null>> */
    abstract protected function rows(): array;

    protected function dateMode(): string
    {
        return 'range';
    }

    /** False for a report whose rows have no building (the filter is then neither shown nor audited). */
    protected function buildingFilter(): bool
    {
        return true;
    }

    /** @return array<string, array<array-key, string>> */
    protected function options(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return [];
    }

    /**
     * Charts drawn above the table from the same rows (see <x-report-chart>); none by default.
     *
     * @param  list<array<string, string|int|null>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function charts(array $rows): array
    {
        return [];
    }

    public function mountReportPage(): void
    {
        abort_unless($this->permission(), 403);
        $today = now('Asia/Bahrain');
        $this->to = $this->to ?: $today->toDateString();
        $this->from = $this->from ?: $today->startOfMonth()->toDateString();
    }

    /** A trait hook (Livewire calls updated{TraitName}), so a report's own updated() can't replace filter validation. */
    public function updatedReportPage(): void
    {
        $this->validate($this->filterRules());
    }

    /** @return array<string, list<string>> */
    private function filterRules(): array
    {
        $rules = ['building' => ['nullable', 'integer']];
        foreach (array_keys($this->options()) as $property) {
            $rules[$property] = ['required', 'in:'.implode(',', array_keys($this->options()[$property]))];
        }

        return match ($this->dateMode()) {
            'range' => [...$rules, 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']],
            'single' => [...$rules, 'to' => ['required', 'date_format:Y-m-d']],
            default => $rules,
        };
    }

    private function filtersValid(): bool
    {
        return Validator::make($this->all(), $this->filterRules())->passes();
    }

    public function export(): BinaryFileResponse
    {
        abort_unless($this->permission(), 403);
        $this->validate($this->filterRules());
        $report = Str::snake(Str::beforeLast(class_basename(static::class), 'Report'));
        $dates = match ($this->dateMode()) {
            'range' => ['from' => $this->from, 'to' => $this->to],
            'single' => ['to' => $this->to],
            default => [],
        };
        Audit::log('report.exported', properties: ['report' => $report, ...($this->buildingFilter() ? ['building' => $this->building] : []), ...$dates,
            ...array_intersect_key($this->all(), $this->options())], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-'.$report.'-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        $sheet = $this->sheetData();
        $writer->addHeader($sheet['columns']);
        foreach ($sheet['rows'] as $row) {
            $writer->addRow($row);
        }
        $writer->close();

        $date = $dates === [] ? now('Asia/Bahrain')->toDateString() : $this->to;

        return response()->download($path, "{$report}-{$date}.xlsx")->deleteFileAfterSend();
    }

    /** @return array{title: string, dates: string, building: bool, options: array<string, array<array-key, string>>, allowed: bool} */
    public function packageForm(User $as): array
    {
        return $this->asActor($as, fn () => ['title' => $this->title(), 'dates' => $this->dateMode(), 'building' => $this->buildingFilter(),
            'options' => $this->options(), 'allowed' => $this->permission()]);
    }

    /** @param  array<array-key, mixed>  $settings */
    public function checkSettings(User $as, array $settings): void
    {
        $this->asActor($as, fn () => Validator::make($settings, $this->packageRules($as))->validate());
    }

    /**
     * Spec 2026-10-17 §4: what export() writes, for $settings, run as $as. Validated before anything is assigned, so a
     * tampered value never reaches a typed property.
     *
     * @param  array<array-key, mixed>  $settings
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}
     */
    public function sheet(User $as, array $settings): array
    {
        return $this->asActor($as, function () use ($as, $settings) {
            if (! $this->permission()) {
                throw new AuthorizationException(__('You do not have access to this report.'));
            }
            $data = Validator::make($settings, $this->packageRules($as))->validate();
            $today = now('Asia/Bahrain')->toDateString();
            $this->building = $this->buildingFilter() && filled($data['building'] ?? null) ? (int) $data['building'] : null;
            $this->from = (string) ($data['from'] ?? $today);
            $this->to = (string) ($data['to'] ?? $today);
            foreach (array_keys($this->options()) as $property) {
                $this->{$property} = (string) $data[$property];
            }

            return $this->sheetData();
        });
    }

    /**
     * Plan ruling 4: the screen's filter rules, every value a string, and only a building $as can see.
     *
     * @return array<string, list<mixed>>
     */
    private function packageRules(User $as): array
    {
        $rules = array_map(fn (array $r) => [...$r, 'string'], $this->filterRules());
        $rules['building'] = ['nullable', 'integer', Rule::in(Building::visibleTo($as)->pluck('id')->all())];

        return $rules;
    }

    /**
     * The export's header and rows, plus the headline (plan ruling 8): the first 3-dp amount among the numeric columns
     * of the last total row.
     *
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}
     */
    private function sheetData(): array
    {
        $columns = $this->columns();
        $rows = $this->rows();
        $headline = null;
        foreach (array_reverse($rows) as $row) {
            if (($row['_kind'] ?? null) !== 'total') {
                continue;
            }
            foreach ($this->numeric() as $key) {
                $value = $row[$key] ?? null;
                if (isset($columns[$key]) && is_string($value) && preg_match('/^-?\d+\.\d{3}$/', $value) === 1) {
                    $headline = "{$columns[$key]}: {$value}";
                    break;
                }
            }
            break;
        }

        return [
            'columns' => array_values($columns),
            'rows' => array_map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? '', array_keys($columns)), $rows),
            'headline' => $headline,
        ];
    }

    public function render(): View
    {
        return view('livewire.reports.table', [
            'title' => $this->title(),
            'columns' => $this->columns(),
            'rows' => $rows = $this->filtersValid() ? $this->rows() : [],
            'charts' => $rows === [] ? [] : $this->charts($rows),
            'numeric' => $this->numeric(),
            'options' => $this->options(),
            'dateMode' => $this->dateMode(),
            'buildingFilter' => $this->buildingFilter(),
            'buildings' => $this->buildingFilter() ? Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']) : collect(),
        ])->title($this->title());
    }
}
