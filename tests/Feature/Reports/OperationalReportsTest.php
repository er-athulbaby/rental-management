<?php

use App\Enums\RoleName;
use App\Livewire\Reports\ExpiringAgreementsReport;
use App\Livewire\Reports\OccupancyReport;
use App\Livewire\Reports\OverstaysReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** A report table row's cell text, so digits in codes, names or dates elsewhere on the page can't satisfy an assertion. */
function reportRowText(string $html, string $needle): string
{
    $row = collect(explode('</tr>', $html))->first(fn (string $tr) => str_contains($tr, '<td') && str_contains($tr, $needle));

    return (string) preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags((string) $row), ENT_QUOTES)));
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    [$u1, $u2, $u3] = Unit::factory()->for($this->mine)->count(3)->create()->all();
    Unit::factory()->for($this->mine)->create(['blocked' => true, 'blocked_reason' => 'Works']);
    $other = Unit::factory()->for($this->theirs)->create();
    $agreement = fn (array $units, string $end) => activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => $end], $units);
    $this->soon = $agreement([$u1], '2026-07-01');      // expires in 21 days
    $this->later = $agreement([$u2], '2026-08-25');     // in 76 days
    $this->overstay = $agreement([$u3], '2026-05-31');  // ended, not moved out
    $this->overstay->forceFill(['status' => 'expired'])->save();
    $this->hidden = $agreement([$other], '2026-06-20'); // another building
    $this->leasing = matrixUser(RoleName::Leasing, $this->mine);
});

test('occupancy counts occupied units over units that are not blocked, per building in scope', function () {
    $html = Livewire::actingAs($this->leasing)->test(OccupancyReport::class)
        ->assertSee($this->mine->code)->assertDontSee($this->theirs->code)
        ->html();

    expect(reportRowText($html, $this->mine->code))->toBe("{$this->mine->code} — {$this->mine->name} 4 1 3 0 100.0%"); // units, blocked, occupied (the overstay holds its unit), vacant, %
});

test('agreements expiring within the chosen window, in scope', function () {
    Livewire::actingAs($this->leasing)->test(ExpiringAgreementsReport::class)
        ->assertSee($this->soon->number)->assertDontSee($this->later->number)->assertDontSee($this->hidden->number)
        ->set('window', '90')->assertSee($this->later->number);
});

test('overstays are expired agreements still holding a unit', function () {
    $html = Livewire::actingAs($this->leasing)->test(OverstaysReport::class)
        ->assertSee($this->overstay->number)->assertDontSee($this->soon->number)
        ->html();

    expect(reportRowText($html, $this->overstay->number))->toStartWith($this->overstay->number)->toEndWith('31/05/2026 10'); // 10 days past end
});

test('a report exports to Excel, audited; a bad date shows an error, not a crash', function () {
    Livewire::actingAs($this->leasing)->test(ExpiringAgreementsReport::class)->call('export')->assertFileDownloaded();
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'expiring_agreements')->count())->toBe(1);

    Livewire::actingAs($this->leasing)->test(OccupancyReport::class)->set('to', 'not-a-date')->assertHasErrors('to')->assertOk();
});

test('the reports index lists only what the viewer may open; the pages need reports.operational', function () {
    $this->actingAs($this->leasing)->get(route('reports.index'))->assertOk()->assertSee('Unit availability and occupancy')->assertDontSee('Building profitability');
    $this->actingAs(matrixUser(RoleName::Finance, $this->mine))->get(route('reports.index'))->assertSee('Building profitability');

    $nobody = User::factory()->create(); // no role, so no report permission
    $this->actingAs($nobody)->get(route('reports.occupancy'))->assertForbidden();
});
