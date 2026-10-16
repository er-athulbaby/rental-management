<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Unit;
use App\Reports\Queries;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Spec 2026-10-16 §3: one row per unit as at a date, a subtotal per building and a grand total. Occupancy follows the
 * Occupancy report (Queries::currentAgreementUnits; % = occupied ÷ (units − blocked)). Rent = the occupying agreement
 * unit's rent charges (plan ruling 2); deposit held as at the date; balance owed live, once per agreement (ruling 4).
 */
class RentRollReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Rent roll');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function dateMode(): string
    {
        return 'single'; // "as at" is $to
    }

    private function showMoney(): bool
    {
        return $this->actor()->can('reports.financial');
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return [
            'building' => __('Building'), 'unit' => __('Unit'), 'type' => __('Type'), 'status' => __('Status'), 'tenant' => __('Tenant'),
            'agreement' => __('Agreement'), 'start' => __('Start'), 'end' => __('End'),
            ...($this->showMoney() ? ['rent' => __('Monthly rent'), 'deposit' => __('Deposit held'), 'balance' => __('Balance owed')] : []),
        ];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['rent', 'deposit', 'balance'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $on = $this->to;
        $user = $this->actor();
        $rent = DB::table('agreement_unit_charges')->where('type', 'rent')->groupBy('agreement_unit_id')
            ->select('agreement_unit_id', DB::raw('SUM(monthly_amount) as rent'));
        $held = DB::table('deposit_movements')->where('posted_at', '<=', $on.' 23:59:59')->groupBy('agreement_unit_id')
            ->select('agreement_unit_id', DB::raw('SUM(amount) as held'));
        $owed = DB::table('invoices')->where('status', 'issued')->where('type', '<>', 'credit_note')->whereNotNull('agreement_id')
            ->groupBy('agreement_id')->select('agreement_id', DB::raw('SUM(balance) as owed'));

        $units = DB::table('units as u')
            ->join('buildings as b', 'b.id', '=', 'u.building_id')
            ->leftJoin('unit_types as ut', 'ut.code', '=', 'u.type')
            ->leftJoinSub(Queries::currentAgreementUnits($user, $on), 'cur', 'cur.unit_id', '=', 'u.id')
            ->leftJoin('agreement_units as au', 'au.id', '=', 'cur.au_id')
            ->leftJoin('agreements as a', 'a.id', '=', 'au.agreement_id')
            ->leftJoin('customers as c', 'c.id', '=', 'a.customer_id')
            ->leftJoinSub($rent, 'r', 'r.agreement_unit_id', '=', 'au.id')
            ->leftJoinSub($held, 'd', 'd.agreement_unit_id', '=', 'au.id')
            ->leftJoinSub($owed, 'o', 'o.agreement_id', '=', 'a.id')
            ->whereIn('u.id', Unit::query()->visibleTo($user)->select('id'))
            ->when($this->building, fn ($q, int $b) => $q->where('u.building_id', $b))
            ->orderBy('b.code')->orderBy('u.code')
            ->get(['u.building_id', 'b.code as building', 'u.code as unit', 'u.blocked', 'u.list_rent', 'u.type', 'ut.name as type_name',
                'au.id as au_id', 'au.start_date', 'au.end_date', 'a.id as agreement_id', 'a.number', 'c.name_en as tenant', 'r.rent', 'd.held', 'o.owed']);

        $money = $this->showMoney();
        $fils = fn (mixed $v) => Fils::fromDecimal((string) ($v ?? '0'));
        $date = fn (mixed $d) => $d === null ? '' : CarbonImmutable::parse((string) $d)->format('d/m/Y');
        $rows = [];
        $seen = [];
        $all = ['units' => 0, 'blocked' => 0, 'occupied' => 0, 'counted' => 0, 'rent' => 0, 'deposit' => 0, 'balance' => 0];
        foreach ($units->groupBy('building_id') as $group) {
            $sub = array_fill_keys(array_keys($all), 0);
            $code = '';
            foreach ($group as $u) {
                $code = (string) $u->building;
                $occupied = $u->au_id !== null;
                $blocked = (bool) $u->blocked;
                $first = $occupied && ! isset($seen[$u->agreement_id]);
                if ($occupied) {
                    $seen[$u->agreement_id] = true;
                }
                $rentFils = $occupied ? $fils($u->rent) : 0;
                $depositFils = $occupied ? $fils($u->held) : 0;
                $balanceFils = $first ? $fils($u->owed) : 0;
                $sub['units']++;
                $sub['blocked'] += (int) $blocked;
                $sub['occupied'] += (int) $occupied;
                $sub['counted'] += (int) ($occupied && ! $blocked); // a blocked unit can't count towards the %
                $sub['rent'] += $rentFils;
                $sub['deposit'] += $depositFils;
                $sub['balance'] += $balanceFils;

                $rows[] = [
                    'building' => $code, 'unit' => $u->unit, 'type' => $u->type_name ?? $u->type,
                    'status' => $occupied ? __('Occupied') : ($blocked ? __('Blocked') : __('Vacant')),
                    'tenant' => $u->tenant ?? '', 'agreement' => $u->number ?? '', 'start' => $date($u->start_date), 'end' => $date($u->end_date),
                    ...($money ? [
                        'rent' => $occupied ? Fils::toDecimal($rentFils) : ($blocked ? '' : Fils::toDecimal($fils($u->list_rent))),
                        'deposit' => $occupied ? Fils::toDecimal($depositFils) : '',
                        'balance' => $occupied ? ($first ? Fils::toDecimal($balanceFils) : __('(all units)')) : '',
                    ] : []),
                    '_muted' => ! $occupied && ! $blocked ? 'rent' : null, // a vacant unit's list rent
                ];
            }
            $rows[] = $this->totalRow(__('Subtotal :building', ['building' => $code]), $sub, $money);
            foreach ($sub as $k => $v) {
                $all[$k] += $v;
            }
        }
        if ($rows !== []) {
            $rows[] = $this->totalRow(__('Total'), $all, $money);
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $t
     * @return array<string, string|int|null>
     */
    private function totalRow(string $label, array $t, bool $money): array
    {
        $available = $t['units'] - $t['blocked'];

        return [
            'building' => $label,
            'unit' => __(':n units', ['n' => $t['units']]),
            'status' => __(':n occupied, :pct', ['n' => $t['occupied'], 'pct' => $available > 0 ? number_format(100 * $t['counted'] / $available, 1).'%' : '—']),
            ...($money ? ['rent' => Fils::toDecimal($t['rent']), 'deposit' => Fils::toDecimal($t['deposit']), 'balance' => Fils::toDecimal($t['balance'])] : []),
            '_kind' => 'total',
        ];
    }
}
