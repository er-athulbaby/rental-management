<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\PaymentMethod;
use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Payments extends Subject
{
    public function key(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return __('Payments');
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
        return Payment::query()->whereIn('payments.customer_id', Customer::query()->visibleTo($user)->select('id'))
            ->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase()
            ->leftJoin('customers', 'customers.id', '=', 'payments.customer_id')
            ->leftJoin('users as recorders', 'recorders.id', '=', 'payments.recorded_by');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $applied = '(SELECT COALESCE(SUM(pa.amount), 0) FROM payment_allocations pa WHERE pa.payment_id = payments.id)';
        $refunded = "(SELECT COALESCE(SUM(d.amount), 0) FROM disbursements d WHERE d.source_type = 'payment' AND d.source_id = payments.id
            AND d.purpose = 'credit_refund' AND d.status = 'paid')";
        $building = "(SELECT CASE WHEN COUNT(DISTINCT pu.building_id) > 1 THEN 'Multiple' ELSE MAX(pb.code) END
            FROM payment_allocations pa2 JOIN invoice_lines pl ON pl.id = pa2.invoice_line_id JOIN units pu ON pu.id = pl.unit_id
            JOIN buildings pb ON pb.id = pu.building_id WHERE pa2.payment_id = payments.id)";

        return [
            Field::text('number', __('Receipt'), __('Payment'), 'payments.number', groupable: false),
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('building', __('Building'), __('Property'), "COALESCE({$building}, '—')", filterable: false),
            Field::date('received_on', __('Received on'), __('Dates'), 'payments.received_on'),
            Field::enum('method', __('Method'), __('Payment'), 'payments.method', Field::optionsFrom(PaymentMethod::class)),
            Field::text('reference', __('Reference'), __('Payment'), 'payments.reference', groupable: false),
            Field::money('amount', __('Amount'), __('Amounts'), 'payments.amount'),
            Field::money('applied', __('Applied to invoices'), __('Amounts'), $applied),
            Field::money('credit_left', __('Left as credit'), __('Amounts'),
                "CASE WHEN payments.status = 'confirmed' THEN payments.amount - {$applied} - {$refunded} ELSE 0 END"),
            Field::enum('status', __('Status'), __('Payment'), 'payments.status', ['confirmed' => __('Confirmed'), 'reversed' => __('Reversed')]),
            Field::text('recorded_by', __('Recorded by'), __('Payment'), 'recorders.name'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'tenant', 'received_on', 'method', 'amount', 'status'];
    }

    public function periodFields(): array
    {
        return ['received_on'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'received_on', 'dir' => 'desc'];
    }
}
