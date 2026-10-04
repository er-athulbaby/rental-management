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

    /** Shows a PDF or picture in the browser; the type is fixed from the allow-list so an upload can never run as a page. */
    public function show(Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        $type = $document->previewType();
        if ($type === null) {
            return $this->download($document);
        }

        Audit::log('document.viewed', $document, causer: auth()->user());

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
