<?php

use App\Actions\Ledger\MatchBankLine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['database.connections.mysql_b' => config('database.connections.mysql')]);
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(fn () => DB::purge('mysql_b'));

// Plan ruling 10: the account row lock is the action's first read. A plain read before it would fix the InnoDB snapshot,
// and the checks made under the lock would not see what the other writer committed while this one waited.
it('sees a match another connection commits while it waits for the account lock', function () {
    $f = bankRecFixture();
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $line = bankLine('CASH DEPOSIT DEP-1 DEP-2');
    $b = DB::connection('mysql_b');

    // B holds the account row and has matched the line; it commits just before the action's lock query runs.
    $b->beginTransaction();
    $b->table('ledger_accounts')->where('id', $f['bank'])->lockForUpdate()->first();
    $b->table('bank_matches')->insert(['bank_reconciliation_id' => $rec->id, 'bank_statement_line_id' => $line->id, 'ledger_account_id' => $f['bank'],
        'entry_type' => 'cash_movement', 'entry_id' => $f['dep1']->id, 'amount' => '200.000', 'created_by' => $f['finance']->id, 'created_at' => now()]);
    $committed = false;
    DB::beforeExecuting(function (string $sql) use ($b, &$committed) {
        if (! $committed && str_contains($sql, 'from `ledger_accounts`') && str_contains($sql, 'for update')) {
            $committed = true;
            $b->commit();
        }
    });

    expect(fn () => app(MatchBankLine::class)->handle($f['finance'], $rec, (string) $line->id, ['cash_movement:'.$f['dep1']->id, 'cash_movement:'.$f['dep2']->id]))
        ->toThrow(ValidationException::class, 'This statement line is already matched.')
        ->and($committed)->toBeTrue();
});
