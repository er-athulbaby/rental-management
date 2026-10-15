<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\OwnerStatementStatus;
use App\Enums\PermissionName;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/** Stored figures only (planning ruling): collections, expenses and remittances need the owner ledger. */
final class OwnerStatements extends Subject
{
    public function key(): string
    {
        return 'owner_statements';
    }

    public function label(): string
    {
        return __('Owner statements');
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
        return OwnerStatement::query()->whereIn('owner_statements.owner_contract_id', OwnerContract::query()->visibleTo($user)
            ->when($buildingId, fn ($q, $b) => $q->where('building_id', $b))->select('id'))->toBase()
            ->join('owner_contracts', 'owner_contracts.id', '=', 'owner_statements.owner_contract_id')
            ->join('owners', 'owners.id', '=', 'owner_contracts.owner_id')
            ->join('buildings', 'buildings.id', '=', 'owner_contracts.building_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('number', __('Statement'), __('Statement'), "COALESCE(owner_statements.number, CONCAT('#', owner_statements.id))", groupable: false),
            Field::text('owner', __('Owner'), __('Owner'), 'owners.name_en'),
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::text('contract', __('Contract'), __('Owner'), 'owner_contracts.number'),
            Field::date('period_start', __('Period start'), __('Dates'), 'owner_statements.period_start'),
            Field::date('period_end', __('Period end'), __('Dates'), 'owner_statements.period_end'),
            Field::money('opening', __('Opening'), __('Amounts'), 'owner_statements.opening_balance'),
            Field::money('fee_base', __('Fee base'), __('Amounts'), 'owner_statements.fee_base'),
            Field::money('fee', __('Fee'), __('Amounts'), 'owner_statements.fee_amount'),
            Field::money('fee_vat', __('VAT on fee'), __('Amounts'), 'owner_statements.fee_tax'),
            Field::money('closing', __('Closing'), __('Amounts'), 'owner_statements.closing_balance'),
            Field::enum('status', __('Status'), __('Statement'), 'owner_statements.status', Field::optionsFrom(OwnerStatementStatus::class)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'owner', 'building', 'period_end', 'opening', 'fee', 'closing', 'status'];
    }

    public function periodFields(): array
    {
        return ['period_end'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'period_end', 'dir' => 'desc'];
    }
}
