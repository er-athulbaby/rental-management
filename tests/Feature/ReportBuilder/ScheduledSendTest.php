<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Notifications\ScheduledReport;
use App\Notifications\ScheduledReportFailed;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 07:05', 'Asia/Bahrain'));
    $this->a = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);
    $this->report = SavedReport::create(['name' => 'Monthly collections', 'subject' => 'payments', 'visibility' => 'shared',
        'definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['number', 'amount'], 'period' => ['field' => 'received_on', 'preset' => 'last_month'],
            'totals' => ['amount' => 'sum'], 'sort' => ['field' => 'number', 'dir' => 'asc']], 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id]);
    $this->schedule = ReportSchedule::create(['saved_report_id' => $this->report->id, 'frequency' => 'monthly', 'day' => 1, 'hour' => 7,
        'next_run_at' => CarbonImmutable::parse('2026-11-01 07:00', 'Asia/Bahrain'), 'created_by' => $this->finance->id]);
    $this->schedule->recipients()->attach([$this->finance->id, $this->leasing->id]);
});

test('a due schedule sends once to each recipient who may see the subject, then moves to the next run', function () {
    $this->artisan('rms:reports:send-scheduled')->assertSuccessful();
    $this->artisan('rms:reports:send-scheduled')->assertSuccessful(); // a second run in the same window sends nothing

    Notification::assertSentTo($this->finance, ScheduledReport::class, fn ($n) => $n->periodLabel === '01/10/2026 – 31/10/2026');
    Notification::assertNotSentTo($this->leasing, ScheduledReport::class); // payments need financial reports
    expect(ReportDelivery::sole())->status->toBe('sent')->recipients_count->toBe(1)
        ->and(ReportDelivery::sole()->error)->toContain($this->leasing->name)
        ->and($this->schedule->fresh()->next_run_at->timezone('Asia/Bahrain')->format('Y-m-d H:i'))->toBe('2026-12-01 07:00')
        ->and(Activity::where('event', 'report.sent')->count())->toBe(1);
});

test('a duplicate delivery for the same due time is never sent again', function () {
    ReportDelivery::create(['report_schedule_id' => $this->schedule->id, 'due_at' => $this->schedule->next_run_at, 'status' => 'sent']);

    $this->artisan('rms:reports:send-scheduled')->assertSuccessful();

    Notification::assertNothingSent();
    expect(ReportDelivery::count())->toBe(1);
});

test('three failures in a row pause the schedule and tell its creator; a deactivated creator pauses it', function () {
    $this->report->update(['definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['nope']]]);
    foreach (range(1, 3) as $i) {
        $this->artisan('rms:reports:send-scheduled');
        $this->travel(1)->months();
        $this->schedule->refresh();
    }

    Notification::assertSentTo($this->finance, ScheduledReportFailed::class);
    expect($this->schedule->fresh()->paused_at)->not->toBeNull();

    $other = ReportSchedule::create(['saved_report_id' => $this->report->id, 'frequency' => 'daily', 'hour' => 7, 'next_run_at' => now()->subMinute(), 'created_by' => $this->finance->id]);
    $this->finance->forceFill(['active' => false])->save();
    $this->artisan('rms:reports:send-scheduled');
    expect($other->fresh()->paused_at)->not->toBeNull();
});
