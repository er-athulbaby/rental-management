<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function download(Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        Audit::log('document.downloaded', $document, causer: auth()->user());

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }
}
