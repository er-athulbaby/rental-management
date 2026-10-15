<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00', 'Asia/Bahrain'));
    $this->finance = matrixUser(RoleName::Finance, Building::factory()->create());
    $this->report = SavedReport::create(['name' => 'R', 'subject' => 'invoices', 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'invoices', 'columns' => ['number'], 'period' => ['field' => 'due_date', 'preset' => 'last_month'], 'sort' => ['field' => 'number', 'dir' => 'asc']]]);
    $this->open = fn (?User $as = null) => Livewire::actingAs($as ?? $this->finance)->test(Builder::class, ['report' => $this->report]);
});

test('the creator schedules a saved report to staff; vendor support and outsiders cannot be picked', function () {
    $vendor = User::factory()->withTwoFactor()->create()->assignRole(RoleName::VendorSupport);
    ($this->open)()->assertDontSee($vendor->name)
        ->set('schedule', ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => [$this->finance->id]])->call('addSchedule')->assertHasNoErrors();

    expect(ReportSchedule::sole())->frequency->toBe('monthly')->and(ReportSchedule::sole()->recipients->pluck('id')->all())->toBe([$this->finance->id])
        ->and(ReportSchedule::sole()->next_run_at->format('Y-m-d H:i'))->toBe('2026-11-01 07:00')
        ->and(Activity::where('event', 'report.scheduled')->count())->toBe(1);

    ($this->open)()->set('schedule', ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => [$vendor->id]])->call('addSchedule')->assertHasErrors('schedule.recipients.0');
    expect(ReportSchedule::count())->toBe(1);
});

test('inactive staff are not offered or accepted, and the picker searches by name', function () {
    $gone = matrixUser(RoleName::Leasing, Building::factory()->create());
    $gone->forceFill(['active' => false, 'name' => 'Zed Inactive', 'email' => 'zed@example.test'])->save();
    $other = matrixUser(RoleName::Leasing, Building::factory()->create());
    $other->update(['name' => 'Omar Active', 'email' => 'omar@example.test']);
    $this->finance->update(['name' => 'Fatima Finance', 'email' => 'fatima@example.test']);
    ($this->open)()->assertDontSee($gone->name)->assertSee($other->name)
        ->set('staffSearch', $this->finance->name)->assertSee($this->finance->name)->assertDontSee($other->name)
        ->set('staffSearch', '')
        ->set('schedule', ['frequency' => 'daily', 'day' => null, 'hour' => 6, 'recipients' => [$gone->id, $other->id]])->call('addSchedule')->assertHasErrors('schedule.recipients.0');
});

test('a report can have several schedules and a schedule several recipients', function () {
    $other = matrixUser(RoleName::Leasing, Building::factory()->create());
    ($this->open)()
        ->set('schedule', ['frequency' => 'weekly', 'day' => 0, 'hour' => 8, 'recipients' => [$this->finance->id, $other->id]])->call('addSchedule')->assertHasNoErrors()
        ->set('schedule', ['frequency' => 'daily', 'day' => null, 'hour' => 5, 'recipients' => [$other->id]])->call('addSchedule')->assertHasNoErrors();

    expect(ReportSchedule::count())->toBe(2)->and(ReportSchedule::where('frequency', 'weekly')->first()->recipients)->toHaveCount(2);
});

test('tampered day, hour, frequency and recipients give errors, never a 500', function (array $schedule, string $error) {
    ($this->open)()->set('schedule', $schedule)->call('addSchedule')->assertHasErrors($error);
    expect(ReportSchedule::count())->toBe(0);
})->with(function () {
    $r = [1];

    return [
        'hour too big' => [['frequency' => 'daily', 'day' => null, 'hour' => 99, 'recipients' => $r], 'schedule.hour'],
        'hour text' => [['frequency' => 'daily', 'day' => null, 'hour' => 'x', 'recipients' => $r], 'schedule.hour'],
        'weekly day 9' => [['frequency' => 'weekly', 'day' => 9, 'hour' => 7, 'recipients' => $r], 'schedule.day'],
        'weekly no day' => [['frequency' => 'weekly', 'day' => null, 'hour' => 7, 'recipients' => $r], 'schedule.day'],
        'monthly no day' => [['frequency' => 'monthly', 'day' => null, 'hour' => 7, 'recipients' => $r], 'schedule.day'],
        'monthly day 31' => [['frequency' => 'monthly', 'day' => 31, 'hour' => 7, 'recipients' => $r], 'schedule.day'],
        'bad frequency' => [['frequency' => 'hourly', 'day' => 1, 'hour' => 7, 'recipients' => $r], 'schedule.frequency'],
        'recipients as text' => [['frequency' => 'daily', 'day' => null, 'hour' => 7, 'recipients' => 'a@b.c'], 'schedule.recipients'],
        'recipients nested' => [['frequency' => 'daily', 'day' => null, 'hour' => 7, 'recipients' => [[1]]], 'schedule.recipients.0'],
        'no recipients' => [['frequency' => 'daily', 'day' => null, 'hour' => 7, 'recipients' => []], 'schedule.recipients'],
        'missing keys' => [[], 'schedule.frequency'],
    ];
});

