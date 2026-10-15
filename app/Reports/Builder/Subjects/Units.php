<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\Furnishing;
use App\Enums\PermissionName;
use App\Enums\UnitUse;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use App\Reports\Queries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/** Read as at a date (spec §4.2): occupancy, current tenant and rent are those on the as-at date. */
final class Units extends Subject
{
    public function key(): string
    {
        return 'units';
    }

    public function label(): string
    {
        return __('Units');
    }

    public function group(): string
    {
        return __('Leasing');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsOperational;
    }

    public function usesAsAt(): bool
    {
        return true;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Unit::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->where('units.building_id', $b))->toBase()
            ->join('buildings', 'buildings.id', '=', 'units.building_id')
            ->leftJoinSub(Queries::currentAgreementUnits($user, $asAt->toDateString()), 'cur', 'cur.unit_id', '=', 'units.id')
            ->leftJoin('agreement_units as cau', 'cau.id', '=', 'cur.au_id')
            ->leftJoin('agreements as ca', 'ca.id', '=', 'cau.agreement_id')
            ->leftJoin('customers as cc', 'cc.id', '=', 'ca.customer_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::text('code', __('Unit'), __('Property'), 'units.code', groupable: false),
            Field::enum('type', __('Type'), __('Property'), 'units.type', UnitType::query()->orderBy('name')->pluck('name', 'code')->all()),
            Field::enum('use', __('Use'), __('Property'), 'units.use', Field::optionsFrom(UnitUse::class)),
            Field::enum('furnishing', __('Furnishing'), __('Property'), 'units.furnishing', Field::optionsFrom(Furnishing::class)),
            Field::enum('occupancy', __('Occupancy'), __('Occupancy'),
                "CASE WHEN units.blocked = 1 THEN 'blocked' WHEN cur.unit_id IS NOT NULL THEN 'occupied' ELSE 'vacant' END",
                ['occupied' => __('Occupied'), 'vacant' => __('Vacant'), 'blocked' => __('Blocked')]),
            Field::money('list_rent', __('List rent'), __('Amounts'), 'units.list_rent'),
            Field::text('current_tenant', __('Current tenant'), __('Occupancy'), 'cc.name_en'),
            Field::money('current_rent', __('Current rent'), __('Amounts'),
                "(SELECT SUM(rc.monthly_amount) FROM agreement_unit_charges rc WHERE rc.agreement_unit_id = cau.id AND rc.type = 'rent')"),
            Field::date('agreement_end', __('Agreement end'), __('Occupancy'), 'cau.end_date'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['building', 'code', 'type', 'occupancy', 'current_tenant', 'list_rent', 'current_rent'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'building', 'dir' => 'asc'];
    }
}
