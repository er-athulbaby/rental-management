<?php

use App\Integrity\IntegrityCheck;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** A two-line journal written straight to the tables (Cash 10.000 Dr, Bank 10.000 Cr). */
function storageJournal(string $kind, string $date, ?string $sourceType = null, ?int $sourceId = null): Journal
{
    $journal = (new Journal)->forceFill(['date' => $date, 'kind' => $kind, 'source_type' => $sourceType, 'source_id' => $sourceId, 'description' => 'Test']);
    $journal->save();
    $journal->lines()->create(['ledger_account_id' => LedgerAccount::query()->where('system_key', 'cash')->value('id'), 'debit' => '10.000', 'credit' => '0.000']);
    $journal->lines()->create(['ledger_account_id' => LedgerAccount::query()->where('system_key', 'bank')->value('id'), 'debit' => '0.000', 'credit' => '10.000']);

    return $journal;
}

test('the default chart, its posting rules and the one settings row are there', function () {
    $owner = LedgerAccount::query()->where('code', '2100')->firstOrFail();

    expect(LedgerAccount::query()->count())->toBe(24)
        ->and(LedgerAccount::query()->where('code', '1100')->value('system_key'))->toBe('receivables')
        ->and($owner->name_ar)->toBe('مستحقات الملاك')
        ->and($owner->type->value)->toBe('liability')
        ->and($owner->label())->toBe('2100 Owner payable')
        ->and(DB::table('ledger_rules')->count())->toBe(25)
        ->and(DB::table('ledger_rules')->where('key', 'method.cheque')->value('ledger_account_id'))->toBe(LedgerAccount::query()->where('code', '1010')->value('id'))
        ->and(DB::table('ledger_rules')->where('key', 'other_payments_out')->value('ledger_account_id'))->toBe(LedgerAccount::query()->where('code', '5190')->value('id'))
        ->and(LedgerSetting::query()->count())->toBe(1)
        ->and(LedgerSetting::current()->start_date)->toBeNull()
        ->and(LedgerSetting::current()->firstOpenDay())->toBeNull()
        ->and(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(67)
        ->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(67);
});

test('manual journals never change; automatic ones are removable only while their date is open', function () {
    $manual = storageJournal('manual', '2026-10-05');
    $auto = storageJournal('auto', '2026-10-05', 'invoice', 1);

    expect(fn () => DB::table('journals')->where('id', $manual->id)->update(['description' => 'Changed']))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => DB::table('journal_lines')->where('journal_id', $manual->id)->update(['memo' => 'x']))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => DB::table('journal_lines')->where('journal_id', $manual->id)->delete())->toThrow(QueryException::class, 'manual')
        ->and(fn () => DB::table('journals')->where('id', $manual->id)->delete())->toThrow(QueryException::class, 'manual');

    LedgerSetting::current()->forceFill(['start_date' => '2026-10-01', 'locked_until' => '2026-10-05'])->save();
    expect(LedgerSetting::current()->firstOpenDay()->toDateString())->toBe('2026-10-06')
        ->and(fn () => DB::table('journal_lines')->where('journal_id', $auto->id)->delete())->toThrow(QueryException::class, 'locked')
        ->and(fn () => storageJournal('auto', '2026-10-04', 'invoice', 2))->toThrow(QueryException::class, 'locked');

    LedgerSetting::current()->forceFill(['locked_until' => '2026-10-04'])->save();
    DB::table('journal_lines')->where('journal_id', $auto->id)->delete();
    DB::table('journals')->where('id', $auto->id)->delete();

    expect(Journal::query()->whereKey($auto->id)->exists())->toBeFalse();
});

test('a line has exactly one of debit or credit, one source has one journal, and the lock never precedes the start', function () {
    $journal = storageJournal('auto', '2026-10-05', 'payment', 7);
    $cash = LedgerAccount::query()->where('system_key', 'cash')->value('id');

    expect(fn () => $journal->lines()->create(['ledger_account_id' => $cash, 'debit' => '1.000', 'credit' => '1.000']))->toThrow(QueryException::class)
        ->and(fn () => $journal->lines()->create(['ledger_account_id' => $cash, 'debit' => '0.000', 'credit' => '0.000']))->toThrow(QueryException::class)
        ->and(fn () => storageJournal('auto', '2026-10-06', 'payment', 7))->toThrow(QueryException::class)
        ->and(fn () => LedgerSetting::current()->forceFill(['locked_until' => '2026-10-05'])->save())->toThrow(QueryException::class);
});
