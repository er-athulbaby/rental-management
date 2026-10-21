<?php

use App\Actions\Agreements\SaveAgreementDetails;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\CustomFields\CustomFields;
use App\Enums\AgreementStatus;
use App\Enums\RoleName;
use App\Livewire\Agreements\Form;
use App\Livewire\Agreements\Show;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\CustomFieldValue;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    app(EnsureDefaultContractTemplate::class)();
    $this->building = Building::factory()->create(['name' => 'Juffair Heights']);
    $this->flat = Unit::factory()->for($this->building)->create(['code' => 'J-101', 'list_rent' => '500.000', 'list_deposit' => '500.000']);
    $this->customer = Customer::factory()->create(['name_en' => 'Hassan Qasim']);
    $this->leasing = matrixUser(RoleName::Leasing, $this->building);
    $this->broker = customField('agreement', 'text', 'Broker', ['required' => true]);
    customField('agreement', 'date', 'Key handover');
});

test('the new-agreement form saves More details with the draft, required fields included', function () {
    $form = Livewire::actingAs($this->leasing)->test(Form::class)
        ->call('selectCustomer', $this->customer->id)
        ->set('form.start_date', '2026-11-01')->set('form.end_date', '2027-10-31')
        ->set('pickBuilding', $this->building->id)->set('pickUnit', $this->flat->id)->call('addUnit');

    $form->call('save')->assertHasErrors('custom.broker');
    $form->set('custom.broker', 'Gulf Realty')->call('save')->assertRedirect();

    expect(array_column(CustomFields::display(Agreement::query()->sole()), 'text', 'label'))->toBe(['Broker' => 'Gulf Realty']);
});

test('after approval the agreement page keeps More details editable for agreement managers; viewers see the box', function () {
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->flat]);

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $agreement])
        ->assertSee('Save details')
        ->set('custom.broker', 'Gulf Realty')->set('custom.key_handover', '2026-01-02')
        ->call('saveDetails')->assertHasNoErrors();

    expect($agreement->refresh()->status)->toBe(AgreementStatus::Active)
        ->and(array_column(CustomFields::display($agreement), 'text', 'label'))->toBe(['Broker' => 'Gulf Realty', 'Key handover' => '02/01/2026']);
    $audit = Activity::query()->where('event', 'custom_fields.changed')->sole();
    expect($audit->subject_id)->toBe($agreement->id)->and($audit->causer_id)->toBe($this->leasing->id)
        ->and($audit->attribute_changes['attributes'])->toBe(['Broker' => 'Gulf Realty', 'Key handover' => '02/01/2026']);

    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    Livewire::actingAs($finance)->test(Show::class, ['agreement' => $agreement])
        ->assertDontSee('Save details')->assertSeeInOrder(['More details', 'Broker', 'Gulf Realty'])
        ->set('custom.broker', 'Changed')->call('saveDetails')->assertForbidden();
    expect(CustomFieldValue::query()->where('custom_field_id', $this->broker->id)->sole()->value_text)->toBe('Gulf Realty');
});

test('the details action re-checks the agreement permission and building scope; tampered input gives messages', function () {
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->flat]);
    $outsider = matrixUser(RoleName::Leasing, Building::factory()->create());

    expect(fn () => app(SaveAgreementDetails::class)->handle($outsider, $agreement, ['broker' => 'X']))->toThrow(AuthorizationException::class);
    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $agreement])
        ->set('custom.broker', ['nested'])->set('custom.key_handover', '2026-02-30')
        ->call('saveDetails')->assertHasErrors(['custom.broker', 'custom.key_handover']);

    expect(CustomFieldValue::query()->count())->toBe(0);
});

test('the details panel clears old errors after a successful save', function () {
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->flat]);

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $agreement])
        ->call('saveDetails')->assertHasErrors('custom.broker')
        ->set('custom.broker', 'Gulf Realty')->call('saveDetails')->assertHasNoErrors();
});
