<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\IdType;
use App\Enums\PartyType;
use App\Enums\PermissionName;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/** Owners are not building-scoped (spec 8.2); the building filter keeps owners with a contract on it. */
final class Owners extends Subject
{
    public function key(): string
    {
        return 'owners';
    }

    public function label(): string
    {
        return __('Owners');
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
        return Owner::query()->when($buildingId, fn ($q, $b) => $q->whereIn('owners.id',
            OwnerContract::query()->visibleTo($user)->where('building_id', $b)->select('owner_id')))->toBase();
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('name', __('Owner'), __('Owner'), 'owners.name_en', groupable: false),
            Field::enum('type', __('Type'), __('Owner'), 'owners.type', Field::optionsFrom(PartyType::class)),
            Field::enum('id_type', __('ID type'), __('Owner'), 'owners.id_type', Field::optionsFrom(IdType::class)),
            Field::number('active_contracts', __('Active contracts'), __('Owner'),
                "(SELECT COUNT(*) FROM owner_contracts oc WHERE oc.owner_id = owners.id AND oc.status = 'active')"),
            Field::text('buildings', __('Buildings'), __('Property'),
                "(SELECT GROUP_CONCAT(DISTINCT ob.code ORDER BY ob.code SEPARATOR ', ') FROM owner_contracts oc2 JOIN buildings ob ON ob.id = oc2.building_id
                    WHERE oc2.owner_id = owners.id)", filterable: false),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'type', 'active_contracts', 'buildings'];
    }
}
