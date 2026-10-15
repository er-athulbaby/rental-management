<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\ChequeStatus;
use App\Enums\PermissionName;
use App\Models\Cheque;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Sql;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Cheques extends Subject
{
    public function key(): string
    {
        return 'cheques';
    }

    public function label(): string
    {
        return __('Cheques');
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
        return Cheque::query()->visibleTo($user)->where('cheques.direction', 'received')
            ->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase()
            ->leftJoin('customers', 'customers.id', '=', 'cheques.customer_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('cheque_no', __('Cheque no.'), __('Cheque'), 'cheques.cheque_no', groupable: false),
            Field::text('bank', __('Bank'), __('Cheque'), 'cheques.bank_name'),
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('building', __('Building'), __('Property'), 'COALESCE('.Sql::buildingOfAgreement('cheques.agreement_id').", '—')", filterable: false),
            Field::date('cheque_date', __('Cheque date'), __('Dates'), 'cheques.cheque_date'),
            Field::money('amount', __('Amount'), __('Amounts'), 'cheques.amount'),
            Field::enum('status', __('Status'), __('Cheque'), 'cheques.status', Field::optionsFrom(ChequeStatus::class)),
            Field::date('deposited_on', __('Deposited on'), __('Dates'), 'cheques.deposited_on'),
            Field::date('cleared_on', __('Cleared on'), __('Dates'), 'cheques.cleared_on'),
            Field::date('bounced_on', __('Bounced on'), __('Dates'), 'cheques.bounced_on'),
            Field::text('bounce_reason', __('Bounce reason'), __('Cheque'), 'cheques.bounce_reason'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['cheque_no', 'bank', 'tenant', 'cheque_date', 'amount', 'status'];
    }

    public function periodFields(): array
    {
        return ['cheque_date', 'cleared_on'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'cheque_date', 'dir' => 'asc'];
    }
}
