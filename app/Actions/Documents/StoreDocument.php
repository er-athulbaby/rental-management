<?php

namespace App\Actions\Documents;

use App\Audit\Audit;
use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\MaintenanceRequest;
use App\Models\PortalAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StoreDocument
{
    /** Spec §8.6. */
    public const string MIMES = 'pdf,jpg,jpeg,png,webp,docx,xlsx';

    /** Spec 2026-10-18 tenant portal §4: what a tenant may send with a portal request. */
    public const string PHOTO_MIMES = 'jpg,jpeg,png,webp';

    public const int MAX_KB = 10240;

    public function handle(User $actor, Model $documentable, UploadedFile $file, DocumentCategory $category, ?CarbonInterface $expiresOn = null): Document
    {
        if (! $actor->can('update', $documentable)) {
            throw new AuthorizationException;
        }

        // Spec §5.6: the generated contract is written only by StoreApprovedContract.
        if ($category === DocumentCategory::GeneratedPdf) {
            throw ValidationException::withMessages(['category' => 'Generated contracts cannot be uploaded.']);
        }

        return $this->store($documentable, $file, self::MIMES, $category, $expiresOn, ['uploaded_by' => $actor->id], $actor);
    }

    /** Spec 2026-10-18 tenant portal §4: a photo the tenant sends with their own portal request (from LogMaintenanceRequest::forPortal). */
    public function forPortal(PortalAccount $account, MaintenanceRequest $request, UploadedFile $file): Document
    {
        if ($request->portal_account_id !== $account->id) {
            throw new AuthorizationException;
        }

        return $this->store($request, $file, self::PHOTO_MIMES, DocumentCategory::Maintenance, null, ['portal_account_id' => $account->id], $account);
    }

    /** @param  array{uploaded_by?: int, portal_account_id?: int}  $uploader  exactly one (the documents_creator_chk CHECK) */
    private function store(Model $documentable, UploadedFile $file, string $mimes, DocumentCategory $category, ?CarbonInterface $expiresOn, array $uploader, Model $causer): Document
    {
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:'.$mimes, 'max:'.self::MAX_KB]])->validate();

        // Random name, no personal data: archive entry names are not encrypted (spec §13.3).
        $path = sprintf('documents/%s/%s.%s', now()->format('Y/m'), Str::uuid(), strtolower($file->extension() ?? $file->getClientOriginalExtension()));
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return DB::transaction(function () use ($documentable, $file, $category, $expiresOn, $path, $uploader, $causer) {
            $document = new Document([
                'category' => $category,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'expires_on' => $expiresOn,
                ...$uploader,
            ]);
            $document->documentable()->associate($documentable);
            $document->save();

            Audit::log('document.uploaded', $document, properties: ['category' => $category->value], causer: $causer);

            return $document;
        });
    }
}
