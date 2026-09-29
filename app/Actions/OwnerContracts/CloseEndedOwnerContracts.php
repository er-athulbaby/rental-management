<?php

namespace App\Actions\OwnerContracts;

use App\Enums\OwnerContractStatus;
use App\Models\OwnerContract;
use Illuminate\Support\Facades\DB;

/** The 02:15 job (spec §4.5, §12). Idempotent: only active contracts past their end date move. */
final class CloseEndedOwnerContracts
{
    public function __invoke(): int
    {
        $today = now('Asia/Bahrain')->toDateString();

        return DB::transaction(fn () => OwnerContract::query()
            ->where('status', OwnerContractStatus::Active)
            ->where('end_date', '<', $today)
            ->lockForUpdate()
            ->get()
            ->each(fn (OwnerContract $contract) => $contract->forceFill([
                'status' => $contract->terminated_on ? OwnerContractStatus::Terminated : OwnerContractStatus::Ended,
            ])->save())
            ->count());
    }
}
