<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

#[Title('Audit log')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url]
    public string $event = '';

    #[Url]
    public ?int $causerId = null;

    #[Url]
    public string $subjectType = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $entries = Activity::query()
            ->with('causer')
            ->when($this->event !== '', fn ($q) => $q->where('event', 'like', '%'.$this->event.'%'))
            ->when($this->causerId, fn ($q) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $this->causerId))
            ->when($this->subjectType !== '', fn ($q) => $q->where('subject_type', $this->subjectType))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', $this->to.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(50);

        return view('livewire.admin.audit-log', [
            'entries' => $entries,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'subjectTypes' => Activity::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }
}
