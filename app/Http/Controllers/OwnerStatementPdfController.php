<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Jobs\StoreOwnerStatement;
use App\Models\OwnerStatement;
use App\Pdf\OwnerStatementPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class OwnerStatementPdfController
{
    public function __invoke(Request $request, OwnerStatement $statement, OwnerStatementPdf $pdf): Response
    {
        abort_unless($request->user()?->can('view', $statement), 403);
        Audit::log('owner_statement.exported', $statement, properties: ['format' => 'pdf'], causer: $request->user());

        // A finalised statement is served from its stored copy (spec §7.9); drafts are rendered with a watermark.
        $stored = StoreOwnerStatement::stored($statement);
        if ($stored !== null) {
            return Storage::disk($stored->disk)->response($stored->path, $stored->original_name, ['Content-Type' => 'application/pdf']);
        }

        return response($pdf->render($statement), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.($statement->number ?? 'statement-draft').'.pdf"']);
    }
}
