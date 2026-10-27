<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Spec §4: a manual journal is corrected by its exact opposite, once, on an open date. Automatic and opening journals follow
 * their source; a manual reversal of an automatic one would also block the rebuild (reverses_journal_id is restrictOnDelete).
 */
final class ReverseManualJournal
{
    public function handle(User $actor, Journal $journal, mixed $date): Journal
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        Validator::make(['reverseDate' => $date], [
            'reverseDate' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
        ])->validate();
        $date = (string) $date;

        return DB::transaction(function () use ($actor, $journal, $date) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $journal = Journal::query()->with('lines')->findOrFail($journal->id);
            if ($journal->kind !== 'manual' || $journal->reverses_journal_id !== null) {
                throw ValidationException::withMessages(['reverseDate' => __('Only a manual journal that is not itself a reversal can be reversed.')]);
            }
            if (Journal::query()->where('reverses_journal_id', $journal->id)->exists()) {
                throw ValidationException::withMessages(['reverseDate' => __('This journal is already reversed.')]);
            }
            PostManualJournal::openDate($settings, $date, 'reverseDate');

            $lines = array_values($journal->lines->map(fn (JournalLine $l) => ['account' => $l->ledger_account_id, 'amount' => Fils::fromDecimal($l->credit) - Fils::fromDecimal($l->debit),
                'customer_id' => $l->customer_id, 'owner_id' => $l->owner_id, 'building_id' => $l->building_id, 'unit_id' => $l->unit_id, 'memo' => $l->memo])->all());
            $reversal = Poster::write('manual', $date, $journal->description, $lines, reverses: $journal->id, createdBy: $actor->id);
            Audit::log('ledger.journal_reversed', $journal, properties: ['reversal' => $reversal->id, 'date' => $date], causer: $actor);

            return $reversal;
        });
    }
}
