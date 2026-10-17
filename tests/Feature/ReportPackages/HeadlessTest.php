<?php

use App\Enums\RoleName;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\User;
use App\Reports\Packages\PackageReport;
use App\Reports\Packages\ReadyMade;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    [$this->a, $this->b, $this->finance, $this->owner] = reportPackageFixture();
    $this->hidden = Building::factory()->create();
    // Finance sees every building (buildings.view_all); this user sees only AAA, so the whitelist can be tested.
    $this->scoped = User::factory()->withTwoFactor()->create()->givePermissionTo(['reports.operational', 'reports.financial']);
    $this->scoped->buildings()->attach($this->a->id);
});

test('the registry lists the 14 reports under the keys their exports audit', function () {
    expect(ReadyMade::REPORTS)->toHaveCount(14);
    foreach (ReadyMade::REPORTS as $key => $class) {
        expect($key)->toBe(Str::snake(Str::beforeLast(class_basename($class), 'Report')))
            ->and(ReadyMade::make($key))->toBeInstanceOf(PackageReport::class);
    }

    expect(ReadyMade::make('rent_roll')->packageForm($this->finance))->toBe(['title' => 'Rent roll', 'dates' => 'single', 'building' => true, 'options' => [], 'allowed' => true])
        ->and(ReadyMade::make('expiring_agreements')->packageForm($this->finance)['options'])->toBe(['window' => ['30' => 'Next 30 days', '60' => 'Next 60 days', '90' => 'Next 90 days']])
        ->and(ReadyMade::make('id_documents')->packageForm($this->finance)['building'])->toBeFalse()
        ->and(ReadyMade::make('owner_income_statement')->packageForm($this->finance))->toBe(['title' => 'Owner income statement', 'dates' => 'months', 'building' => false,
            'options' => ['basis' => ['collected' => 'Collected', 'billed' => 'Billed'], 'owner' => [$this->owner->id => 'Leila Owner']], 'allowed' => true])
        ->and(ReadyMade::make('income_statement')->packageForm($this->finance)['options']['owner'])->toBe(['' => 'All owners', $this->owner->id => 'Leila Owner'])
        ->and(ReadyMade::make('income_statement')->packageForm($this->finance)['building'])->toBeTrue();
});

test('each ready-made report runs headless and gives exactly the header and rows its own Excel export writes', function (string $key, array $settings, bool $hasRows) {
    $settings = array_map(fn ($v) => match ($v) {
        ':a' => $this->a->id, ':owner' => $this->owner->id, default => $v
    }, $settings);
    $page = Livewire::actingAs($this->finance)->test(ReadyMade::REPORTS[$key]);
    foreach ($settings as $property => $value) {
        $page->set($property, $value);
    }
    $exported = xlsxRows($page->instance()->export()->getFile()->getPathname());

    $sheet = ReadyMade::make($key)->sheet($this->finance, $settings);

    expect(xlsxRows(sheetFile($sheet)))->toBe($exported);
    if ($hasRows) {
        expect(count($exported))->toBeGreaterThan(1);
    }
})->with([
    'rent roll, one building' => ['rent_roll', ['building' => ':a', 'to' => '2026-10-15'], true],
    'occupancy' => ['occupancy', ['to' => '2026-10-15'], true],
    'agreements expiring' => ['expiring_agreements', ['window' => '30'], true],
    'overstays' => ['overstays', [], false],
    'pending approvals' => ['pending_approvals', [], false],
    'ID documents' => ['id_documents', ['window' => '90'], false],
    'cheques' => ['cheques', ['kind' => 'bounced'], false],
    'income statement, one building' => ['income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'billed', 'building' => ':a'], true],
    'owner income statement' => ['owner_income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected', 'owner' => ':owner'], true],
    'outstanding' => ['outstanding', [], true],
    'ageing' => ['ageing', [], true],
    'collections' => ['collections', ['from' => '2026-09-01', 'to' => '2026-10-31'], true],
    'deposits held' => ['deposits_held', ['to' => '2026-10-15'], false],
    'VAT summary' => ['vat_summary', ['from' => '2026-08-01', 'to' => '2026-10-31'], true],
]);

test('a headless run acts as the given user and always puts the signed-in user back', function () {
    $leasingB = matrixUser(RoleName::Leasing, $this->b);
    $this->actingAs($this->finance);

    $sheet = ReadyMade::make('rent_roll')->sheet($leasingB, ['to' => '2026-10-15']);

    expect(auth()->id())->toBe($this->finance->id)
        ->and(collect($sheet['rows'])->pluck(0)->unique()->values()->all())->toBe(['BBB', 'Subtotal BBB', 'Total'])
        ->and($sheet['columns'])->not->toContain('Monthly rent'); // leasing has no reports.financial

    expect(fn () => ReadyMade::make('rent_roll')->sheet($leasingB, ['to' => 'yesterday']))->toThrow(ValidationException::class)
        ->and(auth()->id())->toBe($this->finance->id);

    Auth::forgetUser();
    ReadyMade::make('rent_roll')->sheet($leasingB, ['to' => '2026-10-15']);
    expect(Auth::hasUser())->toBeFalse();
});

