<?php

namespace App\Actions\Import;

use App\Actions\Agreements\ActivateAgreement;
use App\Actions\Agreements\SaveAgreement;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Buildings\SaveBuilding;
use App\Actions\Customers\SaveCustomer;
use App\Actions\OwnerContracts\ActivateOwnerContract;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\Owners\SaveOwner;
use App\Actions\Units\SaveUnit;
use App\Audit\Audit;
use App\Enums\AgreementStatus;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\ImportKind;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OwnerChargeType;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\PermissionName;
use App\Enums\TaxCategory;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Owner;
use App\Models\OwnerCharge;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Spec §11. Dry run and real run are the same code: every row goes through the normal Actions
 * inside one transaction, which is committed only when asked AND every row of every file passed.
 */
final class RunImport
{
    public function __construct(
        private SaveBuilding $buildings,
        private SaveUnit $units,
        private SaveOwner $owners,
        private SaveOwnerContract $contracts,
        private ActivateOwnerContract $activate,
        private SaveCustomer $customers,
        private SaveAgreement $agreements,
        private ActivateAgreement $activateAgreement,
        private IssueInvoice $issue,
    ) {}

    /** @param  array<string, string>  $paths  ImportKind value => local .xlsx or .csv path */
    public function handle(User $actor, array $paths, bool $commit): ImportResult
    {
        if (! $actor->can(PermissionName::ImportRun)) {
            throw new AuthorizationException;
        }

        self::ensureOpen();
        $cutover = self::cutover();

        $errors = [];
        $counts = [];
        $totals = []; // kind => fils

        DB::beginTransaction();

        try {
            foreach (ImportKind::cases() as $kind) {
                if (! isset($paths[$kind->value])) {
                    continue;
                }

                $counts[$kind->value] = 0;

                try {
                    $reader = SimpleExcelReader::create($paths[$kind->value])->headersToSnakeCase();
                    $headers = $reader->getHeaders() ?? [];
                } catch (Throwable) {
                    $errors[$kind->value][1] = [__('This file cannot be read as .xlsx or .csv.')];

                    continue;
                }

                $missing = array_values(array_diff($kind->headers(), $headers));

                if ($missing !== []) {
                    $errors[$kind->value][1] = [__('Missing columns: :list', ['list' => implode(', ', $missing)])];

                    continue;
                }

                $items = [];
                foreach ($reader->getRows() as $index => $row) {
                    $items[(int) $index + 2] = $row; // spreadsheet line: header is line 1
                }

                $passed = 0;
                foreach (self::units($kind, $items) as $line => $group) {
                    try {
                        // A savepoint per unit of work: a failed one leaves nothing behind for later ones to trip on.
                        $clean = array_map(fn (array $r) => $this->normalise($kind, $r), $group);
                        DB::transaction(fn () => $this->importRow($actor, $kind, $clean, $cutover));
                        $passed += count($group);
                        if (($column = $kind->moneyColumn()) !== null) {
                            $totals[$kind->value] = ($totals[$kind->value] ?? 0) + array_sum(array_map(fn (array $r) => Fils::fromDecimal((string) $r[$column]), $clean));
                        }
                    } catch (ValidationException $e) {
                        $errors[$kind->value][$line] = array_values(Arr::flatten($e->errors()));
                    }
                }

                $counts[$kind->value] = $passed;
            }

            $committed = $commit && $errors === [];

            if ($committed) {
                Audit::log('import.run', properties: ['counts' => $counts], causer: $actor);
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return new ImportResult($errors, $counts, $committed, array_map(Fils::toDecimal(...), $totals));
    }

    /** After cutover the import screens and Actions refuse to run (spec §11). */
    public static function ensureOpen(): void
    {
        $goLive = CompanySetting::current()->go_live_at;

        if ($goLive !== null && $goLive->isPast()) {
            throw ValidationException::withMessages(['import' => __('This company went live on :date; imports are closed.', [
                'date' => $goLive->timezone('Asia/Bahrain')->format('d/m/Y'),
            ])]);
        }
    }

    /** Plan ruling 1: imports are as at the go-live day (spec §11). */
    public static function cutover(): CarbonImmutable
    {
        $goLive = CompanySetting::current()->go_live_at
            ?? throw ValidationException::withMessages(['import' => __('Set the go-live date first (php artisan rms:setting go_live_at YYYY-MM-DD): imports are as at that date.')]);

        return CarbonImmutable::instance($goLive)->timezone('Asia/Bahrain')->startOfDay();
    }

    /**
     * Plan ruling 5: agreement rows group by import_ref (one row per agreement unit); every other kind is one row each.
     * Keyed by the group's first spreadsheet line, which its errors are reported against.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, list<array<string, mixed>>>
     */
    private static function units(ImportKind $kind, array $items): array
    {
        if ($kind !== ImportKind::Agreements) {
            return array_map(fn (array $row) => [$row], $items);
        }

        $groups = [];
        $firstLine = [];
        foreach ($items as $line => $row) {
            $ref = trim((string) ($row['import_ref'] ?? ''));
            $key = $ref === '' ? "line-{$line}" : $ref; // a row without a reference fails on its own
            $firstLine[$key] ??= $line;
            $groups[$firstLine[$key]][] = $row;
        }

        return $groups;
    }

    /** @param  list<array<string, mixed>>  $rows  one row, except for agreements: the rows sharing one import_ref */
    private function importRow(User $actor, ImportKind $kind, array $rows, CarbonImmutable $cutover): void
    {
        $row = $rows[0];

        match ($kind) {
            ImportKind::Buildings => $this->buildings->handle($actor, null, $row),
            ImportKind::Units => $this->units->handle($actor, null, [
                ...$row,
                'building_id' => $this->buildingId($row['building_code'] ?? null),
                'blocked' => in_array(strtolower((string) ($row['blocked'] ?? '')), ['yes', 'y', 'true', '1'], true),
            ]),
            ImportKind::Owners => $this->owners->handle($actor, null, $row, viaImport: true),
            ImportKind::OwnerContracts => $this->ownerContract($actor, $row, $cutover),
            ImportKind::Customers => $this->customers->handle($actor, null, $row),
            ImportKind::Agreements => $this->agreement($actor, $rows, $cutover),
            ImportKind::CustomerBalances => $this->customerBalance($actor, $row, $cutover),
            ImportKind::OwnerBalances => $this->ownerBalance($actor, $row, $cutover),
        };
    }

    /**
     * Spec §11: an active agreement, approved as "Imported by {user}", billed from cutover. It is not made from a
     * contract template, so it has no clauses and no generated contract: the signed contract is the old system's.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function agreement(User $actor, array $rows, CarbonImmutable $cutover): void
    {
        $first = $rows[0];
        $ref = (string) ($first['import_ref'] ?? '');
        if ($ref === '') {
            throw ValidationException::withMessages(['import_ref' => __('Give each agreement a reference (import_ref).')]);
        }
        // Only the first row supplies the agreement's own columns, so the other rows must agree with it.
        foreach (['customer_id_type', 'customer_id_number', 'start_date', 'end_date', 'frequency', 'billing_day', 'grace_days', 'notice_period_days'] as $column) {
            foreach ($rows as $row) {
                if ((string) ($row[$column] ?? '') !== (string) ($first[$column] ?? '')) {
                    throw ValidationException::withMessages([$column => __('Rows of agreement :ref disagree on :column.', ['ref' => $ref, 'column' => $column])]);
                }
            }
        }
        if (Agreement::query()->where('import_ref', $ref)->exists()) {
            throw ValidationException::withMessages(['import_ref' => __('Agreement :ref was already imported.', ['ref' => $ref])]);
        }
        if (filled($first['end_date'] ?? null) && (string) $first['end_date'] < $cutover->toDateString()) { // blank: SaveAgreement reports it
            throw ValidationException::withMessages(['end_date' => __('Only agreements still running at cutover are imported (:ref ends :d).', ['ref' => $ref, 'd' => (string) $first['end_date']])]);
        }

        $customerId = Customer::query()->where('id_type', $first['customer_id_type'] ?? '')->where('id_number', $first['customer_id_number'] ?? '')->value('id')
            ?? throw ValidationException::withMessages(['customer_id_number' => __('No customer with ID :type :number.', ['type' => $first['customer_id_type'] ?? '', 'number' => $first['customer_id_number'] ?? ''])]);

        $units = array_map(function (array $row) {
            $unit = Unit::query()->where('building_id', $this->buildingId($row['building_code'] ?? null))->where('code', (string) ($row['unit_code'] ?? ''))->first()
                ?? throw ValidationException::withMessages(['unit_code' => __('No unit :code in :building.', ['code' => (string) ($row['unit_code'] ?? ''), 'building' => (string) ($row['building_code'] ?? '')])]);
            $tax = $row['tax_category'] ?? $unit->effectiveTaxCategory()->value;
            $charges = [['type' => 'rent', 'monthly_amount' => $row['rent'] ?? null, 'tax_category' => $tax]];
            foreach (['service_charge', 'parking'] as $type) {
                if (filled($row[$type] ?? null)) {
                    $charges[] = ['type' => $type, 'monthly_amount' => $row[$type], 'tax_category' => $tax];
                }
            }
            if (filled($row['other'] ?? null)) {
                $charges[] = ['type' => 'other', 'description' => $row['other_description'] ?? null, 'monthly_amount' => $row['other'], 'tax_category' => $tax];
            }

            return ['unit_id' => $unit->id, 'deposit_amount' => $row['deposit_amount'] ?? null, 'start_date' => $row['unit_start_date'] ?? null,
                'end_date' => $row['unit_end_date'] ?? null, 'charges' => $charges];
        }, $rows);

        $agreement = $this->agreements->handle($actor, null, [
            ...array_intersect_key($first, array_flip(['start_date', 'end_date', 'frequency', 'billing_day', 'grace_days', 'notice_period_days'])),
            'customer_id' => $customerId,
            'units' => $units,
        ]);
        // SaveAgreement defaults the template for a draft that will be submitted; an imported one never is.
        $agreement->forceFill(['status' => AgreementStatus::PendingApproval, 'import_ref' => $ref, 'contract_template_id' => null])->save();

        Approval::create([
            'approvable_type' => $agreement->getMorphClass(),
            'approvable_id' => $agreement->id,
            'action' => ApprovalAction::AgreementActivation,
            'status' => ApprovalStatus::Approved,
            'requested_by' => $actor->id,
            'requested_at' => now(),
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'comment' => __('Imported by :name', ['name' => $actor->name]),
            'ip' => request()->ip(),
        ]);

        $this->activateAgreement->handle($agreement, $actor, $cutover);
    }

    /** Imported contracts are created active, with an approval recorded as "Imported by {user}" (spec §11). */
    /** @param  array<string, mixed>  $row */
    private function ownerContract(User $actor, array $row, CarbonImmutable $cutover): void
    {
        $buildingId = $this->buildingId($row['building_code'] ?? null);

        $ownerId = Owner::query()->where('id_type', $row['owner_id_type'] ?? '')->where('id_number', $row['owner_id_number'] ?? '')->value('id')
            ?? throw ValidationException::withMessages(['owner_id_number' => __('No owner with ID :type :number.', ['type' => $row['owner_id_type'] ?? '', 'number' => $row['owner_id_number'] ?? ''])]);

        $list = trim((string) ($row['units'] ?? ''));
        $codes = strtoupper($list) === 'ALL' ? null : array_values(array_unique(array_filter(array_map(trim(...), explode(',', $list)))));
        $unitIds = Unit::query()->where('building_id', $buildingId)
            ->when($codes !== null, fn ($q) => $q->whereIn('code', $codes))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($codes !== null && count($unitIds) !== count($codes)) {
            throw ValidationException::withMessages(['units' => __('Unknown unit codes in :list.', ['list' => $list])]);
        }

        $contract = $this->contracts->handle($actor, null, [...$row, 'owner_id' => $ownerId, 'building_id' => $buildingId, 'unit_ids' => $unitIds]);
        $contract->forceFill(['status' => OwnerContractStatus::PendingApproval])->save();

        Approval::create([
            'approvable_type' => $contract->getMorphClass(),
            'approvable_id' => $contract->id,
            'action' => ApprovalAction::OwnerContractActivation,
            'status' => ApprovalStatus::Approved,
            'requested_by' => $actor->id,
            'requested_at' => now(),
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'comment' => __('Imported by :name', ['name' => $actor->name]),
            'ip' => request()->ip(),
        ]);

        $this->activate->handle($contract, $cutover);
    }

    /**
     * Plan rulings 3–4: what the customer owed at cutover, as one issued opening invoice.
     *
     * @param  array<string, mixed>  $row
     */
    private function customerBalance(User $actor, array $row, CarbonImmutable $cutover): void
    {
        if (str_starts_with((string) ($row['amount'] ?? ''), '-')) {
            throw ValidationException::withMessages(['amount' => __('A credit is not imported: enter it as a payment after go-live, reference "Opening credit".')]);
        }
        $v = Validator::make($row, [
            'amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $amount = Fils::toDecimal(Fils::fromDecimal((string) $v['amount']));

        $customer = $this->customerByRow($row);
        [$agreement, $au] = $this->agreementUnitByRow($row, $customer, unitRequired: false);

        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Opening,
            'customer_id' => $customer->id,
            'agreement_id' => $agreement?->id,
            'issue_date' => $cutover->toDateString(),
            'due_date' => $cutover->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => $amount,
            'tax_total' => '0.000',
            'total' => $amount,
            'created_by' => $actor->id,
        ]);
        $invoice->save();
        $invoice->lines()->create([
            'agreement_unit_id' => $au?->id,
            'unit_id' => $au?->unit_id,
            'charge_type' => 'opening_balance',
            'description' => $v['description'] ?? __('Balance brought forward at :d', ['d' => $cutover->format('d/m/Y')]),
            'net' => $amount,
            'tax_category' => TaxCategory::OutOfScope->value, // the old system's figure already includes any VAT billed
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'total' => $amount,
        ]);

        if (! $this->issue->handle($invoice, $actor, autoAllocate: false)) {
            throw ValidationException::withMessages(['unit_code' => __('This balance is held back by a pending owner contract; approve or import that contract first.')]);
        }
    }

    /**
     * Plan ruling 7.
     *
     * @param  array<string, mixed>  $row
     */
    private function ownerBalance(User $actor, array $row, CarbonImmutable $cutover): void
    {
        if (! preg_match('/^-?\d{1,9}(\.\d{1,3})?$/', (string) ($row['amount'] ?? '')) || Fils::fromDecimal((string) $row['amount']) === 0) {
            throw ValidationException::withMessages(['amount' => __('Enter the balance in BHD (negative when the owner owes the company), not zero.')]);
        }
        $amount = Fils::toDecimal(Fils::fromDecimal((string) $row['amount']));

        $ownerId = Owner::query()->where('id_type', $row['owner_id_type'] ?? '')->where('id_number', $row['owner_id_number'] ?? '')->value('id')
            ?? throw ValidationException::withMessages(['owner_id_number' => __('No owner with ID :type :number.', ['type' => $row['owner_id_type'] ?? '', 'number' => $row['owner_id_number'] ?? ''])]);
        $building = (string) ($row['building_code'] ?? '');
        $matches = OwnerContract::query()->effectiveOn($cutover)->where('owner_id', $ownerId)->where('building_id', $this->buildingId($building))
            ->where('type', OwnerContractType::Managed)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['building_code' => __('This owner has more than one managed contract on :b at cutover; split the balance by contract.', ['b' => $building])]);
        }
        $contract = $matches->first()
            ?? throw ValidationException::withMessages(['building_code' => __('This owner has no managed contract on :b at cutover.', ['b' => $building])]);
        if ($contract->charges()->where('type', OwnerChargeType::OpeningBalance)->exists()) {
            throw ValidationException::withMessages(['owner_id_number' => __('Contract :c already has an opening balance.', ['c' => $contract->number])]);
        }

        OwnerCharge::create([
            'owner_contract_id' => $contract->id,
            'type' => OwnerChargeType::OpeningBalance,
            'net' => $amount,
            'tax_amount' => '0.000',
            'amount' => $amount,
            'posted_at' => now(),
            'created_by' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private function customerByRow(array $row): Customer
    {
        return Customer::query()->where('id_type', $row['customer_id_type'] ?? '')->where('id_number', $row['customer_id_number'] ?? '')->first()
            ?? throw ValidationException::withMessages(['customer_id_number' => __('No customer with ID :type :number.', ['type' => $row['customer_id_type'] ?? '', 'number' => $row['customer_id_number'] ?? ''])]);
    }

    /**
     * The imported agreement named by agreement_ref (the customer's), and its unit when building_code/unit_code are given.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: Agreement|null, 1: AgreementUnit|null}
     */
    private function agreementUnitByRow(array $row, Customer $customer, bool $unitRequired): array
    {
        $ref = (string) ($row['agreement_ref'] ?? '');
        if ($ref === '' && (filled($row['building_code'] ?? null) || filled($row['unit_code'] ?? null))) {
            throw ValidationException::withMessages(['agreement_ref' => __('Name the agreement (agreement_ref) for this unit.')]);
        }
        if ($ref === '') {
            return $unitRequired
                ? throw ValidationException::withMessages(['agreement_ref' => __('Name the agreement (agreement_ref).')])
                : [null, null];
        }
        $agreement = Agreement::query()->where('import_ref', $ref)->where('customer_id', $customer->id)->first()
            ?? throw ValidationException::withMessages(['agreement_ref' => __('No imported agreement :ref for this customer.', ['ref' => $ref])]);
        if (blank($row['unit_code'] ?? null) && ! $unitRequired) {
            return [$agreement, null];
        }

        $au = $agreement->agreementUnits()->whereHas('unit', fn ($q) => $q->where('code', (string) ($row['unit_code'] ?? ''))->where('building_id', $this->buildingId($row['building_code'] ?? null)))->first()
            ?? throw ValidationException::withMessages(['unit_code' => __('Unit :u is not on agreement :ref.', ['u' => (string) ($row['unit_code'] ?? ''), 'ref' => $ref])]);

        return [$agreement, $au];
    }

    private function buildingId(mixed $code): int
    {
        return (int) (Building::query()->where('code', (string) $code)->value('id')
            ?? throw ValidationException::withMessages(['building_code' => __('No building with code :code.', ['code' => (string) $code])]));
    }

    /**
     * Cells to strings: dates to Y-m-d (dd/mm/yyyy text too), blanks to null; numbers in Text columns are refused.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalise(ImportKind $kind, array $row): array
    {
        $out = [];

        foreach ($row as $column => $value) {
            if ((is_int($value) || is_float($value)) && in_array($column, $kind->textColumns(), true)) {
                throw ValidationException::withMessages([$column => __('Column :column must be formatted as Text in Excel.', ['column' => $column])]);
            }

            $value = match (true) {
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                is_int($value), is_float($value) => (string) $value,
                is_string($value) => trim($value),
                default => $value,
            };

            if (is_string($value) && str_ends_with((string) $column, '_date') && preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m)) {
                $value = "{$m[3]}-{$m[2]}-{$m[1]}";
            }

            $out[(string) $column] = $value === '' ? null : $value;
        }

        return $out;
    }
}
