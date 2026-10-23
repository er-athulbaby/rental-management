<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Unit;
use App\Reports\Packages\PackageReport;
use App\Reports\Queries;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * Spec 2026-10-16 §3: one row per unit as at a date, a subtotal per building and a grand total. Occupancy follows the
 * Occupancy report (Queries::currentAgreementUnits; % = occupied ÷ (units − blocked)). Rent = the occupying agreement
 * unit's rent charges (plan ruling 2); deposit held as at the date; balance owed live, once per agreement (ruling 4).
 */
class RentRollReport extends Component implements PackageReport
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
            ...($this->showMoney() ? ['rent' => __('Monthly rent'), 'deposit' => __('Deposit held'), 'overdue' => __('Overdue'), 'not_due' => __('Not yet due')] : []),
        ];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['rent', 'deposit', 'overdue', 'not_due'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $on = $this->to;
        $user = $this->actor();
        $units = Queries::rentRollUnits(
            Unit::query()->visibleTo($user)->when($this->building, fn ($q, int $b) => $q->where('units.building_id', $b))->select('id'), $on);

        $money = $this->showMoney();
        $fils = fn (mixed $v) => Fils::fromDecimal((string) ($v ?? '0'));
        $date = fn (mixed $d) => $d === null ? '' : CarbonImmutable::parse((string) $d)->format('d/m/Y');
        $rows = [];
        $seen = [];
        $all = ['units' => 0, 'blocked' => 0, 'occupied' => 0, 'counted' => 0, 'rent' => 0, 'deposit' => 0, 'overdue' => 0, 'not_due' => 0];
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
                $depositFils = $fils($u->held);
                $overdueFils = $first ? $fils($u->overdue) : 0;
                $notDueFils = $first ? $fils($u->not_due) : 0;
                $sub['units']++;
                $sub['blocked'] += (int) $blocked;
                $sub['occupied'] += (int) $occupied;
                $sub['counted'] += (int) ($occupied && ! $blocked); // a blocked unit can't count towards the %
                $sub['rent'] += $rentFils;
                $sub['deposit'] += $depositFils;
                $sub['overdue'] += $overdueFils;
                $sub['not_due'] += $notDueFils;

                $rows[] = [
                    'building' => $code, 'unit' => $u->unit, 'type' => $u->type_name ?? $u->type,
                    'status' => $occupied ? __('Occupied') : ($blocked ? __('Blocked') : __('Vacant')),
                    'tenant' => $u->tenant ?? '', 'agreement' => $u->number ?? '', 'start' => $date($u->start_date), 'end' => $date($u->end_date),
                    ...($money ? [
                        'rent' => $occupied ? Fils::toDecimal($rentFils) : ($blocked || $u->list_rent === null ? '' : __(':amount (list)', ['amount' => Fils::toDecimal($fils($u->list_rent))])),
                        'deposit' => $occupied || $depositFils > 0 ? Fils::toDecimal($depositFils) : '',
                        'overdue' => $occupied ? ($first ? Fils::toDecimal($overdueFils) : __('(all units)')) : '',
                        'not_due' => $occupied ? ($first ? Fils::toDecimal($notDueFils) : __('(all units)')) : '',
                    ] : []),
                    '_muted' => ! $occupied && ! $blocked && $u->list_rent !== null ? 'rent' : null, // a vacant unit's list rent
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
            ...($money ? ['rent' => Fils::toDecimal($t['rent']), 'deposit' => Fils::toDecimal($t['deposit']), 'overdue' => Fils::toDecimal($t['overdue']), 'not_due' => Fils::toDecimal($t['not_due'])] : []),
            '_kind' => 'total',
        ];
    }
}
