<?php

namespace App\Actions\CustomFields;

use App\Audit\Audit;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Enums\PermissionName;
use App\Models\CustomField;
use App\Models\CustomFieldChoice;
use App\Models\CustomFieldValue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Spec 2026-10-21 custom fields §3: adds or changes a field and its choice list (plan rulings 9–11, 13). */
final class SaveCustomField
{
    /** @param  array<string, mixed>  $data  entity (new fields only), label_en, label_ar, type, help, required, in_portal, choices: list of {id, label_en, label_ar, hidden} */
    public function handle(User $actor, ?CustomField $field, array $data): CustomField
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        $data = self::clean($data);
        $choiceIds = $field ? $field->choices()->pluck('id')->all() : [];

        $validated = Validator::make($data, [
            'entity' => [$field ? 'nullable' : 'required', Rule::enum(CustomFieldEntity::class)],
            'label_en' => ['required', 'string', 'max:100'],
            'label_ar' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::enum(CustomFieldType::class)],
            'help' => ['nullable', 'string', 'max:255'],
            'required' => ['boolean'],
            'in_portal' => ['boolean'],
            'choices' => ['nullable', 'array', 'max:50', 'required_if:type,choice'],
            'choices.*' => ['array'],
            'choices.*.id' => ['nullable', 'integer', Rule::in($choiceIds)],
            'choices.*.label_en' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'choices.*.label_ar' => ['nullable', 'string', 'max:100'],
            'choices.*.hidden' => ['boolean'],
        ], [
            'choices.required_if' => __('A choice list needs at least one choice.'),
            'choices.*.id.in' => __('Choose one of the listed options.'),
        ], [
            'label_en' => __('Label (English)'), 'label_ar' => __('Label (Arabic)'), 'help' => __('Help text'),
            'choices.*.label_en' => __('Choice (English)'), 'choices.*.label_ar' => __('Choice (Arabic)'),
        ])->after(function (ValidatorInstance $validator) use ($field, $data) {
            if ($validator->errors()->isEmpty() && $field !== null && $data['type'] !== $field->type->value
                && CustomFieldValue::query()->where('custom_field_id', $field->id)->exists()) {
                $validator->errors()->add('type', __('The type cannot change once values are saved.'));
            }
        })->validate();

        return DB::transaction(function () use ($actor, $field, $validated) {
            $before = null;
            if ($field === null) {
                $entity = CustomFieldEntity::from((string) $validated['entity']);
                // Locks the kind's fields: one add at a time per kind, so the cap, the key and the position hold (plan rulings 9–10).
                $kind = CustomField::query()->where('entity', $entity)->lockForUpdate()->get(['id', 'position', 'archived_at']);
                if ($kind->whereNull('archived_at')->count() >= CustomField::MAX_ACTIVE) {
                    throw ValidationException::withMessages(['label_en' => __('At most :n active fields per record kind. Archive one first.', ['n' => CustomField::MAX_ACTIVE])]);
                }
                $field = (new CustomField)->forceFill([
                    'entity' => $entity, 'key' => CustomField::keyFor($entity, (string) $validated['label_en']),
                    'position' => (int) $kind->max('position') + 1, 'created_by' => $actor->id,
                ]);
            } else {
                $field = CustomField::query()->lockForUpdate()->findOrFail($field->id);
                $before = self::snapshot($field);
            }

            // Never the key: it is fixed at creation (plan ruling 9).
            $field->forceFill([
                'label_en' => $validated['label_en'], 'label_ar' => $validated['label_ar'] ?? null, 'type' => $validated['type'],
                'help' => $validated['help'] ?? null, 'required' => (bool) ($validated['required'] ?? false), 'in_portal' => (bool) ($validated['in_portal'] ?? false),
            ])->save();
            self::syncChoices($field, $field->type === CustomFieldType::Choice ? array_values((array) ($validated['choices'] ?? [])) : []);
            $after = self::snapshot($field->refresh());

            if ($before === null) {
                Audit::log('custom_field.created', $field, new: $after, causer: $actor);
            } else {
                $changed = array_keys(array_filter($after, fn (mixed $value, string $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
                if ($changed !== []) {
                    Audit::log('custom_field.updated', $field, Arr::only($before, $changed), Arr::only($after, $changed), causer: $actor);
                }
            }

            return $field;
        });
    }

    /** @param  list<mixed>  $rows  in order; an existing choice left out is hidden when a record holds it, deleted otherwise (spec §3) */
    private static function syncChoices(CustomField $field, array $rows): void
    {
        $keep = [];
        foreach ($rows as $i => $row) {
            $row = (array) $row;
            $choice = isset($row['id']) ? $field->choices()->findOrFail((int) $row['id']) : new CustomFieldChoice;
            $hidden = filter_var($row['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $choice->forceFill([
                'custom_field_id' => $field->id, 'label_en' => $row['label_en'], 'label_ar' => $row['label_ar'] ?? null, 'position' => $i + 1,
                'hidden_at' => $hidden ? ($choice->hidden_at ?? now()) : null,
            ])->save();
            $keep[] = $choice->id;
        }

        foreach ($field->choices()->whereNotIn('id', $keep)->get() as $gone) {
            // ponytail: a value saved between this check and the delete hits the RESTRICT foreign key; lock values if that race ever matters.
            if (CustomFieldValue::query()->where('choice_id', $gone->id)->exists()) {
                $gone->forceFill(['hidden_at' => $gone->hidden_at ?? now()])->save();
            } else {
                $gone->delete();
            }
        }
    }

    /** @return array<string, mixed> what the audit compares */
    private static function snapshot(CustomField $field): array
    {
        return [
            'label_en' => $field->label_en, 'label_ar' => $field->label_ar, 'type' => $field->type->value, 'help' => $field->help,
            'required' => $field->required, 'in_portal' => $field->in_portal,
            'choices' => $field->choices()->get()->map(fn (CustomFieldChoice $c) => $c->label_en.($c->label_ar ? ' / '.$c->label_ar : '')
                .($c->hidden_at ? ' ('.__('Hidden').')' : ''))->all(),
        ];
    }

    /**
     * Trimmed strings; blanks become null, at any depth.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function clean(array $data): array
    {
        return array_map(fn (mixed $v) => is_array($v) ? self::clean($v) : (is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v), $data);
    }
}
