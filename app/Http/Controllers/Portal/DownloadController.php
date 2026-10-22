<?php

namespace App\Http\Controllers\Portal;

use App\Audit\Audit;
use App\Billing\CustomerStatement;
use App\CustomFields\CustomFields;
use App\Enums\AgreementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Jobs\StoreApprovedContract;
use App\Jobs\StoreReceipt;
use App\Models\Agreement;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PortalAccount;
use App\Pdf\InvoicePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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

    /** Spec §4: the tenant statement PDF; no customer parameter exists, so it is always the signed-in tenant's. */
    public function statement(Request $request): Response
    {
        $account = $this->account();
        $query = $request->query();
        abort_unless(is_string($query['from'] ?? null) && is_string($query['to'] ?? null), 404); // an array value would trip the date rules
        $v = Validator::make($query, [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        abort_if($v->fails(), 404); // a plain link, not a form (plan ruling 10)
        $range = $v->validated();
        $customer = Customer::query()->findOrFail((int) $account->customer_id);
        Audit::log('customer.statement_exported', $customer, properties: $range, causer: $account);

        return response(CustomerStatement::pdf($customer, $range['from'], $range['to']), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="statement.pdf"',
        ]);
    }

    /** Spec §4: a shared document on the tenant, their agreements or their invoices; anything else is a 404. */
    public function document(string $document): Response
    {
        $account = $this->account();
        $model = Document::query()->sharedWithTenant((int) $account->customer_id)->findOrFail($document);
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }

    /** Spec 2026-10-21 custom fields §5: a "Show in portal" file on the tenant, their agreements or those agreements' units (plan ruling 8). */
    public function customFieldFile(string $document): Response
    {
        $account = $this->account();
        $model = CustomFields::portalDocument($document, CustomFields::tenantScope((int) $account->customer_id));
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }

    private function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);
        abort_unless($account->hasTenant(), 404); // owner portal plan ruling 1: never `customer_id IS NULL`

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
