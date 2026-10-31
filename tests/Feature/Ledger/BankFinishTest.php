<?php

use App\Actions\Ledger\DiscardBankReconciliation;
use App\Actions\Ledger\FinishBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\RecordBankOnlyLine;
use App\Actions\Ledger\ReopenBankReconciliation;
use App\Actions\Ledger\UnmatchBankLine;
use App\Ledger\BankEntries;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\LedgerSetting;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = bankRecFixture();
    $this->rec = bankRec($this->f['finance'], '2026-10-20', '1198.700', '1000');
    $this->match = fn (string $line, array $keys, ?BankReconciliation $rec = null) => app(MatchBankLine::class)->handle($this->f['finance'], $rec ?? $this->rec,
        (string) bankLine($line)->id, $keys);
    $this->only = fn (string $line, mixed $kind, mixed $other = null) => app(RecordBankOnlyLine::class)->handle($this->f['finance'], $this->rec,
        (string) bankLine($line)->id, $kind, $other);
    $this->matchAll = function () {
        ($this->match)('TRANSFER '.$this->f['headLease']->number, ['disbursement:'.$this->f['headLease']->id]);
        ($this->match)('CASH DEPOSIT DEP-1 DEP-2', ['cash_movement:'.$this->f['dep1']->id, 'cash_movement:'.$this->f['dep2']->id]);
        ($this->only)('BANK CHARGES OCT', 'bank_charge');
        ($this->only)('CREDIT INTEREST', 'bank_interest');
    };
    $this->figures = fn (?BankReconciliation $rec = null) => array_intersect_key(BankEntries::figures(($rec ?? $this->rec)->fresh()),
        array_flip(['in_transit', 'outstanding', 'adjusted', 'ledger', 'difference', 'covered', 'unmatched_lines', 'changed']));
    $this->done = ['in_transit' => 150000, 'outstanding' => 40000, 'adjusted' => 1308700, 'ledger' => 1308700, 'difference' => 0, 'covered' => true,
        'unmatched_lines' => 0, 'changed' => 0];
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('a bank-only line becomes a cash and bank movement through the existing action, matched with it', function () {
    $f = $this->f;
    expect(fn () => ($this->only)('BANK CHARGES OCT', 'bank_interest'))->toThrow(ValidationException::class, 'A bank charge is money out; bank interest is money in.')
        ->and(fn () => ($this->only)('BANK CHARGES OCT', ['x']))->toThrow(ValidationException::class, 'Choose what this line is.');
    $charge = ($this->only)('BANK CHARGES OCT', 'bank_charge');
    bankStatement($f['finance'], '1010', [['18/10/2026', 'TRANSFER TO PETTY CASH', 'TR-9', '-25']]);
    expect(fn () => ($this->only)('TRANSFER TO PETTY CASH', 'transfer'))->toThrow(ValidationException::class)
        ->and(fn () => ($this->only)('TRANSFER TO PETTY CASH', 'transfer', (string) $f['bank']))->toThrow(ValidationException::class, 'Choose two different money accounts.');
    $transfer = ($this->only)('TRANSFER TO PETTY CASH', 'transfer', (string) $f['cash']);

    expect([$charge->kind->value, $charge->from_account_id, $charge->to_account_id, $charge->amount, $charge->date->toDateString(), $charge->reference])
        ->toBe(['bank_charge', $f['bank'], null, '2.500', '2026-10-15', 'CHG-OCT'])
        ->and(postedLines('cash_movement', $charge->id))->toBe(['5180 2.500 0.000', '1010 0.000 2.500'])
        ->and([$transfer->from_account_id, $transfer->to_account_id, $transfer->amount, $transfer->reference])->toBe([$f['bank'], $f['cash'], '25.000', 'TR-9'])
        ->and(BankMatch::query()->orderBy('id')->get()->map(fn (BankMatch $m) => [$m->entry_type, $m->entry_id, $m->amount])->all())
        ->toBe([['cash_movement', $charge->id, '-2.500'], ['cash_movement', $transfer->id, '-25.000']]);
});

test('finishing waits for every statement line to the end date and a difference of 0.000', function () {
    expect(($this->figures)())->toBe(['in_transit' => 650000, 'outstanding' => 340000, 'adjusted' => 1508700, 'ledger' => 1310000, 'difference' => 198700,
        'covered' => false, 'unmatched_lines' => 4, 'changed' => 0]);
    try {
        app(FinishBankReconciliation::class)->handle($this->f['finance'], $this->rec);
        $this->fail('Finishing should be refused.');
    } catch (ValidationException $e) {
        expect($e->errors()['finish'])->toBe([
            'Statement lines up to the end date not matched: 4.',
            'The opening balance plus the matched statement lines is 1000.000, not the closing balance 1198.700.',
            'The difference is 198.700; it must be 0.000.',
        ]);
    }

    ($this->matchAll)();
    expect(($this->figures)())->toBe($this->done);
    app(FinishBankReconciliation::class)->handle($this->f['finance'], $this->rec);
    $rec = $this->rec->fresh();

    expect([$rec->status, $rec->finished_by])->toBe(['finished', $this->f['finance']->id])
        ->and(Activity::query()->where('event', 'bank.reconciliation_finished')->exists())->toBeTrue()
        ->and(fn () => app(DiscardBankReconciliation::class)->handle($this->f['finance'], $rec))->toThrow(ValidationException::class, 'This reconciliation is finished.')
        ->and(fn () => app(FinishBankReconciliation::class)->handle($this->f['finance'], $rec))->toThrow(ValidationException::class, 'This reconciliation is finished.');
});

