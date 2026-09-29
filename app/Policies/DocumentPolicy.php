<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $document->documentable !== null && $user->can('view', $document->documentable);
    }

    public function delete(User $user, Document $document): bool
    {
        return $document->documentable !== null && $user->can('update', $document->documentable);
    }
}
