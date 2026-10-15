<?php

namespace App\Livewire\Reports;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\Field;
use App\Reports\Builder\FieldType;
use App\Reports\Builder\Formatter;
use App\Reports\Builder\NextRun;
use App\Reports\Builder\Period;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The report builder (spec §3.2). `def` is the definition being edited; every render validates and runs it. */
class Builder extends Component
{
    use WithActor;

    public const int CHART_LIMIT = 50;

    /** @var array<string, mixed> */
    public array $def = [];

    #[Locked]
    public ?int $reportId = null;

    public string $name = '';

    /** @var array<string, mixed> client-controlled: validated in addSchedule() */
    public array $schedule = ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => []];

    public string $staffSearch = '';

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
        $report->schedules()->delete();
        $report->delete();
        Audit::log('report.deleted', $report, properties: ['name' => $report->name], causer: $this->actor());
        $this->redirectRoute('reports.index', navigate: true);
    }

    /** Day follows the frequency: weekly 0-6, monthly 1-28 or 0 for the last day, daily none. */
    public function updatedSchedule(mixed $value, ?string $key = null): void
    {
        if ($key === 'frequency') {
            $this->schedule['day'] = match ($value) {
                'weekly' => 0,
                'monthly' => 1,
                default => null,
            };
        }
    }

    public function addSchedule(): void
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('update', $report), 403);
        $frequency = $this->schedule['frequency'] ?? null;
        $this->validate([
            'schedule.frequency' => ['required', 'in:daily,weekly,monthly'],
            'schedule.day' => $frequency === 'daily' ? ['nullable'] : ['required', 'integer', $frequency === 'weekly' ? 'between:0,6' : 'between:0,28'],
            'schedule.hour' => ['required', 'integer', 'between:0,23'],
            'schedule.recipients' => ['required', 'array', 'min:1', 'max:50'],
            'schedule.recipients.*' => ['integer', Rule::in($this->staffIds())],
        ], ['schedule.recipients.*.in' => __('Pick staff from the list.'), 'schedule.recipients.required' => __('Pick at least one person.'),
            'schedule.day.required' => __('Pick a day.')]);
        $data = ['frequency' => (string) $frequency, 'day' => $frequency === 'daily' ? null : (int) $this->schedule['day'], 'hour' => (int) $this->schedule['hour']];
        $recipients = array_values(array_unique(array_map('intval', $this->schedule['recipients'])));
        $schedule = ReportSchedule::create([...$data, 'saved_report_id' => $report->id, 'created_by' => $this->actor()->id,
            'next_run_at' => NextRun::after($data, now()->toImmutable())]);
        $schedule->recipients()->sync($recipients);
        Audit::log('report.scheduled', $report, properties: ['schedule' => $schedule->id, ...$data, 'recipients' => $recipients], causer: $this->actor());
        $this->schedule = ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => []];
        Flux::toast(variant: 'success', text: __('Scheduled.'));
    }

    public function removeSchedule(int $id): void
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('update', $report), 403);
        $report->schedules()->whereKey($id)->delete();
    }

    /** Clears the pause and failure count, then waits for the next due time. */
    public function resumeSchedule(int $id): void
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('update', $report), 403);
        $schedule = $report->schedules()->whereKey($id)->first();
        $schedule?->update(['paused_at' => null, 'consecutive_failures' => 0, 'next_run_at' => NextRun::after($schedule, now()->toImmutable())]);
    }

    /** @return list<int> active staff who may receive reports: never Vendor Support or the system user (the job skips them too) */
    private function staffIds(): array
    {
        return array_values($this->staff()->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** @return Collection<int, User> */
    private function staff(): Collection
    {
        return User::query()->where('active', true)->where('is_system', false)->orderBy('name')->get(['id', 'name', 'email'])
            ->reject(fn (User $u) => $u->isVendorSupport())->values();
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
        $def['groups'] = array_values(array_filter((array) ($def['groups'] ?? []), fn ($g) => is_array($g) && is_string($g['field'] ?? null) && $g['field'] !== ''));
        foreach ($def['groups'] as $i => $g) {
            $isDate = ($fields[$g['field']] ?? null)?->type === FieldType::Date;
            $def['groups'][$i]['bucket'] = is_string($g['bucket'] ?? null) ? $g['bucket'] : ($isDate ? 'month' : null);
        }
        $def['totals'] = array_filter((array) ($def['totals'] ?? []), fn ($fn, $key) => filled($fn) && in_array($key, (array) ($def['columns'] ?? []), true), ARRAY_FILTER_USE_BOTH);
        $def['filters'] = array_values((array) ($def['filters'] ?? []));
        $def['compare'] = is_string($def['compare'] ?? null) && $def['compare'] !== '' ? $def['compare'] : null;
        // A chart needs grouping and a total that still exists; otherwise there is simply no chart.
        $chart = $def['chart'] ?? null;
        $total = is_array($chart) && is_string($chart['total'] ?? null) ? $chart['total'] : 'count';
        [$tf, $tfn] = array_pad(explode(':', $total, 2), 2, '');
        $def['chart'] = is_array($chart) && is_string($chart['type'] ?? null) && $chart['type'] !== '' && $def['groups'] !== []
            && ($total === 'count' || ($def['totals'][$tf] ?? null) === $tfn)
            ? ['type' => $chart['type'], 'total' => $total] : null;

        return $def;
    }

    /**
     * @param  array<string, Field>  $fields
     * @return array<string, mixed>|null the chart component's spec: level-1 groups only, one chosen total (spec §5)
     */
    private function chartSpec(?ReportResult $result, ReportDefinition $def, array $fields): ?array
    {
        if ($result === null || $def->chart === null || $result->groups === []) {
            return null;
        }
        $total = $def->chart['total'];
        [$key, $fn] = $total === 'count' ? ['', 'count'] : explode(':', $total, 2);
        $field = $fields[$key] ?? null;
        $by = $fields[$def->groups[0]['field']]->label ?? '';
        $level1 = array_values(array_filter($result->groups, fn ($g) => $g['level'] === 1));
        $cmp = $result->comparison;
        if ($cmp !== null) { // each group becomes a pair: this period, then the comparison period in a second shade
            $level1 = array_map(fn ($r) => ['label' => $r['label'], 'current' => $r['current'][$total] ?? 0, 'previous' => $r['previous'][$total] ?? 0], $cmp['rows']);
        }
        $capped = count($level1) > self::CHART_LIMIT;
        $cell = fn (string $label, mixed $raw, array $extra = []) => ['label' => $label, 'short' => $label, 'value' => (float) $raw, 'display' => Formatter::total($field, $fn, $raw)] + $extra;
        $items = [];
        foreach (array_slice($level1, 0, self::CHART_LIMIT) as $g) {
            if ($cmp === null) {
                $items[] = $cell($g['label'], $g['totals'][$total]);
            } else {
                $items[] = $cell($g['label'], $g['current']);
                $items[] = $cell($g['label'].' ('.__('previous').')', $g['previous'], ['color' => '--viz-age-1']);
            }
        }
        $n = $cmp === null ? count($items) : intdiv(count($items), 2);

        return [
            // Spec §5: columns when level 1 is a date bucket, bars for categories.
            'type' => ($fields[$def->groups[0]['field']] ?? null)?->type === FieldType::Date ? 'columns' : 'bars',
            'title' => ($total === 'count' ? __('Count') : $field?->label.' ('.__($fn).')').' '.__('by').' '.$by,
            'items' => $items,
        ] + ($capped || $result->groupsTruncated
            ? ['caption' => __('Chart shows the first :n groups — see the table for all', ['n' => Number::format($n)])] : [])
          + ($cmp === null ? [] : ['legend' => [['label' => $cmp['label'], 'color' => '--viz-1'], ['label' => $cmp['compareLabel'], 'color' => '--viz-age-1']]]);
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
        $chart = null;
        try {
            $definition = ReportDefinition::fromArray($this->normalised(), $user);
            $result = app(Engine::class)->run($user, $definition);
            $chart = $this->chartSpec($result, $definition, $fields);
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        $schedules = $this->reportId === null ? collect() : SavedReport::findOrFail($this->reportId)->schedules()->with('recipients:id,name')->orderBy('id')->get();
        $lastDelivery = ReportDelivery::whereIn('report_schedule_id', $schedules->pluck('id'))->orderByDesc('due_at')->get()->unique('report_schedule_id')->keyBy('report_schedule_id');
        $picked = array_filter((array) ($this->schedule['recipients'] ?? []), 'is_scalar');
        $term = mb_strtolower(trim($this->staffSearch));
        $staff = $this->staff()->filter(fn (User $u) => $term === '' || str_contains(mb_strtolower($u->name.' '.$u->email), $term)
            || in_array($u->id, $picked, false))->take(50);

        return view('livewire.reports.builder', [
            'schedules' => $schedules,
            'lastDelivery' => $lastDelivery,
            'staff' => $staff,
            'subjects' => Registry::forUser($user),
            'subject' => $subject,
            'fields' => $fields,
            'sections' => collect($fields)->groupBy(fn (Field $f) => $f->section),
            'operators' => ReportDefinition::operators(),
            'presets' => Period::presets(),
            'buildings' => Building::visibleTo($user)->orderBy('code')->get(['id', 'code', 'name']),
            'result' => $result,
            'chart' => $chart,
            'defErrors' => $errors,
            'canEdit' => $this->reportId === null || $user->can('update', SavedReport::findOrFail($this->reportId)),
        ])->title($this->name !== '' ? $this->name : __('New report'));
    }
}
