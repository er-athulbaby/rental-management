<?php

namespace Database\Seeders;

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Buildings\SaveBuilding;
use App\Actions\Cheques\ClearCheque;
use App\Actions\Cheques\DepositCheques;
use App\Actions\Cheques\RecordCheques;
use App\Actions\Customers\SaveCustomer;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Expenses\RecordExpense;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Actions\Owners\SaveOwner;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\Payments\RecordPayment;
use App\Actions\Units\SaveUnit;
use App\Actions\Users\CreateUser;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\Approval;
use App\Models\Bank;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Demo data for a local or staging install, built only through the app's Actions so approvals, numbering, invoices,
 * owner attribution and the audit trail are all real. Dates are fixed around 3 October 2026. Runs once (marker: DEMO-SR).
 */
class DemoSeeder extends Seeder
{
    private const string MARKER = 'DEMO-SR';

    private string $step = 'start';

    private User $admin;

    private User $manager;

    private User $finance;

    private User $leasing;

    private User $pm;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('DemoSeeder refuses to run in production.');

            return;
        }
        if (Building::withTrashed()->where('code', self::MARKER)->exists()) {
            $this->command->warn('Demo data already loaded');

            return;
        }

        try {
            $this->load();
            $this->command->info('Demo data loaded.');
        } catch (Throwable $e) {
            $this->command->error("Demo seeding stopped at step \"{$this->step}\": ".$e->getMessage());
            if ($e instanceof ValidationException) {
                foreach ($e->errors() as $field => $messages) {
                    $this->command->error("  {$field}: ".implode(' ', $messages));
                }
            }
            $this->command->warn('What was created before this step stays; the demo is partly loaded.');
        }
    }

    private function load(): void
    {
        $this->at('actors');
        $this->admin = User::query()->where('email', 'admin@rms.test')->firstOrFail();
        $this->manager = User::query()->where('email', 'manager@rms.test')->firstOrFail();
        $this->finance = $this->user('Demo Finance', 'finance@rms.test', RoleName::Finance);
        $this->pm = $this->user('Demo Property Manager', 'pm@rms.test', RoleName::PropertyManager);

        // 1. Buildings
        $this->at('buildings');
        $seef = $this->building('Seef Residence', self::MARKER, 'residential', 'Seef', 'Building 2451, Road 2832, Block 428, Seef', 3, 'covered',
            ['Lift', 'Gym', 'Swimming pool', '24-hour security', 'CCTV']);
        $juffair = $this->building('Juffair Heights', 'DEMO-JH', 'residential', 'Juffair', 'Building 312, Road 4004, Block 340, Juffair', 3, 'basement',
            ['Lift', 'CCTV', 'Backup generator', 'Central air conditioning']);
        $amwaj = $this->building('Amwaj Marina Tower', 'DEMO-AM', 'mixed', 'Amwaj Islands', 'Building 1045, Road 5311, Block 253, Amwaj Islands', 3, 'open',
            ['Lift', 'Gym', 'Swimming pool', 'Reception', 'Maintenance on site']);

        $this->at('leasing user');
        $this->leasing = $this->user('Demo Leasing', 'leasing@rms.test', RoleName::Leasing, [$seef->id]);

        // 2. Units: codes left blank so they are numbered from the floor (101, 102, 201, …).
        $this->at('units');
        $sr = $this->units($seef, [
            ['1', 'flat', 2, 2, 110, 'semi', '350', '350'],
            ['1', 'flat', 1, 1, 75, 'furnished', '280', '280'],
            ['2', 'flat', 2, 2, 115, 'semi', '380', '380'],
            ['2', 'studio', 0, 1, 45, 'furnished', '250', '250'],
            ['3', 'flat', 3, 3, 160, 'unfurnished', '520', '520'],
            ['3', 'flat', 3, 3, 165, 'unfurnished', '550', '550', 'Under renovation'],
        ]);
        $jh = $this->units($juffair, [
            ['1', 'flat', 1, 1, 80, 'furnished', '300', '300'],
            ['1', 'flat', 2, 2, 120, 'semi', '400', '400'],
            ['2', 'flat', 2, 2, 120, 'semi', '420', '420'],
            ['2', 'flat', 1, 1, 82, 'furnished', '320', '320'],
            ['3', 'flat', 3, 3, 170, 'semi', '600', '600'],
            ['3', 'flat', 3, 4, 185, 'semi', '650', '650'],
        ]);
        $am = $this->units($amwaj, [
            ['1', 'shop', null, 1, 95, 'unfurnished', '450', '900', null, 'commercial'],
            ['1', 'flat', 2, 2, 125, 'semi', '480', '480'],
            ['2', 'flat', 2, 2, 118, 'semi', '400', '400'],
            ['2', 'flat', 2, 2, 122, 'furnished', '450', '450'],
            ['3', 'flat', 3, 3, 175, 'semi', '600', '600'],
            ['3', 'flat', 3, 4, 190, 'furnished', '650', '650'],
        ]);

        // 3. Owners (Admin: only owners.bank.manage may enter bank details)
        $this->at('owners');
        $yusuf = app(SaveOwner::class)->handle($this->admin, null, [
            'type' => 'person', 'name_en' => 'Yusuf Ebrahim Al Mannai', 'name_ar' => 'يوسف إبراهيم المناعي',
            'id_type' => 'cpr', 'id_number' => '640218903', 'nationality' => 'Bahraini',
            'phone' => '+973 3962 4417', 'email' => 'yusuf.almannai@example.bh', 'address' => 'Villa 118, Road 3907, Block 939, Riffa',
            'bank_name' => $this->bank('National Bank of Bahrain (NBB)'), 'iban' => 'BH05NBOB00000174520331', 'account_name' => 'Yusuf Ebrahim Al Mannai',
        ]);
        $marina = app(SaveOwner::class)->handle($this->admin, null, [
            'type' => 'company', 'name_en' => 'Marina Crest Properties W.L.L.', 'name_ar' => 'شركة مارينا كرست للعقارات ذ.م.م',
            'id_type' => 'cr', 'id_number' => '112847-1', 'nationality' => 'Bahraini',
            'phone' => '+973 1729 3380', 'email' => 'accounts@marinacrest.example.bh', 'address' => 'Office 22, Building 1045, Road 5311, Amwaj Islands',
            'bank_name' => $this->bank('Bank of Bahrain and Kuwait (BBK)'), 'iban' => 'BH84BBKU00100004862917', 'account_name' => 'Marina Crest Properties W.L.L.',
        ]);

        // 4. Owner contracts: Finance submits, Management approves.
        $this->at('owner contract Juffair');
        $jhContract = $this->ownerContract($yusuf, $juffair, $jh, [
            'type' => 'leased', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'rent_amount' => '9000.000', 'payment_frequency' => 'quarterly',
            'notes' => 'Head lease of the whole building, paid quarterly in advance.',
        ]);
        $this->at('owner contract Amwaj');
        $this->ownerContract($marina, $amwaj, $am, [
            'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'fee_type' => 'percent_collected', 'fee_value' => '10.000',
            'expense_approval_limit' => '200.000', 'deposits_held_by' => 'company', 'notes' => 'Managed for the owner: 10% of rent collected.',
        ]);

        $this->at('head-lease payment');
        $payable = $jhContract->payables()->where('period_start', '2026-07-01')->firstOrFail();
        app(RecordDisbursement::class)->handle($this->finance, [
            'purpose' => 'head_lease', 'owner_payable_id' => $payable->id, 'amount' => $payable->amount, 'method' => 'bank_transfer',
            'paid_on' => '2026-07-01', 'reference' => 'NBB transfer TT-260701-0457',
        ]);

        // 5. Customers (Property Manager)
        $this->at('customers');
        $c = array_map(fn (array $r) => app(SaveCustomer::class)->handle($this->pm, null, [
            'type' => $r[0], 'name_en' => $r[1], 'name_ar' => $r[2], 'id_type' => $r[3], 'id_number' => $r[4], 'nationality' => $r[5],
            'mobile' => $r[6], 'email' => $r[7], 'contact_person' => $r[8], 'address' => 'Kingdom of Bahrain',
        ]), [
            'ahmed' => ['individual', 'Ahmed Yusuf Al Mahmood', 'أحمد يوسف المحمود', 'cpr', '850412337', 'Bahraini', '+973 3655 1289', 'ahmed.almahmood@example.bh', null],
            'fatima' => ['individual', 'Fatima Hassan Al Aradi', 'فاطمة حسن العرادي', 'cpr', '900715824', 'Bahraini', '+973 3311 7740', 'fatima.alaradi@example.bh', null],
            'rajesh' => ['individual', 'Rajesh Kumar Nair', null, 'cpr', '780923561', 'Indian', '+973 3920 6631', 'rajesh.nair@example.com', null],
            'james' => ['individual', 'James Whitfield', null, 'cpr', '820301449', 'British', '+973 3488 0215', 'j.whitfield@example.co.uk', null],
            'maria' => ['individual', 'Maria Santos', null, 'cpr', '910628175', 'Filipino', '+973 3377 9054', 'maria.santos@example.com', null],
            'omar' => ['individual', 'Omar Khalid Al Qahtani', 'عمر خالد القحطاني', 'passport', 'K4829173', 'Saudi', '+973 3604 2278', 'omar.alqahtani@example.sa', null],
            'gulf' => ['company', 'Gulf Horizon Trading W.L.L.', 'شركة أفق الخليج للتجارة ذ.م.م', 'cr', '98542-3', 'Bahraini', '+973 1753 6620', 'admin@gulfhorizon.example.bh', 'Hussain Abdulla (HR manager)'],
            'noor' => ['company', 'Al Noor Medical Services W.L.L.', 'شركة النور للخدمات الطبية ذ.م.م', 'cr', '131207-2', 'Bahraini', '+973 1771 4093', 'finance@alnoormedical.example.bh', 'Dr. Layla Al Sayed'],
        ]);

        // 6. Agreements: created and submitted by the Property Manager (or Leasing), approved by Management.
        $this->at('agreements');
        $rent = fn (Unit $u, string $amount) => ['type' => 'rent', 'monthly_amount' => $amount, 'tax_category' => $u->effectiveTaxCategory()->value];
        $extra = fn (Unit $u, string $type, string $amount, string $desc) => ['type' => $type, 'monthly_amount' => $amount, 'description' => $desc, 'tax_category' => $u->effectiveTaxCategory()->value];

        $ahmed = $this->agreement($this->pm, $c['ahmed'], '2026-07-01', '2027-06-30', 'monthly', [
            ['unit_id' => $sr[0]->id, 'deposit_amount' => '350.000', 'charges' => [$rent($sr[0], '350.000')]],
        ]);
        $maria = $this->agreement($this->leasing, $c['maria'], '2026-08-01', '2027-07-31', 'monthly', [
            ['unit_id' => $sr[3]->id, 'deposit_amount' => '250.000', 'charges' => [$rent($sr[3], '250.000'), $extra($sr[3], 'parking', '15.000', 'Covered parking bay')]],
        ]);
        $gulf = $this->agreement($this->pm, $c['gulf'], '2026-07-01', '2027-06-30', 'quarterly', [
            ['unit_id' => $jh[2]->id, 'deposit_amount' => '420.000', 'charges' => [$rent($jh[2], '420.000'), $extra($jh[2], 'service_charge', '25.000', 'Service charge')]],
            ['unit_id' => $am[2]->id, 'deposit_amount' => '400.000', 'charges' => [$rent($am[2], '400.000'), $extra($am[2], 'service_charge', '25.000', 'Service charge')]],
        ]);
        $noor = $this->agreement($this->pm, $c['noor'], '2026-08-01', '2028-07-31', 'monthly', [
            ['unit_id' => $am[0]->id, 'deposit_amount' => '900.000', 'charges' => [$rent($am[0], '450.000'), $extra($am[0], 'parking', '30.000', 'Two open parking bays')]],
        ]);
        $james = $this->agreement($this->pm, $c['james'], '2026-07-01', '2026-11-30', 'monthly', [
            ['unit_id' => $jh[0]->id, 'deposit_amount' => '300.000', 'charges' => [$rent($jh[0], '300.000')]],
        ]);
        $rajesh = $this->agreement($this->pm, $c['rajesh'], '2026-07-01', '2027-06-30', 'monthly', [
            ['unit_id' => $jh[3]->id, 'deposit_amount' => '320.000', 'charges' => [$rent($jh[3], '320.000')]],
        ]);
        $fatima = $this->agreement($this->pm, $c['fatima'], '2026-07-01', '2027-06-30', 'quarterly', [
            ['unit_id' => $am[4]->id, 'deposit_amount' => '600.000', 'charges' => [$rent($am[4], '600.000'), $extra($am[4], 'service_charge', '40.000', 'Service charge')]],
        ]);

        // 7. Payments (Finance)
        $this->at('payments');
        foreach ([$ahmed, $maria, $gulf] as $agreement) {
            $this->payEachUpTo($agreement, '2026-09-30');
        }
        $this->payEachUpTo($noor, '2026-08-31'); // September is paid by cheque below
        $this->payEachUpTo($james, '2026-08-31');
        $this->pay($c['james'], '2026-09-10', '150.000', 'cash', 'Part payment of September rent; balance promised by month end');
        $this->payEachUpTo($rajesh, '2026-07-31'); // nothing since August: overdue in the ageing report
        $open = $this->openFils($c['fatima']);
        $this->pay($c['fatima'], '2026-10-01', Fils::toDecimal($open + 50_000), 'bank_transfer', 'Paid the quarter in full; 50 BHD over, held as credit');

        // 8. Post-dated cheques for Al Noor
        $this->at('cheques');
        $noorRent = fn (string $due) => $noor->invoices()->where('type', InvoiceType::Rent)->where('due_date', $due)->firstOrFail();
        $cheques = app(RecordCheques::class)->handle($this->finance, $c['noor'], $noor, array_map(fn (array $r) => [
            'cheque_no' => $r[0], 'bank_name' => $this->bank('National Bank of Bahrain (NBB)'), 'account_holder' => 'Al Noor Medical Services W.L.L.',
            'cheque_date' => $r[1], 'amount' => $noorRent($r[2])->total, 'invoice_id' => $noorRent($r[2])->id,
        ], [ // cheque no, cheque date, the rent invoice's due date
            ['004511', '2026-09-01', '2026-09-01'],
            ['004512', '2026-10-05', '2026-10-01'],
            ['004513', '2026-11-01', '2026-11-01'],
            ['004514', '2026-12-01', '2026-12-01'],
        ]));
        $this->at('cheque deposit and clearing');
        app(DepositCheques::class)->handle($this->finance, [$cheques->firstOrFail()->id], '2026-09-02');
        app(ClearCheque::class)->handle($this->finance, $cheques->firstOrFail()->refresh(), '2026-09-06');

        // 9. Expenses (Finance)
        $this->at('expenses');
        $expense = app(RecordExpense::class);
        $expense->handle($this->finance, ['building_id' => $seef->id, 'category' => 'maintenance', 'description' => 'Lift maintenance contract, July to September',
            'expense_date' => '2026-09-15', 'net' => '180.000', 'charge_to' => 'company']);
        $expense->handle($this->finance, ['building_id' => $juffair->id, 'category' => 'cleaning', 'description' => 'Deep cleaning of lobby and stairwells',
            'expense_date' => '2026-09-28', 'net' => '95.000', 'charge_to' => 'company']);
        $expense->handle($this->finance, ['building_id' => $amwaj->id, 'unit_id' => $am[5]->id, 'category' => 'maintenance', 'description' => 'Repaint unit 302 before re-letting',
            'expense_date' => '2026-09-20', 'net' => '150.000', 'charge_to' => 'owner']);
        $expense->handle($this->finance, ['building_id' => $seef->id, 'unit_id' => $sr[0]->id, 'category' => 'maintenance', 'description' => 'Replace broken kitchen cabinet door (tenant damage)',
            'expense_date' => '2026-10-01', 'net' => '35.000', 'charge_to' => 'tenant']);

        // 10. Left pending for Management: one agreement and one credit note.
        $this->at('pending agreement');
        $this->agreement($this->pm, $c['omar'], '2026-10-15', '2027-10-14', 'monthly', [
            ['unit_id' => $sr[2]->id, 'deposit_amount' => '380.000', 'charges' => [$rent($sr[2], '380.000'), $extra($sr[2], 'parking', '15.000', 'Covered parking bay')]],
        ], approve: false);

        $this->at('pending credit note');
        $target = $rajesh->invoices()->where('type', InvoiceType::Rent)->where('status', InvoiceStatus::Issued)->where('due_date', '2026-09-01')->firstOrFail();
        $line = $target->lines()->where('charge_type', 'rent')->firstOrFail();
        $cn = app(SaveCreditNote::class)->handle($this->finance, $target, null, [
            'reason' => 'Air conditioning out of order 6 to 10 September; 50 BHD goodwill credit agreed with the tenant.',
            'lines' => [['credited_line_id' => $line->id, 'amount' => '50.000']],
        ]);
        app(SubmitCreditNote::class)->handle($this->finance, $cn);

        // 11. Amwaj owner statement for September, left as a draft.
        $this->at('owner statement');
        $goLive = CompanySetting::current()->go_live_at;
        if ($goLive !== null && $goLive->isFuture()) {
            $this->command->warn("Go-live is {$goLive->toDateString()}: September 2026 is before go-live, so no owner statement was drafted.");
        } else {
            $result = app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-09-01', 'Asia/Bahrain'));
            if ($result['failed'] > 0) {
                throw new RuntimeException("{$result['failed']} owner statement(s) failed to draft; see the log.");
            }
        }
    }

    private function at(string $step): void
    {
        $this->step = $step;
        $this->command->line("  {$step}…");
    }

    /** @param  list<int>  $buildingIds */
    private function user(string $name, string $email, RoleName $role, array $buildingIds = []): User
    {
        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        $user = app(CreateUser::class)->handle($this->admin, $name, $email, [$role->value], $buildingIds);
        $password = Str::password(16, symbols: false);
        $user->forceFill(['password' => $password])->save(); // hashed cast; Admin never sets passwords in the app

        Storage::disk('local')->append('local-install-credentials.txt', "{$email}  {$password}  ({$role->label()}, demo)");

        return $user;
    }

    /** @param  list<string>  $facilities */
    private function building(string $name, string $code, string $type, string $location, string $address, int $floors, string $parking, array $facilities): Building
    {
        return app(SaveBuilding::class)->handle($this->pm, null, [
            'name' => $name, 'code' => $code, 'type' => $type, 'location' => $location, 'address' => $address,
            'floors_count' => $floors, 'parking' => $parking, 'property_manager_user_id' => $this->pm->id,
            'facility_ids' => Facility::query()->where('active', true)->whereIn('name', $facilities)->pluck('id')->all(),
        ]);
    }

    /**
     * @param  list<array<int, mixed>>  $rows  floor, type, bedrooms, bathrooms, area, furnishing, rent, deposit, blocked reason?, use?
     * @return list<Unit>
     */
    private function units(Building $building, array $rows): array
    {
        return array_map(fn (array $r) => app(SaveUnit::class)->handle($this->pm, null, [
            'building_id' => $building->id, 'floor' => $r[0], 'use' => $r[9] ?? 'residential', 'type' => $r[1],
            'bedrooms' => $r[2], 'bathrooms' => $r[3], 'area_sqm' => $r[4], 'furnishing' => $r[5],
            'list_rent' => $r[6], 'list_deposit' => $r[7], 'blocked' => isset($r[8]), 'blocked_reason' => $r[8] ?? null,
        ]), $rows);
    }

    private function bank(string $name): string
    {
        // A trimmed-down Banks list (tests) falls back to its first active bank.
        return Bank::canonical($name) ?? Bank::activeNames()[0] ?? throw new RuntimeException('The Banks list is empty.');
    }

    /**
     * @param  list<Unit>  $units
     * @param  array<string, mixed>  $terms
     */
    private function ownerContract(Owner $owner, Building $building, array $units, array $terms): OwnerContract
    {
        $contract = app(SaveOwnerContract::class)->handle($this->finance, null, [
            'owner_id' => $owner->id, 'building_id' => $building->id, 'unit_ids' => array_map(fn (Unit $u) => $u->id, $units), ...$terms,
        ]);
        $this->approve(app(SubmitOwnerContract::class)->handle($this->finance, $contract));

        return $contract->refresh();
    }

    /** @param  list<array<string, mixed>>  $units */
    private function agreement(User $creator, Customer $customer, string $start, string $end, string $frequency, array $units, bool $approve = true): Agreement
    {
        $agreement = app(SaveAgreement::class)->handle($creator, null, [
            'customer_id' => $customer->id, 'start_date' => $start, 'end_date' => $end, 'frequency' => $frequency, 'units' => $units,
        ]);
        $approval = app(SubmitAgreement::class)->handle($creator, $agreement);
        if ($approve) {
            $this->approve($approval);
        }

        return $agreement->refresh();
    }

    private function approve(Approval $approval): void
    {
        app(DecideApproval::class)->handle($this->manager, $approval, true, 'Checked against the file. Approved.');
    }

    /** One bank transfer per issued invoice due on or before $upTo, received on its due date. */
    private function payEachUpTo(Agreement $agreement, string $upTo): void
    {
        $invoices = $agreement->invoices()->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote)
            ->where('due_date', '<=', $upTo)->where('balance', '>', 0)->orderBy('due_date')->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            app(RecordPayment::class)->handle($this->finance, $agreement->customer, [
                'received_on' => $invoice->due_date->toDateString(), 'method' => 'bank_transfer', 'amount' => $invoice->balance,
                'reference' => 'TRF '.$invoice->number, 'allocations' => [['invoice_id' => $invoice->id, 'amount' => $invoice->balance]],
            ]);
        }
    }

    private function pay(Customer $customer, string $on, string $amount, string $method, string $notes): void
    {
        app(RecordPayment::class)->handle($this->finance, $customer, ['received_on' => $on, 'method' => $method, 'amount' => $amount, 'notes' => $notes]);
    }

    private function openFils(Customer $customer): int
    {
        return Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Issued)
            ->where('type', '!=', InvoiceType::CreditNote)->get()->sum(fn (Invoice $i) => Fils::fromDecimal($i->balance));
    }
}
