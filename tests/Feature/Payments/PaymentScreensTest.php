<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Payments\Create;
use App\Livewire\Payments\Index;
use App\Livewire\Payments\Show;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt (Task 9)
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create(['name_en' => 'Noor Aziz']);
    $this->a = issuedInvoice($this->customer, [['net' => '400.000']], '2026-10-01');
    $this->b = issuedInvoice($this->customer, [['net' => '300.000']], '2026-11-01');
});

test('Finance records a payment that pays oldest first by default', function () {
    Livewire::withQueryParams(['customer' => $this->customer->id])->actingAs($this->finance)->test(Create::class)
        ->assertSee($this->a->number)
        ->set('form.amount', '500')
        ->set('form.method', 'bank_transfer')
        ->set('form.reference', 'BBK-778')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Payment::sole()->amount)->toBe('500.000')
        ->and($this->a->fresh()->balance)->toBe('0.000')
        ->and($this->b->fresh()->balance)->toBe('200.000');
});

test('Finance can type amounts per invoice instead', function () {
    Livewire::withQueryParams(['customer' => $this->customer->id])->actingAs($this->finance)->test(Create::class)
        ->set('form.amount', '100')
        ->set("split.{$this->b->id}", '100')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->a->fresh()->balance)->toBe('400.000')->and($this->b->fresh()->balance)->toBe('200.000');
});

test('errors land on the form', function () {
    Livewire::withQueryParams(['customer' => $this->customer->id])->actingAs($this->finance)->test(Create::class)
        ->set('form.amount', '50')
        ->set("split.{$this->a->id}", '60')
        ->call('save')
        ->assertHasErrors('allocations');
});

test('the payment page shows allocations and remaining credit; the customer page shows the account', function () {
    Livewire::withQueryParams(['customer' => $this->customer->id])->actingAs($this->finance)->test(Create::class)
        ->set('form.amount', '800')->call('save');
    $payment = Payment::sole();

    Livewire::actingAs($this->finance)->test(Show::class, ['payment' => $payment])
        ->assertSee('RCP-2026-000001')->assertSee($this->a->number)->assertSee('100.000'); // credit left

    Livewire::actingAs($this->finance)->test(CustomerForm::class, ['customer' => $this->customer])
        ->assertSee('Outstanding')->assertSee('0.000')->assertSee('Credit')->assertSee('100.000');

    Livewire::actingAs($this->finance)->test(Index::class)->assertSee('Noor Aziz');
});

test('only payments.manage records; finance.view holders see payments', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    $this->actingAs($management)->get(route('payments.index'))->assertOk();
    $this->actingAs($management)->get(route('payments.create', ['customer' => $this->customer->id]))->assertForbidden();
    $this->actingAs($pm)->get(route('payments.index'))->assertForbidden();
});
