<?php

use App\Enums\RoleName;
use App\Jobs\SendScheduledReport;
use App\Models\CompanySetting;
use App\Models\ReportDelivery;
use App\Models\SavedReport;
use App\Models\User;
use App\Notifications\ScheduledPackage;
use App\Notifications\ScheduledReportFailed;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 07:05', 'Asia/Bahrain'));
    [$this->a, $this->b, $this->finance, $this->owner] = reportPackageFixture();
    $this->leasingB = matrixUser(RoleName::Leasing, $this->b);
    $this->package = packageWith($this->finance, [
        ['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => null, 'period' => 'today']],
        ['kind' => 'ready_made', 'report' => 'collections', 'settings' => ['building' => null, 'period' => 'last_month']],
    ], 'Monthly pack', 'shared');
    $this->schedule = $this->package->schedules()->create(['frequency' => 'monthly', 'day' => 1, 'hour' => 7,
        'next_run_at' => CarbonImmutable::parse('2026-11-01 07:00', 'Asia/Bahrain'), 'created_by' => $this->finance->id]);
    $this->schedule->recipients()->attach([$this->finance->id, $this->leasingB->id]);
});

test('a due package schedule is claimed once; each recipient gets one workbook built as them', function () {
    $this->artisan('rms:reports:send-scheduled')->assertSuccessful();
    $this->artisan('rms:reports:send-scheduled')->assertSuccessful(); // same window: nothing more

    Notification::assertSentToTimes($this->finance, ScheduledPackage::class, 1);
    Notification::assertSentTo($this->finance, ScheduledPackage::class, fn (ScheduledPackage $n) => $n->packageName === 'Monthly pack'
        && $n->periodLabel === 'As at 01/11/2026' && $n->skipped === [] && $n->attach && $n->url === route('reports.package', $this->package)
        && $n->lines === ['Rent roll, As at 01/11/2026: 6 rows · Monthly rent: 800.000', 'Collections by date and method, 01/10/2026 – 31/10/2026: 2 rows · Amount: 300.000']);
    // Leasing on BBB only: its own building's rent roll, no money columns, and no collections (reports.financial).
    Notification::assertSentTo($this->leasingB, ScheduledPackage::class, fn (ScheduledPackage $n) => $n->lines === ['Rent roll, As at 01/11/2026: 3 rows']
        && $n->skipped === ['Collections by date and method: You do not have access to this report.']);

    expect(ReportDelivery::sole())->status->toBe('sent')->recipients_count->toBe(2)->error->toBeNull()
        ->and($this->schedule->fresh()->next_run_at->timezone('Asia/Bahrain')->format('Y-m-d H:i'))->toBe('2026-12-01 07:00')
        ->and(Activity::where('event', 'report.sent')->sole()->subject->is($this->package))->toBeTrue();
});

test('a recipient for whom every item is skipped gets nothing, and the delivery says so', function () {
    $this->package->items()->delete();
    $this->package->items()->create(['position' => 1, 'kind' => 'ready_made', 'report' => 'outstanding', 'settings' => ['building' => null]]);

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertSentTo($this->finance, ScheduledPackage::class);
    Notification::assertNotSentTo($this->leasingB, ScheduledPackage::class);
    expect(ReportDelivery::sole())->status->toBe('sent')->recipients_count->toBe(1)
        ->and(ReportDelivery::sole()->error)->toContain('Every item skipped, nothing sent: '.$this->leasingB->name);
});

test('vendor support and inactive staff never get a package', function () {
    $vendor = matrixUser(RoleName::VendorSupport, $this->a);
    $gone = matrixUser(RoleName::Finance, $this->a);
    $gone->forceFill(['active' => false])->save();
    $this->schedule->recipients()->sync([$vendor->id, $gone->id, $this->finance->id]);

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertNotSentTo($vendor, ScheduledPackage::class);
    Notification::assertNotSentTo($gone, ScheduledPackage::class);
    expect(ReportDelivery::sole()->recipients_count)->toBe(1);
});

test('a deleted package or a deactivated creator pauses the schedule', function () {
    $this->package->delete();
    $this->artisan('rms:reports:send-scheduled');

    expect($this->schedule->fresh()->paused_at)->not->toBeNull()->and(ReportDelivery::count())->toBe(0);
    Notification::assertNothingSent();
});

test('one recipient failing does not stop the others; all failing counts and tells the creator', function () {
    $saved = SavedReport::create(['name' => 'Monthly collections', 'subject' => 'payments', 'visibility' => 'shared', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['number', 'amount'], 'period' => ['field' => 'received_on', 'preset' => 'last_month']]]);
    $this->package->items()->create(['position' => 3, 'kind' => 'saved', 'saved_report_id' => $saved->id, 'settings' => []]);
    $second = matrixUser(RoleName::Finance, $this->a);
    $this->schedule->recipients()->sync([$this->finance->id, $second->id]);
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

    Notification::assertSentTo($second, ScheduledPackage::class);
    Notification::assertNotSentTo($this->finance, ScheduledPackage::class);
    expect(ReportDelivery::sole())->status->toBe('sent')->error->toContain($this->finance->name)
        ->and($this->schedule->fresh()->consecutive_failures)->toBe(0);

    $delivery = ReportDelivery::create(['report_schedule_id' => $this->schedule->id, 'due_at' => '2026-12-01 07:00:00', 'status' => 'sending']);
    (new SendScheduledReport($delivery->id))->failed(new RuntimeException('timed out'));
    expect($delivery->fresh()->status)->toBe('failed')->and($this->schedule->fresh()->consecutive_failures)->toBe(1);
    Notification::assertSentTo($this->finance, ScheduledReportFailed::class, fn (ScheduledReportFailed $n) => $n->reportName === 'Monthly pack');
});

test('the package email: one line per item, the skipped ones, the workbook only under 10 MB, and a link to the package', function () {
    $file = tempnam(sys_get_temp_dir(), 'pkg');
    $mail = fn (bool $attach) => (new ScheduledPackage('Monthly pack', 'As at 01/11/2026', ['Rent roll, As at 01/11/2026: 6 rows · Monthly rent: 800.000'],
        ['Outstanding by tenant: You do not have access to this report.'], $file, $attach, 'https://x.test/p'))->toMail($this->finance);
    $with = $mail(true);
    $without = $mail(false);
    @unlink($file);

    expect($with->subject)->toBe('Monthly pack — As at 01/11/2026')
        ->and($with->introLines)->toBe(['Rent roll, As at 01/11/2026: 6 rows · Monthly rent: 800.000', 'Skipped:', 'Outstanding by tenant: You do not have access to this report.'])
        ->and($with->actionText)->toBe('Open in the app')->and($with->actionUrl)->toBe('https://x.test/p')
        ->and($with->attachments)->toHaveCount(1)
        ->and($without->attachments)->toBeEmpty()
        ->and($without->introLines)->toContain('The file is too large to attach; open the package to download it.')
        ->and(SendScheduledReport::ATTACH_LIMIT)->toBe(10 * 1024 * 1024);
});
