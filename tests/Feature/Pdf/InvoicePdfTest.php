<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use App\Pdf\InvoicePdf;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt (Task 9)
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->customer = Customer::factory()->create(['name_en' => 'Noor Aziz']);
});

test('a VAT-registered company with a standard line prints a tax invoice with TRN and breakdown', function () {
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'trn' => '200012345600002']);
    $invoice = issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard'], ['net' => '50.000', 'tax' => 'exempt']]);

    $built = app(InvoicePdf::class)->build($invoice);
    $html = view($built['view'], $built['data'])->render();

    expect(InvoicePdf::title($invoice))->toBe('Tax Invoice')
        ->and($html)->toContain('200012345600002')->toContain('Noor Aziz')->toContain($invoice->number)
        ->toContain('10.00%')->toContain('10.000')->toContain('160.000');
});

test('without VAT, or with only exempt lines, it is a plain invoice', function () {
    CompanySetting::factory()->create(['vat_registered' => false]);
    $invoice = issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard']]);

    expect(InvoicePdf::title($invoice))->toBe('Invoice');
});

test('finance.view holders download an issued invoice PDF; drafts are refused', function () {
    CompanySetting::factory()->create();
    $invoice = issuedInvoice($this->customer, [['net' => '100.000']]);
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);

    $response = $this->actingAs($management)->get(route('invoices.pdf', $invoice));
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');

    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->actingAs($pm)->get(route('invoices.pdf', $invoice))->assertForbidden();
});
