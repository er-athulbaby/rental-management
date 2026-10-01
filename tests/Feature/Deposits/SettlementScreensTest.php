<?php

use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\DepositSettlements\Index;
use App\Livewire\DepositSettlements\Show;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30'], [Unit::factory()->create()]);
    $this->au = $agreement->agreementUnits()->sole();
    $this->settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($agreement, [$this->au->id], $this->finance));
});

test('Finance adds deductions on the draft and sends it for approval', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['settlement' => $this->settlement])
        ->call('addLine')
        ->set('lines.0.agreement_unit_id', $this->au->id)->set('lines.0.type', 'cleaning')
        ->set('lines.0.description', 'Deep clean')->set('lines.0.amount', '35')
        ->call('saveLines')->assertHasNoErrors()
        ->call('submit')->assertHasNoErrors()
        ->assertSee('Pending Approval');

    expect($this->settlement->fresh()->lines()->sole()->amount)->toBe('35.000');
    Livewire::actingAs($this->finance)->test(Index::class)->assertSee($this->settlement->agreement->label());
});

test('view-only roles see settlements but cannot edit them', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->actingAs($management)->get(route('deposit-settlements.show', $this->settlement))->assertOk();
    Livewire::actingAs($management)->test(Show::class, ['settlement' => $this->settlement])->call('saveLines')->assertForbidden();
});
