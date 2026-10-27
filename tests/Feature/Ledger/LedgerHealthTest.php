<?php

use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\SaveOpeningBalances;
use App\Actions\Payments\RecordPayment;
use App\Jobs\RebuildLedgerJob;
use App\Ledger\LedgerHealth;
use App\Ledger\Poster;
use App\Livewire\Ledger\Home;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
    $this->rows = function (): array {
        LedgerHealth::store();

        return collect(LedgerSetting::current()->health)->keyBy('check')->map(fn (array $r) => [$r['ledger'], $r['app']])->all();
    };
});

test('on the worked example every check matches, to the fil', function () {
    expect(($this->rows)())->toBe([
        'Trial balance' => [0, 0],
        'Records not posted' => [0, 0],
        'Tenant receivables' => [0, 0],
        'Tenant credit' => [0, 0],
        'Tenant deposits held' => [400_000, 400_000],
        'Owner payable' => [348_000, 348_000],
        'Vendor payable' => [80_000, 80_000],
        'Invoice lines with no owner contract' => [0, 0],
    ])->and(LedgerSetting::current()->health_checked_at)->not->toBeNull();
});

test('an unpaid managed charge is owed to the owner in the ledger and matches the statements once its uncollected net is added', function () {
    $f = $this->f;
    issuedInvoice($f['sara'], [['net' => '50.000', 'tax' => 'standard', 'type' => 'parking', 'au' => $f['saraAgreement']->agreementUnits->sole()]], '2026-10-20', $f['saraAgreement']);
    $rows = ($this->rows)();

    expect($rows['Tenant receivables'])->toBe([55_000, 55_000])
        ->and($rows['Owner payable'])->toBe([398_000, 398_000]);
});

