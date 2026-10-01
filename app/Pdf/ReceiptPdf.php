<?php

namespace App\Pdf;

use App\Models\CompanySetting;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Fils;
use Illuminate\Support\Facades\Storage;

/** Spec §7.1, §9.3: the receipt as at recording; stored once and never regenerated (spec §7.3). */
final class ReceiptPdf
{
    public function __construct(private PdfRenderer $renderer) {}

    public function render(Payment $payment): string
    {
        $payment->loadMissing(['customer', 'allocations.line.invoice']);
        $settings = CompanySetting::current();
        $byInvoice = $payment->allocations->groupBy(fn (PaymentAllocation $a): string => $a->line?->invoice->number ?? '')
            ->map(fn ($rows) => Fils::toDecimal($rows->sum(fn ($a) => Fils::fromDecimal($a->amount))));

        return $this->renderer->render('pdf.receipt', [
            'payment' => $payment,
            'company' => $settings,
            'logo' => $settings->logo_path && Storage::disk('local')->exists($settings->logo_path) ? Storage::disk('local')->path($settings->logo_path) : null,
            'byInvoice' => $byInvoice,
            'credit' => Fils::toDecimal($payment->unallocatedFils()),
        ], ['footer' => '<div style="text-align:center; font-size:8pt; color:#555">'.e($payment->number).'</div>']);
    }
}
