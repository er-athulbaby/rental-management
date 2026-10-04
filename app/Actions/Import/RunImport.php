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
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\DepositMovementType;
use App\Enums\ImportKind;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OwnerChargeType;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\ParkingType;
use App\Enums\PermissionName;
use App\Enums\TaxCategory;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\Approval;
use App\Models\Bank;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Owner;
use App\Models\OwnerCharge;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Spec §11. Dry run and real run are the same code: every row goes through the normal Actions
 * inside one transaction, which is committed only when asked AND every row of every file passed.
 */
final class RunImport
{
    /** Opening balances import once: stages may commit separately, so a second balances file would still reconcile. */
    private bool $openingImported = false;

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
        $this->openingImported = Invoice::query()->where('type', InvoiceType::Opening)->exists();

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
            ImportKind::Buildings => $this->buildings->handle($actor, null, self::buildingRow($row)),
            ImportKind::Units => $this->units->handle($actor, null, [
                ...$row,
                'type' => UnitType::canonical((string) ($row['type'] ?? '')) ?? $row['type'] ?? null,
                'building_id' => $this->buildingId($row['building_code'] ?? null),
                'blocked' => in_array(strtolower((string) ($row['blocked'] ?? '')), ['yes', 'y', 'true', '1'], true),
            ]),
            ImportKind::Owners => $this->owners->handle($actor, null, self::listRow($row), viaImport: true),
            ImportKind::OwnerContracts => $this->ownerContract($actor, $row, $cutover),
            ImportKind::Customers => $this->customers->handle($actor, null, self::listRow($row)),
            ImportKind::Agreements => $this->agreement($actor, $rows, $cutover),
            ImportKind::CustomerBalances => $this->customerBalance($actor, $row, $cutover),
            ImportKind::DepositsHeld => $this->depositHeld($actor, $rows[0], $cutover),
            ImportKind::Cheques => $this->cheque($actor, self::listRow($rows[0])),
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
        Validator::make(['import_ref' => $ref], ['import_ref' => ['string', 'max:40']])->validate();
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
            ?? throw ValidationException::withMessages(['customer_id_number' => __('No tenant with ID :type :number.', ['type' => $first['customer_id_type'] ?? '', 'number' => $first['customer_id_number'] ?? ''])]);

