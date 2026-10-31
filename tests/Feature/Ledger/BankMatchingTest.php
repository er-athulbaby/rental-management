<?php

use App\Actions\Ledger\DiscardBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\PostManualJournal;
use App\Actions\Ledger\StartBankReconciliation;
use App\Actions\Ledger\UnmatchBankLine;
use App\Actions\Payments\RecordPayment;
use App\Ledger\BankEntries;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\LedgerAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = bankRecFixture();
    $this->match = fn (BankReconciliation $rec, string $line, array $keys) => app(MatchBankLine::class)->handle($this->f['finance'], $rec, (string) bankLine($line)->id, $keys);
    $this->pay = fn (string $on, string $amount, string $reference) => app(RecordPayment::class)->handle($this->f['finance'], $this->f['omar'],
        ['received_on' => $on, 'method' => 'bank_transfer', 'amount' => $amount, 'reference' => $reference]);
});

test('an entry is one journal\'s net on the money account, keyed by its source and dated by its journal; the opening journal is not one', function () {
    $f = $this->f;
    $split = app(RecordPayment::class)->handle($f['finance'], $f['omar'], ['received_on' => '2026-10-18', 'tenders' => [['method' => 'bank_transfer', 'amount' => '100'],
        ['method' => 'cash', 'amount' => '50']]]);
    $rows = fn (int $account, string $end) => BankEntries::query($account, $end)->orderBy('j.date')->orderBy('j.id')->get()
        ->map(fn (stdClass $e) => [$e->entry_type, (int) $e->entry_id, $e->date, $e->amount])->all();

    expect($rows($f['bank'], '2026-10-20'))->toBe([
        ['disbursement', $f['headLease']->id, '2026-10-10', '-300.000'],
        ['cash_movement', $f['dep1']->id, '2026-10-12', '200.000'],
        ['cash_movement', $f['dep2']->id, '2026-10-12', '300.000'],
        ['manual', $f['manual']->id, '2026-10-17', '-40.000'],
        ['payment', $split->id, '2026-10-18', '100.000'],
        ['cash_movement', $f['dep3']->id, '2026-10-19', '150.000'],
    ])->and(BankEntries::query($f['cash'], '2026-10-20')->where('j.source_type', 'payment')->where('j.source_id', $split->id)->get()->pluck('amount')->all())->toBe(['50.000'])
        ->and($rows($f['bank'], '2026-10-11'))->toBe([['disbursement', $f['headLease']->id, '2026-10-10', '-300.000']]);
});

test('suggestions: same direction and amount, within 7 days, by the end date, unmatched; a reference first, then the nearest date', function () {
    $f = $this->f;
    ($this->pay)('2026-10-05', '100', 'ZZZ-1');                  // 8 days before the line: outside the window
    $near = ($this->pay)('2026-10-12', '100', 'ZZZ-2');          // 1 day
    $named = ($this->pay)('2026-10-15', '100', 'OMAR-OCT');      // 2 days; its reference is in the line
    $last = ($this->pay)('2026-10-20', '100', 'ZZZ-3');          // 7 days
    app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-13', 'description' => 'Refund out', 'lines' => [
        ['account_id' => (int) LedgerAccount::query()->where('code', '5100')->value('id'), 'debit' => '100'], ['account_id' => $f['bank'], 'credit' => '100']]]); // money out
    bankStatement($f['finance'], '1010', [['13/10/2026', 'TRANSFER FROM OMAR OMAR-OCT', '', '100']]);
    $keys = fn (BankReconciliation $rec) => array_map(fn (stdClass $e) => $e->entry_type.':'.$e->entry_id, BankEntries::suggestions($rec, bankLine('TRANSFER FROM OMAR OMAR-OCT')));

    $rec = bankRec($f['finance'], '2026-10-20', '0', '0');
    expect($keys($rec))->toBe(["payment:{$named->id}", "payment:{$near->id}", "payment:{$last->id}"])
        ->and(array_map(fn (stdClass $e) => $e->entry_type, BankEntries::suggestions($rec, bankLine('TRANSFER '.$f['headLease']->number))))->toBe(['disbursement']);

    ($this->match)($rec, 'TRANSFER FROM OMAR OMAR-OCT', ["payment:{$named->id}"]);
    expect($keys($rec))->toBe(["payment:{$near->id}", "payment:{$last->id}"]);

    app(DiscardBankReconciliation::class)->handle($f['finance'], $rec);
    expect($keys(bankRec($f['finance'], '2026-10-14', '0', '0')))->toBe(["payment:{$near->id}"]);
});

test('a statement line matches several entries whose total equals it; an entry matches one line; matches come off while open', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $dep1 = 'cash_movement:'.$f['dep1']->id;
    $dep2 = 'cash_movement:'.$f['dep2']->id;
    $deposit = 'CASH DEPOSIT DEP-1 DEP-2';

    expect(fn () => ($this->match)($rec, $deposit, [$dep1]))->toThrow(ValidationException::class, 'The chosen entries total 200.000; the statement line is 500.000.');
    ($this->match)($rec, $deposit, [$dep2, $dep1]);
    expect(BankMatch::query()->orderBy('id')->get()->map(fn (BankMatch $m) => [$m->entry_type, $m->entry_id, $m->amount])->all())
        ->toBe([['cash_movement', $f['dep1']->id, '200.000'], ['cash_movement', $f['dep2']->id, '300.000']])
        ->and(fn () => ($this->match)($rec, $deposit, [$dep1, $dep2]))->toThrow(ValidationException::class, 'This statement line is already matched.')
        ->and(fn () => ($this->match)($rec, 'CREDIT INTEREST', [$dep1]))->toThrow(ValidationException::class, 'A chosen entry is already matched to another statement line.');

    app(UnmatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine($deposit)->id);
    expect(BankMatch::query()->count())->toBe(0)
        ->and(fn () => app(UnmatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine($deposit)->id))
        ->toThrow(ValidationException::class, 'This statement line has no match in this reconciliation.');

    ($this->match)($rec, $deposit, [$dep1, $dep2]);
    app(DiscardBankReconciliation::class)->handle($f['finance'], $rec);
    expect(BankMatch::query()->count() + BankReconciliation::query()->count())->toBe(0);
});

