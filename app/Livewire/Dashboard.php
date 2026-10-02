<?php

namespace App\Livewire;

use App\Enums\PaymentMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Unit;
use App\Models\User;
use App\Reports\Queries;
use App\Support\Fils;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Spec §10: 8 number tiles, no charts; each tile needs its report's permission and links to it. */
class Dashboard extends Component
{
    use WithActor;

    /** @return list<array{label: string, value: string, url: string}> */
    public static function tiles(User $user): array
    {
        $today = now('Asia/Bahrain');
        $monthStart = $today->startOfMonth()->toDateString();
        $monthEnd = $today->endOfMonth()->toDateString();
        $operational = $user->can('reports.operational');
        $financial = $user->can('reports.financial');
        $cheques = $operational && ($user->can('cheques.manage') || $user->can('finance.view'));
        $money = fn (string $decimal) => Fils::toDecimal(Fils::fromDecimal($decimal));
        $tiles = [];

        if ($operational) {
            $units = Unit::query()->visibleTo($user)->where('blocked', false)->count();
            $occupied = Unit::query()->visibleTo($user)->where('blocked', false)->whereIn('id', Queries::occupiedUnitIds($user, $today->toDateString()))->count();
            $tiles[] = ['label' => __('Occupancy'), 'value' => $units > 0 ? number_format(100 * $occupied / $units, 1).'%' : '—', 'url' => route('reports.occupancy')];
        }
        if ($financial) {
            // Spec §10 defines no rent-due report, so this tile links to the invoice list. Net (subtotal), not total: VAT is
            // only charged on issue, so scheduled invoices carry none yet and summing totals would mix inclusive and exclusive amounts.
            $tiles[] = ['label' => __('Rent due this month'), 'url' => route('invoices.index'), 'value' => $money((string) (Queries::rentDue($user, $monthStart, $monthEnd)->sum('subtotal') ?: '0'))];
            $tiles[] = ['label' => __('Collected this month'), 'url' => route('reports.collections', ['from' => $monthStart, 'to' => $today->toDateString()]),
                'value' => $money((string) (Queries::collections($user, $monthStart, $today->toDateString())->where('method', '!=', PaymentMethod::DepositApplied)->sum('amount') ?: '0'))];
            $tiles[] = ['label' => __('Overdue total'), 'url' => route('reports.ageing'), 'value' => $money((string) (Queries::overdueInvoices($user)->sum('balance') ?: '0'))];
        }
        if ($cheques) {
            $tiles[] = ['label' => __('Cheques to deposit this week'), 'url' => route('reports.cheques', ['kind' => 'week']), 'value' => (string) Queries::chequesToDeposit($user, Queries::weekEnd(), Queries::weekStart())->count()];
            $tiles[] = ['label' => __('Open bounced cheques'), 'url' => route('reports.cheques', ['kind' => 'bounced']), 'value' => (string) Queries::bouncedCheques($user)->count()];
        }
        if ($operational) {
            $tiles[] = ['label' => __('Expiring in 60 days'), 'url' => route('reports.expiring', ['window' => '60']), 'value' => (string) Queries::expiringAgreements($user, 60)->count()];
        }
        if ($user->can('approvals.decide')) {
            $tiles[] = ['label' => __('Pending approvals'), 'url' => route('approvals.index'), 'value' => (string) Queries::approvalsToDecide($user)->count()];
        }

        return $tiles;
    }

    public function render(): View
    {
        return view('livewire.dashboard', ['tiles' => self::tiles($this->actor())])->title(__('Dashboard'));
    }
}
