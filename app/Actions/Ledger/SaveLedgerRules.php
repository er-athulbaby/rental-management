<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Rules;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §3 (plan ruling 10): every rule key points at an active account. A change applies to new postings; the page then offers a rebuild. */
final class SaveLedgerRules
{
    /** @param  array<array-key, mixed>  $map  rule key => account id */
    public function handle(User $actor, array $map): bool
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        $active = LedgerAccount::query()->where('active', true)->pluck('id')->flip();
        $chosen = [];
        foreach (Rules::keys() as $key) {
            $id = $map[$key] ?? null;
            $id = is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19) ? (int) $id : 0;
            if (! $active->has($id)) {
                throw ValidationException::withMessages(['rules' => __('Choose an active account for every rule.')]);
            }
            $chosen[$key] = $id;
        }

        return DB::transaction(function () use ($actor, $chosen) {
            $old = DB::table('ledger_rules')->lockForUpdate()->pluck('ledger_account_id', 'key')->map(fn ($id) => (int) $id)->all();
            $changed = array_filter($chosen, fn (int $id, string $key) => ($old[$key] ?? null) !== $id, ARRAY_FILTER_USE_BOTH);
            foreach ($changed as $key => $id) {
                DB::table('ledger_rules')->updateOrInsert(['key' => $key], ['ledger_account_id' => $id, 'updated_at' => now()]);
            }
            if ($changed !== []) {
                Audit::log('ledger.rules_saved', null, array_intersect_key($old, $changed), $changed, causer: $actor);
            }

            return $changed !== [];
        });
    }
}
