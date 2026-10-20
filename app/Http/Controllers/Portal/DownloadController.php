<?php

namespace App\Http\Controllers\Portal;

use App\Audit\Audit;
use App\Enums\AgreementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Jobs\StoreApprovedContract;
use App\Jobs\StoreReceipt;
use App\Models\Agreement;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PortalAccount;
use App\Pdf\InvoicePdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 2026-10-18 tenant portal §4 and §6: the existing PDFs, looked up inside the signed-in tenant's records, so any
 * other id is a 404 (plan rulings 10 and 18). Each download is audited with the portal account as causer.
 */
final class DownloadController
{
    public function agreement(string $agreement): Response
    {
        $account = $this->account();
        $model = Agreement::query()->where('customer_id', $account->customer_id)
            ->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval])
            ->whereNull('import_ref') // an imported agreement's signed contract lives in the old system, as for staff
            ->findOrFail($agreement);
        if (! StoreApprovedContract::stored($model)) {
            StoreApprovedContract::dispatchSync($model->id, $model->created_by); // the queue has not built it yet
        }

        return $this->serve(StoreApprovedContract::stored($model), $account);
    }

    public function invoice(string $invoice, InvoicePdf $pdf): Response
    {
        $account = $this->account();
        $model = Invoice::query()->where('customer_id', $account->customer_id)->where('status', InvoiceStatus::Issued)->findOrFail($invoice);
        Audit::log('invoice.pdf_viewed', $model, causer: $account);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$model->number.'.pdf"',
        ]);
    }

    public function receipt(string $payment): Response
    {
        $account = $this->account();
        $model = Payment::query()->where('customer_id', $account->customer_id)->where('status', PaymentStatus::Confirmed)->findOrFail($payment);
        if (! StoreReceipt::stored($model)) {
            StoreReceipt::dispatchSync($model->id, $model->recorded_by);
        }

        return $this->serve(StoreReceipt::stored($model), $account);
    }

    private function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);

        return $account;
    }

    /** A stored PDF, in the browser, with a fixed type. */
    private function serve(?Document $document, PortalAccount $account): Response
    {
        abort_if($document === null || $document->trashed(), 404);
        Audit::log('document.downloaded', $document, causer: $account);

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
