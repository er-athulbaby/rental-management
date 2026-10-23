<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\AgreementStatus;
use App\Enums\CustomFieldEntity;
use App\Enums\PaymentFrequency;
use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\User;
use App\Reports\Builder\CustomColumns;
use App\Reports\Builder\Field;
use App\Reports\Builder\Sql;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Agreements extends Subject
{
    public function key(): string
    {
        return 'agreements';
    }

    public function label(): string
    {
        return __('Agreements');
    }

    public function group(): string
    {
        return __('Leasing');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsOperational;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Agreement::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase()
            ->leftJoin('customers', 'customers.id', '=', 'agreements.customer_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $today = Sql::date($asAt);

        return [
            Field::text('number', __('Agreement'), __('Agreement'), "COALESCE(agreements.number, CONCAT('#', agreements.id))", groupable: false),
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('building', __('Building'), __('Property'), 'COALESCE('.Sql::buildingOfAgreement('agreements.id').", '—')", filterable: false),
            Field::text('units', __('Units'), __('Property'), Sql::unitsOfAgreement('agreements.id'), groupable: false),
            Field::date('start_date', __('Start'), __('Dates'), 'agreements.start_date'),
            Field::date('end_date', __('End'), __('Dates'), 'agreements.end_date'),
            Field::number('days_to_expiry', __('Days to expiry'), __('Dates'),
                "CASE WHEN agreements.status = 'active' THEN DATEDIFF(agreements.end_date, {$today}) END"),
            Field::date('notice_date', __('Notice date'), __('Dates'), 'agreements.notice_date'),
            Field::date('planned_exit', __('Planned exit'), __('Dates'), 'agreements.planned_exit_date'),
            Field::enum('status', __('Status'), __('Agreement'), 'agreements.status', Field::optionsFrom(AgreementStatus::class)),
            Field::enum('frequency', __('Billing'), __('Agreement'), 'agreements.frequency', Field::optionsFrom(PaymentFrequency::class)),
            Field::money('monthly_rent', __('Monthly rent'), __('Amounts'),
                "(SELECT COALESCE(SUM(c.monthly_amount), 0) FROM agreement_unit_charges c JOIN agreement_units ca ON ca.id = c.agreement_unit_id
                    WHERE ca.agreement_id = agreements.id AND c.type = 'rent')"),
            Field::money('deposit', __('Deposit'), __('Amounts'),
                '(SELECT COALESCE(SUM(da.deposit_amount), 0) FROM agreement_units da WHERE da.agreement_id = agreements.id)'),
            ...CustomColumns::fields(CustomFieldEntity::Agreement, 'cf_', __('More details'), 'agreements.id'),
            ...CustomColumns::fields(CustomFieldEntity::Customer, 'cft_', __('Tenant'), 'agreements.customer_id'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'tenant', 'building', 'start_date', 'end_date', 'monthly_rent', 'status'];
    }

    public function periodFields(): array
    {
        return ['start_date', 'end_date'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'end_date', 'dir' => 'asc'];
    }
}
