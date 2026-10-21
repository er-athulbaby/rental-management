<?php

use App\Actions\Documents\ShareDocument;
use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\DocumentCategory;
use App\Enums\RoleName as R;
use App\Livewire\Portal;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** A tenant with one of everything the portal shows (isolation tests). */
function isolationTenant(string $name, string $code, User $finance, User $pm, Building $building): array
{
    $tenant = Customer::factory()->create(['name_en' => $name, 'email' => strtolower(strtok($name, ' ')).'@tenant.test']);
    $unit = Unit::factory()->for($building)->create(['code' => $code]);
    $agreement = activeAgreement(['customer_id' => $tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    portalStoredContract($agreement);
    $invoice = issuedInvoice($tenant, [['net' => '400.000', 'au' => $agreement->agreementUnits->first()]], '2026-10-01', $agreement);
    $payment = app(RecordPayment::class)->handle($finance, $tenant, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '100.000']);
    (new Cheque)->forceFill([
        'direction' => 'received', 'customer_id' => $tenant->id, 'cheque_no' => 'CHQ-'.$code, 'bank_name' => 'NBB', 'cheque_date' => '2026-11-01',
        'amount' => '400.000', 'status' => 'held', 'created_by' => $finance->id,
    ])->save();
    $document = app(StoreDocument::class)->handle($pm, $tenant, UploadedFile::fake()->create("rules-{$code}.pdf", 10, 'application/pdf'), DocumentCategory::Other);
    app(ShareDocument::class)->handle($pm, $document, 'tenant', true);
    $request = maintenanceRequest($pm, $unit);

    return [
        'tenant' => $tenant, 'account' => portalAccount($tenant), 'unit' => $unit, 'agreement' => $agreement, 'invoice' => $invoice,
        'payment' => $payment, 'document' => $document, 'request' => $request,
        'markers' => [$name, $agreement->number, (string) $invoice->number, $payment->number, 'CHQ-'.$code, "rules-{$code}.pdf", $request->number, $building->name.' '.$code],
    ];
}

/** An owner with one of everything the owner portal shows, plus a document shared with them (isolation tests). */
function isolationOwner(string $name, string $code, User $admin): array
{
    $owner = portalOwnerFixture($name, $code, $admin);
    $document = app(StoreDocument::class)->handle($admin, $owner['owner'], UploadedFile::fake()->create("deed-{$code}.pdf", 10, 'application/pdf'), DocumentCategory::Other);
    app(ShareDocument::class)->handle($admin, $document, 'owner', true);

    return [...$owner, 'document' => $document, 'markers' => [...$owner['markers'], "deed-{$code}.pdf"]];
}

/** Every owner-portal page and download, with $o's own ids. */
function isolationOwnerUrls(array $o): array
{
    return [
        route('portal.owner.home'), route('portal.owner.buildings'), route('portal.owner.statements'), route('portal.owner.statements.pdf', $o['statement']),
        route('portal.owner.payments'), route('portal.owner.expenses'), route('portal.owner.maintenance'), route('portal.owner.maintenance.show', $o['request']),
        route('portal.owner.contracts'), route('portal.owner.documents'), route('portal.owner.documents.download', $o['document']),
    ];
}

/** @return list<class-string> the owner-portal page components that take no mount argument */
function isolationOwnerComponents(): array
{
    return [Portal\Owner\Home::class, Portal\Owner\Buildings::class, Portal\Owner\Statements::class, Portal\Owner\Payments::class,
        Portal\Owner\Expenses::class, Portal\Owner\Maintenance::class, Portal\Owner\Contracts::class, Portal\Owner\Documents::class];
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->admin = matrixUser(R::Admin, $building);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance);
    $pm = matrixUser(R::PropertyManager, $building);
    $this->a = isolationTenant('Sara Tenant', '101', $finance, $pm, $building);
    $this->b = isolationTenant('Omar Other', '202', $finance, $pm, $building);
    $this->oa = isolationOwner('Ali Owner', '303', $this->admin);
    $this->ob = isolationOwner('Badr Owner', '404', $this->admin);
});

test('tenant A sees none of tenant B\'s records on any portal page', function () {
    $this->actingAs($this->a['account'], 'tenant');
    $pages = [
        route('portal.home'), route('portal.agreements'), route('portal.invoices', ['tab' => 'all']), route('portal.payments'),
        route('portal.cheques'), route('portal.statement'), route('portal.documents'), route('portal.maintenance'), route('portal.maintenance.create'),
    ];

    foreach ($pages as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach ($this->b['markers'] as $marker) {
            expect($html)->not->toContain($marker);
        }
    }
    // A sees their own, so the pages above were not simply empty.
    $this->get(route('portal.documents'))->assertSee('rules-101.pdf');
    $this->get(route('portal.maintenance'))->assertSee($this->a['request']->number);
});

