<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Reports\Builder\NextRun;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

$at = fn (string $s) => CarbonImmutable::parse($s, 'Asia/Bahrain');
$bh = fn (CarbonImmutable $c) => $c->timezone('Asia/Bahrain')->format('Y-m-d H:i');

test('daily, weekly and monthly runs in Bahrain time', function () use ($at, $bh) {
    expect($bh(NextRun::after(['frequency' => 'daily', 'day' => null, 'hour' => 7], $at('2026-10-15 06:00'))))->toBe('2026-10-15 07:00')
        ->and($bh(NextRun::after(['frequency' => 'daily', 'day' => null, 'hour' => 7], $at('2026-10-15 07:00'))))->toBe('2026-10-16 07:00')
        ->and($bh(NextRun::after(['frequency' => 'weekly', 'day' => 0, 'hour' => 8], $at('2026-10-15 10:00'))))->toBe('2026-10-18 08:00') // next Sunday
        ->and($bh(NextRun::after(['frequency' => 'monthly', 'day' => 1, 'hour' => 7], $at('2026-10-15 10:00'))))->toBe('2026-11-01 07:00')
        ->and($bh(NextRun::after(['frequency' => 'monthly', 'day' => 0, 'hour' => 18], $at('2027-02-10 10:00'))))->toBe('2027-02-28 18:00'); // last day
});

test('edges: same weekday later hour, month rolling, last day after the hour, UTC result', function () use ($at, $bh) {
    $weekly = ['frequency' => 'weekly', 'day' => 4, 'hour' => 8]; // Thursday
    expect($bh(NextRun::after($weekly, $at('2026-10-15 07:00'))))->toBe('2026-10-15 08:00')
        ->and($bh(NextRun::after($weekly, $at('2026-10-15 08:00'))))->toBe('2026-10-22 08:00')
        ->and($bh(NextRun::after(['frequency' => 'monthly', 'day' => 0, 'hour' => 18], $at('2026-10-31 18:00'))))->toBe('2026-11-30 18:00')
        ->and($bh(NextRun::after(['frequency' => 'monthly', 'day' => 28, 'hour' => 7], $at('2026-12-28 07:00'))))->toBe('2027-01-28 07:00');

    $utc = NextRun::after(['frequency' => 'daily', 'day' => null, 'hour' => 7], $at('2026-10-15 06:00'));
    expect($utc->timezoneName)->toBe('UTC')->and($utc->format('H:i'))->toBe('04:00');
});

test('next_run_at is stored as the same instant it was given in UTC', function () use ($at) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = matrixUser(RoleName::Finance, Building::factory()->create());
    $report = SavedReport::create(['name' => 'R', 'subject' => 'invoices', 'definition' => [], 'created_by' => $user->id, 'updated_by' => $user->id]);
    $next = NextRun::after(['frequency' => 'daily', 'day' => null, 'hour' => 7], $at('2026-10-15 06:00'));

    $schedule = ReportSchedule::create(['saved_report_id' => $report->id, 'frequency' => 'daily', 'hour' => 7, 'next_run_at' => $next, 'created_by' => $user->id]);
    $delivery = ReportDelivery::create(['report_schedule_id' => $schedule->id, 'due_at' => $next, 'status' => 'sending']);

    expect($schedule->fresh()->next_run_at->equalTo($next))->toBeTrue()
        ->and($delivery->fresh()->due_at->equalTo($next))->toBeTrue()
        ->and(ReportSchedule::where('next_run_at', '<=', $next->timezone(config('app.timezone')))->count())->toBe(1);

    $schedule->recipients()->attach($user->id);
    expect($schedule->recipients)->toHaveCount(1)
        ->and(fn () => ReportDelivery::create(['report_schedule_id' => $schedule->id, 'due_at' => $next, 'status' => 'sent']))->toThrow(QueryException::class);
});

test('deleting a report removes its schedules', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $user = matrixUser(RoleName::Finance, Building::factory()->create());
    Livewire::actingAs($user)->test(Builder::class)->set('name', 'Mine')->call('save');
    $report = SavedReport::sole();
    ReportSchedule::create(['saved_report_id' => $report->id, 'frequency' => 'daily', 'hour' => 7, 'next_run_at' => now()->addDay(), 'created_by' => $user->id]);

    Livewire::actingAs($user)->test(Builder::class, ['report' => $report])->call('deleteReport');

    expect(ReportSchedule::count())->toBe(0);
});
