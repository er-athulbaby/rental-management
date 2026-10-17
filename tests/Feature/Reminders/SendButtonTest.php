<?php

use App\Actions\Cheques\EndCheque;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Jobs\SendTenantReminders;
use App\Livewire\Customers\Form;
use App\Livewire\Reminders\RecordPanel;
use App\Mail\TenantReminder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReminderSend;
use App\Models\ReminderSetting;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    // Every reminder type is off (the install default). The agreement ends in 60 days.
    [$this->tenant, $this->agreement] = reminderTenant(['email' => 'alpha@tenant.test'], '2027-01-09', $this->a);
    $this->invoice = issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-13', $this->agreement);
    $this->cheque = app(RecordCheques::class)->handle($this->finance, $this->tenant, $this->agreement,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']])->first();
});

test('finance previews the exact email and sends it; it is logged as manual by the sender, with every type off', function () {
    $page = Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invoice])
        ->assertSee('Send reminder')->assertSee('Rent due soon · إيجار مستحق قريباً · Demo Properties W.L.L.')->assertSee('alpha@tenant.test');
    $preview = $page->viewData('previewHtml');

    $page->call('send')->assertHasNoErrors();

    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('alpha@tenant.test') && $m->render() === $preview);
    expect(ReminderSend::sole())->manual->toBeTrue()->sent_by->toBe($this->finance->id)->rule_day->toBeNull()
        ->type->toBe('rent_due')->item_type->toBe('invoice')->item_id->toBe($this->invoice->id)->status->toBe('sent');
    $audit = Activity::query()->where('event', 'reminder.sent')->sole();
    expect($audit->properties->only(['manual', 'type', 'item_type', 'item_id'])->all())
        ->toEqual(['manual' => true, 'type' => 'rent_due', 'item_type' => 'invoice', 'item_id' => $this->invoice->id])
        ->and($audit->causer_id)->toBe($this->finance->id);
});

test('the preview is a sandboxed iframe with a title', function () {
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invoice])
        ->assertSeeHtml('<iframe sandbox=""')->assertSeeHtml('title="Email preview"');
});

test('a manual send does not stop the automatic reminder the same day', function () {
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invoice])->call('send')->assertHasNoErrors();
    ReminderSetting::query()->where('type', 'rent_due')->update(['enabled' => true]);

    $this->artisan('rms:reminders:send')->assertSuccessful();

    Mail::assertSentCount(2);
    expect(ReminderSend::query()->where('item_type', 'invoice')->orderBy('id')->pluck('manual')->all())->toBe([true, false]);
});

test('a held cheque and an agreement ending within 90 days each send their own type', function () {
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->cheque])
        ->assertSee('Cheque date coming up')->call('send')->assertHasNoErrors();
    Livewire::actingAs(matrixUser(RoleName::Leasing, $this->a))->test(RecordPanel::class, ['record' => $this->agreement])
        ->assertSee('Agreement ending soon')->call('send')->assertHasNoErrors();

    expect(ReminderSend::query()->orderBy('id')->pluck('type')->all())->toBe(['cheque', 'renewal']);
});

test('sending needs the record\'s manage permission: Finance for invoices and cheques, Leasing for agreements; Management only views', function () {
    $management = matrixUser(RoleName::Management, $this->a);
    foreach ([$this->invoice, $this->cheque, $this->agreement] as $record) {
        Livewire::actingAs($management)->test(RecordPanel::class, ['record' => $record])
            ->assertDontSee('Send reminder')->call('send')->assertForbidden();
    }
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->agreement])
        ->assertDontSee('Send reminder')->call('send')->assertForbidden();

    Mail::assertNothingSent();
    expect(ReminderSend::count())->toBe(0);
});

test('no button, and send refuses, where there is nothing to remind about', function () {
    [, $farOff] = reminderTenant(['email' => 'beta@tenant.test'], '2027-08-31', $this->a); // ends in more than 90 days
    app(EndCheque::class)->handle($this->finance, $this->cheque, 'cancelled');

    $leasing = matrixUser(RoleName::Leasing, $this->a);
    foreach ([[$leasing, $farOff], [$this->finance, $this->cheque->fresh()]] as [$user, $record]) {
        Livewire::actingAs($user)->test(RecordPanel::class, ['record' => $record])->assertDontSee('Send reminder')
            ->call('send')->assertHasErrors('reminder');
    }
    Mail::assertNothingSent();
});

test('opted-out tenants, tenants without email and mail not set up disable the button with the reason', function (string $case, string $reason) {
    match ($case) {
        'opted out' => $this->tenant->update(['no_reminders' => true]),
        'no email' => $this->tenant->update(['email' => null]),
        default => config(['mail.default' => 'log']),
    };

    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invoice])
        ->assertSee('Send reminder')->assertSee("Can't send: {$reason}")->assertViewHas('previewHtml', null)
        ->call('send')->assertHasErrors('reminder');

    Mail::assertNothingSent();
    expect(ReminderSend::count())->toBe(0);
})->with([
    ['opted out', 'The tenant has opted out of reminders.'],
    ['no email', 'The tenant has no email address.'],
    ['mail not set up', 'Email is not set up.'],
]);

