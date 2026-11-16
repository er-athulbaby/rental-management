<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Dashboard;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
});

test('needs attention: only items with something to do, each for a role that can open its list', function () {
    $customer = Customer::factory()->create();
    issuedInvoice($customer, [['net' => '100.000']], '2026-09-01'); // overdue
    activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-11-30'],
        [Unit::factory()->for($this->building)->create()]); // ends within 60 days

    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    expect(Dashboard::attention($finance))
        ->toContain(['label' => 'Overdue invoices', 'count' => 1, 'url' => route('invoices.index', ['tab' => 'overdue'])])
        ->and(collect(Dashboard::attention($finance))->pluck('label')->all())->not->toContain('Bounced cheques'); // none bounced

    $leasing = matrixUser(RoleName::Leasing, $this->building); // no finance.view: no invoice or cheque items
    expect(Dashboard::attention($leasing))
        ->toBe([['label' => 'Agreements expiring in 60 days', 'count' => 1, 'url' => route('agreements.index', ['expiring' => 60])]]);

    Livewire::actingAs($leasing)->test(Dashboard::class)
        ->assertSeeInOrder(['Needs attention', 'Agreements expiring in 60 days', '1']);
});

test('needs attention says so when nothing is waiting', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->building);

    expect(Dashboard::attention($leasing))->toBe([]);
    Livewire::actingAs($leasing)->test(Dashboard::class)->assertSee('Nothing needs attention.');
});

test('needs attention counts only the viewer\'s buildings, and urgent means not started', function () {
    $other = Building::factory()->create();
    $mine = Unit::factory()->for($this->building)->create();
    $theirs = Unit::factory()->for($other)->create();
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-11-30'], [$mine]);
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-11-30'], [$theirs]); // building not assigned
    $pm = matrixUser(RoleName::PropertyManager, $this->building);
    $leasing = matrixUser(RoleName::Leasing, $this->building);
    maintenanceRequest($pm, $mine, ['priority' => 'urgent']);
    $started = maintenanceRequest($pm, $mine, ['priority' => 'urgent']);
    workOrderFor($pm, $started, maintenanceVendor(['trades' => ['plumbing']])); // in progress: not "not started"
    maintenanceRequest($pm, $theirs, ['priority' => 'urgent']);

    expect(Dashboard::attention($leasing))->toBe([
        ['label' => 'Agreements expiring in 60 days', 'count' => 1, 'url' => route('agreements.index', ['expiring' => 60])],
        ['label' => 'Urgent, not started', 'count' => 1, 'url' => route('maintenance.requests.index', ['priority' => 'urgent', 'tab' => 'open'])],
    ]);
});

test('a Property manager sees no finance items in needs attention', function () {
    $pm = matrixUser(RoleName::PropertyManager, $this->building);
    issuedInvoice(Customer::factory()->create(), [['net' => '100.000']], '2026-09-01'); // overdue, but not theirs to see
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-11-30'], [Unit::factory()->for($this->building)->create()]);

    $labels = collect(Dashboard::attention($pm))->pluck('label')->all();

    expect($labels)->toBe(['Agreements expiring in 60 days'])
        ->and($labels)->not->toContain('Overdue invoices', 'Bounced cheques', 'Cheques to deposit this week', 'Approvals for you to decide');
});

test('needs attention reuses the counts the tiles already computed instead of querying them again', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $tiles = Dashboard::tiles($management);

    DB::enableQueryLog();
    $items = Dashboard::attention($management, $tiles);
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q) => str_contains($q, '`approvals`'))->count();
    DB::disableQueryLog();

    expect($queries)->toBe(0)->and($items)->toBe(Dashboard::attention($management));
});
