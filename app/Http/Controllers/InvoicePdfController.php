<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Pdf\InvoicePdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Spec §9.3: rendered on demand; an issued invoice's rows never change, so neither does its PDF. */
final class InvoicePdfController
{
    public function __invoke(Request $request, Invoice $invoice, InvoicePdf $pdf): Response
    {
        abort_unless($request->user()?->can('view', $invoice) === true && $invoice->status === InvoiceStatus::Issued, 403);

        Audit::log('invoice.pdf_viewed', $invoice, causer: $request->user());

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
        ]);
    }
}
