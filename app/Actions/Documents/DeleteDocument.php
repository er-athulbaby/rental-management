<?php

namespace App\Actions\Documents;

use App\Audit\Audit;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DeleteDocument
{
    /** Soft delete: the file stays for the audit trail and backups. */
    public function handle(User $actor, Document $document): void
    {
        if (! $actor->can('delete', $document)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $document) {
            $document->delete();

            Audit::log('document.deleted', $document, causer: $actor);
        });
    }
}
