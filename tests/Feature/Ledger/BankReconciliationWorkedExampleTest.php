<?php

use App\Actions\Ledger\FinishBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\RecordBankOnlyLine;
use App\Actions\Ledger\ReopenBankReconciliation;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Ledger\BankEntries;
use App\Livewire\Ledger\BankReconciliationShow;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\LedgerSetting;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('the bank reconciliation worked example: Bank to 20/10, then to 25/10', function () {
    $f = bankRecFixture();
    $match = fn (BankReconciliation $rec, string $line, array $keys) => app(MatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine($line)->id, $keys);
    $figures = fn (BankReconciliation $rec) => array_slice(BankReconciliationShow::reportRows($rec->fresh()), 1, 9);

    // Reconciliation 1, before matching.
    $first = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    expect($figures($first))->toBe([
        ['Statement opening balance', '1000.000'],
        ['Statement closing balance', '1198.700'],
        ['Deposits in transit', '650.000'],
        ['Outstanding payments', '340.000'],
        ['Adjusted balance', '1508.700'],
        ['Ledger balance at the end date', '1310.000'],
        ['Difference', '198.700'],
        ['Statement lines not matched', '4'],
        ['Opening balance plus matched statement lines', '1000.000'],
    ])->and(fn () => app(FinishBankReconciliation::class)->handle($f['finance'], $first))->toThrow(ValidationException::class)
        ->and(array_map(fn (stdClass $e) => $e->entry_type.':'.$e->entry_id, BankEntries::suggestions($first, bankLine('TRANSFER '.$f['headLease']->number))))
        ->toBe(['disbursement:'.$f['headLease']->id]);

    // Matching, two bank-only lines, finish.
    $match($first, 'TRANSFER '.$f['headLease']->number, ['disbursement:'.$f['headLease']->id]);
    $match($first, 'CASH DEPOSIT DEP-1 DEP-2', ['cash_movement:'.$f['dep1']->id, 'cash_movement:'.$f['dep2']->id]);
    $charge = app(RecordBankOnlyLine::class)->handle($f['finance'], $first, (string) bankLine('BANK CHARGES OCT')->id, 'bank_charge', null);
    $interest = app(RecordBankOnlyLine::class)->handle($f['finance'], $first, (string) bankLine('CREDIT INTEREST')->id, 'bank_interest', null);
    app(FinishBankReconciliation::class)->handle($f['finance'], $first);

    $report = [
        ['Bank reconciliation', '1010 Bank', '20/10/2026', 'Finished'],
        ['Statement opening balance', '1000.000'],
        ['Statement closing balance', '1198.700'],
        ['Deposits in transit', '150.000'],
        ['Outstanding payments', '40.000'],
        ['Adjusted balance', '1308.700'],
        ['Ledger balance at the end date', '1308.700'],
        ['Difference', '0.000'],
        ['Statement lines not matched', '0'],
        ['Opening balance plus matched statement lines', '1198.700'],
        ['Deposits in transit'],
        ['Date', 'Source', 'Description', 'Amount'],
        ['19/10/2026', 'Cash and bank movement', 'DEP-3', '150.000'],
        ['Outstanding payments'],
        ['Date', 'Source', 'Description', 'Amount'],
        ['17/10/2026', 'Manual journal', 'Office chairs CHQ 7781', '-40.000'],
    ];
    expect(BankReconciliationShow::reportRows($first->fresh()))->toBe($report)
        ->and(postedLines('cash_movement', $charge->id))->toBe(['5180 2.500 0.000', '1010 0.000 2.500'])
        ->and(postedLines('cash_movement', $interest->id))->toBe(['1010 1.200 0.000', '4300 0.000 1.200'])
        ->and(ledgerBalances('2026-10-20')[1010])->toBe('1308.700')
        ->and(BankMatch::query()->count())->toBe(5)
        ->and(fn () => app(ReverseMoneyEntry::class)->handle($f['finance'], $f['dep1'], '2026-10-20', 'Typo'))
        ->toThrow(ValidationException::class, 'This entry is reconciled with the bank statement up to 20/10/2026, so it cannot be reversed on or before that day.');

    // A rebuild re-posts every automatic journal under the same source keys.
    app(RebuildLedger::class)->handle($f['finance'], '2026-10', '2026-10');
    expect(BankReconciliationShow::reportRows($first->fresh()))->toBe($report)->and(BankMatch::query()->count())->toBe(5);

    // Statement 2 and reconciliation 2, chained from reconciliation 1.
    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Bahrain'));
    $statement = bankStatement($f['finance'], '1010', [['20/10/2026', 'CREDIT INTEREST', '', '1.200'], ['21/10/2026', 'CASH DEPOSIT DEP-3', '', '150.000'],
        ['22/10/2026', 'CHQ 7781', '', '-40.000']]);
    $next = bankRec($f['finance'], '2026-10-25', '1308.700');
    $match($next, 'CASH DEPOSIT DEP-3', ['cash_movement:'.$f['dep3']->id]);
    $match($next, 'CHQ 7781', ['manual:'.$f['manual']->id]);
    app(FinishBankReconciliation::class)->handle($f['finance'], $next);

    expect([$statement['upload']->imported, $statement['upload']->skipped])->toBe([2, 1])
        ->and($next->fresh()->opening_balance)->toBe('1198.700')
        ->and($figures($next))->toBe([
            ['Statement opening balance', '1198.700'],
            ['Statement closing balance', '1308.700'],
            ['Deposits in transit', '0.000'],
            ['Outstanding payments', '0.000'],
            ['Adjusted balance', '1308.700'],
            ['Ledger balance at the end date', '1308.700'],
            ['Difference', '0.000'],
            ['Statement lines not matched', '0'],
            ['Opening balance plus matched statement lines', '1308.700'],
        ])
        ->and(BankReconciliationShow::reportRows($first->fresh()))->toBe($report) // reconciliation 1 reads the same
        ->and(fn () => app(ReopenBankReconciliation::class)->handle($f['admin'], $first))
        ->toThrow(ValidationException::class, 'Only the latest reconciliation of an account can be reopened.')
        ->and(fn () => app(ReopenBankReconciliation::class)->handle($f['finance'], $next))->toThrow(AuthorizationException::class);

    app(ReopenBankReconciliation::class)->handle($f['admin'], $next);
    expect($next->fresh()->status)->toBe('open')
        ->and(BankMatch::query()->where('bank_reconciliation_id', $next->id)->count())->toBe(2);
});
