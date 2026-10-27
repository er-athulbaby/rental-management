<?php

use App\Actions\Expenses\RecordExpense;
use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\UnlockLedger;
use App\Enums\RoleName;
use App\Jobs\RebuildLedgerJob;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\OwnerPayable;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

/** Every line with its journal's source, date and late flag, without ids: two ledgers built alike compare equal. */
function ledgerSnapshot(): array
{
    return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
        ->orderBy('j.kind')->orderBy('j.source_type')->orderBy('j.source_id')->orderBy('a.code')->orderBy('l.debit')->orderBy('l.credit')
        ->get(['j.kind', 'j.source_type', 'j.source_id', 'j.date', 'j.late_posting', 'a.code', 'l.debit', 'l.credit', 'l.customer_id', 'l.owner_id', 'l.building_id', 'l.unit_id'])
        ->map(fn ($r) => (array) $r)->all();
}

test('Finance locks forward, only Admin unlocks, and both are audited', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');

    expect(LedgerSetting::current()->locked_until->toDateString())->toBe('2026-10-10')
        ->and(fn () => app(LockLedger::class)->handle($f['finance'], '2026-10-09'))->toThrow(ValidationException::class, 'already locked')
        ->and(fn () => app(LockLedger::class)->handle($f['finance'], '2026-10-21'))->toThrow(ValidationException::class)
        ->and(fn () => app(LockLedger::class)->handle($f['finance'], ['2026-10-12']))->toThrow(ValidationException::class)
        ->and(fn () => app(LockLedger::class)->handle($f['admin'], '2026-10-12'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UnlockLedger::class)->handle($f['finance'], null))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UnlockLedger::class)->handle($f['admin'], '2026-09-30'))->toThrow(ValidationException::class);

    app(UnlockLedger::class)->handle($f['admin'], '2026-10-05');
    expect(LedgerSetting::current()->locked_until->toDateString())->toBe('2026-10-05');
    app(UnlockLedger::class)->handle($f['admin'], '');

    expect(LedgerSetting::current()->locked_until)->toBeNull()
        ->and(Activity::query()->where('event', 'ledger.locked')->sole()->causer_id)->toBe($f['finance']->id)
        ->and(Activity::query()->where('event', 'ledger.unlocked')->count())->toBe(2);
});

test('a locked month never changes: its journals cannot be deleted and nothing is rebuilt in it', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');
    $journal = journalFor('expense', $f['companyExpense']->id);

    expect(fn () => $journal->lines()->delete())->toThrow(QueryException::class, 'locked')
        ->and(fn () => app(RebuildLedger::class)->handle($f['finance'], '2026-09', '2026-09'))->toThrow(ValidationException::class, 'Nothing to rebuild');
});

test('a record dated in a locked month posts on the first open day, marked late', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');
    $expense = app(RecordExpense::class)->handle($f['finance'], ['building_id' => $f['tower']->id, 'category' => 'utilities', 'description' => 'Late bill',
        'expense_date' => '2026-10-04', 'net' => '12.000', 'charge_to' => 'company']);
    $journal = journalFor('expense', $expense->id);

    expect($journal->date->toDateString())->toBe('2026-10-11')
        ->and($journal->late_posting)->toBeTrue()
        ->and(postedLines('expense', $expense->id))->toBe(['5110 12.000 0.000', '2200 0.000 12.000']);
});

test('rebuilding an open month gives the same journals as live posting, keeps manual journals, and reports its progress', function () {
    $f = $this->f;
    $line = fn (string $code, int $amount) => ['account' => (int) LedgerAccount::query()->where('code', $code)->value('id'), 'amount' => $amount,
        'customer_id' => null, 'owner_id' => null, 'building_id' => null, 'unit_id' => null, 'memo' => null];
    $manual = Poster::write('manual', '2026-10-19', 'Bank charges', [$line('5190', 2_000), $line('1010', -2_000)], createdBy: $f['finance']->id);
    $before = ledgerSnapshot();
    $old = Journal::query()->where('kind', 'auto')->pluck('id');

    expect(app(RebuildLedger::class)->handle($f['finance'], '2026-10', '2026-10'))->toBe(['from' => '2026-10-01', 'to' => '2026-10-20']);

    $settings = LedgerSetting::current();
    expect(ledgerSnapshot())->toBe($before)
        ->and(Journal::query()->whereKey($old)->exists())->toBeFalse()
        ->and(Journal::query()->whereKey($manual->id)->exists())->toBeTrue()
        ->and($settings->rebuild_status)->toBe('done')
        ->and($settings->rebuild_total)->toBe($old->count())
        ->and($settings->rebuild_done)->toBe($old->count())
        ->and($settings->rebuild_from->toDateString())->toBe('2026-10-01')
        ->and(Activity::query()->where('event', 'ledger.rebuild_requested')->sole()->causer_id)->toBe($f['finance']->id)
        ->and(Activity::query()->where('event', 'ledger.rebuilt')->sole()->causer_id)->toBe($f['finance']->id);
});

test('a rebuild skips locked days, refuses while another runs, and needs Finance or Admin', function () {
    $f = $this->f;
    app(LockLedger::class)->handle($f['finance'], '2026-10-10');
    $locked = journalFor('expense', $f['companyExpense']->id)->id;

    expect(app(RebuildLedger::class)->handle($f['admin'], '2026-10', '2026-12'))->toBe(['from' => '2026-10-11', 'to' => '2026-10-20'])
        ->and(Journal::query()->whereKey($locked)->exists())->toBeTrue();

    LedgerSetting::current()->forceFill(['rebuild_status' => 'running'])->save();
    expect(fn () => app(RebuildLedger::class)->handle($f['finance'], '2026-10', '2026-10'))->toThrow(ValidationException::class, 'already running')
        ->and(fn () => app(LockLedger::class)->handle($f['finance'], '2026-10-12'))->toThrow(ValidationException::class, 'rebuild')
        ->and(fn () => app(RebuildLedger::class)->handle(matrixUser(RoleName::Leasing, $f['tower']), '2026-10', '2026-10'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RebuildLedger::class)->handle($f['admin'], '2026-11', '2026-10'))->toThrow(ValidationException::class);
});

