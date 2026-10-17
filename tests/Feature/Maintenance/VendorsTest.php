<?php

use App\Actions\Maintenance\SaveVendor;
use App\Enums\ExpenseCategory;
use App\Enums\RoleName as R;
use App\Enums\Trade;
use App\Livewire\Maintenance\VendorForm;
use App\Livewire\Maintenance\Vendors;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Vendor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->pm = matrixUser(R::PropertyManager, $this->building);
});

test('maintenance.view goes to Admin, Management, Property manager, Leasing and Vendor Support; manage to Admin, Property manager and Vendor Support', function () {
    $holders = fn (string $permission) => array_values(array_filter(R::cases(), fn (R $r) => Role::findByName($r->value)->hasPermissionTo($permission)));

    expect($holders('maintenance.view'))->toBe([R::Admin, R::Management, R::PropertyManager, R::Leasing, R::VendorSupport])
        ->and($holders('maintenance.manage'))->toBe([R::Admin, R::PropertyManager, R::VendorSupport]);
});

test('cleaning work is a cleaning expense; every other trade is maintenance', function () {
    foreach (Trade::cases() as $trade) {
        expect($trade->expenseCategory())->toBe($trade === Trade::Cleaning ? ExpenseCategory::Cleaning : ExpenseCategory::Maintenance);
    }
});

test('a property manager adds and edits a vendor; trades are kept in the fixed order', function () {
    Livewire::actingAs($this->pm)->test(VendorForm::class)
        ->set('form.name', 'Gulf Cool W.L.L.')->set('form.type', 'company')->set('form.mobile', '+973 3600 0000')
        ->set('form.trades', ['general', 'ac'])->set('form.email', 'jobs@gulfcool.test')
        ->call('save')->assertHasNoErrors();

    $vendor = Vendor::query()->sole();
    expect($vendor->trades)->toBe(['ac', 'general'])
        ->and($vendor->active)->toBeTrue()
        ->and($vendor->tradeLabels())->toBe('Air conditioning, General')
        ->and($vendor->handles(Trade::Plumbing))->toBeTrue(); // general takes any category

    Livewire::actingAs($this->pm)->test(VendorForm::class, ['vendor' => $vendor])
        ->assertSet('form.name', 'Gulf Cool W.L.L.')
        ->set('form.active', false)->call('save')->assertHasNoErrors();
    expect($vendor->fresh()->active)->toBeFalse();
});

test('vendor input is validated; tampering is a message, never a 500', function (string $key, mixed $value, string $error) {
    Livewire::actingAs($this->pm)->test(VendorForm::class)
        ->set('form.name', 'Ali Plumbing')->set('form.mobile', '33000000')->set('form.trades', ['plumbing'])
        ->set($key, $value)->assertOk()
        ->call('save')->assertOk()->assertHasErrors($error);

    expect(Vendor::count())->toBe(0);
})->with([
    'no name' => ['form.name', '', 'form.name'],
    'name too long' => ['form.name', str_repeat('x', 151), 'form.name'],
    'name as an array' => ['form.name', ['x'], 'form.name'],
    'unknown type' => ['form.type', 'supplier', 'form.type'],
    'no trades' => ['form.trades', [], 'form.trades'],
    'unknown trade' => ['form.trades', ['roofing'], 'form.trades'],
    'repeated trade' => ['form.trades', ['ac', 'ac'], 'form.trades'],
    'trades as text' => ['form.trades', 'ac', 'form.trades'],
    'a trade as an array' => ['form.trades', [['ac']], 'form.trades'],
    'no mobile' => ['form.mobile', '', 'form.mobile'],
    'bad email' => ['form.email', 'not-an-email', 'form.email'],
    'active not a boolean' => ['form.active', 'maybe', 'form.active'],
    'the whole form as text' => ['form', 'x', 'form.name'],
]);

