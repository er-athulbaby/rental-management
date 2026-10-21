<?php

namespace App\Actions\CustomFields;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Spec 2026-10-21 custom fields §3 "order (up / down)": swaps a field with its active neighbour and renumbers the kind 1…n (plan ruling 10). */
final class MoveCustomField
{
    public function handle(User $actor, CustomField $field, int $by): void
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $field, $by) {
            $ids = CustomField::query()->where('entity', $field->entity)->whereNull('archived_at')
                ->orderBy('position')->orderBy('id')->lockForUpdate()->pluck('id')->values()->all();
            $from = array_keys($ids, $field->id, true)[0] ?? null;
            $to = $from === null ? null : $from + ($by < 0 ? -1 : 1);
            if ($to === null || ! isset($ids[$to])) {
                return; // already first or last, or archived
            }

            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
            foreach ($ids as $n => $id) {
                CustomField::query()->whereKey($id)->update(['position' => $n + 1]);
            }
            Audit::log('custom_field.moved', $field, properties: ['direction' => $by < 0 ? 'up' : 'down'], causer: $actor);
        });
    }
}
