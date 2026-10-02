<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reports\VatSummaryReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerCharge;
use App\Models\OwnerStatement;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $customer = Customer::factory()->create();
    $standard = issuedInvoice($customer, [['net' => '100.000', 'tax' => 'standard'], ['net' => '40.000', 'tax' => 'exempt']], '2026-06-05');
    $cn = app(SaveCreditNote::class)->handle($this->finance, $standard, null, ['reason' => 'x', 'lines' => [['credited_line_id' => $standard->lines->firstWhere('tax_category', 'standard')->id, 'amount' => '11.000']]]);
    app(DecideApproval::class)->handle(matrixUser(RoleName::Management, $this->building), app(SubmitCreditNote::class)->handle($this->finance, $cn), true);

    $contract = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '50.000'], []);
    $statement = (new OwnerStatement)->forceFill(['owner_contract_id' => $contract->id, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'cutoff_at' => '2026-05-31 23:59:59', 'status' => 'draft', 'created_by' => $contract->created_by]);
    $statement->save();
    OwnerCharge::create(['owner_contract_id' => $contract->id, 'owner_statement_id' => $statement->id, 'type' => 'management_fee', 'net' => '50.000', 'tax_amount' => '5.000', 'amount' => '-55.000', 'posted_at' => '2026-06-01 00:00:00', 'created_by' => $contract->created_by]);
});

test('output VAT by category from invoices less credit notes, plus VAT on management fees', function () {
    Livewire::actingAs($this->finance)->test(VatSummaryReport::class)->set('from', '2026-06-01')->set('to', '2026-06-30')
        ->assertSeeInOrder(['Invoices', 'Standard rated', '100.000', '10.000'])
        ->assertSeeInOrder(['Invoices', 'Exempt', '40.000', '0.000'])
        ->assertSeeInOrder(['Credit notes', 'Standard rated', '-10.000', '-1.000'])
        ->assertSeeInOrder(['Management fees', 'Standard rated', '50.000', '5.000'])
        ->assertSeeInOrder(['Total output VAT', '14.000']);
});
