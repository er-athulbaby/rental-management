<?php

namespace App\Actions\CustomFields;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-21 custom fields §3: a field with no value ever saved can be deleted; its choices go with it (plan ruling 10). */
final class DeleteCustomField
{
    public function handle(User $actor, CustomField $field): void
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $field) {
            $field = CustomField::query()->lockForUpdate()->findOrFail($field->id);
            // A value row exists once any value was saved, even if cleared since (plan ruling 1). The RESTRICT foreign key backs this up.
            if (CustomFieldValue::query()->where('custom_field_id', $field->id)->exists()) {
                throw ValidationException::withMessages(['delete' => __('This field has saved values: archive it instead.')]);
            }

            Audit::log('custom_field.deleted', $field, old: ['key' => $field->key, 'label_en' => $field->label_en, 'type' => $field->type->value], causer: $actor);
            $field->delete();
        });
    }
}
