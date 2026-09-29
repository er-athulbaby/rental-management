<?php

namespace App\Actions\OwnerContracts;

use App\Models\OwnerContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Spec §4.5: a unit is covered by at most one owner contract on any date. Pending and active contracts
 * are in the spec; ended and terminated ones are included too, because their dates still attribute income (§4.6).
 */
final class EnsureNoOverlap
{
    private const array COVERING = ['pending_approval', 'active', 'ended', 'terminated'];

    public function handle(OwnerContract $contract): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EnsureNoOverlap must run inside the caller\'s transaction.');
        }

        $unitIds = DB::table('owner_contract_units')->where('owner_contract_id', $contract->id)
            ->pluck('unit_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        // Ascending id order, so concurrent submits for the same units queue instead of deadlocking.
        DB::table('units')->whereIn('id', $unitIds)->orderBy('id')->lockForUpdate()->pluck('id');

        $clashes = DB::table('owner_contract_units as ocu')
            ->join('owner_contracts as oc', 'oc.id', '=', 'ocu.owner_contract_id')
            ->join('units as u', 'u.id', '=', 'ocu.unit_id')
            ->whereIn('ocu.unit_id', $unitIds)
            ->where('oc.id', '<>', $contract->id)
            ->when($contract->previous_contract_id, fn ($q, $previous) => $q->where('oc.id', '<>', $previous)) // the successor rule
            ->whereIn('oc.status', self::COVERING)
            ->where('oc.start_date', '<=', $contract->end_date->toDateString())
            ->where('oc.end_date', '>=', $contract->start_date->toDateString())
            ->lockForUpdate()
            ->get(['u.code', 'oc.id', 'oc.number']);

        if ($clashes->isNotEmpty()) {
            throw ValidationException::withMessages(['unit_ids' => __('Already covered on these dates: :list.', [
                'list' => $clashes->map(fn ($c) => $c->code.' ('.($c->number ?? __('pending #:id', ['id' => $c->id])).')')->unique()->implode(', '),
            ])]);
        }
    }
}
