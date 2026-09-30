<?php

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\EnsureNumberSequences;
use App\Enums\AgreementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\RoleName;
use App\Livewire\Agreements\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['name_en' => 'Demo Properties', 'name_ar' => 'ديمو للعقارات', 'invoice_lead_days' => 7]);
    app(EnsureDefaultContractTemplate::class)();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);

    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $building = Building::factory()->create();
    $this->leasing->buildings()->attach($building->id);
    $this->unit = Unit::factory()->for($building)->create(['list_rent' => '500.000']);
    $this->customer = Customer::factory()->create(['name_en' => 'Hassan Qasim', 'name_ar' => null, 'id_number' => '850505555']);

    $this->draft = app(SaveAgreement::class)->handle($this->leasing, null, [
        'customer_id' => $this->customer->id, 'start_date' => '2026-10-10', 'end_date' => '2027-10-09', 'frequency' => 'monthly',
        'units' => [['unit_id' => $this->unit->id, 'deposit_amount' => '450', 'charges' => [['type' => 'rent', 'monthly_amount' => '450', 'tax_category' => 'exempt']]]],
    ]);
});

test('submitting freezes the merged clauses, locks the draft and emails Management', function () {
    Notification::fake();

    app(SubmitAgreement::class)->handle($this->leasing, $this->draft);

    $agreement = $this->draft->fresh()->load('clauses');
    expect($agreement->status)->toBe(AgreementStatus::PendingApproval)
        ->and($agreement->clauses)->toHaveCount(9);

    $parties = $agreement->clauses[0];
    expect($parties->body_en)->toContain('Demo Properties')->toContain('Hassan Qasim')->toContain('850505555')
        ->toContain('{agreement_number}')                                  // numbered only on approval
        ->and($parties->body_ar)->toContain('ديمو للعقارات')->toContain('Hassan Qasim') // no Arabic name → English
        ->and($agreement->clauses[1]->body_en)->toBe('{units_table}')
        ->and($agreement->clauses[3]->body_en)->toContain('450.000')->toContain('monthly')
        ->and($agreement->clauses[3]->body_ar)->toContain('شهرياً');

    Notification::assertSentTo($this->management, ApprovalRequested::class);
    expect(fn () => app(SaveAgreement::class)->handle($this->leasing, $agreement, []))->toThrow(ValidationException::class, 'Only draft agreements can be edited.');
});

test('approval activates: AGR number, verify token, schedule, deposit invoice, and what is already due', function () {
    $approval = app(SubmitAgreement::class)->handle($this->leasing, $this->draft);

    app(DecideApproval::class)->handle($this->management, $approval, true);

    $agreement = $this->draft->fresh();
    expect($agreement->status)->toBe(AgreementStatus::Active)
        ->and($agreement->number)->toBe('AGR-2026-000001')
        ->and(strlen((string) $agreement->verify_token))->toBe(32);

    $deposit = $agreement->invoices()->where('type', InvoiceType::Deposit)->sole();
    expect($deposit->status)->toBe(InvoiceStatus::Issued)->and($deposit->number)->toBe('INV-2026-000001');

    $rent = $agreement->invoices()->where('type', InvoiceType::Rent)->orderBy('period_start')->get();
    expect($rent)->toHaveCount(12)
        ->and($rent[0]->status)->toBe(InvoiceStatus::Issued)            // due 10 Oct, issue date 5 Oct (today) → issued at activation
        ->and($rent[0]->number)->toBe('INV-2026-000002')
        ->and($rent[1]->status)->toBe(InvoiceStatus::Scheduled);
});

test('rejection returns the draft, clears the frozen clauses, and the requester cannot approve', function () {
    $approval = app(SubmitAgreement::class)->handle($this->leasing, $this->draft);

    $dual = User::factory()->withTwoFactor()->create()->assignRole([RoleName::Management, RoleName::Leasing]);
    $dual->buildings()->attach($this->unit->building_id);
    $own = app(SaveAgreement::class)->handle($dual, null, [
        'customer_id' => $this->customer->id, 'start_date' => '2028-01-01', 'end_date' => '2028-12-31', 'frequency' => 'monthly',
        'units' => [['unit_id' => $this->unit->id, 'charges' => [['type' => 'rent', 'monthly_amount' => '1', 'tax_category' => 'exempt']]]],
    ]);
    $ownApproval = app(SubmitAgreement::class)->handle($dual, $own);
    expect(fn () => app(DecideApproval::class)->handle($dual, $ownApproval, true))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, false, 'Rent too low');

    $agreement = $this->draft->fresh();
    expect($agreement->status)->toBe(AgreementStatus::Draft)
        ->and($agreement->clauses()->count())->toBe(0)
        ->and($agreement->invoices()->count())->toBe(0);
});

test('the approval summary shows the discount against list rent', function () {
    $approval = app(SubmitAgreement::class)->handle($this->leasing, $this->draft);

    expect($approval->handler()->summary($approval))->toContain('Hassan Qasim')->toContain('450.000')->toContain('50.000')->toContain('10.0%');
});

test('Leasing submits from the agreement page and sees the approval history', function () {
    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->draft])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('Agreement activation');

    expect($this->draft->fresh()->status)->toBe(AgreementStatus::PendingApproval);
});

test('the agreement page renders the documents panel', function () {
    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->draft])
        ->assertSeeLivewire('documents.panel');
});
