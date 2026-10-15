<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Tenants extends Subject
{
    public function key(): string
    {
        return 'tenants';
    }

    public function label(): string
    {
        return __('Tenants');
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
        return Customer::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase();
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $balance = "(SELECT COALESCE(SUM(oi.balance), 0) FROM invoices oi WHERE oi.customer_id = customers.id AND oi.status = 'issued'
            AND oi.type <> 'credit_note' AND oi.balance > 0)";
        $credit = "((SELECT COALESCE(SUM(cp.amount), 0) FROM payments cp WHERE cp.customer_id = customers.id AND cp.status = 'confirmed')
            - (SELECT COALESCE(SUM(ca.amount), 0) FROM payment_allocations ca JOIN payments cp2 ON cp2.id = ca.payment_id
                WHERE cp2.customer_id = customers.id AND cp2.status = 'confirmed')
            - (SELECT COALESCE(SUM(cd.amount), 0) FROM disbursements cd JOIN payments cp3 ON cd.source_type = 'payment' AND cd.source_id = cp3.id
                WHERE cp3.customer_id = customers.id AND cd.purpose = 'credit_refund' AND cd.status = 'paid'))";

        return [
            Field::text('name', __('Tenant'), __('Tenant'), 'customers.name_en', groupable: false),
            Field::enum('type', __('Type'), __('Tenant'), 'customers.type', Field::optionsFrom(CustomerType::class)),
            Field::enum('id_type', __('ID type'), __('Tenant'), 'customers.id_type', Field::optionsFrom(IdType::class)),
            Field::text('nationality', __('Nationality'), __('Tenant'), 'customers.nationality'),
            Field::text('mobile', __('Mobile'), __('Tenant'), 'customers.mobile', groupable: false),
            Field::text('buildings', __('Buildings'), __('Property'),
                "(SELECT GROUP_CONCAT(DISTINCT tb.code ORDER BY tb.code SEPARATOR ', ') FROM agreements ta JOIN agreement_units tau ON tau.agreement_id = ta.id
                    JOIN units tu ON tu.id = tau.unit_id JOIN buildings tb ON tb.id = tu.building_id WHERE ta.customer_id = customers.id AND ta.deleted_at IS NULL)",
                filterable: false),
            Field::number('active_agreements', __('Active agreements'), __('Tenant'),
                "(SELECT COUNT(*) FROM agreements aa WHERE aa.customer_id = customers.id AND aa.status = 'active' AND aa.deleted_at IS NULL)"),
            Field::money('balance', __('Invoice balances'), __('Amounts'), $balance, PermissionName::ReportsFinancial),
            Field::money('credit', __('Credit'), __('Amounts'), $credit, PermissionName::ReportsFinancial),
            Field::money('outstanding', __('Outstanding'), __('Amounts'), "({$balance} - {$credit})", PermissionName::ReportsFinancial),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'type', 'mobile', 'buildings', 'active_agreements'];
    }
}
