<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\WithActor;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** The reports the viewer may open (spec §10), grouped. Later tasks add their entries here. */
class Index extends Component
{
    use WithActor;

    /** @return array<string, list<array{route: string, label: string}>> */
    public static function catalogue(): array
    {
        return [
            'operational' => [
                ['route' => 'reports.occupancy', 'label' => __('Unit availability and occupancy')],
                ['route' => 'reports.expiring', 'label' => __('Agreements expiring')],
                ['route' => 'reports.overstays', 'label' => __('Overstays: expired, not closed')],
                ['route' => 'reports.approvals', 'label' => __('Pending approvals')],
                ['route' => 'reports.id-documents', 'label' => __('ID documents expiring')],
                ['route' => 'reports.cheques', 'label' => __('Cheques')],
            ],
            'financial' => [
                ['route' => 'reports.building-profitability', 'label' => __('Building profitability')],
                ['route' => 'owner-payables.index', 'label' => __('Head-lease payments due')],
                ['route' => 'owner-statements.index', 'label' => __('Owner statements')],
                ['route' => 'reports.outstanding', 'label' => __('Outstanding by customer')],
                ['route' => 'reports.ageing', 'label' => __('Overdue ageing')],
                ['route' => 'reports.collections', 'label' => __('Collections by date and method')],
                ['route' => 'reports.deposits', 'label' => __('Deposits held')],
                ['route' => 'reports.vat', 'label' => __('VAT summary')],
            ],
        ];
    }

    public function render(): View
    {
        $user = $this->actor();
        $cheques = $user->can('cheques.manage') || $user->can('finance.view'); // spec §10: the cheque reports also need one of these
        $operational = array_values(array_filter(self::catalogue()['operational'], fn (array $r) => $r['route'] !== 'reports.cheques' || $cheques));
        $groups = array_filter([
            __('Operational') => $user->can('reports.operational') ? $operational : [],
            __('Financial') => $user->can('reports.financial') ? self::catalogue()['financial'] : [],
        ]);

        return view('livewire.reports.index', ['groups' => $groups])->title(__('Reports'));
    }
}
