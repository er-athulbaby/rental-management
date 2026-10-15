<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\ChargeTo;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PermissionName;
use App\Models\Expense;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Expenses extends Subject
{
    public function key(): string
    {
        return 'expenses';
    }

    public function label(): string
    {
        return __('Expenses');
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
        return Expense::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->where('expenses.building_id', $b))->toBase()
            ->join('buildings', 'buildings.id', '=', 'expenses.building_id')
            ->leftJoin('units', 'units.id', '=', 'expenses.unit_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::text('unit', __('Unit'), __('Property'), 'units.code'),
            Field::enum('category', __('Category'), __('Expense'), 'expenses.category', Field::optionsFrom(ExpenseCategory::class)),
            Field::date('expense_date', __('Date'), __('Dates'), 'expenses.expense_date'),
            Field::money('net', __('Net'), __('Amounts'), 'expenses.net'),
            Field::money('vat', __('VAT'), __('Amounts'), 'expenses.tax_amount'),
            Field::money('total', __('Total'), __('Amounts'), 'expenses.total'),
            Field::enum('charge_to', __('Charged to'), __('Expense'), 'expenses.charge_to', Field::optionsFrom(ChargeTo::class)),
            Field::enum('status', __('Status'), __('Expense'), 'expenses.status', Field::optionsFrom(ExpenseStatus::class)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['expense_date', 'building', 'unit', 'category', 'total', 'charge_to', 'status'];
    }

    public function periodFields(): array
    {
        return ['expense_date'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'expense_date', 'dir' => 'desc'];
    }
}
