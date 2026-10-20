<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName as R;
use App\Livewire\Portal\Invoices;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    $this->agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->account = portalAccount($this->tenant);
    $au = $this->agreement->agreementUnits->first();
    $this->inv1 = issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $au]], '2026-09-01', $this->agreement); // INV-2026-000001
    $this->inv2 = issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $au]], '2026-10-01', $this->agreement); // INV-2026-000002
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance);
    // 500 received, 400 of it on INV-1: INV-2 still owes 400 and 100 is credit. RCP-2026-000001, stored by the sync queue.
    $this->payment = app(RecordPayment::class)->handle($this->finance, $this->tenant, [
        'received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '500.000', 'allocations' => [['invoice_id' => $this->inv1->id, 'amount' => '400.000']],
    ]);
    $cheque = fn (Customer $c, string $no, string $date, string $status) => (new Cheque)->forceFill([
        'direction' => 'received', 'customer_id' => $c->id, 'cheque_no' => $no, 'bank_name' => 'NBB', 'cheque_date' => $date,
        'amount' => '400.000', 'status' => $status, 'created_by' => $this->finance->id,
    ])->save();
    $cheque($this->tenant, 'CHQ-501', '2026-11-01', 'held');
    $cheque($this->tenant, 'CHQ-500', '2026-09-01', 'deposited');
    $this->request = maintenanceRequest(matrixUser(R::PropertyManager, $this->building), $this->unit); // MR-2026-000001, Sara's

    // Another tenant in the same building, never visible to Sara.
    $this->other = Customer::factory()->create(['name_en' => 'Omar Other']);
    $this->otherAgreement = activeAgreement(['customer_id' => $this->other->id, 'start_date' => '2026-01-01', 'end_date' => '2027-06-30'],
        [Unit::factory()->for($this->building)->create(['code' => '202'])]);
    $this->otherInvoice = issuedInvoice($this->other, [['net' => '90.000']], '2026-10-01'); // INV-2026-000003
    $this->otherPayment = app(RecordPayment::class)->handle($this->finance, $this->other, ['received_on' => '2026-10-06', 'method' => 'cash', 'amount' => '90.000']); // RCP-2026-000002
    $cheque($this->other, 'CHQ-900', '2026-11-05', 'held');
    $this->otherMarkers = ['Omar Other', 'INV-2026-000003', 'RCP-2026-000002', 'CHQ-900', $this->otherAgreement->number, 'Seef Tower 202'];
});

test('home shows the balance owed, credit, next due invoice, upcoming held cheques, open requests and agreements ending within 90 days', function () {
    $this->actingAs($this->account, 'tenant')->get(route('portal.home'))->assertOk()
        ->assertSeeInOrder([
            'Balance owed', '400.000', 'Credit', '100.000',
            'Next invoice due', 'INV-2026-000002', '01/10/2026', '400.000',
            'Upcoming cheques', 'CHQ-501', '01/11/2026', '400.000',
            'Open maintenance requests', 'MR-2026-000001',
            'Agreements ending soon', $this->agreement->number, '31/12/2026',
        ])
        ->assertDontSee('CHQ-500');
});

test('the tenant page and the portal agree on what the tenant owes', function () {
    expect($this->tenant->outstandingFils())->toBe(400_000);

    $this->actingAs($this->finance)->get(route('customers.edit', $this->tenant))->assertOk()->assertSeeInOrder(['Outstanding', '400.000', 'Credit', '100.000']);
});

