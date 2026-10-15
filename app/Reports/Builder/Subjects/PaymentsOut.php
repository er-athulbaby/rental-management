<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\DisbursementMethod;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class PaymentsOut extends Subject
{
    public function key(): string
    {
        return 'payments_out';
    }

    public function label(): string
    {
        return __('Payments out');
    }

    public function group(): string
    {
        return __('Costs');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsFinancial;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Disbursement::query()->where(fn ($q) => $q->where('disbursements.payee_type', 'owner')
            ->orWhereIn('disbursements.payee_id', Customer::query()->visibleTo($user)->select('id')))
            ->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase();
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $building = "(CASE disbursements.source_type
            WHEN 'owner_payable' THEN (SELECT xb.code FROM owner_payables xp JOIN owner_contracts xc ON xc.id = xp.owner_contract_id JOIN buildings xb ON xb.id = xc.building_id WHERE xp.id = disbursements.source_id)
            WHEN 'owner_statement' THEN (SELECT yb.code FROM owner_statements ys JOIN owner_contracts yc ON yc.id = ys.owner_contract_id JOIN buildings yb ON yb.id = yc.building_id WHERE ys.id = disbursements.source_id)
            END)";

        return [
            Field::text('number', __('Number'), __('Payment out'), "COALESCE(disbursements.number, CONCAT('#', disbursements.id))", groupable: false),
            Field::enum('payee_type', __('Payee type'), __('Payee'), 'disbursements.payee_type', ['owner' => __('Owner'), 'customer' => __('Tenant')]),
            Field::text('payee', __('Payee'), __('Payee'), "(CASE disbursements.payee_type WHEN 'owner' THEN (SELECT po.name_en FROM owners po WHERE po.id = disbursements.payee_id)
                ELSE (SELECT pc.name_en FROM customers pc WHERE pc.id = disbursements.payee_id) END)"),
            Field::enum('purpose', __('Purpose'), __('Payment out'), 'disbursements.purpose', Field::optionsFrom(DisbursementPurpose::class)),
            Field::text('building', __('Building'), __('Property'), "COALESCE({$building}, '—')", filterable: false),
            Field::money('amount', __('Amount'), __('Amounts'), 'disbursements.amount'),
            Field::enum('method', __('Method'), __('Payment out'), 'disbursements.method', Field::optionsFrom(DisbursementMethod::class)),
            Field::date('paid_on', __('Paid on'), __('Dates'), 'disbursements.paid_on'),
            Field::enum('status', __('Status'), __('Payment out'), 'disbursements.status', Field::optionsFrom(DisbursementStatus::class)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'payee', 'purpose', 'amount', 'paid_on', 'status'];
    }

    public function periodFields(): array
    {
        return ['paid_on'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'paid_on', 'dir' => 'desc'];
    }
}
