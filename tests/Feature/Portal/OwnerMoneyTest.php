<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Expense;
use App\Models\OwnerStatement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->admin = matrixUser(R::Admin, Building::factory()->create());
    $this->a = portalOwnerFixture('Ali Owner', '101', $this->admin);
    $this->b = portalOwnerFixture('Badr Owner', '202', $this->admin);
    $this->draft = (new OwnerStatement)->forceFill(['owner_contract_id' => $this->a['managed']->id, 'period_start' => '2026-10-01',
        'period_end' => '2026-10-31', 'cutoff_at' => '2026-10-31 23:59:59', 'status' => 'draft', 'created_by' => $this->admin->id]);
    $this->draft->save();
    $this->actingAs($this->a['account'], 'tenant');
});

test('statements list only finalised statements with opening, income, charges, fees, paid to you and closing', function () {
    $this->get(route('portal.owner.statements'))->assertOk()
        ->assertSeeInOrder(['OS-101 · Ali Owner Tower', '01/09/2026 – 30/09/2026', 'Opening balance', '0.000 BHD', 'Income', '500.000 BHD',
            'Charges', '50.000 BHD', 'Fees', '0.000 BHD', 'Paid to you', '0.000 BHD', 'Closing balance', '450.000 BHD', 'Statement PDF'])
        ->assertDontSee('Draft statement')->assertDontSee(route('portal.owner.statements.pdf', $this->draft))->assertDontSee('OS-202');
});

test('the statement PDF is the one staff download, for their finalised statements only, audited', function () {
    $response = $this->get(route('portal.owner.statements.pdf', $this->a['statement']))->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');

    $audit = Activity::query()->where('event', 'owner_statement.exported')->sole();
    expect($audit->causer?->is($this->a['account']))->toBeTrue()->and($audit->subject?->is($this->a['statement']))->toBeTrue();

    foreach ([route('portal.owner.statements.pdf', $this->b['statement']), route('portal.owner.statements.pdf', $this->draft),
        '/portal/owner/statements/abc/pdf', '/portal/owner/statements/99999999999999999999/pdf'] as $url) {
        $this->get($url)->assertNotFound();
    }

    $finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance);
    $this->actingAs($finance, 'web')->get(route('owner-statements.pdf', $this->a['statement']))->assertOk(); // the staff download still works
});

test('payments list paid payouts with method and reference, the IBAN masked, and the leased rent schedule', function () {
    DB::table('disbursements')->insert(['payee_type' => 'owner', 'payee_id' => $this->a['owner']->id, 'purpose' => 'other', 'amount' => '99.000',
        'method' => 'cash', 'reference' => 'WAIT-101', 'status' => 'pending_approval', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->get(route('portal.owner.payments'))->assertOk()
        ->assertSee('Bank transfers are paid to NBB, IBAN ••••0101.')
        ->assertSeeInOrder(['DSB-101-1', '05/10/2026', 'Bank Transfer · Reference TRF-101', '450.000 BHD', 'DSB-101-2', '02/10/2026', '300.000 BHD'])
        ->assertSeeInOrder(['Rent schedule', 'Due 01/10/2026 · Ali Owner Lease', '300.000 BHD', 'Paid', 'Due 01/11/2026 · Ali Owner Lease', '300.000 BHD', 'Not paid yet'])
        ->assertDontSee('WAIT-101')->assertDontSee('BH29NBOB')->assertDontSee('DSB-202');
});

test('expenses charged to the owner on their contracts, reversed ones marked, without internal notes', function () {
    Expense::factory()->create(['building_id' => $this->a['tower']->id, 'description' => 'Lobby lights 101', 'charge_to' => 'company', 'recorded_by' => $this->admin->id]);

    $this->get(route('portal.owner.expenses'))->assertOk()
        ->assertSeeInOrder(['12/09/2026 · Ali Owner Tower', 'Reversed', 'Cleaning · Paint touch-up 101', '20.000 BHD',
            '10/09/2026 · Ali Owner Tower · 101', 'Maintenance · Leak repair 101', '50.000 BHD'])
        ->assertDontSee('Lobby lights 101')->assertDontSee('Approved by phone 101')->assertDontSee('Wrong building 101')->assertDontSee('Leak repair 202');
});

test('the money pages are in Arabic with amounts to 3 decimals for an Arabic account', function () {
    $this->a['account']->forceFill(['locale' => 'ar'])->save();

    $this->get(route('portal.owner.statements'))->assertOk()->assertSee('dir="rtl"', false)
        ->assertSee('الرصيد الختامي')->assertSee('450.000 د.ب')->assertSee('01/09/2026');
});
