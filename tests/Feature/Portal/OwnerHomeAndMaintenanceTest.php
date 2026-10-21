<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName as R;
use App\Livewire\Portal\Owner;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->admin = matrixUser(R::Admin, Building::factory()->create());
    $this->a = portalOwnerFixture('Ali Owner', '101', $this->admin);
    $this->b = portalOwnerFixture('Badr Owner', '202', $this->admin);
    $this->actingAs($this->a['account'], 'tenant');
});

test('home shows the balance owed per managed contract, the last payout, the next lease payment, occupancy and contracts ending within 90 days', function () {
    $this->get(route('portal.owner.home'))->assertOk()
        ->assertSeeInOrder(['Welcome, Ali Owner', 'Balance owed to you', 'Ali Owner Tower · OS-101', '450.000 BHD',
            'Last payout', '450.000 BHD', '05/10/2026', 'Next lease payment', '300.000 BHD', 'Due 01/11/2026',
            'Occupancy', 'Ali Owner Tower', '1 of 2 units let',
            'Contracts ending soon', (string) $this->a['managed']->number, 'Ends 31/12/2026', (string) $this->a['leased']->number, 'Ends 31/12/2026'])
        ->assertDontSee('Badr Owner');
});

test('home in Arabic reads right to left with amounts to 3 decimals', function () {
    $this->a['account']->forceFill(['locale' => 'ar'])->save();

    $this->get(route('portal.owner.home'))->assertOk()->assertSee('dir="rtl"', false)
        ->assertSee('الرصيد المستحق لك')->assertSee('450.000 د.ب')->assertSee('31/12/2026');
});

test('maintenance lists requests in their buildings; a request shows the tenant timeline and the cost charged to them, never vendors or notes', function () {
    $request = $this->a['request'];

    $this->get(route('portal.owner.maintenance'))->assertOk()
        ->assertSeeInOrder([$request->number, $request->fresh()->status->label(), 'Ali Owner Tower 101', 'Plumbing', 'Sent 21/10/2026'])
        ->assertDontSee($this->b['request']->number);

    $this->get(route('portal.owner.maintenance.show', $request))->assertOk()
        ->assertSeeInOrder([$request->number, 'Ali Owner Tower 101', 'Cost charged to you', '50.000 BHD', 'Request received', 'Work assigned'])
        ->assertDontSee('Fix-It 101')->assertDontSee($request->workOrders()->first()->number)->assertDontSee('Kitchen sink leaking');
});

test('another owner\'s request and made-up ids are a 404, by URL and by Livewire', function () {
    foreach ([route('portal.owner.maintenance.show', $this->b['request']), '/portal/owner/maintenance/99999999999999999999',
        '/portal/owner/maintenance/abc', '/portal/owner/maintenance/-1'] as $url) {
        $this->get($url)->assertNotFound();
    }

    Livewire::actingAs($this->a['account'], 'tenant')->test(Owner\MaintenanceShow::class, ['maintenance' => (string) $this->b['request']->id])->assertNotFound();
});

test('a request logged before their contract started is not theirs', function () {
    $this->travelTo(CarbonImmutable::parse('2025-12-15 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2025);
    $old = maintenanceRequest($this->admin, Unit::query()->where('code', '101')->sole());
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));

    $this->get(route('portal.owner.maintenance'))->assertOk()->assertDontSee($old->number);
    $this->get(route('portal.owner.maintenance.show', $old))->assertNotFound();
});