test('every one of tenant B\'s ids is a 404 for tenant A, and so are made-up ids', function () {
    $this->actingAs($this->a['account'], 'tenant');

    foreach ([
        route('portal.agreements.pdf', $this->b['agreement']),
        route('portal.invoices.pdf', $this->b['invoice']),
        route('portal.payments.receipt', $this->b['payment']),
        route('portal.documents.download', $this->b['document']),
        route('portal.maintenance.show', $this->b['request']),
        '/portal/agreements/999999999/contract', '/portal/invoices/abc/pdf', '/portal/payments/1e3/receipt',
        '/portal/documents/99999999999999999999999', '/portal/maintenance/-1',
    ] as $url) {
        $this->get($url)->assertNotFound();
    }

    Livewire::actingAs($this->a['account'], 'tenant')->test(Portal\MaintenanceShow::class, ['maintenance' => (string) $this->b['request']->id])->assertNotFound();
});

test('tenant A cannot send a request for tenant B\'s unit', function () {
    Livewire::actingAs($this->a['account'], 'tenant')->test(Portal\NewMaintenanceRequest::class)
        ->set('form.place', 'unit:'.$this->b['unit']->id)->set('form.description', 'Leak')->call('save')->assertHasErrors('form.place');

    expect(MaintenanceRequest::query()->where('channel', 'portal')->count())->toBe(0);
});

test('a tenant session opens no staff page', function () {
    $a = $this->a;
    $this->actingAs($a['account'], 'tenant');

    foreach ([
        route('dashboard'), route('customers.index'), route('customers.edit', $a['tenant']), route('customers.statement', $a['tenant']),
        route('customers.statement.pdf', ['customer' => $a['tenant'], 'from' => '2026-01-01', 'to' => '2026-10-18']),
        route('agreements.pdf', $a['agreement']), route('invoices.index'), route('invoices.pdf', $a['invoice']), route('payments.receipt', $a['payment']),
        route('documents.download', $a['document']), route('maintenance.requests.show', $a['request']), route('admin.audit'), route('profile.edit'),
    ] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

test('a staff session opens no portal page, and portal components refuse a staff user', function () {
    $a = $this->a;
    $this->actingAs($this->admin);

    foreach ([
        route('portal.home'), route('portal.agreements'), route('portal.agreements.pdf', $a['agreement']), route('portal.invoices'),
        route('portal.invoices.pdf', $a['invoice']), route('portal.payments'), route('portal.payments.receipt', $a['payment']), route('portal.cheques'),
        route('portal.statement'), route('portal.statement.pdf', ['from' => '2026-01-01', 'to' => '2026-10-18']), route('portal.documents'),
        route('portal.documents.download', $a['document']), route('portal.maintenance'), route('portal.maintenance.create'),
        route('portal.maintenance.show', $a['request']),
    ] as $url) {
        $this->get($url)->assertRedirect(route('portal.login'));
    }
    $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));

    foreach ([Portal\Home::class, Portal\Agreements::class, Portal\Invoices::class, Portal\Payments::class, Portal\Cheques::class,
        Portal\Statement::class, Portal\Documents::class, Portal\Maintenance::class, Portal\NewMaintenanceRequest::class] as $component) {
        Livewire::actingAs($this->admin)->test($component)->assertForbidden();
    }
});

test('portal components expose no public method that takes a customer or owner id', function () {
    $allowed = ['render', 'mount', 'sendCode', 'signIn', 'startAgain', 'save'];

    foreach (['App\\Livewire\\Portal\\' => glob(app_path('Livewire/Portal/*.php')), 'App\\Livewire\\Portal\\Owner\\' => glob(app_path('Livewire/Portal/Owner/*.php'))] as $namespace => $files) {
        expect($files)->not->toBeEmpty();
        foreach ($files as $file) {
            $class = $namespace.basename($file, '.php');
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || realpath((string) $method->getFileName()) !== realpath($file)) {
                    continue; // inherited, or from a trait such as WithFileUploads
                }
                expect($method->getName())->toBeIn($allowed);
                foreach ($method->getParameters() as $parameter) {
                    expect(strtolower($parameter->getName()))->not->toContain('customer')->not->toContain('owner');
                }
            }
        }
    }
});

