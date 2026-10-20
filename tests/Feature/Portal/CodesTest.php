<?php

use App\Actions\Portal\SendLoginCode;
use App\Actions\Portal\VerifyLoginCode;
use App\Mail\PortalSignInCode;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\PortalLoginCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->app->bind(Timebox::class, fn () => (new Timebox)->returnEarly()); // no real 200 ms sleeps; the time-box test below swaps in its own
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'sara@tenant.test']);
    $this->account = portalAccount($this->tenant);
    $this->send = fn (mixed $email = 'sara@tenant.test', string $ip = '10.0.0.1') => app(SendLoginCode::class)->handle($email, $ip);
    $this->verify = fn (mixed $code, mixed $email = 'sara@tenant.test', string $ip = '10.0.0.1') => app(VerifyLoginCode::class)->handle($email, $code, $ip);
});

test('a 6-digit code is emailed in both languages, stored only as a hash, and signs in once', function () {
    ($this->send)('  Sara@Tenant.TEST ');
    $code = portalCodeFor('sara@tenant.test');

    expect($code)->toMatch('/^\d{6}$/');
    $row = PortalLoginCode::query()->sole();
    expect($row->code_hash)->toBe(PortalLoginCode::hash($this->account, $code))->not->toBe($code)
        ->and(DB::table('portal_login_codes')->where('code_hash', $code)->exists())->toBeFalse()
        ->and($row->expires_at->equalTo(now()->addMinutes(10)))->toBeTrue()
        ->and($row->attempts)->toBe(0);

    $mail = Mail::queued(PortalSignInCode::class)->sole();
    expect($mail)->toBeInstanceOf(ShouldQueue::class)->and($mail->tries)->toBe(1)
        ->and($mail->hasTo('sara@tenant.test', 'Sara Tenant'))->toBeTrue();
    $mail->assertHasSubject('Your sign-in code · رمز الدخول · Demo Properties W.L.L.')
        ->assertSeeInOrderInHtml(['dir="ltr"', 'Dear Sara Tenant,', 'Your sign-in code is', $code, 'dir="rtl"', 'سارة', 'رمز الدخول', $code], false);

    expect(($this->verify)($code)?->is($this->account))->toBeTrue()
        ->and($this->account->fresh()->last_login_at?->equalTo(now()))->toBeTrue()
        ->and(($this->verify)($code))->toBeNull(); // once
});

test('a code works for 10 minutes', function (int $minutes, bool $works) {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    $this->travel($minutes)->minutes();

    expect(($this->verify)($code) !== null)->toBe($works);
})->with([
    'after 9 minutes' => [9, true],
    'after 10 minutes' => [10, false],
]);

test('a new code replaces the unused one', function () {
    ($this->send)();
    ($this->send)();
    $second = portalCodeFor('sara@tenant.test');

    expect(PortalLoginCode::query()->sole()->code_hash)->toBe(PortalLoginCode::hash($this->account, $second))
        ->and(($this->verify)($second))->not->toBeNull();
});

test('five wrong tries spend the code', function () {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $try) {
        expect(($this->verify)($wrong))->toBeNull();
    }
    expect(($this->verify)($code))->toBeNull()
        ->and(PortalLoginCode::query()->sole()->attempts)->toBe(5);
});

test('the right code on the fifth try still works', function () {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $try) {
        ($this->verify)($wrong);
    }
    expect(($this->verify)($code))->not->toBeNull();
});

test('unknown, disabled and deleted tenants get the same answer and no email', function () {
    portalAccount(Customer::factory()->create(['email' => 'off@tenant.test']), ['disabled_at' => now()]);
    $gone = Customer::factory()->create(['email' => 'gone@tenant.test']);
    portalAccount($gone);
    $gone->delete();

    foreach (['nobody@tenant.test', 'off@tenant.test', 'gone@tenant.test'] as $email) {
        ($this->send)($email); // returns normally: nothing tells them apart
        expect(($this->verify)('123456', $email))->toBeNull();
    }
    Mail::assertNothingQueued();
    expect(PortalLoginCode::count())->toBe(0);
});

test('a code stops working when the account is disabled or the tenant deleted', function (Closure $end) {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    $end->call($this);

    expect(($this->verify)($code))->toBeNull();
})->with([
    'disabled' => [fn () => $this->account->forceFill(['disabled_at' => now()])->save()],
    'tenant deleted' => [fn () => $this->tenant->delete()],
]);

