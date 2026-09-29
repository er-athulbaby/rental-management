<?php

namespace App\Actions\OwnerContracts;

use App\Actions\NextDocumentNumber;
use App\Enums\NumberSequenceKey;
use App\Enums\OwnerContractStatus;
use App\Models\OwnerContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Pending → active (spec §4.5). No authorisation here: DecideApproval and the importer authorise. */
final class ActivateOwnerContract
{
    public function __construct(private EnsureNoOverlap $overlap, private NextDocumentNumber $next) {}

    public function handle(OwnerContract $contract): OwnerContract
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ActivateOwnerContract must run inside the caller\'s transaction.');
        }

        $this->overlap->handle($contract); // checked again on approval

        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($contract->id);

        if ($contract->status !== OwnerContractStatus::PendingApproval) {
            throw new LogicException("Owner contract {$contract->id} is not pending approval.");
        }

        if ($contract->previous_contract_id !== null) {
            $previous = OwnerContract::query()->lockForUpdate()->findOrFail($contract->previous_contract_id);
            $dayBefore = $contract->start_date->subDay();

            if ($previous->end_date->greaterThan($dayBefore)) {
                if ($previous->status !== OwnerContractStatus::Active) {
                    throw ValidationException::withMessages(['previous_contract_id' => __('The contract this one replaces is no longer active, and their dates overlap.')]);
                }

                $previous->forceFill(['end_date' => $dayBefore])->save();
                // ponytail: M4 cancels the predecessor's head-lease payables after $dayBefore and prorates a straddling one (spec §7.8).
            }
        }

        $contract->forceFill([
            'status' => OwnerContractStatus::Active,
            'number' => ($this->next)(NumberSequenceKey::OwnerContract),
        ])->save();
        // ponytail: M4 generates a leased contract's payment schedule to the owner here (spec §7.8).

        return $contract;
    }
}
