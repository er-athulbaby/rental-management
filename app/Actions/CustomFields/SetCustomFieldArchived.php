<?php

namespace App\Actions\CustomFields;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-21 custom fields §3: archive hides a field and keeps its values; restore brings it back within the cap (plan ruling 10). */
final class SetCustomFieldArchived
{
    public function handle(User $actor, CustomField $field, bool $archived): void
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $field, $archived) {
            $kind = CustomField::query()->where('entity', $field->entity)->lockForUpdate()->get();
            $field = $kind->firstWhere('id', $field->id) ?? abort(404);
            if ($archived === ($field->archived_at !== null)) {
                return;
            }
            if (! $archived && $kind->whereNull('archived_at')->count() >= CustomField::MAX_ACTIVE) {
                throw ValidationException::withMessages(['restore' => __('At most :n active fields per record kind. Archive one first.', ['n' => CustomField::MAX_ACTIVE])]);
            }

            $field->forceFill(['archived_at' => $archived ? now() : null])->save();
            Audit::log($archived ? 'custom_field.archived' : 'custom_field.restored', $field, causer: $actor);
        });
    }
}
