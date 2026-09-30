<?php

use App\Enums\RoleName;
use App\Enums\UnitStatus;
use App\Livewire\Units\Index;
use App\Models\Agreement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->unit = Unit::factory()->create(['code' => 'A-1']);
});

function pendingFor(Unit $unit, string $from, string $to): Agreement
{
    $agreement = Agreement::factory()->create(['start_date' => $from, 'end_date' => $to]);
    $au = $agreement->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => 1, 'deposit_amount' => 0, 'start_date' => $from, 'end_date' => $to]);
    $au->charges()->create(['type' => 'rent', 'monthly_amount' => 1, 'tax_category' => 'exempt']);
    $agreement->forceFill(['status' => 'pending_approval'])->save();

    return $agreement;
}

test('with no agreements a unit is available, or blocked', function () {
    expect($this->unit->status())->toBe(UnitStatus::Available);
    $this->unit->update(['blocked' => true, 'blocked_reason' => 'Repairs']);
    expect($this->unit->fresh()->status())->toBe(UnitStatus::Blocked);
});

test('drafts do not count; a pending or future agreement reserves the unit', function () {
    Agreement::factory()->create()->agreementUnits()->create(['unit_id' => $this->unit->id, 'list_rent' => 1, 'deposit_amount' => 0, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30']);
    expect($this->unit->status())->toBe(UnitStatus::Available);

    pendingFor($this->unit, '2026-10-01', '2027-09-30');
    expect($this->unit->status())->toBe(UnitStatus::Reserved);
});

test('an active agreement starting later reserves; one covering today occupies', function () {
    $future = activeAgreement(['start_date' => '2026-11-01', 'end_date' => '2027-10-31'], [$this->unit]);
    expect($this->unit->status())->toBe(UnitStatus::Reserved);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Bahrain'));
    expect($this->unit->status())->toBe(UnitStatus::Occupied);
});

test('notice on the agreement or on the unit shows Notice given, and a next tenant is shown', function () {
    $current = activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $current->forceFill(['notice_date' => '2026-10-01', 'planned_exit_date' => '2026-12-31'])->save();
    expect($this->unit->status())->toBe(UnitStatus::NoticeGiven);

    $current->forceFill(['notice_date' => null, 'planned_exit_date' => null])->save();
    DB::table('agreement_units')->where('agreement_id', $current->id)->update(['planned_exit_date' => '2026-12-31']);
    expect($this->unit->fresh()->status())->toBe(UnitStatus::NoticeGiven);

    activeAgreement(['start_date' => '2027-01-01', 'end_date' => '2027-12-31'], [$this->unit]);
    expect($this->unit->fresh()->nextTenantFrom()?->toDateString())->toBe('2027-01-01');
});

test('an expired agreement without a move-out is an overstay; with one it frees the unit', function () {
    $old = activeAgreement(['start_date' => '2025-10-01', 'end_date' => '2026-09-30'], [$this->unit]);
    $old->forceFill(['status' => 'expired'])->save();
    expect($this->unit->status())->toBe(UnitStatus::Occupied);

    DB::table('agreement_units')->where('agreement_id', $old->id)->update(['move_out_date' => '2026-09-30']);
    expect($this->unit->fresh()->status())->toBe(UnitStatus::Available);
});

test('the units list shows the computed status', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    activeAgreement(['start_date' => '2027-01-01', 'end_date' => '2027-12-31'], [$this->unit]);
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    Livewire::actingAs($pm)->test(Index::class)
        ->assertSee('Occupied')
        ->assertSee('next tenant from 01/01/2027');
});
