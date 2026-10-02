<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Approval;
use App\Reports\Queries;
use Livewire\Component;

/** Spec §10: pending approvals the viewer may see, plus their own requests. */
class PendingApprovalsReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Pending approvals');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function buildingFilter(): bool
    {
        return false; // its rows are not filtered by building
    }

    protected function dateMode(): string
    {
        return 'none';
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['action' => __('Action'), 'summary' => __('Summary'), 'requested' => __('Requested'), 'by' => __('By')];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        return array_values(Queries::visiblePendingApprovals($this->actor())
            ->map(fn (Approval $a) => [
                '_url' => $a->handler()->url($a),
                'action' => $a->action->label(),
                'summary' => $a->handler()->summary($a),
                'requested' => $a->requested_at->timezone('Asia/Bahrain')->format('d/m/Y H:i'),
                'by' => $a->requester->name ?? '—',
            ])->all());
    }
}
