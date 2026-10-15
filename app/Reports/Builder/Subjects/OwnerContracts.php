<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\FeeType;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\PaymentFrequency;
use App\Enums\PermissionName;
use App\Models\OwnerContract;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class OwnerContracts extends Subject
{
    public function key(): string
    {
        return 'owner_contracts';
    }

    public function label(): string
    {
        return __('Owner contracts');
    }

    public function group(): string
    {
        return __('Owners');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsFinancial;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return OwnerContract::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->where('owner_contracts.building_id', $b))->toBase()
            ->join('owners', 'owners.id', '=', 'owner_contracts.owner_id')
            ->join('buildings', 'buildings.id', '=', 'owner_contracts.building_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('number', __('Contract'), __('Contract'), "COALESCE(owner_contracts.number, CONCAT('#', owner_contracts.id))", groupable: false),
            Field::text('owner', __('Owner'), __('Owner'), 'owners.name_en'),
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::enum('type', __('Type'), __('Contract'), 'owner_contracts.type', Field::optionsFrom(OwnerContractType::class)),
            Field::enum('status', __('Status'), __('Contract'), 'owner_contracts.status', Field::optionsFrom(OwnerContractStatus::class)),
            Field::date('start_date', __('Start'), __('Dates'), 'owner_contracts.start_date'),
            Field::date('end_date', __('End'), __('Dates'), 'owner_contracts.end_date'),
            Field::money('rent', __('Head-lease rent'), __('Amounts'), 'owner_contracts.rent_amount'),
            Field::enum('frequency', __('Paid'), __('Contract'), 'owner_contracts.payment_frequency', Field::optionsFrom(PaymentFrequency::class)),
            Field::enum('fee_type', __('Fee type'), __('Contract'), 'owner_contracts.fee_type', Field::optionsFrom(FeeType::class)),
            Field::number('fee_value', __('Fee value'), __('Amounts'), 'owner_contracts.fee_value'),
            Field::number('units', __('Units'), __('Property'), '(SELECT COUNT(*) FROM owner_contract_units ocu WHERE ocu.owner_contract_id = owner_contracts.id)'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'owner', 'building', 'type', 'status', 'start_date', 'end_date'];
    }

    public function periodFields(): array
    {
        return ['start_date', 'end_date'];
    }
}
