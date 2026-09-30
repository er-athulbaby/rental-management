<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Enums\AgreementStatus;
use App\Jobs\StoreApprovedContract;
use App\Models\Agreement;
use App\Pdf\ContractPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AgreementPdfController
{
    public function __invoke(Request $request, Agreement $agreement, ContractPdf $pdf): Response|RedirectResponse
    {
        abort_unless($request->user()?->can('view', $agreement) === true, 403);

        if (! in_array($agreement->status, [AgreementStatus::Draft, AgreementStatus::PendingApproval], true)) {
            // The frozen copy, through the audited download route. Built now if the queued job hasn't run yet.
            if (! StoreApprovedContract::stored($agreement)) {
                StoreApprovedContract::dispatchSync($agreement->id, (int) $request->user()->getKey());
            }

            return redirect()->route('documents.download', StoreApprovedContract::stored($agreement));
        }

        Audit::log('agreement.draft_pdf_viewed', $agreement, causer: $request->user());

        return response($pdf->render($agreement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="agreement-'.$agreement->id.'-draft.pdf"',
        ]);
    }
}
