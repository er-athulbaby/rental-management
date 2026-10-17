<?php

use App\Enums\RoleName as R;
use App\Livewire\Admin\TenantReminders;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ReminderSend;
use App\Models\ReminderSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->admin = matrixUser(R::Admin, $this->building);
});

test('a fresh install has the four types, all off, with the default days and intro lines', function () {
    $settings = ReminderSetting::query()->get()->keyBy('type');

    expect($settings->keys()->sort()->values()->all())->toBe(['cheque', 'overdue', 'renewal', 'rent_due'])
        ->and($settings->every(fn (ReminderSetting $s) => $s->enabled === false && $s->intro_en !== '' && $s->intro_ar !== ''))->toBeTrue()
        ->and($settings['rent_due']->days)->toBe([3])
        ->and($settings['overdue']->days)->toBe([1, 7, 14])
        ->and($settings['cheque']->days)->toBe([2])
        ->and($settings['renewal']->days)->toBe([30, 60])
        ->and(Customer::factory()->create()->fresh()->no_reminders)->toBeFalse();
});

test('an admin switches a type on and edits its days and intro lines; days are stored sorted', function () {
    Livewire::actingAs($this->admin)->test(TenantReminders::class)
        ->assertSee('Tenant reminders')->assertSee('Days after the grace period ends')
        ->set('settings.overdue.enabled', true)
        ->set('settings.overdue.days', '14, 1 7')
        ->set('settings.overdue.intro_en', 'Please pay what is overdue.')
        ->set('settings.overdue.intro_ar', 'يرجى سداد المبالغ المتأخرة.')
        ->call('save')->assertHasNoErrors();

    $overdue = ReminderSetting::query()->where('type', 'overdue')->sole();
    expect($overdue->enabled)->toBeTrue()->and($overdue->days)->toBe([1, 7, 14])
        ->and($overdue->intro_en)->toBe('Please pay what is overdue.')->and($overdue->intro_ar)->toBe('يرجى سداد المبالغ المتأخرة.')
        ->and(ReminderSetting::query()->where('type', 'rent_due')->sole()->enabled)->toBeFalse();
});

test('days are 1 to 5 different whole numbers from 1 to 365; intros are required, at most 300 characters; tampering is a message, never a 500', function (string $key, mixed $value) {
    Livewire::actingAs($this->admin)->test(TenantReminders::class)
        ->set($key, $value)->assertOk()
        ->call('save')->assertOk()->assertHasErrors($key);

    // Nothing saved: cheque, overdue, renewal, rent_due keep their defaults.
    expect(ReminderSetting::query()->orderBy('type')->get()->map(fn (ReminderSetting $s) => $s->days)->all())->toBe([[2], [1, 7, 14], [30, 60], [3]])
        ->and(ReminderSetting::query()->where('enabled', true)->count())->toBe(0);
})->with([
    'day zero' => ['settings.rent_due.days', '0'],
    'day 366' => ['settings.rent_due.days', '366'],
    'six days' => ['settings.overdue.days', '1, 2, 3, 4, 5, 6'],
    'repeated day' => ['settings.overdue.days', '7, 7'],
    'not a number' => ['settings.cheque.days', 'soon'],
    'a fraction' => ['settings.cheque.days', '1.5'],
    'no days' => ['settings.renewal.days', ''],
    'days as an array' => ['settings.renewal.days', ['30']],
    'English intro too long' => ['settings.rent_due.intro_en', str_repeat('x', 301)],
    'Arabic intro too long' => ['settings.rent_due.intro_ar', str_repeat('ب', 301)],
    'blank Arabic intro' => ['settings.rent_due.intro_ar', '  '],
    'intro as an array' => ['settings.rent_due.intro_en', ['x']],
    'switch not a boolean' => ['settings.cheque.enabled', 'maybe'],
    'a type as text' => ['settings.overdue', 'x'],
    'the whole form as text' => ['settings', 'x'],
]);

test('only Admin and Vendor Support open the page, and the Administration menu links it', function () {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route('admin.reminders'))->status();

        expect($status)->toBe(in_array($role, [R::Admin, R::VendorSupport], true) ? 200 : 403, "admin.reminders as {$role->value}");
    }
    $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('admin.reminders'));
});

test('an automatic reminder is logged once per tenant, type, item, rule day and date; manual ones never collide', function () {
    $tenant = Customer::factory()->create();
    $row = ['customer_id' => $tenant->id, 'type' => 'overdue', 'item_type' => 'invoice', 'item_id' => 5, 'rule_day' => 7,
        'sent_on' => '2026-11-10', 'channel' => 'email', 'status' => 'sent', 'batch' => 'b1'];
    ReminderSend::create($row);

    expect(fn () => ReminderSend::create([...$row, 'batch' => 'b2']))->toThrow(UniqueConstraintViolationException::class);

    ReminderSend::create([...$row, 'rule_day' => 14, 'batch' => 'b3']);         // another rule day
    ReminderSend::create([...$row, 'sent_on' => '2026-11-11', 'batch' => 'b4']); // another date
    ReminderSend::create([...$row, 'rule_day' => null, 'manual' => true, 'batch' => 'b5']);
    ReminderSend::create([...$row, 'rule_day' => null, 'manual' => true, 'batch' => 'b6']);

    expect(ReminderSend::count())->toBe(5);
});

test('the log refuses unknown types, items and statuses, and an automatic row without a rule day', function (array $change) {
    $tenant = Customer::factory()->create();

    expect(fn () => ReminderSend::create(['customer_id' => $tenant->id, 'type' => 'overdue', 'item_type' => 'invoice', 'item_id' => 5, 'rule_day' => 7,
        'sent_on' => '2026-11-10', 'channel' => 'email', 'status' => 'sent', 'batch' => 'b1', ...$change]))->toThrow(QueryException::class);
})->with([
    'type' => [['type' => 'late_fee']],
    'item type' => [['item_type' => 'payment']],
    'status' => [['status' => 'queued']],
    'automatic without a rule day' => [['rule_day' => null]],
]);

test('the settings page warns when email is not set up, and not when it is', function (string $mailer, bool $warns) {
    config(['mail.default' => $mailer]);

    $page = Livewire::actingAs($this->admin)->test(TenantReminders::class);

    $warns ? $page->assertSee('Email is not set up, so no reminders are sent') : $page->assertDontSee('Email is not set up');
})->with([['log', true], ['array', true], ['smtp', false]]);
