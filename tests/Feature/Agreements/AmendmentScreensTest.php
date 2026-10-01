<?php

use App\Enums\RoleName;
use App\Livewire\Agreements\AmendmentForm;
use App\Livewire\Agreements\Show;
use App\Models\AgreementAmendment;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $building = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($building->id);
    [$u1, $u2] = Unit::factory()->for($building)->count(2)->create()->all();
    $this->spare = Unit::factory()->for($building)->create();
    $this->agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30'], [$u1, $u2]);
});

test('Leasing sends a release for approval from the form and sees it on the agreement', function () {
    $au = $this->agreement->agreementUnits()->first();

    Livewire::withQueryParams(['type' => 'release_unit'])->actingAs($this->leasing)->test(AmendmentForm::class, ['agreement' => $this->agreement])
        ->set('form.agreement_unit_id', $au->id)
        ->set('form.effective_date', '2026-12-31')
        ->set('form.reason', 'Customer downsizing')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('agreements.show', $this->agreement));

    expect(AgreementAmendment::sole()->status->value)->toBe('pending_approval')->and(Approval::sole()->action->value)->toBe('agreement.amend');

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->agreement])
        ->assertSee('Customer downsizing')->assertSee('Pending Approval');
});

test('adding a unit takes its charges on the form', function () {
    Livewire::withQueryParams(['type' => 'add_unit'])->actingAs($this->leasing)->test(AmendmentForm::class, ['agreement' => $this->agreement])
        ->set('form.unit_id', $this->spare->id)
        ->set('form.effective_date', '2026-11-01')
        ->set('form.deposit_amount', '250')
        ->set('form.charges.0.monthly_amount', '250')
        ->set('form.reason', 'Extra store')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(AgreementAmendment::sole()->data['charges'][0]['monthly_amount'])->toBe('250.000');
});

test('errors show on the form; users outside the buildings are refused', function () {
    Livewire::withQueryParams(['type' => 'terminate'])->actingAs($this->leasing)->test(AmendmentForm::class, ['agreement' => $this->agreement])
        ->set('form.effective_date', '2028-01-01')->set('form.reason', 'x')
        ->call('submit')->assertHasErrors('form.effective_date');

    $outsider = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($outsider)->get(route('agreements.amend', ['agreement' => $this->agreement, 'type' => 'terminate']))->assertForbidden();
});

test('a draft amendment can be reopened, edited and deleted; others cannot touch it', function () {
    $au = $this->agreement->agreementUnits()->first();

    Livewire::withQueryParams(['type' => 'release_unit'])->actingAs($this->leasing)->test(AmendmentForm::class, ['agreement' => $this->agreement])
        ->set('form.agreement_unit_id', $au->id)->set('form.effective_date', '2026-12-31')->set('form.reason', 'First reason')
        ->call('saveDraft')->assertHasNoErrors();
    $draft = AgreementAmendment::sole();
    expect($draft->status->value)->toBe('draft');

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->agreement])
        ->assertSee('First reason')->assertSee('Release a unit');

    Livewire::actingAs($this->leasing)->test(AmendmentForm::class, ['amendment' => $draft])
        ->assertSet('form.effective_date', '2026-12-31')->assertSet('form.reason', 'First reason')
        ->set('form.reason', 'Second reason')->call('saveDraft')->assertHasNoErrors();
    expect(AgreementAmendment::count())->toBe(1)->and($draft->refresh()->reason)->toBe('Second reason');

    $outsider = User::factory()->create()->assignRole(RoleName::Leasing);
    Livewire::actingAs($outsider)->test(AmendmentForm::class, ['amendment' => $draft])->assertForbidden();
    Livewire::actingAs($outsider)->test(Show::class, ['agreement' => $this->agreement])->assertForbidden();

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->agreement])
        ->call('deleteAmendmentDraft', $draft->id);
    expect(AgreementAmendment::count())->toBe(0);
});

test('a submitted amendment cannot be edited or deleted', function () {
    $au = $this->agreement->agreementUnits()->first();

    Livewire::withQueryParams(['type' => 'release_unit'])->actingAs($this->leasing)->test(AmendmentForm::class, ['agreement' => $this->agreement])
        ->set('form.agreement_unit_id', $au->id)->set('form.effective_date', '2026-12-31')->set('form.reason', 'Sent reason')
        ->call('submit')->assertHasNoErrors();
    $sent = AgreementAmendment::sole();

    $this->actingAs($this->leasing)->get(route('agreements.amend.edit', $sent))->assertNotFound();
    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->agreement])
        ->assertSee('Sent reason')->call('deleteAmendmentDraft', $sent->id)->assertForbidden();
    expect(AgreementAmendment::count())->toBe(1);
});
