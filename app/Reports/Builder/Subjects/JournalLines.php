<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Ledger\Sources;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** General ledger spec §6: journal lines for saved reports and packages. The ledger is company-wide, so ledger.view sees every building (plan ruling 12). */
final class JournalLines extends Subject
{
    public function key(): string
    {
        return 'journal_lines';
    }

    public function label(): string
    {
        return __('Journal lines');
    }

    public function group(): string
    {
        return __('Ledger');
    }

    public function permission(): PermissionName
    {
        return PermissionName::LedgerView;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->leftJoin('customers', 'customers.id', '=', 'journal_lines.customer_id')
            ->leftJoin('owners', 'owners.id', '=', 'journal_lines.owner_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'journal_lines.building_id')
            ->leftJoin('units', 'units.id', '=', 'journal_lines.unit_id')
            ->when($buildingId, fn (Builder $q, int $b) => $q->where('journal_lines.building_id', $b));
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $sources = ['manual' => Sources::label('manual'), 'opening' => Sources::label('opening')];
        foreach (array_keys(Sources::TYPES) as $type) {
            $sources[$type] = Sources::label($type);
        }

        return [
            Field::date('date', __('Date'), __('Journal'), 'journals.date'),
            Field::number('journal', __('Journal'), __('Journal'), 'journals.id'),
            Field::enum('kind', __('Kind'), __('Journal'), 'journals.kind', ['auto' => __('Automatic'), 'manual' => Sources::label('manual'), 'opening' => Sources::label('opening')]),
            Field::enum('source', __('Source'), __('Journal'), 'COALESCE(journals.source_type, journals.kind)', $sources),
            Field::text('description', __('Description'), __('Journal'), 'journals.description'),
            Field::boolean('late', __('Late posting'), __('Journal'), 'journals.late_posting'),
            Field::text('account_code', __('Account code'), __('Account'), 'ledger_accounts.code'),
            Field::text('account', __('Account'), __('Account'), 'ledger_accounts.name_en'),
            Field::enum('account_type', __('Account type'), __('Account'), 'ledger_accounts.type', Field::optionsFrom(LedgerAccountType::class)),
            Field::money('debit', __('Debit'), __('Amounts'), 'journal_lines.debit'),
            Field::money('credit', __('Credit'), __('Amounts'), 'journal_lines.credit'),
            Field::money('net', __('Debit − credit'), __('Amounts'), '(journal_lines.debit - journal_lines.credit)'),
            Field::text('tenant', __('Tenant'), __('Dimensions'), 'customers.name_en'),
            Field::text('owner', __('Owner'), __('Dimensions'), 'owners.name_en'),
            Field::text('building', __('Building'), __('Dimensions'), 'buildings.code'),
            Field::text('unit', __('Unit'), __('Dimensions'), 'units.code'),
            Field::text('memo', __('Memo'), __('Journal'), 'journal_lines.memo'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['date', 'journal', 'account_code', 'account', 'debit', 'credit', 'description'];
    }

    public function periodFields(): array
    {
        return ['date'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'date', 'dir' => 'asc'];
    }
}
