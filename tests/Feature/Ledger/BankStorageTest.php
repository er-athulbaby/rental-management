<?php

use App\Integrity\IntegrityCheck;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->bank = (int) LedgerAccount::query()->where('code', '1010')->value('id');
    $this->upload = DB::table('bank_uploads')->insertGetId(['ledger_account_id' => $this->bank, 'file_name' => 's.csv', 'imported' => 2, 'skipped' => 0, 'failed' => 0,
        'uploaded_by' => $this->user->id]);
    $this->line = fn (string $description, array $extra = []) => DB::table('bank_statement_lines')->insertGetId(['ledger_account_id' => $this->bank,
        'bank_upload_id' => $this->upload, 'row_number' => 2, 'date' => '2026-10-10', 'description' => $description, 'amount' => '5.000',
        'description_hash' => hash('sha256', mb_strtolower($description)), 'occurrence' => 1, ...$extra]);
    $this->rec = fn (string $end, string $status) => DB::table('bank_reconciliations')->insertGetId(['ledger_account_id' => $this->bank, 'end_date' => $end,
        'opening_balance' => '0.000', 'closing_balance' => '10.000', 'status' => $status, 'created_by' => $this->user->id,
        'finished_by' => $status === 'finished' ? $this->user->id : null, 'finished_at' => $status === 'finished' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
});

test('statement lines are never updated; a finished reconciliation takes, changes and loses no match', function () {
    $a = ($this->line)('A');
    $b = ($this->line)('B');
    $rec = ($this->rec)('2026-10-20', 'open');
    $match = fn (int $line, int $entry) => DB::table('bank_matches')->insert(['bank_reconciliation_id' => $rec, 'bank_statement_line_id' => $line,
        'ledger_account_id' => $this->bank, 'entry_type' => 'manual', 'entry_id' => $entry, 'amount' => '5.000', 'created_by' => $this->user->id]);
    $match($a, 1);
    DB::table('bank_reconciliations')->where('id', $rec)->update(['status' => 'finished', 'finished_by' => $this->user->id, 'finished_at' => now()]);

    expect(fn () => DB::table('bank_statement_lines')->where('id', $a)->update(['description' => 'X']))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => DB::table('bank_matches')->update(['amount' => '6.000']))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => $match($b, 2))->toThrow(QueryException::class, 'the reconciliation is finished')
        ->and(fn () => DB::table('bank_matches')->delete())->toThrow(QueryException::class, 'the reconciliation is finished');

    DB::table('bank_reconciliations')->where('id', $rec)->update(['status' => 'open', 'finished_by' => null, 'finished_at' => null]);
    expect(DB::table('bank_matches')->delete())->toBe(1)
        ->and(DB::table('bank_statement_lines')->where('id', $b)->delete())->toBe(1);
});

test('one open reconciliation per account, one per end date, a finished one says who and when, and no duplicate statement line', function () {
    ($this->rec)('2026-09-30', 'finished');
    ($this->rec)('2026-10-20', 'open');
    ($this->line)('A');

    expect(fn () => ($this->rec)('2026-10-25', 'open'))->toThrow(QueryException::class, 'bank_reconciliations_one_open')
        ->and(fn () => ($this->rec)('2026-10-20', 'finished'))->toThrow(QueryException::class, 'Duplicate entry')
        ->and(fn () => DB::table('bank_reconciliations')->insert(['ledger_account_id' => $this->bank, 'end_date' => '2026-08-31', 'opening_balance' => '0.000',
            'closing_balance' => '0.000', 'status' => 'finished', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class, 'bank_reconciliations_finished_chk')
        ->and(fn () => ($this->line)('a'))->toThrow(QueryException::class, 'bank_statement_lines_duplicate_unique')
        ->and(fn () => ($this->line)('C', ['amount' => '0.000']))->toThrow(QueryException::class, 'bank_statement_lines_amount_chk')
        ->and(($this->line)('A', ['occurrence' => 2]))->toBeInt();
});

test('the trigger count is 77 and the migration will not roll back over rows', function () {
    $migration = require database_path('migrations/2026_12_29_000100_create_bank_reconciliation_tables.php');

    expect(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(77)
        ->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(77)
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Nothing was changed')
        ->and(Schema::hasTable('bank_uploads') && Schema::hasTable('bank_matches'))->toBeTrue()
        ->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(77); // the refused down() dropped nothing
});

test('a match of 0.000 and a mapping with an unknown date format are refused by their check constraints', function () {
    $rec = ($this->rec)('2026-10-20', 'open');
    $line = ($this->line)('A');

    expect(fn () => DB::table('bank_matches')->insert(['bank_reconciliation_id' => $rec, 'bank_statement_line_id' => $line, 'ledger_account_id' => $this->bank,
        'entry_type' => 'manual', 'entry_id' => 1, 'amount' => '0.000', 'created_by' => $this->user->id]))->toThrow(QueryException::class, 'bank_matches_amount_chk')
        ->and(fn () => DB::table('bank_mappings')->insert(['ledger_account_id' => $this->bank, 'header_row' => 1, 'date_column' => 1, 'date_format' => 'm/d/Y',
            'description_column' => 2, 'amount_mode' => 'signed', 'amount_column' => 3, 'updated_by' => $this->user->id]))->toThrow(QueryException::class, 'bank_mappings_format_chk');
});
