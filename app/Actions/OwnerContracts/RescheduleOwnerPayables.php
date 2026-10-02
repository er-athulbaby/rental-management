<?php

namespace App\Actions\OwnerContracts;

use App\Billing\BillingPeriod;
use App\Billing\HeadLeaseAmount;
use App\Enums\OwnerPayableStatus;
use App\Models\CompanySetting;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §4.5, §7.8, plan ruling 3: after a leased contract's end date moves earlier, scheduled payables starting after it
 * are cancelled and a scheduled one straddling it is replaced by a prorated one. Paid payables stay as they are.
 */
final class RescheduleOwnerPayables
{
    public function handle(OwnerContract $locked, CarbonImmutable $newEnd): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RescheduleOwnerPayables must run inside the caller\'s transaction.');
        }
        if ($locked->payment_frequency === null || $locked->rent_amount === null) {
            return;
        }

        $end = $newEnd->toDateString();
        $months = $locked->payment_frequency->months();
        $rent = Fils::fromDecimal($locked->rent_amount);
        $basis = CompanySetting::current()->proration_basis;

        $affected = OwnerPayable::query()->where('owner_contract_id', $locked->id)->where('status', OwnerPayableStatus::Scheduled)
            ->where('period_end', '>', $end)->orderBy('id')->lockForUpdate()->get();

        foreach ($affected as $payable) {
            $payable->forceFill(['status' => OwnerPayableStatus::Cancelled])->save();
            if ($payable->period_start->toDateString() > $end) {
                continue; // wholly after the new end
            }

            $replacement = (new OwnerPayable)->forceFill([
                'owner_contract_id' => $locked->id,
                'period_start' => $payable->period_start->toDateString(),
                'period_end' => $end,
                'due_date' => $payable->period_start->toDateString(),
                'amount' => Fils::toDecimal(HeadLeaseAmount::for(new BillingPeriod($payable->period_start, $newEnd, false), $rent, $months, $basis)),
                'status' => OwnerPayableStatus::Scheduled,
            ]);
            $replacement->save();
            $payable->forceFill(['replaced_by_payable_id' => $replacement->id])->save();
        }
    }
}
