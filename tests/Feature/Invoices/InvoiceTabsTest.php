<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Invoices\Index;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
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
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();

    $this->overdue = issuedInvoice($this->customer, [['net' => '100.000']], '2026-09-01');
    $this->paid = issuedInvoice($this->customer, [['net' => '40.000']], '2026-09-15');
    $this->notYetDue = issuedInvoice($this->customer, [['net' => '60.000']], '2026-10-25');
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-20', 'method' => 'cash', 'amount' => '40',
        'allocations' => [['invoice_id' => $this->paid->id, 'amount' => '40']]]);

    $agreement = activeAgreement(['start_date' => '2026-12-01', 'end_date' => '2027-01-31'], [Unit::factory()->create()]);
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->finance)); // 2 scheduled
    (new Invoice)->forceFill(['type' => 'manual', 'customer_id' => $this->customer->id, 'issue_date' => '2026-10-20', 'due_date' => '2026-10-20',
        'status' => 'draft', 'subtotal' => '0.000', 'tax_total' => '0.000', 'total' => '0.000'])->save();
});

test('tabs split invoices into unpaid, overdue, paid, upcoming and drafts, with counts and totals', function () {
    $page = Livewire::actingAs($this->finance)->test(Index::class);

    expect($page->viewData('counts'))->toBe(['unpaid' => 2, 'overdue' => 1, 'paid' => 1, 'upcoming' => 2, 'drafts' => 1, 'all' => 6])
        ->and($page->viewData('owed'))->toBe(['unpaid' => '160.000', 'overdue' => '100.000']);

    $page->assertSee('Owed: 160.000 BHD')
        ->assertSee($this->overdue->number)->assertSee($this->notYetDue->number)->assertDontSee($this->paid->number)
        ->set('tab', 'overdue')->assertSee('Overdue: 100.000 BHD')->assertSee('49 days overdue')->assertDontSee($this->notYetDue->number)
        ->set('tab', 'paid')->assertSee($this->paid->number)->assertDontSee($this->overdue->number)
        ->set('tab', 'nonsense')->assertSee($this->overdue->number); // an unknown tab falls back to Unpaid
});

test('the counts follow the other filters', function () {
    Livewire::actingAs($this->finance)->test(Index::class)
        ->set('search', $this->overdue->number)
        ->assertViewHas('counts', fn (array $c) => $c['unpaid'] === 1 && $c['overdue'] === 1 && $c['paid'] === 0);
});
