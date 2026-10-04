<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Cheques\Entry;
use App\Livewire\Cheques\Index;
use App\Livewire\Cheques\Show;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt (Task 9)
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-03-31'], [Unit::factory()->create()]);
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->agreement, $this->finance)); // 6 invoices
});

test('the entry screen offers one row per open invoice and saves the filled ones', function () {
    $component = Livewire::withQueryParams(['agreement' => $this->agreement->id])->actingAs($this->finance)->test(Entry::class)
        ->assertCount('rows', 6)
        ->set('bank_name', 'NBB')
        ->set('rows.0.cheque_no', '501')
        ->set('rows.1.cheque_no', '502');

    $component->call('save')->assertHasNoErrors()->assertRedirect(route('cheques.index'));

    expect(Cheque::count())->toBe(2)
        ->and(Cheque::orderBy('id')->first()->amount)->toBe('400.000')
        ->and(Cheque::orderBy('id')->first()->cheque_date->toDateString())->toBe('2026-10-01');
});

test('Finance deposits selected cheques, then clears one into a payment', function () {
    Livewire::withQueryParams(['agreement' => $this->agreement->id])->actingAs($this->finance)->test(Entry::class)
        ->set('bank_name', 'NBB')->set('rows.0.cheque_no', '501')->set('rows.1.cheque_no', '502')->call('save');
    $ids = Cheque::orderBy('id')->pluck('id')->all();

    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertSee('501')
        ->set('selected', $ids)
        ->set('depositedOn', '2026-10-05')
        ->call('depositSelected')
        ->assertHasNoErrors();
    expect(Cheque::where('status', 'deposited')->count())->toBe(2)
        ->and(Cheque::find($ids[0])->deposited_on->toDateString())->toBe('2026-10-05');

    Livewire::actingAs($this->finance)->test(Show::class, ['cheque' => Cheque::find($ids[0])])
        ->set('clearedOn', '2026-10-05')
        ->call('clear')
        ->assertHasNoErrors();
    expect(Payment::sole()->method->value)->toBe('cheque')
        ->and(Cheque::find($ids[0])->status->value)->toBe('cleared')
        ->and(Cheque::find($ids[0])->cleared_on->toDateString())->toBe('2026-10-05');

    Livewire::actingAs($this->finance)->test(Show::class, ['cheque' => Cheque::find($ids[1])])
        ->set('bouncedOn', '2026-10-05')->set('bounceReason', 'Signature mismatch')->call('bounce')
        ->assertHasNoErrors();
    expect(Cheque::find($ids[1])->status->value)->toBe('bounced')
        ->and(Cheque::find($ids[1])->bounce_reason)->toBe('Signature mismatch');
});

test('only cheques.manage holders enter and act; finance.view holders see the register', function () {
    Livewire::withQueryParams(['agreement' => $this->agreement->id])->actingAs($this->finance)->test(Entry::class)
        ->set('bank_name', 'NBB')->set('rows.0.cheque_no', '501')->set('rows.1.cheque_no', '502')->call('save');
    $ids = Cheque::orderBy('id')->pluck('id')->all();
    Livewire::actingAs($this->finance)->test(Index::class)->set('selected', [$ids[0]])->set('depositedOn', '2026-10-05')->call('depositSelected');

    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->actingAs($management)->get(route('cheques.index'))->assertOk();
    $this->actingAs($management)->get(route('cheques.entry', ['agreement' => $this->agreement->id]))->assertForbidden();

    Livewire::actingAs($management)->test(Index::class)->set('selected', [$ids[1]])->call('depositSelected')->assertForbidden();
    Livewire::actingAs($management)->test(Show::class, ['cheque' => Cheque::find($ids[0])])->call('clear')->assertForbidden();

    expect(Cheque::find($ids[1])->status->value)->toBe('held')
        ->and(Cheque::find($ids[0])->status->value)->toBe('deposited')
        ->and(Payment::count())->toBe(0);
});

test('Finance starts cheque entry from the list by choosing an active agreement', function () {
    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertSee('Enter cheques')
        ->set('agreementSearch', $this->agreement->number)
        ->assertSee($this->agreement->number)
        ->call('startEntry', $this->agreement->id)
        ->assertRedirect(route('cheques.entry', ['agreement' => $this->agreement->id]));

    $draft = Agreement::factory()->create(['customer_id' => $this->customer->id]); // a draft: no cheques yet
    Livewire::actingAs($this->finance)->test(Index::class)->call('startEntry', $draft->id)->assertNotFound();

    $manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    Livewire::actingAs($manager)->test(Index::class)->assertDontSee('Enter cheques');
});

test('opening cheque entry without an agreement goes back to the list instead of failing', function () {
    $this->actingAs($this->finance)->get(route('cheques.entry'))->assertRedirect(route('cheques.index'));
});
