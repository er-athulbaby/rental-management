<?php

namespace App\Pdf;

use App\Enums\InvoiceType;
use App\Enums\TaxCategory;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Fils;
use Illuminate\Support\Facades\Storage;

/** Spec §9.3, §6.4: invoice, tax invoice and credit note, English. Rendered on demand from the immutable issued rows. */
final class InvoicePdf
{
    public function __construct(private PdfRenderer $renderer) {}

    public static function title(Invoice $invoice): string
    {
        $taxable = CompanySetting::current()->vat_registered
            && $invoice->lines->contains(fn (InvoiceLine $l) => in_array($l->tax_category, [TaxCategory::Standard, TaxCategory::ZeroRated], true));
        $credit = $invoice->type === InvoiceType::CreditNote;

        return match (true) {
            $credit && $taxable => 'Tax Credit Note',
            $credit => 'Credit Note',
            $taxable => 'Tax Invoice',
            default => 'Invoice',
        };
    }

    /** @return array{view: view-string, data: array<string, mixed>, chrome: array{footer: string}} */
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'agreement:id,number', 'lines.unit.building', 'relatedInvoice:id,number']);
        $settings = CompanySetting::current();
        $title = self::title($invoice);

        // VAT breakdown per category and rate (spec §6.4).
        $breakdown = $invoice->lines->groupBy(fn (InvoiceLine $l) => $l->tax_category->value.'|'.$l->tax_rate)
            ->map(fn ($lines) => [
                'category' => $lines->firstOrFail()->tax_category->label(),
                'rate' => $lines->firstOrFail()->tax_rate,
                'net' => Fils::toDecimal($lines->sum(fn (InvoiceLine $l) => Fils::fromDecimal($l->net))),
                'tax' => Fils::toDecimal($lines->sum(fn (InvoiceLine $l) => Fils::fromDecimal($l->tax_amount))),
            ])->values()->all();

        return [
            'view' => 'pdf.invoice',
            'data' => [
                'title' => $title,
                'invoice' => $invoice,
                'company' => $settings,
                'logo' => $settings->logo_path && Storage::disk('local')->exists($settings->logo_path) ? Storage::disk('local')->path($settings->logo_path) : null,
                'showTax' => str_starts_with($title, 'Tax'),
                'breakdown' => $breakdown,
            ],
            'chrome' => ['footer' => '<div style="text-align:center; font-size:8pt; color:#555">'.e($invoice->label()).' &nbsp;·&nbsp; {PAGENO} / {nbpg}</div>'],
        ];
    }

    public function render(Invoice $invoice): string
    {
        $built = $this->build($invoice);

        return $this->renderer->render($built['view'], $built['data'], $built['chrome']);
    }
}
