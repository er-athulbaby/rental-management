<?php

namespace App\Actions\Billing;

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
 * has already applied the new unit dates. Locks, in the global order: cheques → issued lines → invoices.
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

        // 2. Issued rent invoices ending on or after the effective date: credit what is no longer billed, bill what is new.
        $creditNotes = [];
        $extra = [];
        $issued = Invoice::query()->where('agreement_id', $agreement->id)->where('type', InvoiceType::Rent)
            ->where('status', InvoiceStatus::Issued)->where('period_end', '>=', $from)->orderBy('id')->get();

        foreach ($issued as $invoice) {
            $period = InvoicePeriod::of($agreement, $invoice);
            $desired = collect($this->schedule->linesFor($agreement, $period))->keyBy('agreement_unit_charge_id');
            $billed = InvoiceLine::query()->where('invoice_id', $invoice->id)->whereNotNull('agreement_unit_charge_id')->orderBy('id')->get();

            $entries = [];
            foreach ($billed as $line) {
                $correct = Fils::fromDecimal((string) ($desired->get($line->agreement_unit_charge_id)['net'] ?? '0'));
                $creditNet = Fils::fromDecimal($line->net) - $correct;
                $left = Fils::fromDecimal($line->total) - Fils::fromDecimal((string) $line->credited);
                if ($creditNet <= 0 || $left <= 0) {
                    continue;
                }
                // Plan ruling 4: the whole line takes exactly what is left; a partial credit adds its tax at the line's rate.
                $gross = $correct === 0
                    ? $left
                    : min($left, $creditNet + Tax::amount($creditNet, $line->tax_category, (float) $line->tax_rate > 0, (string) $line->tax_rate));
                $entries[] = [$line, $gross];
            }
            if ($entries !== []) {
                $cn = $this->buildCreditNote->handle($invoice, $entries, $reason, $actor);
                $cn->forceFill(['status' => InvoiceStatus::PendingApproval])->save();
                $this->issueCreditNote->handle($cn, $actor);
                $creditNotes[] = $cn->id;
            }

            $billedCharges = $billed->pluck('agreement_unit_charge_id')->all();
            foreach ($desired as $chargeId => $row) {
                if (! in_array($chargeId, $billedCharges, true)) {
                    $extra[] = $row; // a unit added inside an already-issued period (add_unit)
                }
            }
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
