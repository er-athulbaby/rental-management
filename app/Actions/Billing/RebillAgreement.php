<?php

namespace App\Actions\Billing;

use App\Billing\AllocationTax;
use App\Billing\InvoicePeriod;
use App\Billing\RebillResult;
use App\Billing\Tax;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §5.7 billing effect and §6.3 cancel-and-replace. Internal: the caller holds the customer and agreement locks and
 * has already applied the new unit dates. Locks: cheques → scheduled invoices (ascending) → per credit note (one per
 * affected invoice, ascending): its issued lines → that issued invoice → the credit note. A later credit note's lines are
 * locked after an earlier invoice; that is safe because the caller holds the customer lock, which serialises every
 * writer of this customer's invoices (spec §7.2).
 */
final class RebillAgreement
{
    public function __construct(
        private GenerateRentSchedule $schedule,
        private BuildCreditNote $buildCreditNote,
        private IssueCreditNote $issueCreditNote,
        private IssueInvoice $issue,
    ) {}

    public function handle(Agreement $agreement, CarbonImmutable $effectiveDate, User $actor, string $reason): RebillResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RebillAgreement must run inside the caller\'s transaction.');
        }

        $agreement = Agreement::query()->with('agreementUnits.charges', 'agreementUnits.unit.building')->findOrFail($agreement->id);
        $from = $effectiveDate->toDateString();

        // 1. Scheduled rent invoices ending on or after the effective date: cancel, replace if anything is left, move cheques.
        $scheduled = Invoice::query()->where('agreement_id', $agreement->id)->where('type', InvoiceType::Rent)
            ->where('status', InvoiceStatus::Scheduled)->where('period_end', '>=', $from)->orderBy('id')->pluck('id');
        $cheques = Cheque::query()->whereIn('invoice_id', $scheduled)->whereIn('status', [ChequeStatus::Held, ChequeStatus::Deposited])
            ->orderBy('id')->lockForUpdate()->get();

        $cancelled = 0;
        $replaced = 0;
        $toReturn = [];
        foreach (Invoice::query()->whereKey($scheduled)->orderBy('id')->lockForUpdate()->get() as $old) {
            $period = InvoicePeriod::of($agreement, $old);
            $lines = $this->schedule->linesFor($agreement, $period);
            $old->forceFill(['status' => InvoiceStatus::Cancelled])->save();
            $cancelled++;

            $new = $lines === [] ? null : $this->schedule->createScheduled($agreement, $period, $lines, $actor);
            if ($new) {
                $old->forceFill(['replaced_by_invoice_id' => $new->id])->save();
                $replaced++;
            }
            foreach ($cheques->where('invoice_id', $old->id) as $cheque) {
                $cheque->forceFill(['invoice_id' => $new?->id])->save();
                if ($new === null) {
                    $toReturn[] = $cheque->id;
                }
            }
        }

        // 2. Issued rent periods ending on or after the effective date: per charge, compare what is still billed for the period
        //    (the rent invoice and earlier re-billing manual invoices, less their credit notes) with what is correct now;
        //    credit an excess against the lines holding it, bill a shortfall on one manual invoice.
        $issued = Invoice::query()->where('agreement_id', $agreement->id)->where('type', InvoiceType::Rent)
            ->where('status', InvoiceStatus::Issued)->where('period_end', '>=', $from)->orderBy('id')->get();
        foreach ($issued as $invoice) {
            if (InvoiceLine::query()->where('invoice_id', $invoice->id)->whereNull('agreement_unit_charge_id')->exists()) {
                throw new LogicException("Issued rent invoice {$invoice->number} has lines without a charge; cannot re-bill");
            }
        }

        $entries = []; // target invoice id => credit entries
        $extra = [];
        foreach ($issued as $invoice) {
            $period = InvoicePeriod::of($agreement, $invoice);
            $desired = collect($this->schedule->linesFor($agreement, $period))->keyBy('agreement_unit_charge_id');
            $billed = InvoiceLine::query()->whereNotNull('agreement_unit_charge_id')
                ->whereHas('invoice', fn ($q) => $q->where('agreement_id', $agreement->id)->whereIn('type', [InvoiceType::Rent, InvoiceType::Manual])->where('status', InvoiceStatus::Issued))
                ->where('period_start', '<=', $period->end->toDateString())->where('period_end', '>=', $period->start->toDateString())
                ->orderByDesc('id')->get()->groupBy('agreement_unit_charge_id');

            foreach ($desired->keys()->merge($billed->keys())->unique() as $chargeId) {
                $row = $desired->get($chargeId);
                $correct = $row === null ? 0 : Fils::fromDecimal((string) $row['net']);
                $lines = $billed->get($chargeId) ?? collect();
                $gap = $lines->sum(fn (InvoiceLine $l) => self::netLeft($l)) - $correct;

                if ($gap < 0 && $row !== null) { // a unit added inside an already-issued period (add_unit)
                    $extra[] = [...$row, 'net' => Fils::toDecimal(-$gap), 'total' => Fils::toDecimal(-$gap)];

                    continue;
                }
                foreach ($lines as $line) { // newest line first
                    if ($gap <= 0) {
                        break;
                    }
                    $netLeft = self::netLeft($line);
                    $cut = min($gap, $netLeft);
                    $gap -= $cut;
                    // Plan ruling 4: the line keeps the gross of its correct net (its tax at the line's rate); keeping
                    // nothing takes exactly what is left.
                    $keep = $netLeft - $cut;
                    $gross = Fils::fromDecimal($line->total) - Fils::fromDecimal((string) $line->credited)
                        - ($keep + Tax::amount($keep, $line->tax_category, (float) $line->tax_rate > 0, (string) $line->tax_rate));
                    if ($gross > 0) {
                        $entries[$line->invoice_id][] = [$line, $gross];
                    }
                }
            }
        }

        // One credit note per affected invoice (rent or manual), ascending.
        ksort($entries);
        $creditNotes = [];
        foreach ($entries as $invoiceId => $invoiceEntries) {
            $cn = $this->buildCreditNote->handle(Invoice::query()->findOrFail($invoiceId), $invoiceEntries, $reason, $actor);
            $cn->forceFill(['status' => InvoiceStatus::PendingApproval])->save();
            $this->issueCreditNote->handle($cn, $actor);
            $creditNotes[] = $cn->id;
        }

        // One manual invoice for everything newly billed in issued periods (spec §5.7).
        $manualId = null;
        if ($extra !== []) {
            $sum = array_sum(array_map(fn (array $l) => Fils::fromDecimal($l['net']), $extra));
            $manual = (new Invoice)->forceFill([
                'type' => InvoiceType::Manual,
                'customer_id' => $agreement->customer_id,
                'agreement_id' => $agreement->id,
                'issue_date' => now('Asia/Bahrain')->toDateString(),
                'due_date' => now('Asia/Bahrain')->toDateString(),
                'status' => InvoiceStatus::Draft,
                'subtotal' => Fils::toDecimal($sum),
                'tax_total' => '0.000',
                'total' => Fils::toDecimal($sum),
                'created_by' => $actor->id,
            ]);
            $manual->save();
            $manual->lines()->createMany($extra);
            $this->issue->handle($manual, $actor); // a hold-back (§4.6) leaves it draft for Finance to issue later
            $manualId = $manual->id;
        }

        return new RebillResult($cancelled, $replaced, $creditNotes, $manualId, $toReturn);
    }

    /** The net a line still bills after its credit notes: its credited gross less the tax share already credited. */
    private static function netLeft(InvoiceLine $line): int
    {
        $total = Fils::fromDecimal($line->total);
        $credited = Fils::fromDecimal((string) $line->credited);

        return Fils::fromDecimal($line->net) - ($credited - AllocationTax::between(0, $credited, Fils::fromDecimal($line->tax_amount), $total));
    }
}