test('agreements list number, status, units, dates and monthly rent, with the stored contract PDF, audited', function () {
    portalStoredContract($this->agreement);
    $draft = Agreement::factory()->create(['customer_id' => $this->tenant->id]);

    $this->actingAs($this->account, 'tenant')->get(route('portal.agreements'))->assertOk()
        ->assertSeeInOrder([$this->agreement->number, 'Active', 'Seef Tower 101', '01/01/2026', '31/12/2026', '400.000 BHD a month'])
        ->assertSee(route('portal.agreements.pdf', $this->agreement))
        ->assertDontSee(route('portal.agreements.pdf', $draft));

    $response = $this->get(route('portal.agreements.pdf', $this->agreement))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->streamedContent())->toBe('%PDF-1.4 contract')
        ->and(Activity::query()->where('event', 'document.downloaded')->sole()->causer?->is($this->account))->toBeTrue();
    $this->get(route('portal.agreements.pdf', $draft))->assertNotFound();
});

test('invoices: unpaid, paid and all tabs with total, paid and balance, and the invoice PDF, audited', function () {
    $page = Livewire::actingAs($this->account, 'tenant')->test(Invoices::class)
        ->assertSeeInOrder(['INV-2026-000002', '18/10/2026', '01/10/2026', '400.000', '0.000', '400.000'])
        ->assertDontSee('INV-2026-000001');
    // after an update the assertion sees Livewire's JSON response, where "/" is escaped, so the dates are read from the rendered HTML
    $page->set('tab', 'paid')->assertDontSee('INV-2026-000002');
    expect(preg_replace('/\s+/', ' ', strip_tags($page->html())))->toContain('INV-2026-000001 Paid Issued 18/10/2026 · Due 01/09/2026 Total400.000 Paid400.000 Balance0.000');
    $page->set('tab', 'all')->assertSee('INV-2026-000001')->assertSee('INV-2026-000002');
    $page->set('tab', ['x'])->assertOk()->assertSee('INV-2026-000002')->assertDontSee('INV-2026-000001'); // tampered: Unpaid

    $this->actingAs($this->account, 'tenant')->get(route('portal.invoices.pdf', $this->inv2))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(Activity::query()->where('event', 'invoice.pdf_viewed')->sole()->causer?->is($this->account))->toBeTrue();
});

test('payments list each receipt with its stored PDF, audited', function () {
    $this->actingAs($this->account, 'tenant')->get(route('portal.payments'))->assertOk()
        ->assertSeeInOrder(['RCP-2026-000001', 'Confirmed', '05/10/2026', 'Cash', '500.000'])
        ->assertSee(route('portal.payments.receipt', $this->payment));

    $response = $this->get(route('portal.payments.receipt', $this->payment))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->streamedContent())->toStartWith('%PDF')
        ->and(Activity::query()->where('event', 'document.downloaded')->where('causer_type', $this->account->getMorphClass())->exists())->toBeTrue();
});

test('cheques list the tenant\'s received cheques with bank, date, amount and status', function () {
    $this->actingAs($this->account, 'tenant')->get(route('portal.cheques'))->assertOk()
        ->assertSeeInOrder(['CHQ-501', 'Held', 'NBB', '01/11/2026', '400.000'])
        ->assertSeeInOrder(['CHQ-500', 'Deposited', 'NBB', '01/09/2026']);
});

test('no list shows another tenant\'s records, and their ids give 404', function () {
    $this->actingAs($this->account, 'tenant');

    foreach (['portal.home', 'portal.agreements', 'portal.payments', 'portal.cheques'] as $name) {
        $html = $this->get(route($name))->assertOk()->getContent();
        foreach ($this->otherMarkers as $marker) {
            expect($html)->not->toContain($marker);
        }
    }
    $html = $this->get(route('portal.invoices', ['tab' => 'all']))->assertOk()->getContent();
    expect($html)->not->toContain('INV-2026-000003');

    portalStoredContract($this->otherAgreement);
    $this->get(route('portal.agreements.pdf', $this->otherAgreement))->assertNotFound();
    $this->get(route('portal.invoices.pdf', $this->otherInvoice))->assertNotFound();
    $this->get(route('portal.payments.receipt', $this->otherPayment))->assertNotFound();
    $this->get('/portal/invoices/abc/pdf')->assertNotFound();
    $this->get('/portal/invoices/999999999999999999999999/pdf')->assertNotFound();
});
