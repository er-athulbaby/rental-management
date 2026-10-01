<?php

use App\Actions\Billing\CancelDraftInvoice;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\SaveManualInvoice;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Invoices\ManualForm;
use App\Livewire\Invoices\Show;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt (Task 9)
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'default_grace_days' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    $this->data = fn (array $over = []) => [
        'customer_id' => $this->customer->id, 'due_date' => '2026-10-05',
        'lines' => [
            ['description' => 'Key replacement', 'charge_type' => 'damage', 'net' => '20.000', 'tax_category' => 'standard'],
            ['description' => 'Parking October', 'charge_type' => 'parking', 'net' => '15.000', 'tax_category' => 'exempt'],
        ],
        ...$over,
    ];
});

test('Finance drafts, edits and issues a manual invoice; tax and grace are written on issue', function () {
    $draft = app(SaveManualInvoice::class)->handle($this->finance, null, ($this->data)());
    expect($draft->status->value)->toBe('draft')->and($draft->type->value)->toBe('manual')->and($draft->total)->toBe('35.000');

    $draft = app(SaveManualInvoice::class)->handle($this->finance, $draft, ($this->data)(['due_date' => '2026-10-10']));
    expect($draft->lines()->count())->toBe(2);

    Livewire::actingAs($this->finance)->test(Show::class, ['invoice' => $draft])->call('issueNow')->assertHasNoErrors();

    $invoice = $draft->fresh();
    expect($invoice->status->value)->toBe('issued')
        ->and($invoice->number)->toBe('INV-2026-000001')
        ->and($invoice->tax_total)->toBe('2.000')
        ->and($invoice->total)->toBe('37.000')
        ->and($invoice->grace_until->toDateString())->toBe('2026-10-15')
        ->and($invoice->created_by)->toBe($this->finance->id);
});

test('a line with a unit is owner-attributed on issue', function () {
    $unit = Unit::factory()->create();
    $contract = activeOwnerContract(['building_id' => $unit->building_id, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31'], [$unit]);
    $draft = app(SaveManualInvoice::class)->handle($this->finance, null, ($this->data)(['lines' => [
        ['description' => 'Cleaning', 'charge_type' => 'cleaning', 'unit_id' => $unit->id, 'net' => '30.000', 'tax_category' => 'standard'],
    ]]));

    app(IssueInvoice::class)->handle($draft, $this->finance);

    expect($draft->fresh()->lines->sole()->owner_contract_id)->toBe($contract->id);
});

test('lines and dates are validated; issued invoices cannot be edited', function () {
    foreach ([
        ['lines' => []],
        ['lines' => [['description' => '', 'charge_type' => 'other', 'net' => '1', 'tax_category' => 'exempt']]],
        ['lines' => [['description' => 'x', 'charge_type' => 'deposit', 'net' => '1', 'tax_category' => 'out_of_scope']]],
        ['lines' => [['description' => 'x', 'charge_type' => 'other', 'net' => '0', 'tax_category' => 'exempt']]],
        ['due_date' => 'soon'],
    ] as $bad) {
        expect(fn () => app(SaveManualInvoice::class)->handle($this->finance, null, ($this->data)($bad)))->toThrow(ValidationException::class);
    }

    $invoice = issuedInvoice($this->customer, [['net' => '10.000']]);
    expect(fn () => app(SaveManualInvoice::class)->handle($this->finance, $invoice, ($this->data)()))->toThrow(AuthorizationException::class);
});

test('a draft can be cancelled; only invoices.manage holders draft', function () {
    $draft = app(SaveManualInvoice::class)->handle($this->finance, null, ($this->data)());
    app(CancelDraftInvoice::class)->handle($this->finance, $draft);
    expect($draft->fresh()->status->value)->toBe('cancelled');

    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    expect(fn () => app(SaveManualInvoice::class)->handle($management, null, ($this->data)()))->toThrow(AuthorizationException::class);
});

test('the manual invoice form saves lines and redirects to the draft', function () {
    Livewire::withQueryParams(['customer' => $this->customer->id])->actingAs($this->finance)->test(ManualForm::class)
        ->set('form.due_date', '2026-10-20')
        ->set('lines.0.description', 'Utilities September')
        ->set('lines.0.charge_type', 'utilities')
        ->set('lines.0.net', '42.5')
        ->set('lines.0.tax_category', 'standard')
        ->call('addLine')
        ->set('lines.1.description', 'Late key')
        ->set('lines.1.net', '5')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $invoice = Invoice::sole();
    expect($invoice->total)->toBe('47.500')->and($invoice->lines()->count())->toBe(2);
});
