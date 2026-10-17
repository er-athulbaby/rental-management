<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\WithActor;
use App\Models\ReportPackage;
use App\Models\SavedReport;
use App\Reports\Builder\Registry;
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
                ['route' => 'reports.rent-roll', 'label' => __('Rent roll'), 'icon' => 'table-cells', 'about' => __('Every unit with its tenant, rent, deposit and balance, as at a date.')],
                ['route' => 'reports.occupancy', 'label' => __('Unit availability and occupancy'), 'icon' => 'home-modern', 'about' => __('Occupied, vacant and blocked units per building.')],
                ['route' => 'reports.expiring', 'label' => __('Agreements expiring'), 'icon' => 'calendar-days', 'about' => __('Agreements ending soon, to renew or plan move-outs.')],
                ['route' => 'reports.overstays', 'label' => __('Overstays: expired, not closed'), 'icon' => 'exclamation-triangle', 'about' => __('Tenants still in after their agreement ended.')],
                ['route' => 'reports.approvals', 'label' => __('Pending approvals'), 'icon' => 'check-badge', 'about' => __('Requests waiting for a decision.')],
                ['route' => 'reports.id-documents', 'label' => __('ID documents expiring'), 'icon' => 'identification', 'about' => __('CPR, passport and CR copies about to expire.')],
                ['route' => 'reports.cheques', 'label' => __('Cheques'), 'icon' => 'banknotes', 'about' => __('Cheques to deposit, and bounced cheques to chase.')],
                ['route' => 'reports.open-maintenance', 'label' => __('Open maintenance'), 'icon' => 'wrench-screwdriver', 'about' => __('Requests still open, urgent first, with their age and vendor.')],
                ['route' => 'reports.maintenance-cost', 'label' => __('Maintenance cost'), 'icon' => 'wrench', 'about' => __('What maintenance cost, by building, unit or category.')],
            ],
            'financial' => [
                ['route' => 'reports.building-profitability', 'label' => __('Building profitability'), 'icon' => 'chart-bar', 'about' => __('Income against head lease and expenses, per building.')],
                ['route' => 'reports.income-statement', 'label' => __('Income statement'), 'icon' => 'document-chart-bar', 'about' => __('Income, costs and result by month, collected or billed.')],
                ['route' => 'reports.owner-income-statement', 'label' => __('Owner income statement'), 'icon' => 'user-circle', 'about' => __('What an owner earned each month, after fees and expenses.')],
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
        $maintenance = $user->can('maintenance.view'); // the maintenance reports also need it (plan ruling 16)
        $operational = array_values(array_filter(self::catalogue()['operational'], fn (array $r) => ($r['route'] !== 'reports.cheques' || $cheques)
            && (! in_array($r['route'], ['reports.open-maintenance', 'reports.maintenance-cost'], true) || $maintenance)));
        $groups = array_filter([
            __('Operational') => $user->can('reports.operational') ? $operational : [],
            __('Financial') => $user->can('reports.financial') ? self::catalogue()['financial'] : [],
        ]);

        return view('livewire.reports.index', [
            'groups' => $groups,
            'myReports' => SavedReport::visibleTo($user)->where('created_by', $user->id)->latest('updated_at')->get(),
            'sharedReports' => SavedReport::visibleTo($user)->where('created_by', '!=', $user->id)->with('creator:id,name')->latest('updated_at')->get(),
            'canBuild' => Registry::forUser($user) !== [],
            'canPackage' => $user->can('reports.operational') || $user->can('reports.financial'),
            'myPackages' => ReportPackage::visibleTo($user)->where('created_by', $user->id)->withCount('items')->latest('updated_at')->get(),
            'sharedPackages' => ReportPackage::visibleTo($user)->where('created_by', '!=', $user->id)->with('creator:id,name')->withCount('items')->latest('updated_at')->get(),
        ])->title(__('Reports'));
    }
}
