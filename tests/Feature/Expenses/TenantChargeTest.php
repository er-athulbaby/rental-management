<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Enums\RoleName;
use App\Livewire\Expenses\Form;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->unit = Unit::factory()->create(['default_tax_category' => 'standard']);
    $this->customer = Customer::factory()->create();
    activeOwnerContract(['building_id' => $this->unit->building_id, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'type' => 'managed'], [$this->unit]);
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30'], [$this->unit]);
    $this->data = fn (array $over = []) => [
        'building_id' => $this->unit->building_id, 'unit_id' => $this->unit->id, 'category' => 'maintenance',
        'description' => 'Broken window (tenant damage)', 'expense_date' => '2026-10-04', 'net' => '80.000', 'tax_amount' => '8.000',
        'charge_to' => 'tenant', ...$over,
    ];
});

test('a tenant-charged expense issues a manual invoice for the net, taxed by the unit, stamped to no owner', function () {
    $expense = app(RecordExpense::class)->handle($this->finance, ($this->data)());

    $invoice = $expense->invoice;
    $line = $invoice->lines->sole();
    expect($expense->agreement_unit_id)->toBe($this->agreement->agreementUnits()->sole()->id)
        ->and($invoice->status->value)->toBe('issued')
        ->and($invoice->type->value)->toBe('manual')
        ->and($invoice->customer_id)->toBe($this->customer->id)
        ->and($invoice->agreement_id)->toBe($this->agreement->id)
        ->and($line->net)->toBe('80.000')
        ->and($line->tax_amount)->toBe('8.000')
        ->and($line->unit_id)->toBeNull()
        ->and($line->owner_contract_id)->toBeNull()
        ->and($line->agreement_unit_id)->toBe($expense->agreement_unit_id);
});

test('it needs a unit let on the expense date and invoices.manage', function () {
    expect(fn () => app(RecordExpense::class)->handle($this->finance, ($this->data)(['unit_id' => null])))->toThrow(ValidationException::class);
    expect(fn () => app(RecordExpense::class)->handle($this->finance, ($this->data)(['expense_date' => '2026-09-30'])))->toThrow(ValidationException::class);

    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    expect(fn () => app(RecordExpense::class)->handle($pm, ($this->data)()))->toThrow(AuthorizationException::class);
});

test('the link to the invoice is frozen', function () {
    $expense = app(RecordExpense::class)->handle($this->finance, ($this->data)());

    expect(fn () => DB::table('expenses')->where('id', $expense->id)->update(['invoice_id' => null]))->toThrow(QueryException::class, 'expenses: the tenant charge is frozen');
});

test('the form offers Tenant to invoices.manage holders', function () {
    Livewire::actingAs($this->finance)->test(Form::class)->assertSee('Tenant')
        ->set('form.building_id', $this->unit->building_id)->set('form.unit_id', $this->unit->id)
        ->set('form.category', 'maintenance')->set('form.description', 'Lock change')->set('form.expense_date', '2026-10-04')
        ->set('form.net', '20')->set('form.charge_to', 'tenant')
        ->call('save')->assertHasNoErrors();

    expect(Expense::sole()->invoice_id)->not->toBeNull();

    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    Livewire::actingAs($pm)->test(Form::class)->assertDontSee('Tenant');
});

test('a unit with no default category is taxed by the company category for its use', function () {
    CompanySetting::query()->update(['commercial_tax_category' => 'standard', 'residential_tax_category' => 'exempt']);
    $this->unit->update(['default_tax_category' => null, 'use' => 'commercial']);

    $expense = app(RecordExpense::class)->handle($this->finance, ($this->data)());

    expect($expense->invoice->lines->sole()->tax_category->value)->toBe('standard')
        ->and($expense->invoice->lines->sole()->tax_amount)->toBe('8.000');
});