        $units = array_map(function (array $row) use ($ref, $cutover) {
            $unit = Unit::query()->where('building_id', $this->buildingId($row['building_code'] ?? null))->where('code', (string) ($row['unit_code'] ?? ''))->first()
                ?? throw ValidationException::withMessages(['unit_code' => __('No unit :code in :building.', ['code' => (string) ($row['unit_code'] ?? ''), 'building' => (string) ($row['building_code'] ?? '')])]);
            if (filled($row['unit_end_date'] ?? null) && (string) $row['unit_end_date'] < $cutover->toDateString()) {
                throw ValidationException::withMessages(['unit_end_date' => __('Unit :u on :ref ended before cutover: do not import it.', ['u' => $unit->code, 'ref' => $ref])]);
            }
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
        if ($this->openingImported) {
            throw ValidationException::withMessages(['customer_id_number' => __('Opening balances were already imported; they can be imported only once.')]);
        }
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
        if ($agreement !== null && $au === null) { // the unit decides the owner attribution (§4.6)
            $aus = $agreement->agreementUnits()->get();
            $au = $aus->count() === 1 ? $aus->sole() : throw ValidationException::withMessages(['unit_code' => __('Name the unit (building_code, unit_code): agreement :ref has more than one.', ['ref' => $agreement->import_ref])]);
        }

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

    /**
     * Spec §11, §7.6: the deposit each agreement unit holds at cutover.
     *
     * @param  array<string, mixed>  $row
     */
    private function depositHeld(User $actor, array $row, CarbonImmutable $cutover): void
    {
        $v = Validator::make($row, ['amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/']])->validate();
        $au = $this->agreementUnitByRow($row, $this->customerByRow($row), unitRequired: true)[1]
            ?? throw new LogicException('unitRequired always yields a unit');
        if (DepositMovement::query()->where('agreement_unit_id', $au->id)->exists()) {
            throw ValidationException::withMessages(['unit_code' => __('Unit :u on :ref already has its deposit.', ['u' => (string) $row['unit_code'], 'ref' => (string) $row['agreement_ref']])]);
        }

        DepositMovement::create([
            'agreement_unit_id' => $au->id,
            // Spec §7.6: an opening movement is stamped by the §4.6 rule on the cutover date, and never recomputed.
            'owner_contract_id' => OwnerContract::query()->effectiveOn($cutover)->whereHas('units', fn ($q) => $q->whereKey($au->unit_id))->value('owner_contracts.id'),
            'type' => DepositMovementType::Opening,
            'amount' => Fils::toDecimal(Fils::fromDecimal((string) $v['amount'])),
            'source_type' => 'import',
            'source_id' => $au->agreement_id,
            'posted_at' => now(),
        ]);
    }

    /**
     * Spec §7.4: a post-dated cheque still held at cutover (plan ruling 2: RecordCheques' rules, the import's authority).
     *
     * @param  array<string, mixed>  $row
     */
    private function cheque(User $actor, array $row): void
    {
        $v = Validator::make($row, [
            'cheque_no' => ['required', 'string', 'max:30'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_holder' => ['nullable', 'string', 'max:150'],
            'cheque_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $customer = $this->customerByRow($row);
        [$agreement] = $this->agreementUnitByRow($row, $customer, unitRequired: false);
        if (Cheque::query()->where('customer_id', $customer->id)->where('cheque_no', $v['cheque_no'])->where('bank_name', $v['bank_name'])->exists()) {
            throw ValidationException::withMessages(['cheque_no' => __('Cheque :no of :bank was already imported for this tenant.', ['no' => $v['cheque_no'], 'bank' => $v['bank_name']])]);
        }

        (new Cheque)->forceFill([
            'direction' => ChequeDirection::Received,
            'customer_id' => $customer->id,
            'agreement_id' => $agreement?->id,
            'cheque_no' => $v['cheque_no'],
            'bank_name' => $v['bank_name'],
            'account_holder' => $v['account_holder'] ?? null,
            'cheque_date' => $v['cheque_date'],
            'amount' => Fils::toDecimal(Fils::fromDecimal((string) $v['amount'])),
            'notes' => $v['notes'] ?? null,
            'status' => ChequeStatus::Held,
            'created_by' => $actor->id,
        ])->save();
    }

    /** @param  array<string, mixed>  $row */
    private function customerByRow(array $row): Customer
    {
        return Customer::query()->where('id_type', $row['customer_id_type'] ?? '')->where('id_number', $row['customer_id_number'] ?? '')->first()
            ?? throw ValidationException::withMessages(['customer_id_number' => __('No tenant with ID :type :number.', ['type' => $row['customer_id_type'] ?? '', 'number' => $row['customer_id_number'] ?? ''])]);
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
            ?? throw ValidationException::withMessages(['agreement_ref' => __('No imported agreement :ref for this tenant.', ['ref' => $ref])]);
        if (blank($row['unit_code'] ?? null) && ! $unitRequired) {
            return [$agreement, null];
        }

        $au = $agreement->agreementUnits()->whereHas('unit', fn ($q) => $q->where('code', (string) ($row['unit_code'] ?? ''))->where('building_id', $this->buildingId($row['building_code'] ?? null)))->first()
            ?? throw ValidationException::withMessages(['unit_code' => __('Unit :u is not on agreement :ref.', ['u' => (string) ($row['unit_code'] ?? ''), 'ref' => $ref])]);

        return [$agreement, $au];
    }

    /**
     * The template's parking and facilities are words: parking is matched to a choice by its value or label,
     * and facilities are comma-separated names from the Admin's list.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function buildingRow(array $row): array
    {
        $parking = trim((string) ($row['parking'] ?? ''));
        if ($parking !== '') {
            $match = collect(ParkingType::cases())->first(fn (ParkingType $p) => strcasecmp($parking, $p->value) === 0 || strcasecmp($parking, $p->label()) === 0);
            $row['parking'] = ($match ?? throw ValidationException::withMessages(['parking' => __('Parking ":p" is not one of: :list.', [
                'p' => $parking, 'list' => collect(ParkingType::cases())->map(fn (ParkingType $p) => $p->label())->implode(', '),
            ])]))->value;
        }

        $names = array_values(array_filter(array_map(trim(...), explode(',', (string) ($row['facilities'] ?? '')))));
        $facilities = Facility::query()->where('active', true)->whereIn('name', $names)->pluck('id', 'name');
        $unknown = array_udiff($names, $facilities->keys()->all(), 'strcasecmp');
        if ($unknown !== []) {
            throw ValidationException::withMessages(['facilities' => __('Unknown facilities: :list. Add them under Administration → Facilities first.', ['list' => implode(', ', $unknown)])]);
        }
        unset($row['facilities']);

        return [...$row, 'facility_ids' => $facilities->values()->all()];
    }

    /**
     * Nationality and bank are words matched to their lists whatever the case, and stored as the list spells them.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function listRow(array $row): array
    {
        if (filled($n = $row['nationality'] ?? null)) {
            $row['nationality'] = array_find(config()->array('nationalities'), fn (mixed $item) => is_string($item) && strcasecmp($item, (string) $n) === 0)
                ?? throw ValidationException::withMessages(['nationality' => __("Nationality ':n' is not in the list.", ['n' => $n])]);
        }
        if (filled($b = $row['bank_name'] ?? null)) {
            $row['bank_name'] = Bank::canonical((string) $b)
                ?? throw ValidationException::withMessages(['bank_name' => __("Unknown bank ':b'. Add it under Administration → Banks first.", ['b' => $b])]);
        }

        return $row;
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
