<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\WithActor;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** The reports the viewer may open (spec §10), grouped. Later tasks add their entries here. */
class Index extends Component
{
    use WithActor;

    /** @return array<string, list<array{route: string, label: string, icon: string, about: string}>> */
    public static function catalogue(): array
    {
        return [
            'operational' => [
                ['route' => 'reports.occupancy', 'label' => __('Unit availability and occupancy'), 'icon' => 'home-modern', 'about' => __('Occupied, vacant and blocked units per building.')],
                ['route' => 'reports.expiring', 'label' => __('Agreements expiring'), 'icon' => 'calendar-days', 'about' => __('Agreements ending soon, to renew or plan move-outs.')],
                ['route' => 'reports.overstays', 'label' => __('Overstays: expired, not closed'), 'icon' => 'exclamation-triangle', 'about' => __('Tenants still in after their agreement ended.')],
                ['route' => 'reports.approvals', 'label' => __('Pending approvals'), 'icon' => 'check-badge', 'about' => __('Requests waiting for a decision.')],
                ['route' => 'reports.id-documents', 'label' => __('ID documents expiring'), 'icon' => 'identification', 'about' => __('CPR, passport and CR copies about to expire.')],
                ['route' => 'reports.cheques', 'label' => __('Cheques'), 'icon' => 'banknotes', 'about' => __('Cheques to deposit, and bounced cheques to chase.')],
            ],
            'financial' => [
                ['route' => 'reports.building-profitability', 'label' => __('Building profitability'), 'icon' => 'chart-bar', 'about' => __('Income against head lease and expenses, per building.')],
                ['route' => 'owner-payables.index', 'label' => __('Head-lease payments due'), 'icon' => 'calendar', 'about' => __('Rent owed to owners, by due date.')],
                ['route' => 'owner-statements.index', 'label' => __('Owner statements'), 'icon' => 'document-text', 'about' => __('Monthly statements and remittances to owners.')],
                ['route' => 'reports.outstanding', 'label' => __('Outstanding by tenant'), 'icon' => 'user-group', 'about' => __('What each tenant owes, less their credit.')],
                ['route' => 'reports.ageing', 'label' => __('Overdue ageing'), 'icon' => 'clock', 'about' => __('Overdue money by how long it has been late.')],
                ['route' => 'reports.collections', 'label' => __('Collections by date and method'), 'icon' => 'arrow-trending-up', 'about' => __('Money received per day, by cash, card, transfer or cheque.')],
                ['route' => 'reports.deposits', 'label' => __('Deposits held'), 'icon' => 'lock-closed', 'about' => __('Security deposits held per agreement unit.')],
                ['route' => 'reports.vat', 'label' => __('VAT summary'), 'icon' => 'receipt-percent', 'about' => __('Output VAT by rate, for the return.')],
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
