<?php

namespace App\Actions\Deposits;

use App\Enums\DepositSettlementStatus;
use App\Models\Agreement;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\DepositSettlementUnit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §7.7: a draft when occupancy ends (move-out, 02:00 job) or a renewal leaves deposit behind. Idempotent per unit. */
final class CreateDepositSettlement
{
    /** @param  list<int>  $agreementUnitIds */
    public function handle(Agreement $agreement, array $agreementUnitIds, User $actor): ?DepositSettlement
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CreateDepositSettlement must run inside the caller\'s transaction.');
        }

        $taken = DepositSettlementUnit::query()->whereIn('agreement_unit_id', $agreementUnitIds)->pluck('agreement_unit_id')->all();
        $ids = array_values(array_diff($agreementUnitIds, $taken));
        if ($ids === []) {
            return null;
        }

        $settlement = (new DepositSettlement)->forceFill([
            'agreement_id' => $agreement->id,
            'status' => DepositSettlementStatus::Draft,
            'created_by' => $actor->id,
        ]);
        $settlement->save();

        foreach ($ids as $id) {
            $settlement->units()->create(['agreement_unit_id' => $id, 'held_amount' => Fils::toDecimal(DepositMovement::heldFils($id))]);
        }

        return $settlement->load('units');
    }
}
