<?php

use App\Actions\Banks\EnsureDefaultBanks;
use App\Actions\Buildings\EnsureDefaultFacilities;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\EnsureNumberSequences;
use App\Enums\AgreementStatus;
use App\Enums\ApprovalStatus;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OwnerStatementStatus;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\Approval;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\OwnerStatement;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Bahrain'));
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    app(EnsureNumberSequences::class)(2026);
    app(EnsureNumberSequences::class)(2027);
    app(EnsureDefaultContractTemplate::class)();
    app(EnsureDefaultFacilities::class)();
    app(EnsureDefaultBanks::class)();
    User::factory()->create(['email' => 'admin@rms.test'])->assignRole(RoleName::Admin);
    User::factory()->create(['email' => 'manager@rms.test'])->assignRole(RoleName::Management);
});

function runDemoSeeder(): string
{
    Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--no-interaction' => true]);

    return Artisan::output();
}

test('the demo seeder loads a working demo through the Actions, once', function () {
    $output = runDemoSeeder();
    expect($output)->not->toContain('stopped')->toContain('Demo data loaded.');

    expect(Building::query()->where('code', 'like', 'DEMO-%')->count())->toBe(3)
        ->and(Unit::query()->count())->toBe(18)
        ->and(Unit::query()->where('blocked', true)->count())->toBe(1)
        ->and(Customer::query()->count())->toBe(8)
        ->and(Agreement::query()->where('status', AgreementStatus::Active)->count())->toBe(7)
        ->and(Agreement::query()->where('status', AgreementStatus::PendingApproval)->count())->toBe(1)
        ->and(Invoice::query()->where('status', InvoiceStatus::Issued)->count())->toBeGreaterThan(20)
        ->and(Payment::query()->count())->toBeGreaterThan(15)
        ->and(Payment::query()->where('method', 'cheque')->count())->toBe(1)
        ->and(Cheque::query()->where('status', ChequeStatus::Held)->count())->toBe(3)
        ->and(Expense::query()->count())->toBe(4)
        ->and(Approval::query()->where('status', ApprovalStatus::Pending)->count())->toBe(2)
        ->and(OwnerStatement::query()->where('status', OwnerStatementStatus::Draft)->count())->toBe(1);

    $credentials = Storage::disk('local')->get('local-install-credentials.txt');
    expect($credentials)->toContain('finance@rms.test')->toContain('leasing@rms.test')->toContain('pm@rms.test')
        ->and($output)->not->toContain(trim(explode('  ', explode("\n", $credentials)[0])[1]));
    expect(User::query()->where('email', 'leasing@rms.test')->firstOrFail()->buildings()->pluck('code')->all())->toBe(['DEMO-SR']);

    expect(Artisan::call('rms:integrity-check'))->toBe(0);

    // Idempotent: a second run changes nothing.
    expect(runDemoSeeder())->toContain('Demo data already loaded');
    expect(Building::query()->count())->toBe(3)->and(Customer::query()->count())->toBe(8);
});

test('the demo seeder refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

    expect(Artisan::output())->toContain('refuses to run in production')
        ->and(Building::query()->count())->toBe(0);
});
