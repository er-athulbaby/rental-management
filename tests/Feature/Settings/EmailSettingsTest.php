<?php

use App\Actions\Settings\SaveMailSettings;
use App\Enums\RoleName as R;
use App\Livewire\Admin\EmailSettings;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\MailSetting;
use App\Reminders\EmailChannel;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

const SECRET = 'S3cret-smtp-pass!';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->admin = matrixUser(R::Admin, $this->building);
    $this->leasing = matrixUser(R::Leasing, $this->building);
    $this->valid = [
        'enabled' => true, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls',
        'username' => 'mailer', 'from_address' => 'rent@example.test', 'from_name' => 'Rent Office', 'password' => SECRET,
    ];
});

function mailForm(array $over = []): array
{
    return array_merge(test()->valid, $over);
}

function saveMailAs($user, array $over = [])
{
    return Livewire::actingAs($user)->test(EmailSettings::class)->set('form', mailForm($over))->call('save');
}

test('an admin opens the page; a role without settings.manage gets 403 on the route and on both actions', function () {
    $this->actingAs($this->admin)->get(route('admin.email'))->assertOk()->assertSee('Email settings');
    $this->actingAs($this->leasing)->get(route('admin.email'))->assertForbidden();

    Livewire::actingAs($this->leasing)->test(EmailSettings::class)->assertForbidden();
    expect(fn () => app(SaveMailSettings::class)->handle($this->leasing, mailForm()))->toThrow(AuthorizationException::class)
        ->and(MailSetting::query()->count())->toBe(0);
});

test('an admin saves; the row holds the values and the sidebar links to the page', function () {
    saveMailAs($this->admin)->assertHasNoErrors();

    $row = MailSetting::query()->sole();
    expect($row->enabled)->toBeTrue()->and($row->host)->toBe('smtp.example.test')->and($row->port)->toBe(587)
        ->and($row->encryption)->toBe('tls')->and($row->from_address)->toBe('rent@example.test')
        ->and($row->updated_by)->toBe($this->admin->id)->and($row->password)->toBe(SECRET);
    $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('admin.email'));
});

test('switching on needs a host and a from address; off, they may stay empty', function () {
    saveMailAs($this->admin, ['host' => '', 'from_address' => ''])->assertHasErrors(['form.host', 'form.from_address']);
    expect(MailSetting::query()->count())->toBe(0);

    saveMailAs($this->admin, ['enabled' => false, 'host' => '', 'from_address' => ''])->assertHasNoErrors();
    expect(MailSetting::query()->sole()->enabled)->toBeFalse();
});

test('port, encryption, lengths and address are checked; tampering is a message, never a 500', function (string $key, mixed $value) {
    saveMailAs($this->admin, [$key => $value])->assertHasErrors(["form.$key"]);
    expect(MailSetting::query()->count())->toBe(0);
})->with([
    'port 0' => ['port', 0], 'port too big' => ['port', 70000], 'port text' => ['port', 'abc'], 'port array' => ['port', [1]],
    'encryption' => ['encryption', 'starttls'], 'encryption array' => ['encryption', ['tls']],
    'host long' => ['host', str_repeat('a', 256)], 'host array' => ['host', ['x']],
    'username long' => ['username', str_repeat('a', 256)], 'from name long' => ['from_name', str_repeat('a', 256)],
    'from name array' => ['from_name', ['x']], 'from address' => ['from_address', 'not-an-email'],
    'password array' => ['password', ['x']],
]);

test('a tampered form (not an array) is a message too', function () {
    Livewire::actingAs($this->admin)->test(EmailSettings::class)->set('form', 'oops')->call('save')->assertHasErrors();
    Livewire::actingAs($this->admin)->test(EmailSettings::class)->set('form', mailForm())->set('testTo', ['a'])->call('sendTest')->assertHasErrors(['testTo']);
});

