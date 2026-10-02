<?php

namespace App\Actions\Disbursements;

use App\Models\Disbursement;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §7.2: a payment out to an owner locks owner_contracts, then its payable, before any disbursements row. */
final class LockOwnerSource
{
    public function handle(Disbursement $out): ?OwnerContract
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('LockOwnerSource must run inside the caller\'s transaction.');
        }

        $contractId = match ($out->source_type) {
            Disbursement::SOURCE_PAYABLE => OwnerPayable::query()->whereKey($out->source_id)->value('owner_contract_id'),
            Disbursement::SOURCE_STATEMENT => OwnerStatement::query()->whereKey($out->source_id)->value('owner_contract_id'),
            default => null,
        };
        if ($contractId === null) {
            return null;
        }

        $contract = OwnerContract::query()->lockForUpdate()->findOrFail((int) $contractId);
        if ($out->source_type === Disbursement::SOURCE_PAYABLE) {
            OwnerPayable::query()->lockForUpdate()->findOrFail($out->source_id);
        }
        if ($out->source_type === Disbursement::SOURCE_STATEMENT) {
            OwnerStatement::query()->lockForUpdate()->findOrFail($out->source_id);
        }

        return $contract;
    }
}
