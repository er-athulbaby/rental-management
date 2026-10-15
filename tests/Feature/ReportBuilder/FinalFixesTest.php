<?php

use App\Enums\RoleName;
use App\Jobs\SendScheduledReport;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ScheduledReport;
use App\Reports\Builder\Grouping;
use App\Reports\Builder\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 07:05', 'Asia/Bahrain'));
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);
});

function sharedTenantsReport(User $by, array $extra = []): SavedReport
{
    return SavedReport::create(['name' => 'Tenant balances', 'subject' => 'tenants', 'visibility' => 'shared', 'created_by' => $by->id, 'updated_by' => $by->id,
        'definition' => ['version' => 1, 'subject' => 'tenants', 'columns' => ['name', 'balance'], 'totals' => ['balance' => 'sum'],
            'filters' => [['field' => 'credit', 'op' => 'gte', 'value' => '0']], 'sort' => ['field' => 'balance', 'dir' => 'desc']] + $extra]);
}

test('the job keeps to one attempt and fails on timeout, so a retry can never email twice', function () {
    $job = new SendScheduledReport(1);

    expect($job->tries)->toBe(1)->and($job->failOnTimeout)->toBeTrue();
});

test('forViewer drops fields the viewer lacks from every part of the definition and names them', function () {
    [$clean, $dropped] = ReportDefinition::forViewer(sharedTenantsReport($this->finance, ['groups' => [['field' => 'credit']], 'chart' => ['type' => 'bars', 'total' => 'balance:sum']])->definition, $this->leasing);

    expect($clean['columns'])->toBe(['name'])->and($clean['filters'])->toBe([])->and($clean['groups'])->toBe([])
        ->and($clean['totals'])->toBe([])->and($clean['chart'])->toBeNull()->and($clean['sort'])->toBe(['field' => 'name', 'dir' => 'asc'])
        ->and($dropped)->toContain('Invoice balances')->toContain('Credit');
    ReportDefinition::fromArray($clean, $this->leasing); // valid for the viewer
});

test('a shared report with a financial field opens for an operational-only user with a note and without the field', function () {
    Customer::factory()->create();
    $report = sharedTenantsReport($this->finance);

    $component = Livewire::actingAs($this->leasing)->test(Builder::class, ['report' => $report])
        ->assertSee('left out')->assertSee('Invoice balances');

    expect(collect($component->viewData('result')->columns)->pluck('key')->all())->toBe(['name'])
        ->and($component->viewData('defErrors'))->toBe([])
        ->and($component->get('def')['columns'])->toBe(['name']);
});

test('a scheduled send to such a user sends without the field, notes it, and is not a failure', function () {
    Customer::factory()->create();
    $report = sharedTenantsReport($this->finance);
    $schedule = ReportSchedule::create(['saved_report_id' => $report->id, 'frequency' => 'monthly', 'day' => 1, 'hour' => 7,
        'next_run_at' => CarbonImmutable::parse('2026-11-01 07:00', 'Asia/Bahrain'), 'created_by' => $this->finance->id]);
    $schedule->recipients()->attach($this->leasing->id);

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertSentTo($this->leasing, ScheduledReport::class, fn ($n) => $n->note !== null && str_contains($n->note, 'Invoice balances')
        && str_contains(implode(' ', $n->toMail($this->leasing)->introLines), 'left out') && $n->rows === 1);
    expect(ReportDelivery::sole()->status)->toBe('sent')->and($schedule->fresh()->consecutive_failures)->toBe(0);
});

test('the scheduled email includes the comparison, and the same lines as the screen', function () {
    $report = SavedReport::create(['name' => 'Collections', 'subject' => 'payments', 'visibility' => 'shared', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['number', 'amount'], 'totals' => ['amount' => 'sum'],
            'period' => ['field' => 'received_on', 'preset' => 'last_month'], 'compare' => 'previous', 'sort' => ['field' => 'number', 'dir' => 'asc']]]);
    $schedule = ReportSchedule::create(['saved_report_id' => $report->id, 'frequency' => 'monthly', 'day' => 1, 'hour' => 7,
        'next_run_at' => CarbonImmutable::parse('2026-11-01 07:00', 'Asia/Bahrain'), 'created_by' => $this->finance->id]);
    $schedule->recipients()->attach($this->finance->id);

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertSentTo($this->finance, ScheduledReport::class, function ($n) {
        $lines = implode("\n", $n->toMail($this->finance)->introLines);

        return str_contains($lines, 'Comparison: 01/10/2026 – 31/10/2026 vs 01/09/2026 – 30/09/2026')
            && str_contains($lines, 'Total · Rows: 0 · 0 · 0') && str_contains($lines, '(sum): 0.000 · 0.000 · 0.000');
    });
});

test('group keys that differ only by case or trailing space are one group and keep their detail rows', function () {
    Customer::factory()->create(['name_en' => 'One', 'nationality' => 'Bahraini']);
    Customer::factory()->create(['name_en' => 'Two', 'nationality' => 'bahraini ']);

    $result = runReport($this->finance, ['subject' => 'tenants', 'columns' => ['name', 'nationality'], 'groups' => [['field' => 'nationality']], 'sort' => ['field' => 'name', 'dir' => 'asc']]);
    $by = Grouping::rowsByGroup($result->rows);
    $group = $result->groups[0];

    expect($result->groups)->toHaveCount(1)->and($group['totals']['count'])->toBe(2)
        ->and($by[Grouping::keyId($group['keys'])])->toHaveCount(2)
        ->and(Grouping::keyId(['NBB']))->toBe(Grouping::keyId(['nbb ']));
});

test('the Buildings field shows only buildings the viewer can see and skips deleted units', function () {
    $b = Building::factory()->create(['code' => 'BBB']);
    $c = Building::factory()->create(['code' => 'CCC']);
    $tenant = Customer::factory()->create(['name_en' => 'Zed']);
    $gone = Unit::factory()->for($c)->create();
    activeAgreement(['customer_id' => $tenant->id, 'start_date' => '2025-01-01', 'end_date' => '2027-08-31'],
        [Unit::factory()->for($this->a)->create(), Unit::factory()->for($b)->create(), $gone]);
    $gone->delete();
    $data = ['subject' => 'tenants', 'columns' => ['name', 'buildings'], 'sort' => ['field' => 'name', 'dir' => 'asc']];

    $scoped = runReport($this->leasing, $data)->rows[0]['buildings'];
    $all = runReport($this->finance, $data)->rows[0]['buildings']; // sees every building, but never a deleted unit's

    expect($scoped)->toBe('AAA')->and($all)->toBe('AAA, BBB');
});
