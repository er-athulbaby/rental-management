<?php

namespace App\Livewire\Reports\Concerns;

use App\Audit\Audit;
use App\Enums\RoleName;
use App\Models\ReportDelivery;
use App\Models\ReportPackage;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use App\Reports\Builder\NextRun;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The "Schedule email" form (builder spec §7), shared by the builder (a saved report) and a package (spec 2026-10-17
 * §5). The schedule belongs to scheduleTarget(); audits name it as the subject. Needs WithActor.
 */
trait ManagesSchedules
{
    /** @var array<string, mixed> client-controlled: validated in addSchedule() */
    public array $schedule = ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => []];

    public string $staffSearch = '';

    /** The saved report or package being scheduled: 404 when unsaved, 403 unless the user may change it. */
    abstract protected function scheduleTarget(): SavedReport|ReportPackage;

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
        $target = $this->scheduleTarget();
        $frequency = $this->schedule['frequency'] ?? null;
        $this->validate([
            'schedule.frequency' => ['required', 'in:daily,weekly,monthly'],
            'schedule.day' => $frequency === 'daily' ? ['nullable'] : ['required', 'integer', $frequency === 'weekly' ? 'between:0,6' : 'between:0,28'],
            'schedule.hour' => ['required', 'integer', 'between:0,23'],
            'schedule.recipients' => ['required', 'array', 'min:1', 'max:50'],
            'schedule.recipients.*' => ['integer', Rule::in($this->eligibleAmong($this->schedule['recipients'] ?? []))],
        ], ['schedule.recipients.*.in' => __('Pick staff from the list.'), 'schedule.recipients.required' => __('Pick at least one person.'),
            'schedule.day.required' => __('Pick a day.')]);
        $data = ['frequency' => (string) $frequency, 'day' => $frequency === 'daily' ? null : (int) $this->schedule['day'], 'hour' => (int) $this->schedule['hour']];
        $recipients = array_values(array_unique(array_map('intval', $this->schedule['recipients'])));
        $schedule = $target->schedules()->create([...$data, 'created_by' => $this->actor()->id, 'next_run_at' => NextRun::after($data, now()->toImmutable())]);
        $schedule->recipients()->sync($recipients);
        Audit::log('report.scheduled', $target, properties: ['schedule' => $schedule->id, ...$data, 'recipients' => $recipients], causer: $this->actor());
        $this->schedule = ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => []];
        Flux::toast(variant: 'success', text: __('Scheduled.'));
    }

    public function removeSchedule(mixed $id): void
    {
        $target = $this->scheduleTarget();
        $id = is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 10) ? (int) $id : 0; // a tampered argument finds nothing
        $schedule = $id > 0 ? $target->schedules()->whereKey($id)->first() : null;
        if ($schedule !== null) {
            $schedule->delete();
            Audit::log('report.scheduled', $target, properties: ['schedule' => $id, 'action' => 'removed'], causer: $this->actor());
            Flux::toast(text: __('Schedule removed.'));
        }
    }

    /** Clears the pause and failure count, then waits for the next due time. */
    public function resumeSchedule(mixed $id): void
    {
        $target = $this->scheduleTarget();
        $id = is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 10) ? (int) $id : 0; // a tampered argument finds nothing
        $schedule = $id > 0 ? $target->schedules()->whereKey($id)->first() : null;
        if ($schedule !== null) {
            $schedule->update(['paused_at' => null, 'consecutive_failures' => 0, 'next_run_at' => NextRun::after($schedule, now()->toImmutable())]);
            Audit::log('report.scheduled', $target, properties: ['schedule' => $id, 'action' => 'resumed'], causer: $this->actor());
            Flux::toast(variant: 'success', text: __('Schedule resumed.'));
        }
    }

    /**
     * What the schedule modal shows; nothing for an unsaved target.
     *
     * @return array{schedules: Collection<int, ReportSchedule>, lastDelivery: Collection<int, ReportDelivery>, staff: Collection<int, User>}
     */
    protected function scheduleData(SavedReport|ReportPackage|null $target): array
    {
        $schedules = $target === null ? collect() : $target->schedules()->with('recipients:id,name')->orderBy('id')->get();

        return [
            'schedules' => $schedules,
            'lastDelivery' => ReportDelivery::whereIn('report_schedule_id', $schedules->pluck('id'))->orderByDesc('due_at')->get()->unique('report_schedule_id')->keyBy('report_schedule_id'),
            'staff' => $target === null ? collect() : $this->staff(),
        ];
    }

    /** @return list<int> the submitted ids that are eligible recipients, in one query */
    private function eligibleAmong(mixed $ids): array
    {
        $ids = array_values(array_filter(is_array($ids) ? $ids : [], fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v) && strlen($v) < 10)));

        return $ids === [] ? [] : array_values($this->eligible()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Active staff who may receive reports: never Vendor Support or the system user (the job skips them too).
     *
     * @return EloquentBuilder<User>
     */
    private function eligible(): EloquentBuilder
    {
        return User::query()->where('active', true)->where('is_system', false)->withoutRole(RoleName::VendorSupport);
    }

    /** @return Collection<int, User> what the picker shows: the search matches (max 50) plus everyone already ticked */
    private function staff(): Collection
    {
        $term = trim($this->staffSearch);
        $picked = $this->eligibleAmong($this->schedule['recipients'] ?? []);
        $matches = $this->eligible()->orderBy('name')->limit(50)
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')->orWhere('email', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->get(['id', 'name', 'email']);
        $ticked = $picked === [] ? collect() : $this->eligible()->whereIn('id', $picked)->get(['id', 'name', 'email']);

        return $matches->concat($ticked)->unique('id')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
