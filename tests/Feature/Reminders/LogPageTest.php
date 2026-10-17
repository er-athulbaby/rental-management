<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reminders\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    [$this->alpha, $this->agA] = reminderTenant(['name_en' => 'Alpha Tenant', 'email' => 'alpha@tenant.test'], '2027-01-09', $this->a);
    [$this->beta, $this->agB] = reminderTenant(['name_en' => 'Beta Tenant', 'email' => null], '2027-08-31', $this->b);
    $this->invA = issuedInvoice($this->alpha, [['net' => '400.000', 'au' => $this->agA->agreementUnits->first()]], '2026-11-04', $this->agA);
    $this->invB = issuedInvoice($this->beta, [['net' => '250.000', 'au' => $this->agB->agreementUnits->first()]], '2026-11-12', $this->agB);
    logReminder($this->alpha, 'overdue', $this->invA);                                                                    // today, sent
    logReminder($this->alpha, 'renewal', $this->agA, ['rule_day' => 60, 'status' => 'failed', 'reason' => 'SMTP said no']);  // today, failed
    logReminder($this->beta, 'rent_due', $this->invB, ['rule_day' => 3, 'sent_on' => '2026-11-09', 'status' => 'skipped', 'reason' => 'No email']);
    logReminder($this->beta, 'renewal', $this->agB, ['rule_day' => null, 'manual' => true, 'sent_by' => $this->finance->id, 'sent_on' => '2026-10-01']); // before the default range
});

test('the log shows the last 30 days: date, tenant, type, item, channel, status, reason, and who sent it', function () {
    $html = Livewire::actingAs($this->finance)->test(Index::class)->assertSet('from', '2026-10-11')->assertSet('to', '2026-11-10')->html();

    expect(reportRowText($html, $this->invA->number))->toBe("10/11/2026 Alpha Tenant Overdue {$this->invA->number} Email Sent Automatic")
        ->and(reportRowText($html, 'SMTP said no'))->toBe("10/11/2026 Alpha Tenant Renewal {$this->agA->number} Email Failed SMTP said no Automatic")
        ->and(reportRowText($html, $this->invB->number))->toBe("09/11/2026 Beta Tenant Rent due {$this->invB->number} Email Skipped The tenant has no email address. Automatic")
        ->and($html)->not->toContain('01/10/2026');
});

test('filters: date range, type, status and building', function () {
    $page = Livewire::actingAs($this->finance)->test(Index::class);

    $page->set('from', '2026-09-01')->assertSee('01/10/2026')->assertSee('Manual · '.$this->finance->name);
    $page->set('type', 'renewal')->assertSee($this->agA->number)->assertDontSee($this->invA->number);
    $page->set('type', '')->set('status', 'failed')->assertSee('SMTP said no')->assertDontSee($this->invA->number);
    $page->set('status', '')->set('building', (string) $this->b->id)->assertSee($this->invB->number)->assertSee('01/10/2026')
        ->assertDontSee($this->invA->number);
});

test('leasing sees agreement reminders in its own buildings only', function () {
    $leasingA = matrixUser(RoleName::Leasing, $this->a);
    $leasingB = matrixUser(RoleName::Leasing, $this->b);

    Livewire::actingAs($leasingA)->test(Index::class)->set('from', '2026-09-01')
        ->assertSee($this->agA->number)->assertDontSee($this->invA->number)->assertDontSee('Beta Tenant');
    Livewire::actingAs($leasingB)->test(Index::class)->set('from', '2026-09-01')
        ->assertSee('Beta Tenant')->assertDontSee($this->invB->number)->assertDontSee('Alpha Tenant')
        ->set('building', (string) $this->a->id)->assertHasErrors('building');
});

test('Excel export of the filtered log is audited as report.exported', function () {
    Livewire::actingAs($this->finance)->test(Index::class)->set('status', 'failed')
        ->call('export')->assertFileDownloaded('reminders-2026-10-11-2026-11-10.xlsx');

    expect(Activity::query()->where('event', 'report.exported')->sole()->properties->all())
        ->toEqual(['report' => 'reminders', 'from' => '2026-10-11', 'to' => '2026-11-10', 'type' => '', 'status' => 'failed', 'building' => '']);
});

test('tampered filters are messages, never a 500, and never export', function (string $property, mixed $value) {
    Livewire::actingAs($this->finance)->test(Index::class)->set($property, $value)->assertOk()->assertHasErrors($property)
        ->call('export')->assertNoFileDownloaded();

    expect(Activity::query()->where('event', 'report.exported')->count())->toBe(0);
})->with([
    'from as an array' => ['from', ['x']],
    'from not a date' => ['from', 'abc'],
    'to before from' => ['to', '2026-10-01'],
    'unknown type' => ['type', 'late_fee'],
    'type as an array' => ['type', ['overdue']],
    'unknown status' => ['status', 'queued'],
    'unknown building' => ['building', '999999'],
    'building as an array' => ['building', ['1']],
]);

test('bad URL parameters fall back to the defaults; every role opens the page; a user with neither permission is refused', function () {
    Livewire::withQueryParams(['from' => ['x'], 'to' => 'zzz', 'type' => 'nope', 'building' => 'abc'])->actingAs($this->finance)->test(Index::class)
        ->assertSet('from', '2026-10-11')->assertSet('to', '2026-11-10')->assertSet('type', '')->assertSet('building', '')->assertHasNoErrors();

    foreach (RoleName::cases() as $role) {
        $this->actingAs(matrixUser($role, $this->a))->get(route('reminders.index'))->assertOk();
    }
    $this->actingAs(User::factory()->withTwoFactor()->create())->get(route('reminders.index'))->assertForbidden();
    $this->actingAs($this->finance)->get(route('dashboard'))->assertSee(route('reminders.index'));
});
