<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\InvoiceType;
use App\Enums\PermissionName;
use App\Models\Invoice;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Sql;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Invoices extends Subject
{
    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return __('Invoices');
    }

    public function group(): string
    {
        return __('Money in');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsFinancial;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Invoice::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase()
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('agreements', 'agreements.id', '=', 'invoices.agreement_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $today = Sql::date($asAt);
        $owed = "invoices.status = 'issued' AND invoices.type <> 'credit_note'";

        return [
            Field::text('number', __('Invoice'), __('Invoice'), "COALESCE(invoices.number, CONCAT('#', invoices.id))", groupable: false),
            Field::enum('type', __('Type'), __('Invoice'), 'invoices.type', Field::optionsFrom(InvoiceType::class)),
            Field::enum('status', __('Status'), __('Invoice'),
                "CASE WHEN {$owed} THEN (CASE WHEN invoices.balance <= 0 THEN 'paid' WHEN invoices.grace_until < {$today} THEN 'overdue'
                    WHEN invoices.balance < invoices.total THEN 'partially_paid' ELSE 'unpaid' END) ELSE invoices.status END",
                ['unpaid' => __('Unpaid'), 'partially_paid' => __('Partially paid'), 'overdue' => __('Overdue'), 'paid' => __('Paid'),
                    'draft' => __('Draft'), 'pending_approval' => __('Pending approval'), 'scheduled' => __('Scheduled'),
                    'issued' => __('Issued'), 'cancelled' => __('Cancelled')]),
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('agreement', __('Agreement'), __('Tenant'), 'agreements.number'),
            Field::text('building', __('Building'), __('Property'), 'COALESCE('.Sql::buildingOfInvoice('invoices.id').", '—')", filterable: false),
            Field::text('units', __('Units'), __('Property'), Sql::unitsOfInvoice('invoices.id'), groupable: false),
            Field::date('issue_date', __('Issue date'), __('Dates'), 'invoices.issue_date'),
            Field::date('due_date', __('Due date'), __('Dates'), 'invoices.due_date'),
            Field::number('days_overdue', __('Days overdue'), __('Dates'),
                "CASE WHEN {$owed} AND invoices.balance > 0 AND invoices.grace_until < {$today} THEN DATEDIFF({$today}, invoices.due_date) END"),
            Field::money('net', __('Net'), __('Amounts'), 'invoices.subtotal'),
            Field::money('vat', __('VAT'), __('Amounts'), 'invoices.tax_total'),
            Field::money('total', __('Total'), __('Amounts'), 'invoices.total'),
            Field::money('paid', __('Paid'), __('Amounts'), 'invoices.allocated'),
            Field::money('credited', __('Credited'), __('Amounts'), 'invoices.credited'),
            Field::money('balance', __('Balance'), __('Amounts'), "CASE WHEN invoices.status = 'issued' THEN invoices.balance ELSE 0 END"),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'tenant', 'building', 'due_date', 'total', 'balance', 'status'];
    }

    public function periodFields(): array
    {
        return ['issue_date', 'due_date'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'due_date', 'dir' => 'asc'];
    }
}
