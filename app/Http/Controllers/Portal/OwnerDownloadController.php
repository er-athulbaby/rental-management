<?php

namespace App\Http\Controllers\Portal;

use App\Audit\Audit;
use App\CustomFields\CustomFields;
use App\Models\Document;
use App\Models\PortalAccount;
use App\Pdf\OwnerStatementPdf;
use App\Portal\OwnerPortal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 2026-10-21 owner portal §4 and §6: the owner's downloads, looked up inside their records, so any other id is a
 * 404 (owner portal plan ruling 10). Each download is audited with the portal account as causer.
 */
final class OwnerDownloadController
{
    /** Spec §4: a finalised statement of their contracts, the PDF staff download. */
    public function statement(string $statement, OwnerStatementPdf $pdf): Response
    {
        $account = $this->account();
        $model = OwnerPortal::statements((int) $account->owner_id)->findOrFail($statement);
        Audit::log('owner_statement.exported', $model, properties: ['format' => 'pdf'], causer: $account);

        return $pdf->response($model);
    }

    /** Spec §4: a document shared with the owner on them, their contracts or their buildings; anything else is a 404. */
    public function document(string $document): Response
    {
        $account = $this->account();
        $model = Document::query()->sharedWithOwner((int) $account->owner_id)->findOrFail($document);
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }

    /** Spec 2026-10-21 custom fields §5: a "Show in portal" file on the owner or a building of their contracts (plan ruling 8). */
    public function customFieldFile(string $document): Response
    {
        $account = $this->account();
        $model = CustomFields::portalDocument($document, CustomFields::ownerScope((int) $account->owner_id));
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }

    private function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);
        abort_unless($account->hasOwner(), 404);

        return $account;
    }
}