test('the password is stored encrypted, never in the page or snapshot, and a blank one keeps it', function () {
    $page = saveMailAs($this->admin)->assertHasNoErrors();

    $raw = DB::table('mail_settings')->value('password');
    expect($raw)->not->toBe(SECRET)->and($raw)->not->toContain(SECRET);

    $page->assertDontSee(SECRET)->assertSee('Saved — leave blank to keep')->assertSet('form.password', '');
    expect(json_encode($page->snapshot))->not->toContain(SECRET);
    $this->actingAs($this->admin)->get(route('admin.email'))->assertDontSee(SECRET)->assertSee('Saved — leave blank to keep');

    saveMailAs($this->admin, ['host' => 'other.example.test', 'password' => ''])->assertHasNoErrors();
    expect(MailSetting::query()->sole()->password)->toBe(SECRET)->and(MailSetting::query()->sole()->host)->toBe('other.example.test');

    saveMailAs($this->admin, ['password' => 'new-pass-123'])->assertHasNoErrors();
    expect(MailSetting::query()->sole()->password)->toBe('new-pass-123');
});

test('a save is audited with the staff member as causer and the password never appears', function () {
    saveMailAs($this->admin)->assertHasNoErrors();
    saveMailAs($this->admin, ['host' => 'other.example.test', 'password' => 'new-pass-123'])->assertHasNoErrors();

    $logs = Activity::query()->where('event', 'settings.mail.updated')->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->every(fn ($a) => $a->causer_id === $this->admin->id))->toBeTrue()
        ->and($logs[1]->attribute_changes['old']['host'])->toBe('smtp.example.test')
        ->and($logs[1]->attribute_changes['attributes']['host'])->toBe('other.example.test')
        ->and($logs[1]->properties['password_changed'])->toBeTrue();
    $dump = Activity::query()->get()->toJson();
    expect($dump)->not->toContain(SECRET)->and($dump)->not->toContain('new-pass-123');
});

test('loader: an enabled row sets smtp, host, port, scheme and from, and mail reads as set up', function (string $encryption, string $scheme) {
    config(['mail.default' => 'array']);
    MailSetting::query()->create(mailForm(['encryption' => $encryption]));
    MailSetting::apply();

    expect(config('mail.default'))->toBe('smtp')->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(587)->and(config('mail.mailers.smtp.scheme'))->toBe($scheme)
        ->and(config('mail.mailers.smtp.username'))->toBe('mailer')->and(config('mail.mailers.smtp.password'))->toBe(SECRET)
        ->and(config('mail.from.address'))->toBe('rent@example.test')->and(config('mail.from.name'))->toBe('Rent Office')
        ->and((new EmailChannel)->ready())->toBeTrue();
})->with([['tls', 'smtp'], ['none', 'smtp'], ['ssl', 'smtps']]);

test('loader: a disabled row reads as not set up; no row leaves config alone', function () {
    config(['mail.default' => 'array', 'mail.from.address' => 'env@example.test']);
    MailSetting::apply();
    expect(config('mail.default'))->toBe('array')->and(config('mail.from.address'))->toBe('env@example.test');

    config(['mail.default' => 'smtp']);
    MailSetting::query()->create(mailForm(['enabled' => false]));
    MailSetting::apply();
    expect(config('mail.default'))->toBe('log')->and((new EmailChannel)->ready())->toBeFalse();
});

test('loader: a queued job sees settings saved after boot (Queue::before)', function () {
    config(['mail.default' => 'array']);
    MailSetting::query()->create(mailForm(['host' => 'queue.example.test']));
    dispatch(function () {
        app()->instance('seen-mail-host', config('mail.mailers.smtp.host'));
    })->onConnection('sync');

    expect(app('seen-mail-host'))->toBe('queue.example.test')->and(config('mail.default'))->toBe('smtp');
});

/** Replaces the smtp transport with one that records the config it was built with and every message it is given, or fails. */
function fakeSmtp(?string $failWith = null): object
{
    $state = new stdClass;
    $state->built = null;
    $state->sent = [];
    Mail::extend('smtp', function (array $config) use ($state, $failWith) {
        $state->built = $config;

        return new class($state, $failWith) implements TransportInterface
        {
            public function __construct(private object $state, private ?string $failWith) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if ($this->failWith !== null) {
                    throw new TransportException($this->failWith);
                }
                $this->state->sent[] = $message;

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };
    });

    return $state;
}

