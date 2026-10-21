<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Models\OwnerStatement;
use App\Pdf\OwnerStatementPdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OwnerStatementPdfController
{
    public function __invoke(Request $request, OwnerStatement $statement, OwnerStatementPdf $pdf): Response
    {
        abort_unless($request->user('web')?->can('view', $statement) === true, 403);
        Audit::log('owner_statement.exported', $statement, properties: ['format' => 'pdf'], causer: $request->user('web'));

        return $pdf->response($statement);
    }
}
