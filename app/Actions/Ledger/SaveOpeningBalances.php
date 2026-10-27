<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Poster;
use App\Ledger\Rules;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spec §3 (plan ruling 6): one opening balance per account, as a debit or a credit; the difference goes to Opening
 * balance equity, so the opening journal always balances. Replaces the opening journal, only while nothing is locked.
 */
final class SaveOpeningBalances
{
    /** @param  mixed  $balances  account id => ['debit' => '0.000', 'credit' => '0.000'] */
    public function handle(User $actor, mixed $balances): ?Journal
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        $active = LedgerAccount::query()->where('active', true)->pluck('id')->flip();
        $lines = [];
        foreach (is_array($balances) ? $balances : [] as $id => $row) {
            $row = is_array($row) ? $row : [];
            $debit = self::amount($row['debit'] ?? null, "balances.{$id}.debit");
            $credit = self::amount($row['credit'] ?? null, "balances.{$id}.credit");
            if ($debit === 0 && $credit === 0) {
                continue;
            }
            if (! $active->has((int) $id)) {
                throw ValidationException::withMessages(['balances' => __('Opening balances go on active accounts only.')]);
            }
            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages(["balances.{$id}.debit" => __('Enter a debit or a credit, not both.')]);
            }
            $lines[] = ['account' => (int) $id, 'amount' => $debit - $credit, 'customer_id' => null, 'owner_id' => null, 'building_id' => null, 'unit_id' => null, 'memo' => null];
        }
        $difference = array_sum(array_column($lines, 'amount'));

        return DB::transaction(function () use ($actor, $lines, $difference) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            if ($settings->start_date === null) {
                throw ValidationException::withMessages(['balances' => __('Set the ledger start date first.')]);
            }
            if ($settings->locked_until !== null) {
                throw ValidationException::withMessages(['balances' => __('Opening balances change only while no month is locked.')]);
            }
            if ($settings->rebuilding()) {
                throw ValidationException::withMessages(['balances' => __('Wait for the rebuild to finish.')]);
            }
            $old = Journal::query()->where('kind', 'opening')->first();
            if ($old !== null) {
                JournalLine::query()->where('journal_id', $old->id)->delete();
                $old->delete();
            }
            if ($lines === []) {
                Audit::log('ledger.opening_saved', $settings, properties: ['lines' => 0], causer: $actor);

                return null;
            }
            if ($difference !== 0) {
                $lines[] = ['account' => Rules::load()->account('opening_equity'), 'amount' => -$difference, 'customer_id' => null, 'owner_id' => null,
                    'building_id' => null, 'unit_id' => null, 'memo' => null];
            }
            $journal = Poster::write('opening', $settings->start_date->toDateString(), __('Opening balances'), $lines, createdBy: $actor->id);
            Audit::log('ledger.opening_saved', $journal, properties: ['lines' => count($lines), 'to_equity' => Fils::toDecimal(-$difference)], causer: $actor);

            return $journal;
        });
    }

    private static function amount(mixed $value, string $field): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (! is_string($value) || preg_match('/^\d{1,9}(\.\d{1,3})?$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => __('Enter an amount with up to 3 decimals.')]);
        }

        return Fils::fromDecimal($value);
    }
}
