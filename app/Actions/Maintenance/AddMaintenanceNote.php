<?php

namespace App\Actions\Maintenance;

use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §5: "timeline with add-note"; allowed on closed requests too (plan ruling 4). */
final class AddMaintenanceNote
{
    public function handle(User $actor, MaintenanceRequest $request, string $note): MaintenanceEvent
    {
        if (! $actor->can('update', $request)) {
            throw new AuthorizationException;
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => __('Write a note of up to 1,000 characters.')]);
        }

        return MaintenanceEvent::record($request, $actor, note: $note);
    }
}
