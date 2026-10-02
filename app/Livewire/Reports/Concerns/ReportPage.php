<?php

namespace App\Livewire\Reports\Concerns;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
        $columns = $this->columns();
        $writer->addHeader(array_values($columns));
        foreach ($this->rows() as $row) {
            $writer->addRow(array_map(fn (string $key) => $row[$key] ?? '', array_keys($columns)));
        }
        $writer->close();

        $date = $dates === [] ? now('Asia/Bahrain')->toDateString() : $this->to;

        return response()->download($path, "{$report}-{$date}.xlsx")->deleteFileAfterSend();
    }

    public function render(): View
    {
        return view('livewire.reports.table', [
            'title' => $this->title(),
            'columns' => $this->columns(),
            'rows' => $this->filtersValid() ? $this->rows() : [],
            'numeric' => $this->numeric(),
            'options' => $this->options(),
            'dateMode' => $this->dateMode(),
            'buildingFilter' => $this->buildingFilter(),
            'buildings' => $this->buildingFilter() ? Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']) : collect(),
        ])->title($this->title());
    }
}