test('only the creator or an admin may schedule, and a stranger cannot touch another report\'s schedule', function () {
    $stranger = matrixUser(RoleName::Finance, Building::factory()->create());
    $this->report->update(['visibility' => 'shared']);
    ($this->open)($stranger)->set('schedule', ['frequency' => 'daily', 'day' => null, 'hour' => 7, 'recipients' => [$stranger->id]])->call('addSchedule')->assertForbidden();

    $schedule = ReportSchedule::create(['saved_report_id' => $this->report->id, 'frequency' => 'daily', 'hour' => 7, 'next_run_at' => now()->addDay(), 'created_by' => $this->finance->id]);
    ($this->open)($stranger)->call('removeSchedule', $schedule->id)->assertForbidden();
    ($this->open)($stranger)->call('resumeSchedule', $schedule->id)->assertForbidden();

    $admin = matrixUser(RoleName::Admin, Building::factory()->create());
    ($this->open)($admin)->set('schedule', ['frequency' => 'daily', 'day' => null, 'hour' => 7, 'recipients' => [$admin->id]])->call('addSchedule')->assertHasNoErrors();
    expect(ReportSchedule::count())->toBe(2);
});

test('an unsaved report has no schedule form and cannot be scheduled', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->assertDontSee('Schedule email')
        ->set('schedule', ['frequency' => 'daily', 'day' => null, 'hour' => 7, 'recipients' => [$this->finance->id]])->call('addSchedule')->assertNotFound();
});

test('schedules list the next run, paused state and last delivery; remove and resume work', function () {
    $schedule = ReportSchedule::create(['saved_report_id' => $this->report->id, 'frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'created_by' => $this->finance->id,
        'next_run_at' => CarbonImmutable::parse('2026-11-01 07:00', 'Asia/Bahrain'), 'consecutive_failures' => 3, 'paused_at' => now()]);
    ReportDelivery::create(['report_schedule_id' => $schedule->id, 'due_at' => '2026-10-01 07:00:00', 'status' => 'failed', 'error' => 'boom']);

    $c = ($this->open)()->assertSee('01/11/2026 07:00')->assertSee('Paused')->assertSee('Last send: failed');
    $c->call('resumeSchedule', $schedule->id);
    $fresh = $schedule->fresh();
    expect($fresh->paused_at)->toBeNull()->and($fresh->consecutive_failures)->toBe(0)
        ->and($fresh->next_run_at->format('Y-m-d H:i'))->toBe('2026-11-01 07:00');

    $c->call('removeSchedule', $schedule->id);
    expect(ReportSchedule::count())->toBe(0);
});

test('a fixed date range warns that it will send the same dates every time', function () {
    $this->report->update(['definition' => [...$this->report->definition, 'period' => ['field' => 'due_date', 'preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']]]);
    ($this->open)()->assertSee('will send the same dates every time');
});

test('removing and resuming a schedule are audited as report.scheduled', function () {
    $schedule = ReportSchedule::create(['saved_report_id' => $this->report->id, 'frequency' => 'daily', 'hour' => 7, 'created_by' => $this->finance->id,
        'next_run_at' => now()->addDay(), 'paused_at' => now(), 'consecutive_failures' => 3]);
    ($this->open)()->call('resumeSchedule', $schedule->id)->call('removeSchedule', $schedule->id);

    expect(Activity::where('event', 'report.scheduled')->get()->map(fn ($a) => $a->properties['action'])->all())->toBe(['resumed', 'removed']);
});

test('another report\'s schedule id is ignored and not audited', function () {
    $other = SavedReport::create(['name' => 'O', 'subject' => 'invoices', 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id, 'definition' => $this->report->definition]);
    $schedule = ReportSchedule::create(['saved_report_id' => $other->id, 'frequency' => 'daily', 'hour' => 7, 'created_by' => $this->finance->id, 'next_run_at' => now()->addDay()]);
    ($this->open)()->call('removeSchedule', $schedule->id)->call('resumeSchedule', $schedule->id);

    expect(ReportSchedule::count())->toBe(1)->and(Activity::where('event', 'report.scheduled')->count())->toBe(0);
});

test('the last day of the month and unknown user ids', function () {
    ($this->open)()->set('schedule', ['frequency' => 'monthly', 'day' => 0, 'hour' => 7, 'recipients' => [$this->finance->id]])->call('addSchedule')->assertHasNoErrors();
    expect(ReportSchedule::sole())->day->toBe(0)->and(ReportSchedule::sole()->next_run_at->format('Y-m-d H:i'))->toBe('2026-10-31 07:00');

    ($this->open)()->set('schedule', ['frequency' => 'daily', 'day' => null, 'hour' => 7, 'recipients' => [999999]])->call('addSchedule')->assertHasErrors('schedule.recipients.0');
});
