<?php

use App\Actions\Banks\EnsureDefaultBanks;
use App\Actions\Cheques\RecordCheques;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Owners\SaveOwner;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Admin\Banks;
use App\Livewire\Cheques\Entry;
use App\Livewire\Owners\Form;
use App\Models\Bank;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->nbb = Bank::create(['name' => 'National Bank of Bahrain (NBB)']);
    $this->old = Bank::create(['name' => 'Old Bank', 'active' => false]);
});

function bankOwner(array $overrides = []): array
{
    return [
        'type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '880101234',
        'iban' => 'BH67BMAG00001299123456', 'bank_name' => 'National Bank of Bahrain (NBB)', 'account_name' => 'Ali Hassan', ...$overrides,
    ];
}

test('an admin adds, renames and switches off banks; names are unique; others are forbidden', function () {
    $page = Livewire::actingAs($this->admin)->test(Banks::class)
        ->set('name', 'Khaleeji Bank')->call('add')->assertHasNoErrors()
        ->set('name', 'khaleeji bank')->call('add')->assertHasErrors('name');

    $bank = Bank::where('name', 'Khaleeji Bank')->sole();
    $page->call('rename', $bank->id, 'Khaleeji')->call('toggle', $bank->id);
    expect($bank->fresh()->name)->toBe('Khaleeji')->and($bank->fresh()->active)->toBeFalse();

    $page->call('rename', $bank->id, 'old bank')->assertHasErrors('name');

    $this->actingAs($this->finance)->get(route('admin.banks'))->assertForbidden();
});

test('a switched-off bank is not offered for new records', function () {
    $agreement = activeAgreement([], []);
    Livewire::actingAs($this->finance)->withQueryParams(['agreement' => $agreement->id])->test(Entry::class)
        ->assertSee('National Bank of Bahrain (NBB)')->assertDontSee('Old Bank');
});

test("an owner's bank must come from the list; an unchanged old bank survives an edit", function () {
    expect(fn () => app(SaveOwner::class)->handle($this->admin, null, bankOwner(['bank_name' => 'Bank of Mars'])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveOwner::class)->handle($this->admin, null, bankOwner(['bank_name' => 'Old Bank'])))->toThrow(ValidationException::class);

    $owner = app(SaveOwner::class)->handle($this->admin, null, bankOwner());
    expect($owner->bank_name)->toBe('National Bank of Bahrain (NBB)');

    $owner->forceFill(['bank_name' => 'Legacy Bank'])->save();
    Livewire::actingAs($this->admin)->test(Form::class, ['owner' => $owner])->assertSee('Legacy Bank')
        ->set('form.name_en', 'Ali H.')->call('save')->assertHasNoErrors();
    expect($owner->fresh()->bank_name)->toBe('Legacy Bank');
});

test("a received cheque's bank must come from the list", function () {
    $customer = Customer::factory()->create();
    $row = ['cheque_no' => '1', 'cheque_date' => '2026-10-05', 'amount' => '5'];

    expect(fn () => app(RecordCheques::class)->handle($this->finance, $customer, null, [[...$row, 'bank_name' => 'Old Bank']]))->toThrow(ValidationException::class);
    expect(app(RecordCheques::class)->handle($this->finance, $customer, null, [[...$row, 'bank_name' => 'National Bank of Bahrain (NBB)']])->sole()->bank_name)
        ->toBe('National Bank of Bahrain (NBB)');
});

test("an issued cheque's bank must come from the list", function () {
    Storage::fake('local');
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $payment = app(RecordPayment::class)->handle($this->finance, Customer::factory()->create(), ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '90.000']);
    $data = ['purpose' => 'credit_refund', 'payment_id' => $payment->id, 'amount' => '90.000', 'method' => 'cheque', 'paid_on' => '2026-10-05', 'cheque_no' => '7', 'cheque_date' => '2026-10-07'];

    expect(fn () => app(RecordDisbursement::class)->handle($this->finance, [...$data, 'bank_name' => 'Bank of Mars']))->toThrow(ValidationException::class);
    app(RecordDisbursement::class)->handle($this->finance, [...$data, 'bank_name' => 'National Bank of Bahrain (NBB)']);
    expect(Cheque::sole()->bank_name)->toBe('National Bank of Bahrain (NBB)');
});

test('a new install starts with the banks operating in Bahrain', function () {
    app(EnsureDefaultBanks::class)();
    $count = Bank::count();
    app(EnsureDefaultBanks::class)(); // safe to run twice

    expect(Bank::count())->toBe($count)
        ->and(Bank::where('name', 'Bank of Bahrain and Kuwait (BBK)')->exists())->toBeTrue()
        ->and(Bank::where('name', 'Mashreq (Bahrain)')->exists())->toBeTrue();
});
