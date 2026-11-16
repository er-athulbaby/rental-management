<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Cheques\Entry;
use App\Livewire\Cheques\Index;
use App\Livewire\Cheques\Show;
use App\Models\Building;
use App\Models\Cheque;
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
    fixtureBanks();
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain')); // a Monday: this week is 05/10 to 11/10
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-03-31'], [Unit::factory()->create()]);
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->agreement, $this->finance)); // 6 rent invoices of 400.000
});

test('cheque figures: held (count and amount), to deposit this week and bounced, under every filter but the status', function () {
    Livewire::withQueryParams(['agreement' => $this->agreement->id])->actingAs($this->finance)->test(Entry::class)
        ->set('bank_name', 'NBB')
        ->set('rows.0.cheque_no', '501')->set('rows.0.cheque_date', '2026-10-07') // this week
        ->set('rows.1.cheque_no', '502')                                           // 01/11/2026
        ->set('rows.2.cheque_no', '503')                                           // 01/12/2026, bounced below
        ->call('save')->assertHasNoErrors();
    $bounced = Cheque::where('cheque_no', '503')->sole();
    Livewire::actingAs($this->finance)->test(Index::class)->set('selected', [$bounced->id])->set('depositedOn', '2026-10-05')->call('depositSelected')->assertHasNoErrors();
    Livewire::actingAs($this->finance)->test(Show::class, ['cheque' => $bounced->fresh()])
        ->set('bouncedOn', '2026-10-05')->set('bounceReason', 'Signature mismatch')->call('bounce')->assertHasNoErrors();

    $all = ['held' => 2, 'held_amount' => '800.000', 'week' => 1, 'bounced' => 1];
    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertViewHas('stats', $all)
        ->assertSeeInOrder(['Held', '2', '800.000 BHD', 'To deposit this week', '1', 'Bounced', '1'])
        ->set('status', 'all')->assertViewHas('stats', $all) // the status filter does not change them
        ->set('search', '501')->assertViewHas('stats', ['held' => 1, 'held_amount' => '400.000', 'week' => 1, 'bounced' => 0])
        ->set('search', '')->set('building', Building::factory()->create()->id) // a building none of them is in
        ->assertViewHas('stats', ['held' => 0, 'held_amount' => '0.000', 'week' => 0, 'bounced' => 0]);
});
