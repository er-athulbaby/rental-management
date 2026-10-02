<?php

namespace App\Actions\OwnerStatements;

use App\Billing\OwnerLedger;
use App\Billing\OwnerStatementCalculator;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\OwnerStatementStatus;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Spec §7.9, §12: on the 1st, a draft statement for the previous month for every managed contract that was active on
 * any day of it, or has ledger entries after its last statement's cutoff. Idempotent: one statement per contract and month.
 */
final class DraftOwnerStatements
{
    public function handle(CarbonImmutable $month): int
    {
        $start = $month->startOfMonth()->startOfDay();
        $end = $month->endOfMonth()->startOfDay();
        $cutoff = CarbonImmutable::parse($end->toDateString().' 23:59:59', 'Asia/Bahrain');

        $ids = OwnerContract::query()->where('type', OwnerContractType::Managed)
            ->whereIn('status', [OwnerContractStatus::Active, OwnerContractStatus::Ended, OwnerContractStatus::Terminated])
            ->where('start_date', '<=', $end->toDateString())->orderBy('id')->pluck('id');

        $created = 0;
        foreach ($ids as $id) {
            try {
                $created += DB::transaction(fn () => $this->draftOne($id, $start, $end, $cutoff), attempts: 3);
            } catch (Throwable $e) {
                report($e); // one contract's failure doesn't stop the others' statements
            }
        }

        return $created;
    }

    private function draftOne(int $id, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $cutoff): int
    {
        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($id);
        if ($contract->statements()->where('period_start', $start->toDateString())->exists()) {
            return 0;
        }

        $last = $contract->statements()->where('period_start', '<', $start->toDateString())->orderByDesc('period_start')->first();
        $activeInMonth = $contract->end_date->greaterThanOrEqualTo($start);
        if (! $activeInMonth && OwnerLedger::entries($contract, $last?->cutoff_at, $cutoff)->isEmpty()) {
            return 0;
        }

        $statement = (new OwnerStatement)->forceFill([
            'owner_contract_id' => $contract->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'cutoff_at' => $cutoff,
            'status' => OwnerStatementStatus::Draft,
            'created_by' => $contract->created_by, // plan ruling 8, until M5 adds a system user
        ]);
        $statement->setRelation('contract', $contract);
        OwnerStatementCalculator::apply($statement);
        $statement->save();

        return 1;
    }
}
