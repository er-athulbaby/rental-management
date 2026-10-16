<?php

use App\Actions\Agreements\SaveAmendment;
use App\Actions\Agreements\SubmitAmendment;
use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reports\RentRollReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    $unit = fn (Building $b, string $code, array $extra = []) => Unit::factory()->for($b)->create(['code' => $code, ...$extra]);
    $u1 = $unit($this->mine, 'A101');
    $u2 = $unit($this->mine, 'A102');
    $this->spare = $unit($this->mine, 'A103', ['list_rent' => '350.000']);
    $unit($this->mine, 'A104', ['blocked' => true, 'blocked_reason' => 'Works']);
    $unit($this->theirs, 'T201');
    $this->leasing = matrixUser(RoleName::Leasing, $this->mine);
    $this->finance = matrixUser(RoleName::Finance, $this->mine);
    $this->agreement = activeAgreement(['customer_id' => Customer::factory()->create(['name_en' => 'Alice Tenant'])->id, 'start_date' => '2026-01-01',
        'end_date' => '2026-12-31', 'created_by' => $this->leasing->id], [$u1, $u2]);
    $au = $this->agreement->agreementUnits()->orderBy('id')->get();
    DepositMovement::create(['agreement_unit_id' => $au[0]->id, 'type' => 'opening', 'amount' => '400.000', 'source_type' => 'import', 'source_id' => $this->agreement->id, 'posted_at' => '2026-06-01 10:00:00']);
    issuedInvoice($this->agreement->customer, [['net' => '400.000', 'au' => $au[0]], ['net' => '400.000', 'au' => $au[1]]], '2026-06-01', $this->agreement); // unpaid: 800 owed
    $this->number = $this->agreement->number;
});

test('one row per unit as at a date, a subtotal per building and a grand total', function () {
    $html = Livewire::actingAs($this->finance)->test(RentRollReport::class)->html();
    $m = $this->mine->code;

    expect(reportRowText($html, 'A101'))->toBe("{$m} A101 Flat / Apartment Occupied Alice Tenant {$this->number} 01/01/2026 31/12/2026 400.000 400.000 800.000")
        ->and(reportRowText($html, 'A102'))->toBe("{$m} A102 Flat / Apartment Occupied Alice Tenant {$this->number} 01/01/2026 31/12/2026 400.000 0.000 (all units)")
        ->and(reportRowText($html, 'A103'))->toBe("{$m} A103 Flat / Apartment Vacant 350.000")    // list rent, muted
        ->and(reportRowText($html, 'A104'))->toBe("{$m} A104 Flat / Apartment Blocked")
        ->and(reportRowText($html, "Subtotal {$m}"))->toBe("Subtotal {$m} 4 units 2 occupied, 66.7% 800.000 400.000 800.000") // 2 ÷ (4 − 1 blocked)
        ->and(reportRowText($html, 'T201'))->toBe("{$this->theirs->code} T201 Flat / Apartment Vacant 400.000")
        ->and(reportRowText($html, '5 units'))->toBe('Total 5 units 2 occupied, 50.0% 800.000 400.000 800.000');
});

test('without reports.financial the money columns are hidden; a building-scoped user sees only their buildings', function () {
    $html = Livewire::actingAs($this->leasing)->test(RentRollReport::class)->assertDontSee('Monthly rent')->assertDontSee('T201')->html();

    expect(reportRowText($html, 'A101'))->toBe("{$this->mine->code} A101 Flat / Apartment Occupied Alice Tenant {$this->number} 01/01/2026 31/12/2026")
        ->and(reportRowText($html, 'A103'))->toBe("{$this->mine->code} A103 Flat / Apartment Vacant")
        ->and(reportRowText($html, '4 units'))->toBe("Subtotal {$this->mine->code} 4 units 2 occupied, 66.7%")
        ->and($html)->not->toContain('800.000')->not->toContain('350.000');
});

test('a unit added by an amendment shows its own rent from its start; before then it is vacant', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $draft = app(SaveAmendment::class)->handle($this->leasing, $this->agreement, null, ['type' => 'add_unit', 'unit_id' => $this->spare->id, 'effective_date' => '2026-06-15',
        'reason' => 'Expansion', 'deposit_amount' => '300.000', 'charges' => [['type' => 'rent', 'monthly_amount' => '300.000', 'tax_category' => 'exempt']]]);
    app(DecideApproval::class)->handle($management, app(SubmitAmendment::class)->handle($this->leasing, $draft), true);

    $page = Livewire::actingAs($this->finance)->test(RentRollReport::class);
    expect(reportRowText($page->html(), 'A103'))->toBe("{$this->mine->code} A103 Flat / Apartment Vacant 350.000");

    expect(reportRowText($page->set('to', '2026-06-20')->html(), 'A103'))
        ->toBe("{$this->mine->code} A103 Flat / Apartment Occupied Alice Tenant {$this->number} 15/06/2026 31/12/2026 300.000 0.000 (all units)");
});

test('the rent roll exports to Excel, audited; a bad date shows an error, not a crash; it is listed on the Reports page', function () {
    Livewire::actingAs($this->leasing)->test(RentRollReport::class)->call('export')->assertFileDownloaded('rent_roll-2026-06-10.xlsx');
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'rent_roll')->sole()->properties->only(['building', 'to'])->all())
        ->toEqual(['building' => null, 'to' => '2026-06-10']);

    Livewire::actingAs($this->leasing)->test(RentRollReport::class)->set('to', 'not-a-date')->assertHasErrors('to')->assertOk()
        ->call('export')->assertNoFileDownloaded();

    $this->actingAs($this->leasing)->get(route('reports.index'))->assertSee('Rent roll');
});
