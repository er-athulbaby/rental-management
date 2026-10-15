<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\SavedReport;
use App\Reports\Builder\Engine;
use App\Reports\Builder\Field;
use App\Reports\Builder\FieldType;
use App\Reports\Builder\Period;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The report builder (spec §3.2). `def` is the definition being edited; every render validates and runs it. */
class Builder extends Component
{
    use WithActor;

    /** @var array<string, mixed> */
    public array $def = [];

    #[Locked]
    public ?int $reportId = null;

    public string $name = '';

    public function mount(?SavedReport $report = null): void
    {
        abort_unless(Registry::forUser($this->actor()) !== [], 403);

        if ($report?->exists) {
            abort_unless($this->actor()->can('view', $report), 403);
            $this->reportId = $report->id;
            $this->name = $report->name;
            $this->def = $report->definition;

            return;
        }

        $first = array_key_first(Registry::forUser($this->actor()));
        $this->def = ReportDefinition::fresh(Registry::get((string) $first), $this->actor())->toArray();
    }

    public function setSubject(string $key): void
    {
        abort_unless(isset(Registry::forUser($this->actor())[$key]), 403);
        $this->def = ReportDefinition::fresh(Registry::get($key), $this->actor())->toArray();
    }

    public function toggleColumn(string $key): void
    {
        $columns = $this->columns();
        $this->def['columns'] = in_array($key, $columns, true)
            ? array_values(array_diff($columns, [$key]))
            : [...$columns, $key];
    }

    public function moveColumn(string $key, int $by): void
    {
        $columns = $this->columns();
        $i = array_search($key, $columns, true);
        $j = is_int($i) ? $i + $by : -1;
        if (is_int($i) && $j >= 0 && $j < count($columns)) {
            [$columns[$i], $columns[$j]] = [$columns[$j], $columns[$i]];
            $this->def['columns'] = $columns;
        }
    }

    public function addFilter(): void
    {
        $this->def['filters'] = is_array($this->def['filters'] ?? null) ? $this->def['filters'] : [];
        $this->def['filters'][] = ['field' => '', 'op' => '', 'value' => null];
    }

    public function removeFilter(int $index): void
    {
        $filters = is_array($this->def['filters'] ?? null) ? $this->def['filters'] : [];
        unset($filters[$index]);
        $this->def['filters'] = array_values($filters);
    }

    /** Changing a filter's field resets its operator and value. $key is null when Livewire replaces the whole array. */
    public function updatedDef(mixed $value, ?string $key = null): void
    {
        if ($key !== null && preg_match('/^filters\.(\d+)\.field$/', $key, $m) === 1) {
            $this->def['filters'][(int) $m[1]]['op'] = '';
            // An enum filter's checkbox group needs an array to bind to.
            $name = $this->def['filters'][(int) $m[1]]['field'] ?? null;
            $field = is_string($name) ? ($this->fields()[$name] ?? null) : null;
            $this->def['filters'][(int) $m[1]]['value'] = $field?->type === FieldType::Enum ? [] : null;
        }
    }

    /** @return list<string> */
    private function columns(): array
    {
        return is_array($this->def['columns'] ?? null) ? array_values(array_filter($this->def['columns'], 'is_string')) : [];
    }

    /** The edited subject, or the first allowed one when `def` was tampered with. */
    private function subject(): Subject
    {
        $allowed = Registry::forUser($this->actor());
        $key = $this->def['subject'] ?? null;

        return is_string($key) && isset($allowed[$key]) ? $allowed[$key] : Registry::get((string) array_key_first($allowed));
    }

    /** @return array<string, Field> */
    private function fields(): array
    {
        $asAt = $this->def['as_at'] ?? null;
        $date = is_string($asAt) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asAt) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $asAt, 'Asia/Bahrain') : false;

        return $this->subject()->fields($this->actor(), $date ?: CarbonImmutable::today('Asia/Bahrain'));
    }

    public function render(): View
    {
        $user = $this->actor();
        $subject = $this->subject();
        $fields = $this->fields();
        $result = null;
        $errors = [];
        try {
            $result = app(Engine::class)->run($user, ReportDefinition::fromArray($this->def, $user));
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        return view('livewire.reports.builder', [
            'subjects' => Registry::forUser($user),
            'subject' => $subject,
            'fields' => $fields,
            'sections' => collect($fields)->groupBy(fn (Field $f) => $f->section),
            'operators' => ReportDefinition::operators(),
            'presets' => Period::presets(),
            'buildings' => Building::visibleTo($user)->orderBy('code')->get(['id', 'code', 'name']),
            'result' => $result,
            'defErrors' => $errors,
            'canEdit' => $this->reportId === null || $user->can('update', SavedReport::findOrFail($this->reportId)),
        ])->title($this->name !== '' ? $this->name : __('New report'));
    }
}