test('the vendors list filters by trade, type and active, and searches name or mobile', function () {
    maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing'], 'type' => 'individual', 'mobile' => '33111111']);
    maintenanceVendor(['name' => 'Gulf Cool', 'trades' => ['ac']]);
    maintenanceVendor(['name' => 'Old Painter', 'trades' => ['painting'], 'active' => false]);
    maintenanceVendor(['name' => 'Our Handyman', 'trades' => ['general'], 'type' => 'own_staff']);

    $page = Livewire::actingAs(matrixUser(R::Leasing, $this->building))->test(Vendors::class)
        ->assertSee('Ali Plumbing')->assertSee('Gulf Cool')->assertSee('Our Handyman')->assertDontSee('Old Painter')->assertDontSee('New vendor');
    $page->set('trade', 'ac')->assertSee('Gulf Cool')->assertDontSee('Ali Plumbing');
    $page->set('trade', '')->set('type', 'own_staff')->assertSee('Our Handyman')->assertDontSee('Gulf Cool');
    $page->set('type', '')->set('active', '0')->assertSee('Old Painter')->assertDontSee('Gulf Cool');
    $page->set('active', '')->set('search', '33111')->assertSee('Ali Plumbing')->assertDontSee('Gulf Cool');
    $page->set('trade', ['x'])->set('type', ['x'])->set('active', ['x'])->set('search', ['x'])->assertOk()->assertSee('Old Painter'); // tampered: no filter
});

test('Leasing sees vendors but cannot add or change them; Finance has no maintenance pages', function () {
    $leasing = matrixUser(R::Leasing, $this->building);
    $vendor = maintenanceVendor();

    $this->actingAs($leasing)->get(route('maintenance.vendors.index'))->assertOk();
    $this->actingAs($leasing)->get(route('maintenance.vendors.edit', $vendor))->assertOk();
    $this->actingAs($leasing)->get(route('maintenance.vendors.create'))->assertForbidden();
    Livewire::actingAs($leasing)->test(VendorForm::class, ['vendor' => $vendor])->set('form.name', 'Renamed')->call('save')->assertForbidden();
    expect(fn () => app(SaveVendor::class)->handle($leasing, $vendor, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and($vendor->fresh()->name)->toBe('Fix-It Co');

    $finance = matrixUser(R::Finance, $this->building);
    $this->actingAs($finance)->get(route('maintenance.vendors.index'))->assertForbidden();
    $this->actingAs($this->pm)->get(route('dashboard'))->assertSee(route('maintenance.vendors.index'));
    $this->actingAs($finance)->get(route('dashboard'))->assertDontSee(route('maintenance.vendors.index'));
});

test('Management sees vendors read-only', function () {
    $management = matrixUser(R::Management, $this->building);
    $vendor = maintenanceVendor();

    $this->actingAs($management)->get(route('maintenance.vendors.index'))->assertOk();
    $this->actingAs($management)->get(route('maintenance.vendors.create'))->assertForbidden();
    Livewire::actingAs($management)->test(VendorForm::class, ['vendor' => $vendor])->set('form.name', 'Renamed')->call('save')->assertForbidden();
    expect($vendor->fresh()->name)->toBe('Fix-It Co');
});

test('vendor search treats % and _ literally and stored text is trimmed', function () {
    maintenanceVendor(['name' => 'Ali Plumbing']);
    maintenanceVendor(['name' => '100%_Fix', 'mobile' => '33222222']);

    Livewire::actingAs($this->pm)->test(Vendors::class)->set('search', '%')->assertSee('100%_Fix')->assertDontSee('Ali Plumbing');

    $vendor = app(SaveVendor::class)->handle($this->pm, null, ['name' => '  Spaced Co ', 'type' => 'company', 'trades' => ['ac'], 'mobile' => ' 33000001 ', 'active' => true]);
    expect($vendor->name)->toBe('Spaced Co')->and($vendor->mobile)->toBe('33000001');
});
