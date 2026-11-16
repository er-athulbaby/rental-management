<?php

namespace App\Livewire\Agreements;

use App\CustomFields\CustomFieldFilter;
use App\CustomFields\CustomFields;
use App\Enums\AgreementStatus;
use App\Enums\CustomFieldEntity;
use App\Livewire\Concerns\FiltersByBuilding;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Agreements')]
class Index extends Component
{
    use FiltersByBuilding, WithActor, WithPagination;

    #[Url]
    public string $status = '';

    /** "Expiring soon" is a filter, not a status (spec §5.4): 30, 60 or 90 days. */
    #[Url]
    public ?int $expiring = null;

    #[Url]
    public string $search = '';

    /** Spec 2026-10-21 custom fields §5 "More filters": field, op, value, from, to, choices. Mixed and not in the URL, so a tampered value is ignored, never a TypeError (plan ruling 6). */
    public mixed $more = [];

    public function updating(): void
    {
        $this->resetPage();
    }

    /** @return Builder<Agreement> every filter but status and end date: visibility, building, search, custom fields (plan ruling 1) */
    private function filtered(): Builder
    {
        return Agreement::query()
            ->visibleTo($this->actor())
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Agreement, $this->more));
    }

    /**
     * Design pass §5: active, pending approval, and active ending between today and today + 60 days (the dashboard's
     * Queries::expiringAgreements). One aggregate query over filtered().
     *
     * @return array{active: int, pending: int, expiring: int}
     */
    private function stats(): array
    {
        $active = AgreementStatus::Active->value;
        $row = (array) ($this->filtered()->toBase()->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_count, '
            .'SUM(CASE WHEN status = ? AND end_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS expiring_count',
            [$active, AgreementStatus::PendingApproval->value, $active, now('Asia/Bahrain')->toDateString(), now('Asia/Bahrain')->addDays(60)->toDateString()],
        )->first() ?? []);

        return ['active' => (int) ($row['active_count'] ?? 0), 'pending' => (int) ($row['pending_count'] ?? 0), 'expiring' => (int) ($row['expiring_count'] ?? 0)];
    }

    public function render(): View
    {
        $today = now('Asia/Bahrain')->toDateString();

        $agreements = $this->filtered()
            ->with('customer:id,name_en')
            ->with('agreementUnits.unit:id,code,building_id', 'agreementUnits.unit.building:id,code')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when(in_array($this->expiring, [30, 60, 90], true), fn ($q) => $q
                ->where('status', AgreementStatus::Active)
                ->whereBetween('end_date', [$today, now('Asia/Bahrain')->addDays((int) $this->expiring)->toDateString()]))
            ->latest('id')
            ->paginate(25);

        return view('livewire.agreements.index', ['agreements' => $agreements, 'stats' => $this->stats(), 'statuses' => AgreementStatus::cases(),
            'customFields' => CustomFields::active(CustomFieldEntity::Agreement)]);
    }
}
