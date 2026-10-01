<?php

use App\Actions\Agreements\RecordNotice;
use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\ContractTemplates\SaveContractTemplate;
use App\Actions\Customers\SaveCustomer;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Spec §14 flow 11 for M2. Every user is assigned the fixture building, so Leasing is tested on its role, not its scope
// (scope has its own tests: AgreementScopeTest, NoticeTest, AgreementDraftsTest).

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureDefaultContractTemplate::class)();
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);

    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->customer = Customer::factory()->create();
    $this->leasingCreator = User::factory()->create()->assignRole(R::Leasing);
    $this->leasingCreator->buildings()->attach($this->building->id);

    $this->terms = fn (string $from, string $to) => [
        'customer_id' => $this->customer->id, 'start_date' => $from, 'end_date' => $to, 'frequency' => 'monthly',
        'units' => [['unit_id' => $this->unit->id, 'charges' => [['type' => 'rent', 'monthly_amount' => '300', 'tax_category' => 'exempt']]]],
    ];
    $this->draft = app(SaveAgreement::class)->handle($this->leasingCreator, null, ($this->terms)('2031-01-01', '2031-12-31'));
    $this->pending = app(SubmitAgreement::class)->handle($this->leasingCreator, app(SaveAgreement::class)->handle($this->leasingCreator, null, ($this->terms)('2032-01-01', '2032-12-31')));
    $this->active = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2020-01-01', 'end_date' => '2020-12-31'], [$this->unit]);
    $this->scheduled = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->active, $this->leasingCreator))->first();
});

test('pages open for exactly the roles the spec allows', function (string $route, array $allowed) {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route($route))->status();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$route} as {$role->value}");
    }
})->with([
    ['customers.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['customers.create', [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['agreements.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['agreements.create', [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['admin.templates.index', [R::Admin, R::VendorSupport]],
    ['invoices.index', [R::Admin, R::Management, R::Finance, R::VendorSupport]],
]);

test('each protected Action allows exactly the spec roles', function (string $name, Closure $run, array $allowed) {
    foreach (R::cases() as $role) {
        $user = matrixUser($role, $this->building);
        $denied = false;

        try {
            $run->call($this, $user);
        } catch (AuthorizationException) {
            $denied = true;
        } catch (ValidationException) {
            // Authorised; the data was refused (e.g. already submitted by an earlier role).
        }

        expect($denied)->toBe(! in_array($role, $allowed, true), "{$name} as {$role->value}");
    }
})->with([
    ['save customer', function (User $u) {
        app(SaveCustomer::class)->handle($u, null, ['type' => 'individual', 'name_en' => 'X', 'id_type' => 'cpr', 'id_number' => uniqid(), 'mobile' => '+97330000000']);
    }, [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['save agreement draft', function (User $u) {
        app(SaveAgreement::class)->handle($u, null, ($this->terms)('2033-01-01', '2033-12-31'));
    }, [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['submit agreement', function (User $u) {
        app(SubmitAgreement::class)->handle($u, $this->draft);
    }, [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['decide agreement approval', function (User $u) {
        app(DecideApproval::class)->handle($u, $this->pending, false, 'No');
    }, [R::Management]],
    ['record notice', function (User $u) {
        app(RecordNotice::class)->handle($u, $this->active, null, '2020-06-01', '2020-12-31');
    }, [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['issue invoice now', function (User $u) {
        if (! $u->can('issue', $this->scheduled)) {
            throw new AuthorizationException;
        }
    }, [R::Finance]],
    ['save contract template', function (User $u) {
        app(SaveContractTemplate::class)->handle($u, null, ['name' => uniqid('T'), 'active' => true, 'is_default' => false,
            'clauses' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => 'x', 'body_ar' => 'س']]]);
    }, [R::Admin, R::VendorSupport]],
]);
