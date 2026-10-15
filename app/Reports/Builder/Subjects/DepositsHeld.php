<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DepositsHeld extends Subject
{
    public function key(): string
    {
        return 'deposits_held';
    }

    public function label(): string
    {
        return __('Deposits held');
    }

    public function group(): string
    {
        return __('Costs');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsFinancial;
    }

    public function usesAsAt(): bool
    {
        return true;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        $end = $asAt->endOfDay()->toDateTimeString();

        return DB::table('agreement_units')
            ->whereIn('agreement_units.agreement_id', Agreement::query()->visibleTo($user)->select('id'))
            ->join('agreements', 'agreements.id', '=', 'agreement_units.agreement_id')
            ->join('units', 'units.id', '=', 'agreement_units.unit_id')
            ->join('buildings', 'buildings.id', '=', 'units.building_id')
            ->join('customers', 'customers.id', '=', 'agreements.customer_id')
            ->leftJoinSub(DB::table('deposit_movements')->where('posted_at', '<=', $end)
                ->groupBy('agreement_unit_id')->select('agreement_unit_id', DB::raw('SUM(amount) as held')), 'dm', 'dm.agreement_unit_id', '=', 'agreement_units.id')
            ->when($buildingId, fn ($q, $b) => $q->where('units.building_id', $b))
            ->where(fn ($q) => $q->where('agreement_units.deposit_amount', '>', 0)->orWhereNotNull('dm.held'));
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('agreement', __('Agreement'), __('Tenant'), 'agreements.number'),
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::text('unit', __('Unit'), __('Property'), 'units.code', groupable: false),
            Field::money('agreed', __('Deposit agreed'), __('Amounts'), 'agreement_units.deposit_amount'),
            Field::money('held', __('Held'), __('Amounts'), 'COALESCE(dm.held, 0)'),
            Field::boolean('settled', __('Settled'), __('Tenant'),
                "EXISTS (SELECT 1 FROM deposit_settlement_units su JOIN deposit_settlements ss ON ss.id = su.deposit_settlement_id
                    WHERE su.agreement_unit_id = agreement_units.id AND ss.status = 'completed')"),
        ];
    }

    public function defaultColumns(): array
    {
        return ['tenant', 'agreement', 'building', 'unit', 'agreed', 'held'];
    }
}