test('the next reconciliation starts from the last closing balance with a later end date; a changed entry blocks finishing', function () {
    $f = $this->f;
    ($this->matchAll)();
    app(FinishBankReconciliation::class)->handle($f['finance'], $this->rec);
    expect(fn () => bankRec($f['finance'], '2026-10-20', '1308.700'))
        ->toThrow(ValidationException::class, 'Choose an end date after 20/10/2026, the end date of the last reconciliation.');

    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Bahrain'));
    bankStatement($f['finance'], '1010', [['21/10/2026', 'CASH DEPOSIT DEP-3', '', '150'], ['22/10/2026', 'CHQ 7781', '', '-40']]);
    $next = bankRec($f['finance'], '2026-10-25', '1308.700', '5');
    expect($next->opening_balance)->toBe('1198.700');
    ($this->match)('CASH DEPOSIT DEP-3', ['cash_movement:'.$f['dep3']->id], $next);
    ($this->match)('CHQ 7781', ['manual:'.$f['manual']->id], $next);

    // As if a posting rule had moved the entry since it was matched: the stored amount no longer agrees with the ledger.
    $m = BankMatch::query()->where('entry_type', 'manual')->sole();
    DB::table('bank_matches')->where('id', $m->id)->delete();
    DB::table('bank_matches')->insert([...$m->only(['bank_reconciliation_id', 'bank_statement_line_id', 'ledger_account_id', 'entry_type', 'entry_id', 'created_by']),
        'amount' => '-41.000']);
    expect(fn () => app(FinishBankReconciliation::class)->handle($f['finance'], $next))
        ->toThrow(ValidationException::class, 'Matched entries that changed in the ledger since they were matched: 1. Remove those matches and match again.');

    app(UnmatchBankLine::class)->handle($f['finance'], $next, (string) bankLine('CHQ 7781')->id);
    ($this->match)('CHQ 7781', ['manual:'.$f['manual']->id], $next);
    app(FinishBankReconciliation::class)->handle($f['finance'], $next);

    expect(($this->figures)($next))->toBe(['in_transit' => 0, 'outstanding' => 0, 'adjusted' => 1308700, 'ledger' => 1308700, 'difference' => 0, 'covered' => true,
        'unmatched_lines' => 0, 'changed' => 0])
        ->and(($this->figures)())->toBe($this->done); // the first one reads the same after the second matched its in-transit items
});

test('Admin reopens only the latest finished reconciliation of the account; its matches stay', function () {
    $f = $this->f;
    ($this->matchAll)();
    app(FinishBankReconciliation::class)->handle($f['finance'], $this->rec);
    expect(fn () => app(ReopenBankReconciliation::class)->handle($f['finance'], $this->rec))->toThrow(AuthorizationException::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Bahrain'));
    $next = bankRec($f['finance'], '2026-10-25', '1198.700');
    expect(fn () => app(ReopenBankReconciliation::class)->handle($f['admin'], $this->rec))
        ->toThrow(ValidationException::class, 'Only the latest reconciliation of an account can be reopened.')
        ->and(fn () => app(ReopenBankReconciliation::class)->handle($f['admin'], $next))->toThrow(ValidationException::class, 'Only a finished reconciliation can be reopened.');

    app(DiscardBankReconciliation::class)->handle($f['finance'], $next);
    app(ReopenBankReconciliation::class)->handle($f['admin'], $this->rec);
    $rec = $this->rec->fresh();

    expect([$rec->status, $rec->finished_by, $rec->finished_at, $rec->reopened_by])->toBe(['open', null, null, $f['admin']->id])
        ->and(BankMatch::query()->where('bank_reconciliation_id', $rec->id)->count())->toBe(5)
        ->and(Activity::query()->where('event', 'bank.reconciliation_reopened')->sole()->causer_id)->toBe($f['admin']->id);
});

test('matches survive a ledger rebuild; finishing waits while a rebuild runs', function () {
    $f = $this->f;
    ($this->matchAll)();
    $before = journalFor('cash_movement', $f['dep1']->id)?->id;
    LedgerSetting::query()->update(['rebuild_status' => 'running']);
    expect(fn () => app(FinishBankReconciliation::class)->handle($f['finance'], $this->rec))->toThrow(ValidationException::class, 'Wait for the rebuild to finish.');
    LedgerSetting::query()->update(['rebuild_status' => null]);
    app(FinishBankReconciliation::class)->handle($f['finance'], $this->rec);

    app(RebuildLedger::class)->handle($f['finance'], '2026-10', '2026-10');

    expect(journalFor('cash_movement', $f['dep1']->id)?->id)->not->toBe($before)
        ->and(BankMatch::query()->count())->toBe(5)
        ->and(($this->figures)())->toBe($this->done);
});
