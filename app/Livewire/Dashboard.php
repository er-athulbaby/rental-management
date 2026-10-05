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

/** Spec §10: 8 number tiles, each needing its report's permission and linking to it; plus two charts (collections, occupancy) under the same permissions. */
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

    /**
     * Collected per month for the last six months, deposit-applied excluded (it moves no money). Null without reports.financial.
     *
     * @return array<string, mixed>|null
     */
    public static function collectionsChart(User $user): ?array
    {
        if (! $user->can('reports.financial')) {
            return null;
        }
        $today = now('Asia/Bahrain');
        $start = $today->startOfMonth()->subMonths(5);
        $byMonth = Queries::collections($user, $start->toDateString(), $today->toDateString())->where('method', '!=', PaymentMethod::DepositApplied)
            ->get(['received_on', 'amount'])->groupBy(fn ($p) => $p->received_on->format('Y-m'))
            ->map(fn ($ps) => $ps->sum(fn ($p) => Fils::fromDecimal((string) $p->amount)));

        $items = [];
        for ($m = $start; $m <= $today; $m = $m->addMonth()) {
            $fils = (int) ($byMonth[$m->format('Y-m')] ?? 0);
            $items[] = ['label' => $m->format('F Y'), 'short' => $m->format('M'), 'value' => $fils / 1000, 'display' => number_format($fils / 1000, 3).' BHD'];
        }

        return ['type' => 'columns', 'title' => __('Collected, last 6 months'), 'caption' => __('BHD'), 'items' => $items];
    }

    /** @return array{units: int, occupied: int, percent: float}|null available (not blocked) units today; null without reports.operational */
    public static function occupancy(User $user): ?array
    {
        if (! $user->can('reports.operational')) {
            return null;
        }
        $units = Unit::query()->visibleTo($user)->where('blocked', false)->count();
        $occupied = Unit::query()->visibleTo($user)->where('blocked', false)->whereIn('id', Queries::occupiedUnitIds($user, now('Asia/Bahrain')->toDateString()))->count();

        return ['units' => $units, 'occupied' => $occupied, 'percent' => $units > 0 ? round(100 * $occupied / $units, 1) : 0.0];
    }

    public function render(): View
    {
        $user = $this->actor();

        return view('livewire.dashboard', [
            'tiles' => self::tiles($user),
            'collections' => self::collectionsChart($user),
            'occupancy' => self::occupancy($user),
        ])->title(__('Dashboard'));
    }
}
