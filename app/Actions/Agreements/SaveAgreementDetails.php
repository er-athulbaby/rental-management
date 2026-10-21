<?php

namespace App\Actions\Agreements;

use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Spec 2026-10-21 custom fields §4: an agreement's "More details" after the draft, at any status (agreements lock,
 * custom fields do not), for whoever can manage the agreement: agreements.manage with every unit in scope (plan ruling 3).
 */
final class SaveAgreementDetails
{
    /** @param  array<string, mixed>  $custom  field key => value */
    public function handle(User $actor, Agreement $agreement, array $custom): void
    {
        if (! $actor->can('update', $agreement)) {
            throw new AuthorizationException;
        }

        $data = ['custom' => $custom];
        [$rules, $messages, $names] = CustomFields::validation(CustomFieldEntity::Agreement, $agreement, $data);
        $validated = Validator::make($data, $rules, $messages, $names)->validate();

        DB::transaction(function () use ($actor, $agreement, $validated) {
            // A read lock only: the agreement row is not written, so its status triggers are not involved.
            $locked = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
            CustomFields::save($actor, $locked, (array) ($validated['custom'] ?? []));
        });
    }
}
