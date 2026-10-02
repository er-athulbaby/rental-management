<?php

use App\Enums\RoleName;
use App\Livewire\Reports\ChequesReport;
use App\Models\Building;
use App\Models\Cheque;
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
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain')); // a Wednesday; this week ends Sunday 14 June
    $this->building = Building::factory()->create();
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($this->building)->create()]);
    $cheque = fn (string $no, string $date, string $status, bool $toReturn = false) => tap((new Cheque)->forceFill([
        'direction' => 'received', 'customer_id' => $this->customer->id, 'agreement_id' => $this->agreement->id, 'cheque_no' => $no, 'bank_name' => 'NBB',
        'cheque_date' => $date, 'amount' => '400.000', 'status' => $status, 'to_return' => $toReturn, 'created_by' => User::factory()->create()->id,
        'bounced_on' => $status === 'bounced' ? '2026-06-01' : null, 'bounce_reason' => $status === 'bounced' ? 'Funds' : null,
    ]))->save();
    $cheque('T-TODAY', '2026-06-10', 'held');
    $cheque('T-WEEK', '2026-06-14', 'held');
    $cheque('T-LASTWEEK', '2026-06-05', 'held');
    $cheque('T-LATER', '2026-07-01', 'held');
    $cheque('T-BOUNCED', '2026-05-01', 'bounced');
    $cheque('T-RETURN', '2026-08-01', 'held', toReturn: true);
    $cheque('T-UNMATCHED', '2026-09-01', 'held'); // never had an invoice: not "to return"
    $this->finance = matrixUser(RoleName::Finance, $this->building);
});

test('cheques to deposit today and this week (Monday to Sunday)', function () {
    $page = Livewire::actingAs($this->finance)->test(ChequesReport::class);
    $page->assertSee('T-TODAY')->assertDontSee('T-WEEK')->assertDontSee('T-LATER'); // default: today
    $page->set('kind', 'week')->assertSee('T-TODAY')->assertSee('T-WEEK')->assertDontSee('T-LATER')->assertDontSee('T-LASTWEEK'); // a held cheque dated last week is overdue (today), not this week
    $page->set('kind', 'today')->assertSee('T-LASTWEEK');
});

test('bounced cheques awaiting action, and held cheques flagged to return', function () {
    Livewire::actingAs($this->finance)->test(ChequesReport::class)->set('kind', 'bounced')->assertSee('T-BOUNCED')->assertDontSee('T-TODAY');
    Livewire::actingAs($this->finance)->test(ChequesReport::class)->set('kind', 'return')->assertSee('T-RETURN')->assertDontSee('T-UNMATCHED');
});

test('the agreement page lists only cheques re-billing left to return', function () {
    $this->actingAs($this->finance)->get(route('agreements.show', $this->agreement))->assertSee('T-RETURN')->assertDontSee('T-UNMATCHED');
});

test('the cheque reports also need cheques.manage or finance.view', function () {
    $this->actingAs(matrixUser(RoleName::Leasing, $this->building))->get(route('reports.cheques'))->assertForbidden(); // operational only
    $this->actingAs($this->finance)->get(route('reports.cheques'))->assertOk();
});

test('the reports index lists the cheque reports only with cheques.manage or finance.view', function () {
    $this->actingAs(matrixUser(RoleName::Leasing, $this->building))->get(route('reports.index'))
        ->assertSee(route('reports.expiring'))->assertDontSee(route('reports.cheques'));
    $this->actingAs($this->finance)->get(route('reports.index'))->assertSee(route('reports.cheques'));
});