test('the nightly run posts head-lease rent on its due date, and runs at 00:30', function () {
    $f = $this->f;
    $november = (new OwnerPayable)->forceFill(['owner_contract_id' => $f['leased']->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
        'due_date' => '2026-11-01', 'amount' => '300.000', 'status' => 'scheduled']);
    $november->save();
    expect(journalFor('owner_payable', $november->id))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30', 'Asia/Bahrain'));
    $this->artisan('rms:ledger:nightly')->assertExitCode(0);

    expect(journalFor('owner_payable', $november->id)->date->toDateString())->toBe('2026-11-01')
        ->and(scheduledEvent('rms:ledger:nightly')->expression)->toBe('30 0 * * *');
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'rebuild_status' => null, 'rebuild_from' => null, 'rebuild_to' => null,
        'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null]);
});

test('rebuilding the whole worked example leaves every balance as it was', function () {
    $before = ledgerBalances();
    app(RebuildLedger::class)->handle($this->f['admin'], '2026-10', '2026-10');

    expect(ledgerBalances())->toBe($before)->and(Poster::pendingCount())->toBe(0);
});

test('a lock that lands mid-rebuild fails the job cleanly with the trigger message', function () {
    $f = $this->f;
    LedgerSetting::query()->update(['locked_until' => '2026-10-10', 'rebuild_status' => 'queued']);

    (new RebuildLedgerJob('2026-10-01', '2026-10-20', $f['finance']->id))->handle(app(Poster::class));

    $settings = LedgerSetting::current();
    expect($settings->rebuild_status)->toBe('failed')
        ->and($settings->rebuild_message)->toBe('Rebuild failed. Details are in the application log.')
        ->and(Activity::query()->where('event', 'ledger.rebuilt')->exists())->toBeFalse();
});

test('a disbursement saved several times in one action is checked for posting once', function () {
    $head = $this->f['headLease'];
    $queries = 0;
    DB::listen(function ($q) use (&$queries) {
        $queries += str_contains($q->sql, 'ledger_settings') ? 1 : 0;
    });

    DB::transaction(function () use ($head) {
        foreach (range(1, 3) as $i) {
            $this->travel(1)->minutes();
            $head->touch();
        }
    });

    expect($queries)->toBe(2); // disbursement and disbursement_reversal, once each
});

test('a lock covers closed days only: today cannot be locked', function () {
    expect(fn () => app(LockLedger::class)->handle($this->f['finance'], '2026-10-20'))->toThrow(ValidationException::class)
        ->and(LedgerSetting::current()->locked_until)->toBeNull();

    app(LockLedger::class)->handle($this->f['finance'], '2026-10-19');
    expect(LedgerSetting::current()->locked_until->toDateString())->toBe('2026-10-19');
});

test('a rebuild split into many short legs gives the same ledger as one leg and ends done', function () {
    $f = $this->f;
    $before = ledgerSnapshot();
    $old = Journal::query()->where('kind', 'auto')->count();
    LedgerSetting::query()->update(['rebuild_status' => 'queued']);

    RebuildLedgerJob::dispatchSync('2026-10-01', '2026-10-20', $f['finance']->id, budget: -1); // every leg is over budget after one chunk

    $settings = LedgerSetting::current();
    expect(ledgerSnapshot())->toBe($before)
        ->and($settings->rebuild_status)->toBe('done')
        ->and($settings->rebuild_total)->toBe($old)
        ->and($settings->rebuild_done)->toBe($old)
        ->and(Activity::query()->where('event', 'ledger.rebuilt')->count())->toBe(1);
});

test('a job the queue fails leaves the rebuild failed, not running', function () {
    LedgerSetting::query()->update(['rebuild_status' => 'running']);

    (new RebuildLedgerJob('2026-10-01', '2026-10-20', $this->f['finance']->id))->failed(new RuntimeException('timed out'));

    $settings = LedgerSetting::current();
    expect($settings->rebuild_status)->toBe('failed')->and($settings->rebuild_message)->toBe('Rebuild failed. Details are in the application log.')->and($settings->rebuilding())->toBeFalse();
});

test('the nightly run reports what it really posted and fails while anything is still unposted', function () {
    $f = $this->f;
    $utilities = LedgerAccount::query()->where('code', '5110');
    $utilities->update(['active' => false]);
    $expense = app(RecordExpense::class)->handle($f['finance'], ['building_id' => $f['tower']->id, 'category' => 'utilities', 'description' => 'Stuck bill',
        'expense_date' => '2026-10-19', 'net' => '12.000', 'charge_to' => 'company']);
    expect(journalFor('expense', $expense->id))->toBeNull();

    $this->artisan('rms:ledger:nightly')->expectsOutputToContain('Posted 0 pending ledger sources, 1 still unposted.')->assertExitCode(1);

    $utilities->update(['active' => true]);
    $this->artisan('rms:ledger:nightly')->expectsOutputToContain('Posted 1 pending ledger sources, 0 still unposted.')->assertExitCode(0);
    expect(journalFor('expense', $expense->id))->not->toBeNull();
});