test('send test: uses the form values unsaved, sends once to the typed address, in both languages', function () {
    $smtp = fakeSmtp();

    Livewire::actingAs($this->admin)->test(EmailSettings::class)
        ->assertSet('testTo', $this->admin->email)
        ->set('form', mailForm(['host' => 'unsaved.example.test']))
        ->set('testTo', 'someone@example.test')
        ->call('sendTest')->assertHasNoErrors()->assertSee('Test email sent to someone@example.test');

    expect($smtp->built['host'])->toBe('unsaved.example.test')->and($smtp->built['password'])->toBe(SECRET)
        ->and(MailSetting::query()->count())->toBe(0)->and($smtp->sent)->toHaveCount(1);
    $message = $smtp->sent[0];
    expect(array_map(fn ($a) => $a->getAddress(), $message->getTo()))->toBe(['someone@example.test'])
        ->and($message->getFrom()[0]->getAddress())->toBe('rent@example.test')
        ->and($message->getHtmlBody())->toContain('dir="rtl"')->toContain('رسالة'); // English, then Arabic
});

test('send test: a blank password falls back to the saved one', function () {
    MailSetting::query()->create(mailForm());
    $smtp = fakeSmtp();

    Livewire::actingAs($this->admin)->test(EmailSettings::class)->set('form', mailForm(['password' => '']))->call('sendTest')->assertHasNoErrors();

    expect($smtp->built['password'])->toBe(SECRET);
});

test('send test: a failing transport shows the server error, not a 500, and not the password', function () {
    fakeSmtp('Connection refused (535 authentication failed) for '.SECRET);

    $page = Livewire::actingAs($this->admin)->test(EmailSettings::class)->set('form', mailForm())->call('sendTest');

    $page->assertSee('Connection refused')->assertSee('authentication failed')->assertDontSee(SECRET);
    expect($page->get('testMessage'))->not->toContain(SECRET);
});

test('send test: the form and the address are validated before anything is sent', function () {
    $smtp = fakeSmtp();

    Livewire::actingAs($this->admin)->test(EmailSettings::class)->set('form', mailForm(['port' => 0]))->call('sendTest')->assertHasErrors(['form.port']);
    Livewire::actingAs($this->admin)->test(EmailSettings::class)->set('form', mailForm(['enabled' => false, 'host' => '']))->call('sendTest')->assertHasErrors(['form.host']);
    Livewire::actingAs($this->admin)->test(EmailSettings::class)->set('form', mailForm())->set('testTo', 'nope')->call('sendTest')->assertHasErrors(['testTo']);

    expect($smtp->sent)->toBe([]);
});

test('send test: five a minute per user, the sixth is refused', function () {
    $smtp = fakeSmtp();

    $page = Livewire::actingAs($this->admin)->test(EmailSettings::class)->set('form', mailForm());
    foreach (range(1, 5) as $i) {
        $page->call('sendTest')->assertSee('Test email sent to');
    }
    $page->call('sendTest')->assertSee('Too many test emails');

    expect($smtp->sent)->toHaveCount(5);
});

test('a password that can no longer be decrypted (APP_KEY changed) never breaks the app: the page opens and Admin can re-enter it', function () {
    saveMailAs($this->admin)->assertHasNoErrors();
    DB::table('mail_settings')->update(['password' => 'not-an-encrypted-value']);

    MailSetting::apply();
    expect(config('mail.default'))->toBe('smtp')->and(config('mail.mailers.smtp.password'))->toBeNull();

    $this->actingAs($this->admin)->get(route('admin.email'))->assertOk();
    saveMailAs($this->admin)->assertHasNoErrors();
    expect(MailSetting::current()->password)->toBe(SECRET);
});
