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

test('portal components expose no public method that takes a customer id', function () {
    $allowed = ['render', 'mount', 'sendCode', 'signIn', 'startAgain', 'save'];

    foreach (glob(app_path('Livewire/Portal/*.php')) as $file) {
        $class = 'App\\Livewire\\Portal\\'.basename($file, '.php');
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || realpath((string) $method->getFileName()) !== realpath($file)) {
                continue; // inherited, or from a trait such as WithFileUploads
            }
            expect($method->getName())->toBeIn($allowed);
            foreach ($method->getParameters() as $parameter) {
                expect(strtolower($parameter->getName()))->not->toContain('customer');
            }
        }
    }
});
