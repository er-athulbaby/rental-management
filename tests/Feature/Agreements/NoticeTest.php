<?php

use App\Actions\Agreements\RecordNotice;
use App\Enums\RoleName;
use App\Livewire\Agreements\Show;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->mine = Building::factory()->create();
    $this->theirs = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($this->mine->id);
    $this->ownUnit = Unit::factory()->for($this->mine)->create();
    $this->otherUnit = Unit::factory()->for($this->theirs)->create();
    $this->agreement = activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->ownUnit, $this->otherUnit]);
});

test('notice on the whole agreement sets its dates and leaves the end date alone', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    app(RecordNotice::class)->handle($pm, $this->agreement, null, '2026-10-01', '2026-11-30');

    $fresh = $this->agreement->fresh();
    expect($fresh->notice_date->toDateString())->toBe('2026-10-01')
        ->and($fresh->planned_exit_date->toDateString())->toBe('2026-11-30')
        ->and($fresh->end_date->toDateString())->toBe('2026-12-31');
});

test('a scoped user can give notice for a unit in their building, not for the whole spanning agreement', function () {
    $own = $this->agreement->agreementUnits()->where('unit_id', $this->ownUnit->id)->sole();
    $other = $this->agreement->agreementUnits()->where('unit_id', $this->otherUnit->id)->sole();

    app(RecordNotice::class)->handle($this->leasing, $this->agreement, $own, '2026-10-02', '2026-10-31');
    expect($own->fresh()->planned_exit_date->toDateString())->toBe('2026-10-31');

    expect(fn () => app(RecordNotice::class)->handle($this->leasing, $this->agreement, $other, '2026-10-02', '2026-10-31'))->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordNotice::class)->handle($this->leasing, $this->agreement, null, '2026-10-02', '2026-10-31'))->toThrow(AuthorizationException::class);
});

test('notice needs an active or expired agreement and sensible dates', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    expect(fn () => app(RecordNotice::class)->handle($pm, $this->agreement, null, '2026-10-10', '2026-10-01'))->toThrow(ValidationException::class);
    expect(fn () => app(RecordNotice::class)->handle($pm, $this->agreement, null, '2026-11-01', '2026-11-30'))->toThrow(ValidationException::class); // notice in the future

    $draft = Agreement::factory()->create(['created_by' => $pm->id]);
    expect(fn () => app(RecordNotice::class)->handle($pm, $draft, null, '2026-10-01', '2026-11-30'))->toThrow(ValidationException::class);
});

test('notice is recorded from the agreement page', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    Livewire::actingAs($pm)->test(Show::class, ['agreement' => $this->agreement])
        ->set('noticeDate', '2026-10-05')
        ->set('plannedExit', '2026-12-15')
        ->call('recordNotice')
        ->assertHasNoErrors();

    expect($this->agreement->fresh()->planned_exit_date->toDateString())->toBe('2026-12-15');
});