test('the database refuses a second match row for the same account, entry type and entry id', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    ($this->match)($rec, 'CASH DEPOSIT DEP-1 DEP-2', ['cash_movement:'.$f['dep1']->id, 'cash_movement:'.$f['dep2']->id]);
    $row = ['bank_reconciliation_id' => $rec->id, 'bank_statement_line_id' => bankLine('CREDIT INTEREST')->id, 'ledger_account_id' => $f['bank'],
        'entry_type' => 'cash_movement', 'entry_id' => $f['dep1']->id, 'amount' => '1.200', 'created_by' => $f['finance']->id, 'created_at' => now()];

    expect(fn () => DB::table('bank_matches')->insert($row))->toThrow(QueryException::class, 'bank_matches_entry_unique')
        ->and(BankMatch::query()->count())->toBe(2);
});

test('the first reconciliation asks for the opening balance; one is open per account at a time', function () {
    $f = $this->f;
    $start = fn (string $code, array $data) => app(StartBankReconciliation::class)->handle($f['finance'],
        ['ledger_account_id' => (string) LedgerAccount::query()->where('code', $code)->value('id'), ...$data]);

    expect(fn () => bankRec($f['finance'], '2026-10-20', '1198.700'))
        ->toThrow(ValidationException::class, 'Enter the statement opening balance: this is the first reconciliation of this account.')
        ->and(fn () => bankRec($f['finance'], '2026-10-21', '1', '0'))->toThrow(ValidationException::class)
        ->and(fn () => bankRec($f['finance'], '2026-10-20', '1,198.700', '0'))
        ->toThrow(ValidationException::class, 'Enter an amount with up to 3 decimals, with a minus sign if it is overdrawn.')
        ->and(fn () => $start('4000', ['end_date' => '2026-10-20', 'closing_balance' => '0', 'opening_balance' => '0']))
        ->toThrow(ValidationException::class, 'Choose an active money account.');

    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    expect([$rec->status, $rec->opening_balance, $rec->closing_balance, $rec->end_date->toDateString()])->toBe(['open', '1000.000', '1198.700', '2026-10-20'])
        ->and(fn () => bankRec($f['finance'], '2026-10-19', '1', '0'))
        ->toThrow(ValidationException::class, 'This account already has an open reconciliation. Finish or discard it first.')
        ->and($start('1000', ['end_date' => '2026-10-20', 'closing_balance' => '-5', 'opening_balance' => '0'])->closing_balance)->toBe('-5.000');
});

test('tampered or stale input gets a message, never a 500', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-18', '0', '0');
    $line = (string) bankLine('CASH DEPOSIT DEP-1 DEP-2')->id;
    $dep1 = 'cash_movement:'.$f['dep1']->id;
    $match = fn (mixed $line, mixed $keys) => app(MatchBankLine::class)->handle($f['finance'], $rec, $line, $keys);
    $pick = 'Choose the ledger entries that make up this line.';
    $gone = 'A chosen entry is not on this account by the end date.';

    expect(fn () => $match($line, 'x'))->toThrow(ValidationException::class, $pick)
        ->and(fn () => $match($line, [['a']]))->toThrow(ValidationException::class, $pick)
        ->and(fn () => $match($line, ['payment:1e3']))->toThrow(ValidationException::class, $pick)
        ->and(fn () => $match('abc', [$dep1]))->toThrow(ValidationException::class, 'Choose a statement line of this account.')
        ->and(fn () => $match((string) bankLine('CREDIT INTEREST')->id, [$dep1]))->toThrow(ValidationException::class, 'This statement line is after the end date.')
        ->and(fn () => $match($line, ['payment:'.$f['saraPayment']->id]))->toThrow(ValidationException::class, $gone) // cash: not on Bank
        ->and(fn () => $match($line, ['cash_movement:'.$f['dep3']->id]))->toThrow(ValidationException::class, $gone) // 19/10: after the end date
        ->and(fn () => app(UnmatchBankLine::class)->handle($f['finance'], $rec, ['x']))
        ->toThrow(ValidationException::class, 'This statement line has no match in this reconciliation.');
});

test('only Finance starts, matches, unmatches and discards', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $line = (string) bankLine('TRANSFER '.$f['headLease']->number)->id;

    expect(fn () => app(StartBankReconciliation::class)->handle($f['admin'], []))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MatchBankLine::class)->handle($f['admin'], $rec, $line, ['disbursement:'.$f['headLease']->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UnmatchBankLine::class)->handle($f['admin'], $rec, $line))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DiscardBankReconciliation::class)->handle($f['admin'], $rec))->toThrow(AuthorizationException::class);
});
