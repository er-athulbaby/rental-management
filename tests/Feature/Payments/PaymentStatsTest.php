<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Payments\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
});

test('payment figures: collected this month (1st to today, confirmed) under the list\'s filters', function () {
    $pay = fn (string $on, string $amount, string $method) => app(RecordPayment::class)->handle($this->finance, $this->customer,
        ['received_on' => $on, 'method' => $method, 'amount' => $amount]);
    $pay('2026-09-30', '500', 'cash');            // last month: not counted
    $first = $pay('2026-10-01', '40', 'cash');
    $pay('2026-10-20', '100', 'bank_transfer');
    $pay('2026-10-10', '75', 'cash')->forceFill(['status' => 'reversed', 'reversed_at' => now()])->save();     // reversed: not counted
    $first->replicate(['number'])->forceFill(['number' => 'RCP-APPLIED', 'method' => 'deposit_applied', 'received_on' => '2026-10-12', 'amount' => '60.000'])->save(); // moves no money: not counted

    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertViewHas('stats', ['collected' => '140.000', 'receipts' => 2])
        ->assertSeeInOrder(['Collected this month', '140.000 BHD', 'Receipts this month', '2'])
        ->set('search', $first->number)->assertViewHas('stats', ['collected' => '40.000', 'receipts' => 1])
        ->set('search', '')->set('building', Building::factory()->create()->id) // a building none of them is in
        ->assertViewHas('stats', ['collected' => '0.000', 'receipts' => 0]);
});

test('payment figures follow a building-limited user: only tenants of their buildings', function () {
    $a = Building::factory()->create();
    $b = Building::factory()->create();
    $mine = Customer::factory()->create();
    $theirs = Customer::factory()->create();
    activeAgreement(['customer_id' => $mine->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($a)->create()]);
    activeAgreement(['customer_id' => $theirs->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($b)->create()]);
    $pay = fn (Customer $c, string $amount) => app(RecordPayment::class)->handle($this->finance, $c, ['received_on' => '2026-10-15', 'method' => 'cash', 'amount' => $amount]);
    $pay($mine, '40');
    $pay($theirs, '900');

    Livewire::actingAs(matrixUser(RoleName::Leasing, $a))->test(Index::class)
        ->assertViewHas('stats', ['collected' => '40.000', 'receipts' => 1]);
});
