<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Spec 2026-10-18 tenant portal §4 and 2026-10-21 owner portal §4: "Share with tenant" and "Share with owner". Only on
 * the audience's shareable records, by someone who can change that record; Document's LogsActivity records each change
 * (owner portal plan ruling 13).
 */
final class ShareDocument
{
    public const array AUDIENCES = ['tenant', 'owner'];

    public function handle(User $actor, Document $document, string $audience, bool $shared): Document
    {
        if (! in_array($audience, self::AUDIENCES, true)) {
            throw new InvalidArgumentException("Unknown audience {$audience}"); // a programming error: callers pass a literal
        }
        $record = $document->documentable;
        if ($record === null || ! in_array($record::class, Document::SHAREABLE[$audience], true)) {
            throw ValidationException::withMessages(['document' => $audience === 'tenant'
                ? __('Only documents on a tenant, an agreement or an invoice can be shared with the tenant.')
                : __('Only documents on an owner, an owner contract or a building can be shared with the owner.')]);
        }
        if (! $actor->can('update', $record)) {
            throw new AuthorizationException;
        }

        $document->forceFill([$audience === 'owner' ? 'shared_with_owner' : 'shared_with_tenant' => $shared])->save();

        return $document;
    }
}