test('permission and building scope: leasing sends renewals in its own buildings only, never invoice reminders', function () {
    $leasingA = matrixUser(RoleName::Leasing, $this->a);
    $leasingB = matrixUser(RoleName::Leasing, $this->b);

    Livewire::actingAs($leasingA)->test(RecordPanel::class, ['record' => $this->agreement])->assertSee('Send reminder')->call('send')->assertHasNoErrors();
    Livewire::actingAs($leasingB)->test(RecordPanel::class, ['record' => $this->agreement])->assertForbidden();
    Livewire::actingAs($leasingA)->test(RecordPanel::class, ['record' => $this->invoice])->assertForbidden();

    expect(ReminderSend::sole()->sent_by)->toBe($leasingA->id);
});

test('send re-checks the policy: a user who loses access after mount gets a 403 and nothing is sent', function () {
    $leasingA = matrixUser(RoleName::Leasing, $this->a);
    $page = Livewire::actingAs($leasingA)->test(RecordPanel::class, ['record' => $this->agreement]);

    $leasingA->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $page->call('send')->assertForbidden();
    Mail::assertNothingSent();
    expect(ReminderSend::count())->toBe(0);
});

test('the record kind and id are locked: a tampered id is rejected and nothing is sent for it', function () {
    $other = reminderTenant(['email' => 'gamma@tenant.test'], '2027-01-09', $this->b)[1];
    $page = Livewire::actingAs(matrixUser(RoleName::Leasing, $this->a))->test(RecordPanel::class, ['record' => $this->agreement]);

    expect(fn () => $page->set('recordId', $other->id))->toThrow(Exception::class, 'Cannot update locked property');
    expect(fn () => $page->set('kind', 'invoice'))->toThrow(Exception::class, 'Cannot update locked property');
    $page->call('send')->assertHasNoErrors();

    expect(ReminderSend::sole()->item_id)->toBe($this->agreement->id);
});

test('a record outside the building scope is a 403 on mount', function () {
    $other = reminderTenant(['email' => 'gamma@tenant.test'], '2027-01-09', $this->b)[1];

    Livewire::actingAs(matrixUser(RoleName::Leasing, $this->a))->test(RecordPanel::class, ['record' => $other])->assertForbidden();
});

test('a killed daily job does not flip a concurrent manual send', function () {
    $row = fn (bool $manual, string $batch) => ReminderSend::create(['customer_id' => $this->tenant->id, 'type' => 'rent_due', 'item_type' => 'invoice',
        'item_id' => $this->invoice->id, 'rule_day' => $manual ? null : 3, 'sent_on' => '2026-11-10', 'channel' => 'email', 'status' => 'sending',
        'manual' => $manual, 'sent_by' => $manual ? $this->finance->id : null, 'batch' => $batch]);
    $auto = $row(false, 'auto');
    $manual = $row(true, 'hand');

    (new SendTenantReminders($this->tenant->id, '2026-11-10'))->failed(new RuntimeException('Timed out'));

    expect($auto->fresh()->status)->toBe('failed')->and($manual->fresh()->status)->toBe('sending');
});

test('the invoice, cheque and agreement pages carry the panel', function () {
    $this->actingAs($this->finance)->get(route('invoices.show', $this->invoice))->assertOk()->assertSeeLivewire(RecordPanel::class);
    $this->actingAs($this->finance)->get(route('cheques.show', $this->cheque))->assertOk()->assertSeeLivewire(RecordPanel::class);
    $this->actingAs($this->finance)->get(route('agreements.show', $this->agreement))->assertOk()->assertSeeLivewire(RecordPanel::class);
});

test('the tenant form has the "Don\'t send reminders" switch; tampered values are messages, never a 500', function () {
    $admin = matrixUser(RoleName::Admin, $this->a);

    Livewire::actingAs($admin)->test(Form::class, ['customer' => $this->tenant])->assertSee("Don't send reminders")
        ->assertSet('form.no_reminders', false)
        ->set('form.no_reminders', true)->call('save')->assertHasNoErrors();
    expect($this->tenant->fresh()->no_reminders)->toBeTrue();

    foreach (['maybe', ['x']] as $bad) {
        Livewire::actingAs($admin)->test(Form::class, ['customer' => $this->tenant])->set('form.no_reminders', $bad)->call('save')
            ->assertOk()->assertHasErrors('form.no_reminders');
    }
    expect($this->tenant->fresh()->no_reminders)->toBeTrue();
});

test('a second send of the same reminder within a minute is refused: two rapid sends email once', function () {
    $page = Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invoice]);

    $page->call('send')->assertHasNoErrors();
    $page->call('send')->assertHasErrors('reminder');

    Mail::assertSentCount(1);
    expect(ReminderSend::count())->toBe(1);

    $this->travelTo(now()->addSeconds(61));
    $page->call('send')->assertHasNoErrors();
    Mail::assertSentCount(2);
});
