<?php

namespace App\Actions\Expenses;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Documents\StoreDocument;
use App\Enums\AgreementStatus;
use App\Enums\ChargeTo;
use App\Enums\ChargeType;
use App\Enums\DocumentCategory;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceChargeType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OwnerContractType;
use App\Enums\PermissionName;
use App\Enums\TaxCategory;
use App\Models\AgreementUnit;
use App\Models\Building;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\OwnerContract;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §4.7, company, owner and tenant charges. */
final class RecordExpense
{
    public function __construct(private StoreDocument $documents, private IssueInvoice $issue) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, array $data, ?UploadedFile $ownerApproval = null): Expense
    {
        if (! $actor->can('create', Expense::class)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data);

        $validated = Validator::make([...$data, 'owner_approval' => $ownerApproval], [
            'building_id' => ['required', 'integer', Rule::exists('buildings', 'id')->whereNull('deleted_at')],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('building_id', (int) ($data['building_id'] ?? 0))->whereNull('deleted_at')],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'max:500'],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'net' => ['required', Fils::rule()],
            'tax_amount' => ['nullable', Fils::rule()],
            'charge_to' => ['required', Rule::enum(ChargeTo::class)],
            'owner_approval_note' => ['nullable', 'string', 'max:2000'],
            'owner_approval' => ['nullable', 'file', 'mimes:'.StoreDocument::MIMES, 'max:'.StoreDocument::MAX_KB],
        ])->validate();

        if (! Building::visibleTo($actor)->whereKey($validated['building_id'])->exists()) {
            throw new AuthorizationException;
        }

        if ($validated['charge_to'] === ChargeTo::Tenant->value) {
            if (! $actor->can(PermissionName::InvoicesManage)) {
                throw new AuthorizationException; // spec §4.7: tenant charges also need invoices.manage
            }
            if (blank($validated['unit_id'] ?? null)) {
                throw ValidationException::withMessages(['unit_id' => __('Choose the unit whose tenant is charged.')]);
            }
        }

        $net = Fils::fromDecimal((string) $validated['net']);
        $tax = Fils::fromDecimal((string) ($validated['tax_amount'] ?? '0'));

        if ($net + $tax === 0) {
            throw ValidationException::withMessages(['net' => __('Enter the amount.')]);
        }

        return DB::transaction(function () use ($actor, $validated, $net, $tax, $ownerApproval) {
            $contract = $validated['charge_to'] === ChargeTo::Owner->value ? $this->ownerContract($validated) : null;

            if ($contract?->expense_approval_limit !== null && $net + $tax > Fils::fromDecimal($contract->expense_approval_limit)
                && (blank($validated['owner_approval_note'] ?? null) || $ownerApproval === null)) {
                throw ValidationException::withMessages(['owner_approval_note' => __('Above the owner\'s approval limit of :limit BHD: add the owner\'s approval note and document.', ['limit' => $contract->expense_approval_limit])]);
            }

            $tenant = $validated['charge_to'] === ChargeTo::Tenant->value ? $this->tenantInvoice($actor, $validated, $net) : null;

            $expense = new Expense(Arr::only($validated, ['building_id', 'unit_id', 'category', 'description', 'expense_date', 'owner_approval_note']));
            $expense->forceFill([
                'net' => Fils::toDecimal($net),
                'tax_amount' => Fils::toDecimal($tax),
                'total' => Fils::toDecimal($net + $tax),
                'charge_to' => $validated['charge_to'],
                'owner_contract_id' => $contract?->id,
                'agreement_unit_id' => $tenant?->lines->sole()->agreement_unit_id,
                'invoice_id' => $tenant?->id,
                'status' => ExpenseStatus::Recorded,
                'posted_at' => now(),
                'recorded_by' => $actor->id,
            ])->save();
            // ponytail: M4 posts owner-charged expenses to the owner ledger (spec §7.9).

            if ($ownerApproval) {
                $this->documents->handle($actor, $expense, $ownerApproval, DocumentCategory::OwnerApproval);
            }

            return $expense;
        });
    }

    /** @param  array<string, mixed>  $v */
    private function ownerContract(array $v): OwnerContract
    {
        $unitId = $v['unit_id'] ?? null;

        $contracts = OwnerContract::query()
            ->effectiveOn((string) $v['expense_date'])
            ->where('type', OwnerContractType::Managed)
            ->where('building_id', $v['building_id'])
            ->when($unitId, fn ($q) => $q->whereHas('units', fn ($units) => $units->whereKey($unitId)))
            ->get();

        if ($contracts->count() !== 1) {
            throw ValidationException::withMessages(['charge_to' => $unitId
                ? __('No managed owner contract covers this unit on that date.')
                : __('Charging the owner without a unit needs exactly one managed contract in the building on that date; enter one expense per unit instead.')]);
        }

        return $contracts->firstOrFail();
    }

    /**
     * Spec §4.7: a manual invoice for the expense's net, taxed by the unit's category at issue. The line has no unit_id,
     * so it is never owner-attributed or held back (§4.6 "stamped NULL"); agreement_unit_id records whose charge it is.
     *
     * @param  array<string, mixed>  $v
     */
    private function tenantInvoice(User $actor, array $v, int $net): Invoice
    {
        $date = (string) $v['expense_date'];
        $au = AgreementUnit::query()
            ->with(['agreement', 'unit', 'charges'])
            ->where('unit_id', $v['unit_id'])
            ->whereHas('agreement', fn ($q) => $q->where('status', AgreementStatus::Active))
            ->where('start_date', '<=', $date)
            ->whereRaw('COALESCE(move_out_date, end_date) >= ?', [$date])
            ->first();

        if ($au === null) {
            throw ValidationException::withMessages(['unit_id' => __('No active agreement covers this unit on that date.')]);
        }

        $category = $au->unit->default_tax_category
            ?? $au->charges->where('type', ChargeType::Rent)->first()->tax_category
            ?? TaxCategory::Exempt;

        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Manual,
            'customer_id' => $au->agreement->customer_id,
            'agreement_id' => $au->agreement_id,
            'issue_date' => now('Asia/Bahrain')->toDateString(),
            'due_date' => now('Asia/Bahrain')->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => Fils::toDecimal($net),
            'tax_total' => '0.000',
            'total' => Fils::toDecimal($net),
            'created_by' => $actor->id,
        ]);
        $invoice->save();

        $invoice->lines()->create([
            'agreement_unit_id' => $au->id,
            'unit_id' => null,
            'charge_type' => match ($v['category']) {
                ExpenseCategory::Utilities->value => InvoiceChargeType::Utilities,
                ExpenseCategory::Cleaning->value => InvoiceChargeType::Cleaning,
                default => InvoiceChargeType::Damage,
            },
            'description' => (string) $v['description'],
            'net' => Fils::toDecimal($net),
            'tax_category' => $category,
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'total' => Fils::toDecimal($net),
        ]);

        $this->issue->handle($invoice, $actor); // never held back: the line has no unit

        return Invoice::with('lines')->findOrFail($invoice->id);
    }
}
