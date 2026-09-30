<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\EnsureNumberSequences;
use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Agreements\Show as AgreementShow;
use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\Show;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->unit = Unit::factory()->create();
    $this->agreement = activeAgreement(['start_date' => '2026-11-01', 'end_date' => '2027-10-31'], [$this->unit]);
    $this->invoices = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->agreement, $this->finance));
});

test('Finance issues a scheduled invoice now, for a customer paying ahead', function () {
    $december = $this->invoices[1];

    Livewire::actingAs($this->finance)->test(Show::class, ['invoice' => $december])
        ->assertSee('Scheduled')
        ->call('issueNow')
        ->assertHasNoErrors()
        ->assertSee('INV-2026-000001');

    expect($december->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('a held-back invoice explains why', function () {
    $pending = OwnerContract::factory()->create(['building_id' => $this->unit->building_id, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31']);
    $pending->units()->attach($this->unit->id);
    $pending->forceFill(['status' => 'pending_approval'])->save();

    Livewire::actingAs($this->finance)->test(Show::class, ['invoice' => $this->invoices[0]])
        ->call('issueNow')
        ->assertHasErrors('invoice');
});

test('issued invoices past their grace date show as overdue', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['invoice' => $this->invoices[0]])->call('issueNow');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Bahrain'));

    expect($this->invoices[0]->fresh()->displayLabel())->toBe('Overdue');
    Livewire::actingAs($this->finance)->test(Index::class)->assertSee('Overdue')->assertSee('Scheduled');
});

test('who sees invoices, and who may issue them', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    $this->actingAs($management)->get(route('invoices.index'))->assertOk();
    $this->actingAs($pm)->get(route('invoices.index'))->assertForbidden();

    Livewire::actingAs($management)->test(Show::class, ['invoice' => $this->invoices[0]])->call('issueNow')->assertForbidden();

    Livewire::actingAs($this->finance)->test(AgreementShow::class, ['agreement' => $this->agreement])->assertSee(__('Invoices'));
    Livewire::actingAs($pm)->test(AgreementShow::class, ['agreement' => $this->agreement])->assertDontSee(route('invoices.show', $this->invoices[0]));
});

test('the 01:00 job issues due invoices and is scheduled with its siblings', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 01:00', 'Asia/Bahrain'));

    $this->artisan('rms:invoices:issue')->assertSuccessful();

    expect(Invoice::where('status', 'issued')->count())->toBe(1);
});
