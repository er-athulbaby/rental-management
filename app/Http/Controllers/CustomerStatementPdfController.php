<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Billing\CustomerStatement;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CustomerStatementPdfController
{
    public function __invoke(Request $request, Customer $customer, PdfRenderer $pdf): Response
    {
        $user = $request->user();
        abort_unless($user !== null && Customer::visibleTo($user)->whereKey($customer->id)->exists(), 403);
        $range = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        Audit::log('customer.statement_exported', $customer, properties: $range, causer: $user);

        $bytes = $pdf->render('pdf.statement', [
            'company' => CompanySetting::current(),
            'customer' => $customer,
            'from' => $range['from'],
            'to' => $range['to'],
            'receivables' => CustomerStatement::receivables($customer, $range['from'], $range['to']),
            'deposits' => CustomerStatement::deposits($customer, $range['from'], $range['to']),
        ], ['footer' => '<div style="text-align:center; font-size:8pt; color:#555">{PAGENO} / {nbpg}</div>']);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="statement-'.$customer->id.'.pdf"']);
    }
}
