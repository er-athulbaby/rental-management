<?php

namespace App\Policies;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\Expense;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $document->documentable !== null && $user->can('view', $document->documentable);
    }

    public function delete(User $user, Document $document): bool
    {
        // Spec §4.7: the evidence behind a frozen expense must not be removable.
        if ($document->documentable instanceof Expense && $document->category === DocumentCategory::OwnerApproval) {
            return false;
        }

        return $document->documentable !== null && $user->can('update', $document->documentable);
    }
}