test('settings are whitelisted for the user who runs them, and tampered values are messages, never a 500', function (string $key, array $settings, string $error) {
    $settings = array_map(fn ($v) => match ($v) {
        ':hidden' => $this->hidden->id, ':owner' => $this->owner->id, default => $v
    }, $settings);

    foreach (['checkSettings', 'sheet'] as $method) {
        try {
            ReadyMade::make($key)->{$method}($this->scoped, $settings);
            $this->fail("{$method} accepted the settings");
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($error);
        }
    }
})->with([
    'a building the user cannot see' => ['rent_roll', ['to' => '2026-10-15', 'building' => ':hidden'], 'building'],
    'a building as an array' => ['rent_roll', ['to' => '2026-10-15', 'building' => ['1']], 'building'],
    'an unknown option' => ['expiring_agreements', ['window' => '45'], 'window'],
    'an option as an array' => ['expiring_agreements', ['window' => ['30']], 'window'],
    'a missing option' => ['expiring_agreements', [], 'window'],
    'dates the wrong way round' => ['collections', ['from' => '2026-10-31', 'to' => '2026-10-01'], 'to'],
    'a date as an array' => ['rent_roll', ['to' => ['2026-10-15']], 'to'],
    'an unknown basis' => ['income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'cash'], 'basis'],
    'a statement building the user cannot see' => ['income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'billed', 'building' => ':hidden'], 'building'],
    'more than 12 months' => ['income_statement', ['from' => '2025-01', 'to' => '2026-10', 'basis' => 'billed'], 'to'],
    'an owner statement without an owner' => ['owner_income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected'], 'owner'],
    'an unknown owner' => ['owner_income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected', 'owner' => 999999], 'owner'],
]);

test('valid settings pass the check', function () {
    ReadyMade::make('owner_income_statement')->checkSettings($this->scoped, ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected', 'owner' => $this->owner->id]);
    ReadyMade::make('income_statement')->checkSettings($this->scoped, ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected', 'building' => '', 'owner' => '']);
    ReadyMade::make('rent_roll')->checkSettings($this->scoped, ['to' => '2026-10-15', 'building' => (string) $this->a->id]);

    expect(true)->toBeTrue();
});

test('a user without the report\'s permission is refused', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->a);

    expect(ReadyMade::make('outstanding')->packageForm($leasing)['allowed'])->toBeFalse()
        ->and(ReadyMade::make('income_statement')->packageForm($leasing)['allowed'])->toBeFalse()
        ->and(ReadyMade::make('rent_roll')->packageForm($leasing)['allowed'])->toBeTrue()
        ->and(fn () => ReadyMade::make('outstanding')->sheet($leasing, []))->toThrow(AuthorizationException::class)
        ->and(fn () => ReadyMade::make('income_statement')->sheet($leasing, ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'billed']))->toThrow(AuthorizationException::class);
});

test('the headline is the first money figure of the last total row, or none', function () {
    $headline = fn (string $key, array $settings) => ReadyMade::make($key)->sheet($this->finance, $settings)['headline'];

    expect($headline('rent_roll', ['to' => '2026-10-15']))->toBe('Monthly rent: 800.000')
        ->and($headline('collections', ['from' => '2026-09-01', 'to' => '2026-10-31']))->toBe('Amount: 400.000')
        ->and($headline('vat_summary', ['from' => '2026-08-01', 'to' => '2026-10-31']))->toBe('VAT: 0.000')
        ->and($headline('outstanding', []))->toBeNull()
        ->and($headline('income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'billed']))->toStartWith('Result: ');
});

test('asActor restores the previous user even when the run throws', function () {
    $this->actingAs($this->finance);
    $leasing = matrixUser(RoleName::Leasing, $this->a);
    $probe = new class
    {
        use WithActor;

        public function run(User $as, Closure $f): mixed
        {
            return $this->asActor($as, $f);
        }
    };

    expect(fn () => $probe->run($leasing, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class)
        ->and(auth()->id())->toBe($this->finance->id)
        ->and($probe->run($leasing, fn () => auth()->id()))->toBe($leasing->id)
        ->and(auth()->id())->toBe($this->finance->id);
});

test('a non-string or garbage date fails validation, never an exception', function (string $key, array $settings) {
    foreach (['checkSettings', 'sheet'] as $method) {
        expect(fn () => ReadyMade::make($key)->{$method}($this->finance, $settings))->toThrow(ValidationException::class);
    }
})->with([
    'from and to as arrays' => ['collections', ['from' => ['2026-09-01'], 'to' => ['2026-10-31']]],
    'from as an array' => ['collections', ['from' => ['2026-09-01'], 'to' => '2026-10-31']],
    'to as an array' => ['vat_summary', ['from' => '2026-09-01', 'to' => ['2026-10-31']]],
    'garbage strings' => ['collections', ['from' => 'abc', 'to' => 'xyz']],
    'a statement from as an array' => ['income_statement', ['from' => ['2026-08'], 'to' => ['2026-10'], 'basis' => 'billed']],
]);

test('a building-scoped user gets only their building in the output', function () {
    $sheet = ReadyMade::make('rent_roll')->sheet($this->scoped, ['to' => '2026-10-15']);

    expect(collect($sheet['rows'])->pluck(0)->unique()->values()->all())->toBe(['AAA', 'Subtotal AAA', 'Total']);
});
