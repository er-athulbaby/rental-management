<?php

namespace App\Actions\Billing;

use App\Billing\BillingPeriod;
use App\Billing\BillingPeriods;
use App\Billing\Proration;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §6.2: one scheduled rent invoice per billing period for the whole term. Runs inside the activation transaction.
 * linesFor() is the one definition of "what a period bills", shared with re-billing (spec §5.7, §6.3).
 */
final class GenerateRentSchedule
{
    /** @return Collection<int, Invoice> */
    public function handle(Agreement $agreement, User $actor): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('GenerateRentSchedule must run inside the caller\'s transaction.');
        }

        $invoices = collect();
        foreach (BillingPeriods::for($agreement->start_date, $agreement->end_date, $agreement->frequency, $agreement->billing_day) as $period) {
            $lines = $this->linesFor($agreement, $period);
            if ($lines !== []) {
                $invoices->push($this->createScheduled($agreement, $period, $lines, $actor));
            }
        }

        return $invoices;
    }

    /**
     * Each agreement unit's charges over its own dates within the period, prorated (spec §6.2).
     *
     * @return list<array<string, mixed>>
     */
    public function linesFor(Agreement $agreement, BillingPeriod $period, ?int $onlyAgreementUnitId = null): array
    {
        $agreement->loadMissing('agreementUnits.charges', 'agreementUnits.unit.building');
        $settings = CompanySetting::current();
        $months = $agreement->frequency->months();
        $lines = [];

        foreach ($agreement->agreementUnits as $au) {
            if ($onlyAgreementUnitId !== null && $au->id !== $onlyAgreementUnitId) {
                continue;
            }
            $from = $au->start_date->max($period->start);
            $to = $au->end_date->min($period->end);

            foreach ($au->charges as $charge) {
                $net = Proration::line($period, $au->start_date, $au->end_date, Fils::fromDecimal($charge->monthly_amount), $months, $settings->proration_basis);
                if ($net === 0) {
                    continue;
                }

                $lines[] = [
                    'agreement_unit_id' => $au->id,
                    'agreement_unit_charge_id' => $charge->id,
                    'unit_id' => $au->unit_id,
                    'charge_type' => $charge->type->value,
                    'description' => sprintf('%s — %s / %s, %s–%s', $charge->description ?: $charge->type->label(),
                        $au->unit->building->code, $au->unit->code, $from->format('d/m/Y'), $to->format('d/m/Y')),
                    'period_start' => $from->toDateString(),
                    'period_end' => $to->toDateString(),
                    'net' => Fils::toDecimal($net),
                    'tax_category' => $charge->tax_category->value,
                    'tax_rate' => '0.00',
                    'tax_amount' => '0.000',
                    'total' => Fils::toDecimal($net), // tax is written at issue (spec §6.3)
                ];
            }
        }

        return $lines;
    }

    /** @param  list<array<string, mixed>>  $lines */
    public function createScheduled(Agreement $agreement, BillingPeriod $period, array $lines, User $actor): Invoice
    {
        $settings = CompanySetting::current();
        $sum = array_sum(array_map(fn (array $l) => Fils::fromDecimal($l['net']), $lines));
        $due = $period->start; // rent is billed in advance
        $issue = $due->subDays($settings->invoice_lead_days)->max(CarbonImmutable::now('Asia/Bahrain')->startOfDay());

        // Created as draft, lines inserted, then scheduled: lines may only be inserted into a draft (spec §8.5).
        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Rent,
            'customer_id' => $agreement->customer_id,
            'agreement_id' => $agreement->id,
            'period_start' => $period->start->toDateString(),
            'period_end' => $period->end->toDateString(),
            'issue_date' => $issue->toDateString(),
            'due_date' => $due->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => Fils::toDecimal($sum),
            'tax_total' => '0.000',
            'total' => Fils::toDecimal($sum),
            'created_by' => $actor->id,
        ]);
        $invoice->save();
        $invoice->lines()->createMany($lines);
        $invoice->forceFill(['status' => InvoiceStatus::Scheduled])->save();

        return $invoice;
    }
}
