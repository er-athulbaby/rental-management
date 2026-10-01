<?php

use App\Actions\Agreements\RecordMoveOut;
use App\Enums\RoleName;
use App\Livewire\Agreements\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositSettlement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $building = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($building->id);
    [$this->u1, $this->u2] = Unit::factory()->for($building)->count(2)->create()->all();
    $this->agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'created_by' => $this->leasing->id], [$this->u1, $this->u2]);
});

test('moving out after the end date records it, drafts the settlement, and closes the agreement once every unit is out', function () {
    $au1 = $this->agreement->agreementUnits()->where('unit_id', $this->u1->id)->sole();
    app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, $au1, '2026-10-02', 'Electric 10432', 'Keys returned');

    expect($au1->fresh()->move_out_date->toDateString())->toBe('2026-10-02')
        ->and($au1->fresh()->move_out_readings)->toBe('Electric 10432')
        ->and($au1->fresh()->move_out_recorded_by)->toBe($this->leasing->id)
        ->and(DepositSettlement::sole()->units->sole()->agreement_unit_id)->toBe($au1->id)
        ->and($this->agreement->fresh()->status->value)->toBe('expired'); // past its end, unit 2 still in: an overstay

    app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, null, '2026-10-04', null, null); // everyone else
    expect($this->agreement->fresh()->status->value)->toBe('closed')
        ->and(DepositSettlement::count())->toBe(2);
});

test('move-outs are validated and scoped', function () {
    expect(fn () => app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, null, '2026-10-06', null, null))->toThrow(ValidationException::class);
    expect(fn () => app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, null, '2025-09-30', null, null))->toThrow(ValidationException::class);

    $au1 = $this->agreement->agreementUnits()->where('unit_id', $this->u1->id)->sole();
    app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, $au1, '2026-10-02', null, null);
    expect(fn () => app(RecordMoveOut::class)->handle($this->leasing, $this->agreement, $au1, '2026-10-03', null, null))->toThrow(ValidationException::class);

    $outsider = User::factory()->create()->assignRole(RoleName::Leasing);
    expect(fn () => app(RecordMoveOut::class)->handle($outsider, $this->agreement, null, '2026-10-02', null, null))->toThrow(AuthorizationException::class);
});

test('the agreement page records a move-out and shows the photo panel for the unit', function () {
    $au1 = $this->agreement->agreementUnits()->where('unit_id', $this->u1->id)->sole();

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->agreement])
        ->set('moveOutTarget', (string) $au1->id)->set('moveOutDate', '2026-10-03')->set('moveOutNotes', 'Clean')
        ->call('recordMoveOut')->assertHasNoErrors()
        ->assertSee('Move-out photos — '.$this->u1->code); // the unit's own photo panel (the agreement already has one)

    expect($au1->fresh()->move_out_notes)->toBe('Clean');
});
