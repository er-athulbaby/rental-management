<?php

namespace App\Actions\Documents;

use App\Audit\Audit;
use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class StoreDocument
{
    /** Spec §8.6. */
    public const string MIMES = 'pdf,jpg,jpeg,png,webp,docx,xlsx';

    public const int MAX_KB = 10240;

    public function handle(User $actor, Model $documentable, UploadedFile $file, DocumentCategory $category, ?CarbonInterface $expiresOn = null): Document
    {
        if (! $actor->can('update', $documentable)) {
            throw new AuthorizationException;
        }

        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:'.self::MIMES, 'max:'.self::MAX_KB]])->validate();

        // Random name, no personal data: archive entry names are not encrypted (spec §13.3).
        $path = sprintf('documents/%s/%s.%s', now()->format('Y/m'), Str::uuid(), strtolower($file->extension()));
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return DB::transaction(function () use ($actor, $documentable, $file, $category, $expiresOn, $path) {
            $document = new Document([
                'category' => $category,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'expires_on' => $expiresOn,
                'uploaded_by' => $actor->id,
            ]);
            $document->documentable()->associate($documentable);
            $document->save();

            Audit::log('document.uploaded', $document, properties: ['category' => $category->value], causer: $actor);

            return $document;
        });
    }
}
