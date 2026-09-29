<?php

namespace App\Actions\OwnerContracts;

use App\Audit\Audit;
use App\Enums\DepositsHeldBy;
use App\Enums\FeeType;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\PaymentFrequency;
use App\Models\Building;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Creates or edits a DRAFT owner contract (spec §4.5). Submitted contracts are frozen. */
final class SaveOwnerContract
{
    /** @param  array<string, mixed>  $data  including unit_ids: list<int> */
    public function handle(User $actor, ?OwnerContract $contract, array $data): OwnerContract
    {
        if (! ($contract ? $actor->can('update', $contract) : $actor->can('create', OwnerContract::class))) {
            throw new AuthorizationException;
        }

        if ($contract && $contract->status !== OwnerContractStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('Only draft contracts can be edited.')]);
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        $validated = Validator::make($data, [
            'owner_id' => ['required', 'integer', Rule::exists('owners', 'id')->whereNull('deleted_at')],
            'building_id' => ['required', 'integer', Rule::exists('buildings', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::enum(OwnerContractType::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'previous_contract_id' => ['nullable', 'integer'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'distinct'],
            'rent_amount' => ['exclude_unless:type,leased', 'required', Fils::rule()],
            'payment_frequency' => ['exclude_unless:type,leased', 'required', Rule::enum(PaymentFrequency::class)],
            'fee_type' => ['exclude_unless:type,managed', 'required', Rule::enum(FeeType::class)],
            'fee_value' => ['exclude_unless:type,managed', 'required', Fils::rule()],
            'expense_approval_limit' => ['exclude_unless:type,managed', 'nullable', Fils::rule()],
            'deposits_held_by' => ['exclude_unless:type,managed', 'required', Rule::enum(DepositsHeldBy::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->after(fn (ValidatorInstance $validator) => $this->checkTerms($validator, $data, $contract))->validate();

        if (! Building::visibleTo($actor)->whereKey($validated['building_id'])->exists()) {
            throw new AuthorizationException;
        }

        $unitIds = array_map(intval(...), $validated['unit_ids']);
        unset($validated['unit_ids']);

        foreach (['rent_amount', 'fee_value', 'expense_approval_limit'] as $money) {
            if (isset($validated[$money])) {
                $validated[$money] = Fils::toDecimal(Fils::fromDecimal((string) $validated[$money]));
            }
        }

        return DB::transaction(function () use ($actor, $contract, $validated, $unitIds) {
            $contract ??= (new OwnerContract)->forceFill(['created_by' => $actor->id]);

            // Clear the other type's terms, and optional fields left out of $data.
            $contract->fill([
                ...array_fill_keys([...OwnerContract::LEASED_TERMS, ...OwnerContract::MANAGED_TERMS], null),
                'previous_contract_id' => null,
                'notes' => null,
                ...$validated,
            ])->save();

            $changes = $contract->units()->sync($unitIds);

            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                Audit::log('owner_contract.units_changed', $contract,
                    ['detached' => array_values($changes['detached'])],
                    ['attached' => array_values($changes['attached'])],
                    causer: $actor,
                );
            }

            return $contract;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function checkTerms(ValidatorInstance $validator, array $data, ?OwnerContract $contract): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $unitIds = array_map(intval(...), (array) $data['unit_ids']);
        if (Unit::query()->where('building_id', $data['building_id'])->whereKey($unitIds)->count() !== count($unitIds)) {
            $validator->errors()->add('unit_ids', __('Every unit must belong to the chosen building.'));
        }

        if ($data['type'] === OwnerContractType::Leased->value && Fils::fromDecimal((string) $data['rent_amount']) === 0) {
            $validator->errors()->add('rent_amount', __('Rent must be more than zero.'));
        }

        if ($data['type'] === OwnerContractType::Managed->value && $data['fee_type'] !== FeeType::Fixed->value
            && Fils::fromDecimal((string) $data['fee_value']) > 100_000) {
            $validator->errors()->add('fee_value', __('A percentage fee cannot exceed 100.'));
        }

        if (filled($data['previous_contract_id'] ?? null)) {
            $previous = OwnerContract::query()->whereKey($data['previous_contract_id'])->first();

            if (! $previous
                || $previous->id === $contract?->id
                || $previous->status !== OwnerContractStatus::Active
                || $previous->owner_id !== (int) $data['owner_id']
                || $previous->building_id !== (int) $data['building_id']
                || $data['start_date'] <= $previous->start_date->toDateString()) {
                $validator->errors()->add('previous_contract_id', __('A successor must follow an active contract of the same owner and building, and start after it.'));
            }
        }
    }
}