test('5 code requests per email per hour and 20 per IP per hour', function () {
    foreach (range(1, 5) as $i) {
        ($this->send)('sara@tenant.test', '10.0.1.'.$i);
    }
    expect(fn () => ($this->send)('sara@tenant.test', '10.0.1.99'))->toThrow(ValidationException::class);
    Mail::assertQueued(PortalSignInCode::class, 5);

    RateLimiter::increment('portal-code-ip:10.0.2.1', 3600, 20);
    expect(fn () => ($this->send)('other@tenant.test', '10.0.2.1'))->toThrow(ValidationException::class);
    ($this->send)('other@tenant.test', '10.0.2.2'); // another IP is fine

    $this->travel(61)->minutes();
    ($this->send)('sara@tenant.test', '10.0.1.99');
    Mail::assertQueued(PortalSignInCode::class, 6);
});

test('10 code checks per IP per minute; a refused check costs the code nothing', function () {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    RateLimiter::increment('portal-verify-ip:10.0.0.1', 60, 10);

    expect(fn () => ($this->verify)($code))->toThrow(ValidationException::class)
        ->and(PortalLoginCode::query()->sole()->attempts)->toBe(0)
        ->and(($this->verify)($code, 'sara@tenant.test', '10.0.0.2'))->not->toBeNull();
});

test('asking for a code needs an email address', function (mixed $email) {
    expect(fn () => ($this->send)($email))->toThrow(ValidationException::class);
    Mail::assertNothingQueued();
})->with([
    'empty' => [''],
    'not an email' => ['sara'],
    'an array' => [['sara@tenant.test']],
    'too long' => [str_repeat('a', 250).'@x.test'],
]);

test('tampered or malformed input is only a wrong code, and costs no attempt', function (mixed $code, mixed $email) {
    ($this->send)();

    expect(($this->verify)($code, $email))->toBeNull()
        ->and(PortalLoginCode::query()->sole()->attempts)->toBe(0);
})->with([
    'code as an array' => [['123456'], 'sara@tenant.test'],
    'five digits' => ['12345', 'sara@tenant.test'],
    'letters' => ['12a456', 'sara@tenant.test'],
    'email as an array' => ['123456', ['sara@tenant.test']],
    'no email' => ['123456', ''],
]);

test('no codes while portal email is not set up', function () {
    config(['mail.default' => 'log']);

    expect(fn () => ($this->send)())->toThrow(ValidationException::class);
    Mail::assertNothingQueued();
    expect(PortalLoginCode::count())->toBe(0);
});

test('asking for and checking a code run in the 200 ms time box, for known and unknown emails alike', function () {
    $box = new class extends Timebox
    {
        public array $boxed = [];

        public function call(callable $callback, int $microseconds)
        {
            $this->boxed[] = $microseconds;

            return parent::call($callback, $microseconds);
        }
    };
    $this->app->instance(Timebox::class, $box);

    foreach (['sara@tenant.test', 'nobody@tenant.test'] as $email) {
        ($this->send)($email);
        ($this->verify)('123456', $email);
    }

    expect($box->boxed)->toBe([200_000, 200_000, 200_000, 200_000]);
});

test('10 code checks per email per 10 minutes, from any IP, known or unknown', function (string $email) {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    foreach (range(1, 10) as $i) {
        ($this->verify)('123456', $i % 2 ? Str::upper($email) : " {$email} ", '10.0.3.'.$i); // case and spaces count as the same email
    }

    expect(fn () => ($this->verify)($code, $email, '10.0.3.99'))->toThrow(ValidationException::class);
    $this->travel(10)->minutes();
    expect(($this->verify)('123456', $email, '10.0.3.99'))->toBeNull(); // the window has passed: an answer, not a refusal
})->with(['known' => ['sara@tenant.test'], 'unknown' => ['nobody@tenant.test']]);

test('requests for an unknown email count toward its limit, whatever the case and spaces', function () {
    foreach (range(1, 5) as $i) {
        ($this->send)($i % 2 ? '  NOBODY@tenant.test' : 'nobody@Tenant.test ', '10.0.4.'.$i);
    }

    expect(fn () => ($this->send)('nobody@tenant.test', '10.0.4.99'))->toThrow(ValidationException::class);
    Mail::assertNothingQueued();
});
