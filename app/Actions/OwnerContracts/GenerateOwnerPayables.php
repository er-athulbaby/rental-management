<?php

namespace App\Actions\OwnerContracts;

use App\Billing\BillingPeriods;
use App\Billing\HeadLeaseAmount;
use App\Enums\OwnerContractType;
use App\Enums\OwnerPayableStatus;
use App\Models\CompanySetting;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §7.8: a leased contract's payments to its owner, one per payment period, anchored on its start date. */
final class GenerateOwnerPayables
{
    public function handle(OwnerContract $contract, ?CarbonImmutable $from = null): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('GenerateOwnerPayables must run inside the caller\'s transaction.');
        }
        if ($contract->type !== OwnerContractType::Leased || $contract->payment_frequency === null || $contract->rent_amount === null) {
            return 0;
        }

        $months = $contract->payment_frequency->months();
        $rent = Fils::fromDecimal($contract->rent_amount);
        $basis = CompanySetting::current()->proration_basis;
        $count = 0;

        foreach (BillingPeriods::for($contract->start_date, $contract->end_date, $contract->payment_frequency, null) as $period) {
            if ($from !== null && $period->start->lessThan($from->startOfDay())) {
                continue; // spec §11: periods before cutover were billed in the old system; the anchor stays on start_date
            }
            (new OwnerPayable)->forceFill([
                'owner_contract_id' => $contract->id,
                'period_start' => $period->start->toDateString(),
                'period_end' => $period->end->toDateString(),
                'due_date' => $period->start->toDateString(),
                'amount' => Fils::toDecimal(HeadLeaseAmount::for($period, $rent, $months, $basis)),
                'status' => OwnerPayableStatus::Scheduled,
            ])->save();
            $count++;
        }

        return $count;
    }
}