test('owner A sees none of owner B\'s records on any owner page', function () {
    $this->actingAs($this->oa['account'], 'tenant');

    foreach ([...array_filter(isolationOwnerUrls($this->oa), fn (string $url) => ! str_ends_with($url, '/pdf') && ! str_contains($url, '/documents/'))] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach ($this->ob['markers'] as $marker) {
            expect($html)->not->toContain($marker);
        }
    }
    // A sees their own, so the pages above were not simply empty.
    $this->get(route('portal.owner.statements'))->assertSee('OS-303');
    $this->get(route('portal.owner.buildings'))->assertSee('Renter 303');
    $this->get(route('portal.owner.documents'))->assertSee('deed-303.pdf');
});

test('every one of owner B\'s ids is a 404 for owner A, and so are made-up ids', function () {
    $this->actingAs($this->oa['account'], 'tenant');

    foreach ([
        route('portal.owner.statements.pdf', $this->ob['statement']), route('portal.owner.documents.download', $this->ob['document']),
        route('portal.owner.maintenance.show', $this->ob['request']),
        '/portal/owner/statements/999999999/pdf', '/portal/owner/statements/abc/pdf', '/portal/owner/documents/99999999999999999999999',
        '/portal/owner/maintenance/-1', '/portal/owner/maintenance/1e3',
    ] as $url) {
        $this->get($url)->assertNotFound();
    }

    Livewire::actingAs($this->oa['account'], 'tenant')->test(Portal\Owner\MaintenanceShow::class, ['maintenance' => (string) $this->ob['request']->id])->assertNotFound();
});

test('owner pages refuse a tenant-only account', function () {
    $this->actingAs($this->a['account'], 'tenant');

    foreach (isolationOwnerUrls($this->oa) as $url) {
        $this->get($url)->assertNotFound();
    }
    foreach (isolationOwnerComponents() as $component) {
        Livewire::actingAs($this->a['account'], 'tenant')->test($component)->assertNotFound();
    }
});

test('tenant pages refuse an owner-only account; /portal sends it to the owner home', function () {
    $a = $this->a;
    $this->actingAs($this->oa['account'], 'tenant');

    $this->get(route('portal.home'))->assertRedirect(route('portal.owner.home'));
    foreach ([
        route('portal.agreements'), route('portal.agreements.pdf', $a['agreement']), route('portal.invoices'), route('portal.invoices.pdf', $a['invoice']),
        route('portal.payments'), route('portal.payments.receipt', $a['payment']), route('portal.cheques'), route('portal.statement'),
        route('portal.statement.pdf', ['from' => '2026-01-01', 'to' => '2026-10-18']), route('portal.documents'), route('portal.documents.download', $a['document']),
        route('portal.maintenance'), route('portal.maintenance.create'), route('portal.maintenance.show', $a['request']),
    ] as $url) {
        $this->get($url)->assertNotFound();
    }
    foreach ([Portal\Home::class, Portal\Agreements::class, Portal\Invoices::class, Portal\Payments::class, Portal\Cheques::class,
        Portal\Statement::class, Portal\Documents::class, Portal\Maintenance::class, Portal\NewMaintenanceRequest::class] as $component) {
        Livewire::actingAs($this->oa['account'], 'tenant')->test($component)->assertNotFound();
    }
});

test('a staff session opens no owner page, and owner components refuse a staff user', function () {
    $this->actingAs($this->admin);

    foreach (isolationOwnerUrls($this->oa) as $url) {
        $this->get($url)->assertRedirect(route('portal.login'));
    }
    foreach (isolationOwnerComponents() as $component) {
        Livewire::actingAs($this->admin)->test($component)->assertForbidden();
    }
});

test('an owner session opens no staff page', function () {
    $o = $this->oa;
    $this->actingAs($o['account'], 'tenant');

    foreach ([
        route('dashboard'), route('owners.index'), route('owners.edit', $o['owner']), route('owner-contracts.show', $o['managed']),
        route('owner-statements.show', $o['statement']), route('owner-statements.pdf', $o['statement']), route('disbursements.show', $o['payout']),
        route('documents.download', $o['document']), route('admin.audit'),
    ] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
    expect(OwnerContract::query()->portalFor($o['owner']->id)->count())->toBe(2); // the fixture's two contracts, so the routes above had real ids
});
