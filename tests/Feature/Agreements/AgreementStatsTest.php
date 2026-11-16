<?php

use App\Enums\RoleName;
use App\Livewire\Agreements\Index;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->leasing = matrixUser(RoleName::Leasing, $this->a); // sees building A only
});

test('agreement figures: active, pending approval and expiring in 60 days, in the viewer\'s buildings, under every filter but status and end date', function () {
    $ending = activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-11-30'], [Unit::factory()->for($this->a)->create()]); // ends in 56 days
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2027-09-30'], [Unit::factory()->for($this->a)->create()]);
    $unit = Unit::factory()->for($this->a)->create();
    $pending = Agreement::factory()->create(['start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $pending->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => $unit->list_rent, 'deposit_amount' => '400.000',
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $pending->forceFill(['status' => 'pending_approval'])->save();
    Agreement::factory()->create(); // a draft with no units by someone else: not visible, and a draft is never counted
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-11-15'], [Unit::factory()->for($this->b)->create()]); // building B: not visible

    $all = ['active' => 2, 'pending' => 1, 'expiring' => 1];
    Livewire::actingAs($this->leasing)->test(Index::class)
        ->assertViewHas('stats', $all)
        ->assertSeeInOrder(['Active', '2', 'Pending approval', '1', 'Expiring in 60 days', '1'])
        ->set('status', 'draft')->set('expiring', 30)->assertViewHas('stats', $all) // neither filter changes them
        ->set('status', '')->set('expiring', null)->set('search', $ending->number)
        ->assertViewHas('stats', ['active' => 1, 'pending' => 0, 'expiring' => 1]);
});
