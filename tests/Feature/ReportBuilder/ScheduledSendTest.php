<?php

use App\Enums\RoleName;
use App\Jobs\SendScheduledReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ScheduledReport;
use App\Notifications\ScheduledReportFailed;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
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
        $this->schedule->refresh();
        expect($this->schedule->consecutive_failures)->toBe($i)->and($this->schedule->paused_at !== null)->toBe($i === 3);
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

test('the audit names the recipients and the real-date period', function () {
    $this->artisan('rms:reports:send-scheduled');

    $props = Activity::where('event', 'report.sent')->sole()->properties;
    expect($props['recipients'])->toBe([$this->finance->id])->and($props['period'])->toBe('01/10/2026 – 31/10/2026');
});

test('each recipient gets only the rows of their own buildings', function () {
    $b = Building::factory()->create();
    Unit::factory()->for($this->a)->count(2)->create();
    Unit::factory()->for($b)->create();
    $leasingB = matrixUser(RoleName::Leasing, $b);
    $units = SavedReport::create(['name' => 'Units', 'subject' => 'units', 'visibility' => 'shared', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'units', 'columns' => ['code']]]);
    $this->schedule->update(['saved_report_id' => $units->id]);
    $this->schedule->recipients()->sync([$this->leasing->id, $leasingB->id]);

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertSentTo($this->leasing, ScheduledReport::class, fn ($n) => $n->rows === 2);
    Notification::assertSentTo($leasingB, ScheduledReport::class, fn ($n) => $n->rows === 1);
});

test('vendor support and inactive users never get the report', function () {
    $vendor = matrixUser(RoleName::VendorSupport, $this->a);
    $gone = matrixUser(RoleName::Finance, $this->a);
    $gone->forceFill(['active' => false])->save();
    $this->schedule->recipients()->attach([$vendor->id, $gone->id]);

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertNotSentTo($vendor, ScheduledReport::class);
    Notification::assertNotSentTo($gone, ScheduledReport::class);
    Notification::assertSentTo($this->finance, ScheduledReport::class);
    expect(ReportDelivery::sole()->recipients_count)->toBe(1);
});

test('a success resets the failure counter', function () {
    $this->schedule->update(['consecutive_failures' => 2]);

    $this->artisan('rms:reports:send-scheduled');

    expect($this->schedule->fresh())->consecutive_failures->toBe(0)->paused_at->toBeNull();
});

test('one recipient failing does not stop the others; a partial send is not a failure', function () {
    $second = matrixUser(RoleName::Finance, $this->a);
    $this->schedule->recipients()->sync([$this->finance->id, $second->id]);
    $this->schedule->update(['consecutive_failures' => 1]);
    $bad = $this->finance->id;
    app()->bind(Engine::class, fn () => new class($bad) extends Engine
    {
        public function __construct(private int $bad) {}

        public function run(User $user, ReportDefinition $def, ?int $limit = self::DETAIL_LIMIT): ReportResult
        {
            throw_if($user->id === $this->bad, RuntimeException::class, 'boom');

            return parent::run($user, $def, $limit);
        }
    });

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertSentTo($second, ScheduledReport::class);
    Notification::assertNotSentTo($this->finance, ScheduledReport::class);
    expect(ReportDelivery::sole())->status->toBe('sent')->recipients_count->toBe(1)->error->toContain($this->finance->name)
        ->and($this->schedule->fresh()->consecutive_failures)->toBe(1);
    Notification::assertNotSentTo($this->finance, ScheduledReportFailed::class);
});

test('when every recipient fails the delivery fails and counts', function () {
    app()->bind(Engine::class, fn () => new class extends Engine
    {
        public function run(User $user, ReportDefinition $def, ?int $limit = self::DETAIL_LIMIT): ReportResult
        {
            throw new RuntimeException('boom');
        }
    });

    $this->artisan('rms:reports:send-scheduled');

    expect(ReportDelivery::sole()->status)->toBe('failed')->and($this->schedule->fresh()->consecutive_failures)->toBe(1);
    Notification::assertSentTo($this->finance, ScheduledReportFailed::class);
});

test('a job killed while sending is marked failed and the creator told', function () {
    $delivery = ReportDelivery::create(['report_schedule_id' => $this->schedule->id, 'due_at' => $this->schedule->next_run_at, 'status' => 'sending']);

    (new SendScheduledReport($delivery->id))->failed(new RuntimeException('timed out'));

    expect($delivery->fresh()->status)->toBe('failed')->and($this->schedule->fresh()->consecutive_failures)->toBe(1);
    Notification::assertSentTo($this->finance, ScheduledReportFailed::class);
});

test('the email shows the name, period, rows and totals, and attaches only when allowed', function () {
    $file = tempnam(sys_get_temp_dir(), 'rep');
    $with = (new ScheduledReport('Monthly collections', '01/10/2026 – 31/10/2026', 1234, ['Amount (Sum)' => '5.000'], $file, true, 'https://x.test/r'))->toMail($this->finance);
    $without = (new ScheduledReport('Monthly collections', '01/10/2026 – 31/10/2026', 1234, ['Amount (Sum)' => '5.000'], $file, false, 'https://x.test/r'))->toMail($this->finance);
    @unlink($file);

    expect($with->subject)->toBe('Monthly collections — 01/10/2026 – 31/10/2026')
        ->and($with->introLines)->toContain('1,234 rows.')->toContain('Amount (Sum): 5.000')
        ->and($with->actionUrl)->toBe('https://x.test/r')
        ->and($with->attachments)->toHaveCount(1)
        ->and($without->attachments)->toBeEmpty()
        ->and($without->introLines)->toContain('The file is too large to attach; open the report to download it.');
});
