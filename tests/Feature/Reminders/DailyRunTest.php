<?php

use App\Actions\Cheques\EndCheque;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Jobs\SendTenantReminders;
use App\Mail\TenantReminder;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ReminderSend;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Reminders\Channel;
use App\Reminders\EmailChannel;
use App\Reminders\ReminderItem;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['phone' => '+973 1700 0000', 'address_en' => 'Seef, Manama']);
    config(['mail.default' => 'smtp']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    ReminderSetting::query()->update(['enabled' => true]);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    // Alpha has one of every type today: overdue day 1, rent due in 3 days, a cheque in 2 days, the agreement ends in 60 days.
    [$this->alpha, $agA] = reminderTenant(['name_en' => 'Alpha Tenant', 'email' => 'alpha@tenant.test'], '2027-01-09');
    $au = $agA->agreementUnits->first();
    $this->overdue = issuedInvoice($this->alpha, [['net' => '400.000', 'au' => $au]], '2026-11-04', $agA); // grace until 09/11
    $this->due = issuedInvoice($this->alpha, [['net' => '400.000', 'au' => $au]], '2026-11-13', $agA);
    $this->cheque = app(RecordCheques::class)->handle($this->finance, $this->alpha, $agA,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']])->first();
    // Beta, Gamma (no email) and Delta (opted out): rent due in 3 days only.
    foreach (['beta' => ['email' => 'beta@tenant.test'], 'gamma' => ['email' => null], 'delta' => ['email' => 'delta@tenant.test', 'no_reminders' => true]] as $key => $attrs) {
        [$this->{$key}, $agreement] = reminderTenant(['name_en' => ucfirst($key).' Tenant', ...$attrs], '2027-08-31');
        issuedInvoice($this->{$key}, [['net' => '250.000', 'au' => $agreement->agreementUnits->first()]], '2026-11-13', $agreement);
    }
});

test('one combined email per tenant, subject by urgency; skips are logged with their reason; each sent message is audited', function () {
    $this->artisan('rms:reminders:send')->expectsOutputToContain('4 tenant(s) to remind.')->assertSuccessful();

    Mail::assertSentCount(2);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('alpha@tenant.test')
        && $m->subjectLine() === 'Rent overdue · إيجار متأخر · Demo Properties W.L.L.' && count($m->items) === 4);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('beta@tenant.test')
        && $m->subjectLine() === 'Rent due soon · إيجار مستحق قريباً · Demo Properties W.L.L.');

    $alphaRows = ReminderSend::query()->where('customer_id', $this->alpha->id)->orderBy('id')->get();
    expect($alphaRows->map(fn (ReminderSend $r) => [$r->type, $r->rule_day, $r->status, $r->manual])->all())
        ->toBe([['overdue', 1, 'sent', false], ['rent_due', 3, 'sent', false], ['cheque', 2, 'sent', false], ['renewal', 60, 'sent', false]])
        ->and($alphaRows->pluck('batch')->unique())->toHaveCount(1)
        ->and(ReminderSend::query()->where('customer_id', $this->gamma->id)->sole())->status->toBe('skipped')->reason->toBe('No email')
        ->and(ReminderSend::query()->where('customer_id', $this->delta->id)->sole())->status->toBe('skipped')->reason->toBe('Opted out')
        ->and(ReminderSend::count())->toBe(7);

    $audits = Activity::query()->where('event', 'reminder.sent')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->firstWhere('subject_id', $this->alpha->id)->properties['counts'])->toEqual(['overdue' => 1, 'rent_due' => 1, 'cheque' => 1, 'renewal' => 1])
        ->and($audits->firstWhere('subject_id', $this->alpha->id)->subject_type)->toBe($this->alpha->getMorphClass());
});

test('never twice: a second run the same day sends and logs nothing more', function () {
    $this->artisan('rms:reminders:send')->assertSuccessful();
    $this->artisan('rms:reminders:send')->assertSuccessful();

    Mail::assertSentCount(2);
    expect(ReminderSend::count())->toBe(7)->and(Activity::query()->where('event', 'reminder.sent')->count())->toBe(2);
});

