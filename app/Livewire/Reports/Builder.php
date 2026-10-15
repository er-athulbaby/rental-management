<?php

namespace App\Livewire\Reports;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\SavedReport;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\Field;
use App\Reports\Builder\FieldType;
use App\Reports\Builder\Period;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            SavedReport::query()->whereKey($report->id)->toBase()->update(['last_run_at' => now()]);

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

    /** Changing a filter's field resets its value and picks a default operator for the new field's type. $key is null when Livewire replaces the whole array. */
    public function updatedDef(mixed $value, ?string $key = null): void
    {
        if ($key !== null && preg_match('/^filters\.(\d+)\.field$/', $key, $m) === 1) {
            // An enum filter's checkbox group needs an array to bind to.
            $name = $this->def['filters'][(int) $m[1]]['field'] ?? null;
            $field = is_string($name) ? ($this->fields()[$name] ?? null) : null;
            $this->def['filters'][(int) $m[1]]['op'] = match ($field?->type) {
                FieldType::Text => 'contains',
                FieldType::Enum => 'in',
                FieldType::Money, FieldType::Number => 'gte',
                FieldType::Date => 'between',
                FieldType::Boolean => 'yes',
                null => '',
            };
            $this->def['filters'][(int) $m[1]]['value'] = match ($field?->type) {
                FieldType::Enum => [],
                FieldType::Date => [null, null],
                default => null,
            };
        }
        // A new group field forgets the old bucket; normalised() supplies "month" for dates.
        if ($key !== null && preg_match('/^groups\.(\d+)\.field$/', $key, $m) === 1) {
            $this->def['groups'][(int) $m[1]]['bucket'] = null;
        }
    }

    public function save(): void
    {
        $user = $this->actor();
        $this->validate(['name' => ['required', 'string', 'max:120']], ['name.required' => __('Give the report a name.')]);
        $definition = ReportDefinition::fromArray($this->normalised(), $user)->toArray(); // ValidationException shows on the page

        if ($this->reportId === null) {
            $report = SavedReport::create(['name' => $this->name, 'subject' => $definition['subject'], 'definition' => $definition,
                'visibility' => 'private', 'created_by' => $user->id, 'updated_by' => $user->id]);
        } else {
            $report = SavedReport::findOrFail($this->reportId);
            abort_unless($user->can('update', $report), 403);
            $report->update(['name' => $this->name, 'subject' => $definition['subject'], 'definition' => $definition, 'updated_by' => $user->id]);
        }
        Audit::log('report.saved', $report, properties: ['name' => $report->name, 'subject' => $report->subject], causer: $user);
        Flux::toast(variant: 'success', text: __('Report saved.'));
        $this->redirectRoute('reports.builder', $report, navigate: true);
    }

    public function saveAsCopy(): void
    {
        $user = $this->actor();
        $definition = ReportDefinition::fromArray($this->normalised(), $user)->toArray();
        $suffix = ' '.__('(copy)');
        $base = mb_substr($this->name !== '' ? $this->name : __('Untitled report'), 0, 120 - mb_strlen($suffix));
        $report = SavedReport::create(['name' => $base.$suffix,
            'subject' => $definition['subject'], 'definition' => $definition, 'visibility' => 'private', 'created_by' => $user->id, 'updated_by' => $user->id]);
        Audit::log('report.saved', $report, properties: ['name' => $report->name, 'copied_from' => $this->reportId], causer: $user);
        $this->redirectRoute('reports.builder', $report, navigate: true);
    }

    public function share(bool $shared): void
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('update', $report), 403);
        $report->update(['visibility' => $shared ? 'shared' : 'private']);
        Audit::log('report.shared', $report, properties: ['visibility' => $report->visibility], causer: $this->actor());
        Flux::toast(text: $shared ? __('Shared with everyone who has report access.') : __('Now only you can see it.'));
    }

    public function deleteReport(): void
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('delete', $report), 403);
        $report->delete();
        Audit::log('report.deleted', $report, properties: ['name' => $report->name], causer: $this->actor());
        $this->redirectRoute('reports.index', navigate: true);
    }

    public function export(): BinaryFileResponse
    {
        $user = $this->actor();
        $def = ReportDefinition::fromArray($this->normalised(), $user);
        $result = app(Engine::class)->run($user, $def, limit: null);
        Audit::log('report.exported', properties: ['report' => 'builder:'.$def->subject, 'saved_report_id' => $this->reportId,
            'building' => $def->buildingId, 'period' => $result->periodLabel], causer: $user);
        if ($this->reportId !== null) {
            SavedReport::query()->whereKey($this->reportId)->toBase()->update(['last_run_at' => now()]);
        }
        $path = sys_get_temp_dir().'/rms-report-'.Str::uuid().'.xlsx';
        ExcelWriter::write($result, $path);

        return response()->download($path, (Str::slug($this->name) ?: $def->subject).'-'.now('Asia/Bahrain')->toDateString().'.xlsx')->deleteFileAfterSend();
    }

    /** @return array<string, mixed> the edited definition with empty picker rows removed */
    private function normalised(): array
    {
        $def = $this->def;
        $fields = $this->fields();
        $def['groups'] = array_values(array_filter((array) ($def['groups'] ?? []), fn ($g) => is_array($g) && filled($g['field'] ?? null)));
        foreach ($def['groups'] as $i => $g) {
            $isDate = ($fields[$g['field']] ?? null)?->type === FieldType::Date;
            $def['groups'][$i]['bucket'] = $g['bucket'] ?? ($isDate ? 'month' : null);
        }
        $def['totals'] = array_filter((array) ($def['totals'] ?? []), fn ($fn, $key) => filled($fn) && in_array($key, (array) ($def['columns'] ?? []), true), ARRAY_FILTER_USE_BOTH);
        $def['filters'] = array_values((array) ($def['filters'] ?? []));

        return $def;
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
            $result = app(Engine::class)->run($user, ReportDefinition::fromArray($this->normalised(), $user));
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
