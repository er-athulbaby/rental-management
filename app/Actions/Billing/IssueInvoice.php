<?php

namespace App\Actions\Billing;

use App\Actions\NextDocumentNumber;
use App\Billing\Tax;
use App\Enums\InvoiceChargeType;
use App\Enums\InvoiceStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\OwnerContractStatus;
use App\Enums\TaxCategory;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\OwnerContract;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §6.3 — the only place tax, owner attribution, numbers and grace dates are written. */
final class IssueInvoice
{
    public function __construct(private NextDocumentNumber $next) {}

    /** @return bool false when held back by a pending owner contract (spec §4.6) */
    public function handle(Invoice $invoice, ?User $issuer = null): bool
    {
        return DB::transaction(function () use ($invoice, $issuer) {
            $invoice = Invoice::query()->lockForUpdate()->with(['lines.agreementUnit', 'agreement'])->findOrFail($invoice->id);

            if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Scheduled], true)) {
                throw ValidationException::withMessages(['invoice' => __('Only a draft or scheduled invoice can be issued.')]);
            }

            foreach ($invoice->lines as $line) {
                if ($line->unit_id !== null && self::coveredByPendingContract($line->unit_id, self::attributionDate($line, $invoice))) {
                    return false;
                }
            }

            $settings = CompanySetting::current();
            $subtotal = 0;
            $taxTotal = 0;

            foreach ($invoice->lines as $line) {
                $net = Fils::fromDecimal($line->net);
                $rate = $settings->vat_registered && $line->tax_category === TaxCategory::Standard ? (string) $settings->vat_rate : '0.00';
                $tax = Tax::amount($net, $line->tax_category, (bool) $settings->vat_registered, $rate);
                $date = self::attributionDate($line, $invoice)->toDateString();

                $line->forceFill([
                    'tax_rate' => $rate,
                    'tax_amount' => Fils::toDecimal($tax),
                    'total' => Fils::toDecimal($net + $tax),
                    'owner_contract_id' => $line->unit_id === null ? null : OwnerContract::query()
                        ->effectiveOn($date)
                        ->whereHas('units', fn ($q) => $q->whereKey($line->unit_id))
                        ->value('owner_contracts.id'),
                ])->save();

                $subtotal += $net;
                $taxTotal += $tax;
            }

            $agreement = $invoice->agreement;
            $graceDays = $agreement === null ? $settings->default_grace_days : $agreement->grace_days;

            // One UPDATE: the CHECKs tie the number, issued_at and grace_until to status = issued.
            $invoice->forceFill([
                'subtotal' => Fils::toDecimal($subtotal),
                'tax_total' => Fils::toDecimal($taxTotal),
                'total' => Fils::toDecimal($subtotal + $taxTotal),
                'number' => ($this->next)(NumberSequenceKey::Invoice),
                'issue_date' => now('Asia/Bahrain')->toDateString(), // the tax point (spec §6.4): issuing early moves it
                'grace_until' => $invoice->due_date->addDays($graceDays)->toDateString(),
                'issued_at' => now(),
                'issued_by' => $issuer?->id,
                'status' => InvoiceStatus::Issued,
            ])->save();

            return true;
        }, attempts: 3);
    }

    /** Spec §4.6: the line's period start; for deposits the agreement unit's start; otherwise the due date. */
    private static function attributionDate(InvoiceLine $line, Invoice $invoice): CarbonImmutable
    {
        return $line->period_start
            ?? ($line->charge_type === InvoiceChargeType::Deposit ? $line->agreementUnit?->start_date : null)
            ?? $invoice->due_date;
    }

    private static function coveredByPendingContract(int $unitId, CarbonImmutable $date): bool
    {
        return OwnerContract::query()
            ->where('status', OwnerContractStatus::PendingApproval)
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->whereHas('units', fn ($q) => $q->whereKey($unitId))
            ->exists();
    }
}