test('one job per tenant; an item that stopped qualifying before its job ran is dropped from the email', function () {
    Queue::fake();
    $this->artisan('rms:reminders:send')->assertSuccessful();

    Queue::assertPushed(SendTenantReminders::class, 4);
    Queue::assertPushed(SendTenantReminders::class, fn (SendTenantReminders $job) => $job->customerId === $this->alpha->id && $job->date === '2026-11-10');

    app(EndCheque::class)->handle($this->finance, $this->cheque, 'cancelled');
    app()->call([new SendTenantReminders($this->alpha->id, '2026-11-10'), 'handle']);

    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('alpha@tenant.test')
        && array_map(fn (ReminderItem $i) => $i->type, $m->items) === ['overdue', 'rent_due', 'renewal']);
    expect(ReminderSend::query()->where('item_type', 'cheque')->exists())->toBeFalse();
});

test('nothing is sent while every type is off or mail is not set up', function (string $case, string $message) {
    match ($case) {
        'off' => ReminderSetting::query()->update(['enabled' => false]),
        default => config(['mail.default' => $case]),
    };

    $this->artisan('rms:reminders:send')->expectsOutputToContain($message)->assertSuccessful();

    Mail::assertNothingSent();
    expect(ReminderSend::count())->toBe(0);
})->with([
    'all types off' => ['off', 'No reminder type is switched on'],
    'mail to the log' => ['log', 'Mail is not set up'],
    'mail to an array' => ['array', 'Mail is not set up'],
]);

test('only switched-on types are sent', function () {
    ReminderSetting::query()->where('type', '!=', 'renewal')->update(['enabled' => false]);

    $this->artisan('rms:reminders:send')->assertSuccessful();

    Mail::assertSentCount(1);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('alpha@tenant.test')
        && $m->subjectLine() === 'Agreement ending soon · اقتراب انتهاء العقد · Demo Properties W.L.L.');
});

test('one tenant\'s mail failure is recorded as failed, the others are still sent, and it is not retried', function () {
    app()->bind(Channel::class, fn () => new class(new EmailChannel) implements Channel
    {
        public function __construct(private EmailChannel $email) {}

        public function name(): string
        {
            return 'email';
        }

        public function ready(): bool
        {
            return true;
        }

        public function unavailableReason(Customer $tenant): ?string
        {
            return $this->email->unavailableReason($tenant);
        }

        public function send(Customer $tenant, array $items): void
        {
            throw_if($tenant->email === 'alpha@tenant.test', RuntimeException::class, 'SMTP said no');
            $this->email->send($tenant, $items);
        }
    });

    $this->artisan('rms:reminders:send')->assertSuccessful();
    $this->artisan('rms:reminders:send')->assertSuccessful();

    Mail::assertSentCount(1);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('beta@tenant.test'));
    expect(ReminderSend::query()->where('customer_id', $this->alpha->id)->pluck('status')->unique()->all())->toBe(['failed'])
        ->and(ReminderSend::query()->where('customer_id', $this->alpha->id)->pluck('reason')->unique()->all())->toBe(['SMTP said no'])
        ->and(Activity::query()->where('event', 'reminder.sent')->count())->toBe(1);
});

test('a job killed mid-send marks its claimed rows failed', function () {
    ReminderSend::create(['customer_id' => $this->alpha->id, 'type' => 'overdue', 'item_type' => 'invoice', 'item_id' => $this->overdue->id, 'rule_day' => 1,
        'sent_on' => '2026-11-10', 'channel' => 'email', 'status' => 'sending', 'batch' => 'b1']);

    (new SendTenantReminders($this->alpha->id, '2026-11-10'))->failed(new RuntimeException('Timed out'));

    expect(ReminderSend::sole())->status->toBe('failed')->reason->toBe('Timed out');
});

test('the run is scheduled daily at 08:00 without overlap; each job runs once within 120 seconds', function () {
    $event = scheduledEvent('rms:reminders:send');
    $job = new SendTenantReminders(1, '2026-11-10');

    expect($event->expression)->toBe('0 8 * * *')->and($event->withoutOverlapping)->toBeTrue()
        ->and(config('app.timezone'))->toBe('Asia/Bahrain')
        ->and($job->tries)->toBe(1)->and($job->timeout)->toBe(120)->and($job->failOnTimeout)->toBeTrue()
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan(120);
});
