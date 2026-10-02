<?php

namespace App\Actions\Billing;

use App\Billing\InvoicePeriod;
use App\Billing\RebillResult;
use App\Billing\Tax;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TaxCategory;
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
 * has already applied the new unit dates. Locks: cheques → scheduled invoices (ascending) → held-back re-billing drafts
 * (ascending) → per credit note (one per affected invoice, ascending): its issued lines → that issued invoice → the
 * credit note. A later credit note's lines are locked after an earlier invoice; that is safe because the caller holds
 * the customer lock, which serialises every writer of this customer's invoices (spec §7.2).
 */
final class RebillAgreement
{
    /** invoices.credit_source of the credit notes re-billing writes. */
    public const string CREDIT_SOURCE = 'rebill';

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
        // $scheduled came from an unlocked read: only invoices still Scheduled under the lock are cancelled (one issued
        // meanwhile by IssueDueInvoices is left to step 2); cheques are moved per cancelled invoice only.
        foreach (Invoice::query()->whereKey($scheduled)->where('status', InvoiceStatus::Scheduled)->orderBy('id')->lockForUpdate()->get() as $old) {
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
                $cheque->forceFill(['invoice_id' => $new?->id, 'to_return' => $new === null])->save();
                if ($new === null) {
                    $toReturn[] = $cheque->id;
                }
            }
        }

        // A re-billing manual invoice held back as draft (§4.6) bills nothing yet (no money is allocated to a draft):
        // cancel it, and its shortfall is billed whole on this run's manual invoice. Step 2 then re-examines every period
        // its lines cover, even one ending before the effective date.
        $heldBack = Invoice::query()->where('agreement_id', $agreement->id)->where('type', InvoiceType::Manual)->where('status', InvoiceStatus::Draft)
            ->whereHas('lines', fn ($q) => $q->whereNotNull('agreement_unit_charge_id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($heldBack as $draft) {
            $draft->forceFill(['status' => InvoiceStatus::Cancelled])->save();
        }
        $heldFrom = InvoiceLine::query()->whereIn('invoice_id', $heldBack->modelKeys())->min('period_start');
        $scanFrom = $heldFrom !== null && $heldFrom < $from ? (string) $heldFrom : $from;

        // 2. Issued rent periods ending on or after the scan start (the effective date, or earlier): per charge, compare what is still billed for the period
        //    (the rent invoice and earlier re-billing manual invoices, less re-billing's own credit notes) with what is correct now;
        //    credit an excess against the lines holding it, bill a shortfall on one manual invoice.
        $issued = Invoice::query()->where('agreement_id', $agreement->id)->where('type', InvoiceType::Rent)
            ->where('status', InvoiceStatus::Issued)->where('period_end', '>=', $scanFrom)->orderBy('id')->get();
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
            $billedLines = InvoiceLine::query()->whereNotNull('agreement_unit_charge_id')
                ->whereHas('invoice', fn ($q) => $q->where('agreement_id', $agreement->id)->whereIn('type', [InvoiceType::Rent, InvoiceType::Manual])->where('status', InvoiceStatus::Issued))
                ->where('period_start', '<=', $period->end->toDateString())->where('period_end', '>=', $period->start->toDateString())
                ->orderByDesc('id')->get();
            $billed = $billedLines->groupBy('agreement_unit_charge_id');
            // Only re-billing's own credit notes reduce what is billed; a discretionary credit note stays a concession.
            $rebillCredits = InvoiceLine::query()->whereIn('credited_line_id', $billedLines->modelKeys())
                ->whereHas('invoice', fn ($q) => $q->where('credit_source', self::CREDIT_SOURCE)->where('status', InvoiceStatus::Issued))
                ->get()->groupBy('credited_line_id');
            $netLeft = fn (InvoiceLine $l): int => Fils::fromDecimal($l->net)
                - (int) ($rebillCredits->get($l->id) ?? collect())->sum(fn (InvoiceLine $c) => Fils::fromDecimal($c->net));
            $grossLeft = fn (InvoiceLine $l): int => Fils::fromDecimal($l->total)
                - (int) ($rebillCredits->get($l->id) ?? collect())->sum(fn (InvoiceLine $c) => Fils::fromDecimal($c->total));

            foreach ($desired->keys()->merge($billed->keys())->unique() as $chargeId) {
                $row = $desired->get($chargeId);
                $correct = $row === null ? 0 : Fils::fromDecimal((string) $row['net']);
                $lines = $billed->get($chargeId) ?? collect();
                $gap = (int) $lines->sum($netLeft) - $correct;

                // A shortfall is decided in gross: after a partial VAT credit a line's tax can stay one fil over Tax(kept
                // net), so its net left reads one fil short while its gross is exactly right.
                $rate = (string) ($lines->first()->tax_rate ?? '0.00');
                $correctGross = $row === null ? 0 : $correct + Tax::amount($correct, TaxCategory::from($row['tax_category']), (float) $rate > 0, $rate);
                $billedGross = (int) $lines->sum($grossLeft);
                if ($gap < 0 && $row !== null && $billedGross < $correctGross) { // a unit added inside an already-issued period (add_unit)
                    $extra[] = [...$row, 'net' => Fils::toDecimal(-$gap), 'total' => Fils::toDecimal(-$gap)];

                    continue;
                }
                foreach ($lines as $line) { // newest line first
                    if ($gap <= 0) {
                        break;
                    }
                    $lineNetLeft = $netLeft($line);
                    $cut = min($gap, $lineNetLeft);
                    $gap -= $cut;
                    // Plan ruling 4: the line keeps the gross of its correct net (its tax at the line's rate); keeping
                    // nothing takes exactly what is left. Capped by what the line still holds after any concession.
                    $keep = $lineNetLeft - $cut;
                    $gross = min(
                        $grossLeft($line) - ($keep + Tax::amount($keep, $line->tax_category, (float) $line->tax_rate > 0, (string) $line->tax_rate)),
                        Fils::fromDecimal($line->total) - Fils::fromDecimal((string) $line->credited),
                    );
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
            $cn->forceFill(['credit_source' => self::CREDIT_SOURCE, 'status' => InvoiceStatus::PendingApproval])->save(); // set while draft: frozen after
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
}
