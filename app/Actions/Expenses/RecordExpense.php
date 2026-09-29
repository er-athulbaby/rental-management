<?php

namespace App\Actions\Expenses;

use App\Actions\Documents\StoreDocument;
use App\Enums\ChargeTo;
use App\Enums\DocumentCategory;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\OwnerContractType;
use App\Models\Building;
use App\Models\Expense;
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

/** Spec §4.7, company and owner charges. */
final class RecordExpense
{
    public function __construct(private StoreDocument $documents) {}

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
            // ponytail: charge_to = tenant arrives in M3, where it creates a manual invoice (spec §4.7).
            'charge_to' => ['required', Rule::in([ChargeTo::Company->value, ChargeTo::Owner->value])],
            'owner_approval_note' => ['nullable', 'string', 'max:2000'],
            'owner_approval' => ['nullable', 'file', 'mimes:'.StoreDocument::MIMES, 'max:'.StoreDocument::MAX_KB],
        ])->validate();

        if (! Building::visibleTo($actor)->whereKey($validated['building_id'])->exists()) {
            throw new AuthorizationException;
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

            $expense = new Expense(Arr::only($validated, ['building_id', 'unit_id', 'category', 'description', 'expense_date', 'owner_approval_note']));
            $expense->forceFill([
                'net' => Fils::toDecimal($net),
                'tax_amount' => Fils::toDecimal($tax),
                'total' => Fils::toDecimal($net + $tax),
                'charge_to' => $validated['charge_to'],
                'owner_contract_id' => $contract?->id,
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
}