test('a record written behind the actions shows as a difference until the nightly run posts it', function () {
    $f = $this->f;
    DB::table('payments')->insert(['number' => 'RCT-RAW-1', 'customer_id' => $f['omar']->id, 'received_on' => '2026-10-20', 'method' => 'cash', 'amount' => '25.000',
        'status' => 'confirmed', 'recorded_by' => $f['finance']->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $rows = ($this->rows)();

    expect($rows['Records not posted'])->toBe([1, 0])
        ->and($rows['Tenant credit'])->toBe([0, 25_000]);

    $this->artisan('rms:ledger:nightly')->assertExitCode(0);
    $rows = collect(LedgerSetting::current()->health)->keyBy('check');

    expect($rows->every(fn (array $r) => $r['ledger'] === $r['app']))->toBeTrue();
});

test('a unit with no owner contract is counted', function () {
    $f = $this->f;
    $unit = Unit::factory()->for($f['tower'])->create();
    $tenant = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    issuedInvoice($tenant, [['net' => '100.000', 'au' => $agreement->agreementUnits->sole()]], '2026-10-20', $agreement);

    expect(($this->rows)()['Invoice lines with no owner contract'])->toBe([1, 0]);
});

test('the Ledger page shows the health card to Finance and Admin only, and its forms work', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(Home::class)
        ->call('checkNow')->assertSee('Owner payable')->assertSee('348.000')
        ->set('lockDate', ['x'])->call('lock')->assertHasErrors('lockDate')
        ->set('lockDate', '2026-10-10')->call('lock')->assertHasNoErrors()
        ->set('fromMonth', '2026-10')->set('toMonth', '2026-10')->call('rebuild')->assertHasNoErrors()
        ->assertSee('Rebuilt 11/10/2026 – 20/10/2026.');
    expect(LedgerSetting::current()->locked_until->toDateString())->toBe('2026-10-10');

    Livewire::actingAs($f['admin'])->test(Home::class)->set('unlockDate', '')->call('unlock')->assertHasNoErrors();
    expect(LedgerSetting::current()->locked_until)->toBeNull();

    Livewire::actingAs($f['admin'])->test(Home::class)->call('lock')->assertForbidden();
    Livewire::actingAs($f['management'])->test(Home::class)->assertForbidden();
});

afterEach(function () {
    LedgerSetting::query()->update(['start_date' => null, 'locked_until' => null, 'health' => null, 'health_checked_at' => null, 'rebuild_status' => null, 'rebuild_from' => null,
        'rebuild_to' => null, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuild_message' => null, 'rebuild_run' => null]);
});

test('tampered input on the Ledger page gives a message, never a 500', function () {
    Livewire::actingAs($this->f['finance'])->test(Home::class)
        ->set('fromMonth', ['x'])->call('rebuild')->assertHasErrors('fromMonth')
        ->set('lockDate', 5)->call('lock')->assertHasErrors('lockDate');
    Livewire::actingAs($this->f['admin'])->test(Home::class)->set('unlockDate', ['x'])->call('unlock')->assertHasErrors('unlockDate');
});

test('each reconciliation shows a difference when its journal is removed', function () {
    $f = $this->f;
    $cases = [
        'Tenant receivables' => ['invoice', $f['saraRent']->id],
        'Tenant credit' => ['payment', $f['saraPayment']->id],
        'Tenant deposits held' => ['invoice', $f['saraDeposit']->id],
        'Owner payable' => ['owner_charge', $f['fee']->id],
        'Vendor payable' => ['expense', $f['companyExpense']->id],
    ];
    foreach ($cases as $check => [$type, $id]) {
        $journal = journalFor($type, $id);
        DB::table('journal_lines')->where('journal_id', $journal->id)->delete();
        DB::table('journals')->where('id', $journal->id)->delete();
        [$ledger, $app] = ($this->rows)()[$check];

        expect($ledger)->not->toBe($app, $check);
    }
});

test('records dated before the start date show no difference once the opening balances include them', function () {
    $f = $this->f;
    $at = fn (string $when) => $this->travelTo(CarbonImmutable::parse($when, 'Asia/Bahrain'));
    $au = $f['saraAgreement']->agreementUnits->sole();
    $at('2026-09-15 09:00');
    issuedInvoice($f['sara'], [['net' => '100.000', 'au' => $au]], '2026-09-15', $f['saraAgreement']);
    issuedInvoice($f['sara'], [['net' => '50.000', 'au' => $au]], '2026-09-16', $f['saraAgreement']);
    $at('2026-09-20 10:00');
    app(RecordPayment::class)->handle($f['finance'], $f['sara'], ['received_on' => '2026-09-20', 'method' => 'cash', 'amount' => '100.000']);
    $at('2026-10-12 10:00');
    app(RecordPayment::class)->handle($f['finance'], $f['sara'], ['received_on' => '2026-10-12', 'method' => 'cash', 'amount' => '50.000']);
    $at('2026-10-20 10:00');

    expect(($this->rows)()['Owner payable'])->toBe([348_000, 498_000])->and(Poster::pendingCount())->toBe(0);

    // At the start date: the first invoice was collected (cash 100, owner owed 100), the second accrued and still unpaid.
    $id = fn (string $code) => LedgerAccount::query()->where('code', $code)->value('id');
    app(SaveOpeningBalances::class)->handle($f['admin'], [
        $id('1000') => ['debit' => '100.000', 'credit' => ''],
        $id('1100') => ['debit' => '50.000', 'credit' => ''],
        $id('2100') => ['debit' => '', 'credit' => '150.000'],
    ]);

    expect(collect(($this->rows)())->every(fn (array $r) => $r[0] === $r[1]))->toBeTrue()
        ->and(($this->rows)()['Owner payable'])->toBe([498_000, 498_000]);
});

test('the nightly run fails a rebuild that has made no progress for 10 minutes, and leaves a fresh one alone', function () {
    LedgerSetting::query()->update(['rebuild_status' => 'running', 'rebuild_message' => null]);
    DB::table('ledger_settings')->update(['updated_at' => now()->subMinutes(5)]);
    $this->artisan('rms:ledger:nightly');
    expect(LedgerSetting::current()->rebuild_status)->toBe('running');

    DB::table('ledger_settings')->update(['updated_at' => now()->subMinutes(11)]);
    $this->artisan('rms:ledger:nightly');
    $settings = LedgerSetting::current();

    expect($settings->rebuild_status)->toBe('failed')->and($settings->rebuild_message)->toBe('Rebuild stopped: no progress for 10 minutes.')
        ->and($settings->rebuilding())->toBeFalse();
});

test('Check now needs ledger.view: Admin can run it, Management cannot open the page', function () {
    Livewire::actingAs($this->f['admin'])->test(Home::class)->assertSee('Check now')->call('checkNow')->assertHasNoErrors();
    expect(LedgerSetting::current()->health_checked_at)->not->toBeNull();
    Livewire::actingAs($this->f['management'])->test(Home::class)->assertForbidden();
});

test('a failed rebuild shows a fixed message, never the exception text', function () {
    LedgerSetting::query()->update(['rebuild_status' => 'failed', 'rebuild_message' => 'SQLSTATE[23000]: secret table text']);

    Livewire::actingAs($this->f['finance'])->test(Home::class)->assertSee('Rebuild failed. Details are in the application log.')->assertDontSee('SQLSTATE');
});

test('a leg of an old rebuild exits without writing once the rebuild was failed or replaced', function () {
    $journals = Journal::query()->count();
    LedgerSetting::query()->update(['rebuild_status' => 'queued', 'rebuild_run' => 'new-run', 'rebuild_done' => 0]);
    RebuildLedgerJob::dispatchSync('2026-10-01', '2026-10-20', $this->f['finance']->id, run: 'old-run');
    $settings = LedgerSetting::current();

    expect($settings->rebuild_status)->toBe('queued')->and($settings->rebuild_done)->toBe(0)->and(Journal::query()->count())->toBe($journals);

    LedgerSetting::query()->update(['rebuild_status' => 'failed', 'rebuild_message' => LedgerSetting::STALE_MESSAGE]);
    RebuildLedgerJob::dispatchSync('2026-10-01', '2026-10-20', $this->f['finance']->id, run: 'new-run');
    $settings = LedgerSetting::current();

    expect($settings->rebuild_status)->toBe('failed')->and($settings->rebuild_message)->toBe(LedgerSetting::STALE_MESSAGE)->and(Journal::query()->count())->toBe($journals);
});

test('a rebuild with its token, split into legs, still completes and each request gets its own token', function () {
    $journals = Journal::query()->count();
    app(RebuildLedger::class)->handle($this->f['finance'], '2026-10', '2026-10');
    $first = LedgerSetting::current();

    expect($first->rebuild_status)->toBe('done')->and($first->rebuild_run)->not->toBeNull()->and(Journal::query()->count())->toBe($journals);

    LedgerSetting::query()->update(['rebuild_status' => 'queued', 'rebuild_run' => 'r1']);
    RebuildLedgerJob::dispatchSync('2026-10-01', '2026-10-20', $this->f['finance']->id, budget: -1, run: 'r1');

    expect(LedgerSetting::current()->rebuild_status)->toBe('done')->and(Journal::query()->count())->toBe($journals);

    app(RebuildLedger::class)->handle($this->f['finance'], '2026-10', '2026-10');
    expect(LedgerSetting::current()->rebuild_run)->not->toBe($first->rebuild_run);
});
