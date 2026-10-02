<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Reports\AgeingReport;
use App\Livewire\Reports\OutstandingReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['default_grace_days' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->sara = Customer::factory()->create(['name_en' => 'Sara Ahmed']);
    $this->omar = Customer::factory()->create(['name_en' => 'Omar Ali']);
    $invoice = fn (Customer $c, string $net, string $due) => issuedInvoice($c, [['net' => $net]], $due);
    $invoice($this->sara, '100.000', '2026-06-01'); // grace to 06-06: 4 days past → 1–30
    $invoice($this->sara, '200.000', '2026-04-01'); // grace to 04-06: 65 days past → 61–90
    $invoice($this->omar, '50.000', '2026-06-20');  // not yet due
    app(RecordPayment::class)->handle($this->finance, $this->omar, ['received_on' => '2026-06-10', 'method' => 'cash', 'amount' => '80.000']); // 50 allocated, 30 credit
});

test('outstanding by customer is invoice balances less customer credit', function () {
    $html = Livewire::actingAs($this->finance)->test(OutstandingReport::class)->html();
    expect(reportRowText($html, 'Sara Ahmed'))->toBe('Sara Ahmed 300.000 0.000 300.000')
        ->and(reportRowText($html, 'Omar Ali'))->toBe('Omar Ali 0.000 30.000 -30.000');
});

test('ageing buckets overdue balances by days past grace', function () {
    $html = Livewire::actingAs($this->finance)->test(AgeingReport::class)->html();
    expect(reportRowText($html, 'Sara Ahmed'))->toBe('Sara Ahmed 100.000 0.000 200.000 0.000 300.000')
        ->and($html)->not->toContain('Omar Ali');
});

test('the receivables reports need reports.financial', function () {
    $this->actingAs(matrixUser(RoleName::Leasing, $this->building))->get(route('reports.outstanding'))->assertForbidden();
});
