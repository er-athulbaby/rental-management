<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §4: the "Share with tenant" flag. Only on tenant, agreement and invoice documents, by
 * someone who can change that record; Document's LogsActivity records each change (plan ruling 13).
 */
final class ShareDocumentWithTenant
{
    public function handle(User $actor, Document $document, bool $shared): Document
    {
        $record = $document->documentable;
        if ($record === null || ! in_array($record::class, Document::SHAREABLE, true)) {
            throw ValidationException::withMessages(['document' => __('Only documents on a tenant, an agreement or an invoice can be shared with the tenant.')]);
        }
        if (! $actor->can('update', $record)) {
            throw new AuthorizationException;
        }

        $document->forceFill(['shared_with_tenant' => $shared])->save();

        return $document;
    }
}
