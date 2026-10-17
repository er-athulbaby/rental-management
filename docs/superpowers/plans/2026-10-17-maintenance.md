# Maintenance Requests and Work Orders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Log maintenance problems, send the work to vendors or own staff as work orders, follow them to completion, and record the cost as an expense through the existing `RecordExpense` rules, with tenant emails, lists, a dashboard card and two reports.

**Architecture:** Three new records (`vendors`, `maintenance_requests`, `work_orders`) plus a timeline (`maintenance_events`). They are not money records and have no triggers. Every write goes through a small action in `app/Actions/Maintenance`. `CompleteWorkOrder` calls `App\Actions\Expenses\RecordExpense` inside its own `DB::transaction`, so a refused expense leaves the work order open. The link is stored on `work_orders.expense_id`, and the locked `expenses` table is not changed. Screens are Livewire components in `app/Livewire/Maintenance`. The tenant emails reuse the reminder email's layout and safety rules. The two reports use the `ReportPage` trait and join the report-package registry.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, spatie/laravel-permission, spatie/laravel-activitylog, spatie/simple-excel, `App\Support\Fils`, `App\Billing\Tax`, `App\Actions\NextDocumentNumber`, `<x-building-filter>`, `<x-customer-picker>`, `<x-date-input>`, `<x-status-badge>`, `Documents\Panel`.

**Spec:** `docs/superpowers/specs/2026-10-17-maintenance-design.md`. Read it first; this plan argues from it.

## Global Constraints

- Money is BHD in fils with 3 decimals. In PHP use `App\Support\Fils` (`fromDecimal`, `toDecimal`, `grouped`, `rule`) and never floats. Money columns are `DECIMAL(12,3)`.
- Timezone is Asia/Bahrain. Dates display as dd/mm/yyyy (`format('d/m/Y')`). "Today" is `now('Asia/Bahrain')`.
- "Requests and work orders read and link money records but only create them through `RecordExpense`" (spec §7). Actions are the only write path for money. Expenses are created only through `RecordExpense`. No triggers are added. `IntegrityCheck::EXPECTED_TRIGGERS` stays 61.
- All UI strings go through `__()`. Write "tenant", never "customer", in UI copy. The building filter is `<x-building-filter>`.
- "Tampered Livewire input gives a validation message, never a 500; public Livewire methods check permission and building scope" (spec §7). Public non-static Livewire methods can be called from the browser. Never expose one that returns data, or takes an id, without authorization and building-scope checks. Internals are `private` or `static`. Livewire form and filter properties are untyped `mixed` and checked before use.
- Permissions (spec §6): `maintenance.view` goes to Admin, Management, Property manager and Leasing. `maintenance.manage` goes to Admin and Property manager. "Recording the cost also needs `expenses.manage`." Vendor Support keeps "everything except decisions and finance management", so it gets both. "Building scope applies to requests, work orders and reports; vendors are company-wide."
- Numbers: `MR-YYYY-NNNNNN` (`NumberSequenceKey::MaintenanceRequest`) and `WO-YYYY-NNNNNN` (`NumberSequenceKey::WorkOrder`), from `NextDocumentNumber` inside a transaction.
- Trades, which are also the request categories, are a fixed list: `plumbing`, `electrical`, `ac`, `carpentry`, `painting`, `cleaning`, `pest_control`, `general`. Trade maps to expense category as "cleaning → `cleaning`, all others → `maintenance`".
- Tests run serially (`php artisan test`, never `--parallel`) against `rms_test`. Never run `migrate:fresh` on the dev DB `rms`. Dev migrations use `php artisan migrate --database=migrator --force`. Seeded lookup tables go into `TestCase::$exceptTables`; this feature seeds none.
- PHP binary: `/c/Users/ababy/.config/herd/bin/php85/php.exe` (written `php` below). Gates before each commit: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, and `npm run build` when a view changed.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file. Alpine attributes on Flux components need `:x-show`-style binding. Put plain `x-data` on a wrapping `<div>`, not on a Flux component.
- Jobs and queued mail use `tries = 1`. The queue's `retry_after` is 330.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Rulings made while planning

1. **Permissions on an installed system.** `RolesAndPermissionsSeeder` runs only from `rms:install` and tests, never on deploy. So the vendors migration creates the two permissions and grants them, but only when the `roles` table already has rows. That is the case on an installed system such as the dev DB `rms`. A fresh install, including every test database, gets them from the seeder. The grant is additive (`givePermissionTo`), so it keeps an Admin's audited role edits. `tests/Feature/Auth/RolesAndPermissionsTest.php` moves from 25 permissions to 27.
2. **Number sequences.** Two new `NumberSequenceKey` cases mean `EnsureNumberSequences` creates 10 rows a year. `EnsureNumberSequencesTest` (8 → 10) and `InstallCommandTest` (16 → 20) change to match. An installed system needs the rows for the current year once: run `php artisan rms:number-sequences --year=2026` after migrating (Task 2, Step 9). The migration does not insert them, so tests stay independent of the real clock.
3. **`completed_on` instead of the spec's `completed_at`.** The column is a DATE: the day the work was done. Staff often enter it later. It must be on or before today and not before the day the request was logged. It is the expense date ("expense date = completion date", spec §4 step 4). `updated_at` records when it was entered.
4. **Closed requests are final.** A resolved or cancelled request takes no new work orders and no status changes, and is never reopened. Notes are still allowed. Manual resolve is refused while any work order is assigned or in progress. So a closed request never has open work. When every work order is cancelled and none was completed, the request stays in progress. Staff then add another work order, or resolve or cancel it by hand. Because resolution happens once, the "request completed" email is sent once.
5. **One transaction for completion.** `CompleteWorkOrder` validates its own fields, then opens `DB::transaction`. Inside it locks the request and the work order, re-checks the status, and calls `RecordExpense::handle()`. `RecordExpense` opens its own `DB::transaction`, which becomes a savepoint. It refuses with a `ValidationException` (approval limit, no managed contract, no agreement on the unit, amount) or an `AuthorizationException` (no `expenses.manage`; tenant charge without `invoices.manage`; building out of scope). Either one propagates and rolls everything back, including a tenant invoice and its number. `CompleteWorkOrder` renames `RecordExpense`'s error keys to its own (`net` → `actual_net`, `tax_amount` → `actual_tax`, `expense_date` → `completed_on`, `unit_id`/`building_id` → `charge_to`). `RecordExpense` stores the approval file on disk before the insert, as it does on the Expenses screen. A refused save can therefore leave an unreferenced file. That is existing behaviour and is not changed.
6. **The prefilled expense** (spec §4 step 4). Building, unit, date, net and VAT come from the request and the completion and cannot be changed in the form. Category (trade → category) and description (`WO-… · vendor · request summary`, cut to 500 characters) are prefilled and editable. The payer is chosen as on the Expenses screen: company, owner or tenant. `RecordExpense` finds the owner contract and the tenant itself. The Tenant choice is shown only to holders of `invoices.manage`, as on the Expenses screen. A work order is completed with no cost (warranty, own staff) when net + VAT is 0. No expense is recorded then, and `actual_net` and `actual_tax` stay NULL.
7. **Spec conflict: who can record a cost.** Completing needs `maintenance.manage`. A cost also needs `expenses.manage`. With the default roles only Property manager has both. Admin and Vendor Support can complete work orders only with no cost. Charging the tenant also needs `invoices.manage`, and no role that has `maintenance.manage` has it by default. A tenant charge from a work order therefore needs an Admin role edit. The plan implements the spec as written. The completion form says why a cost is refused. The tests use a property manager given `invoices.manage` directly for the tenant case.
8. **Audit.** `Vendor`, `MaintenanceRequest`, `WorkOrder` and `MaintenanceEvent` use `LogsActivity` (`logAll`, dirty only). Every create, status change and timeline row is therefore in the audit log, which satisfies "Also audited" (spec §3.4). The report exports are audited `report.exported` by the `ReportPage` trait.
9. **Tenant emails** (`App\Mail\MaintenanceUpdate`, kinds `received` and `completed`). The rules match the reminders. The address comes from the tenant record only. Nothing is sent when the tenant has `no_reminders`, when there is no email or tenant, or when `EmailChannel::ready()` is false (`MAIL_MAILER` is `log` or `array`). The mail is queued (`ShouldQueue`, `tries = 1`) with `afterCommit()`, so a rolled-back request never emails. These emails are not logged in `reminder_sends`, whose CHECKs allow only reminder types. "Received" is sent when the request is logged. "Completed" is sent on the one transition to resolved, whether automatic or manual. Cancelling sends nothing. The bilingual page is moved into an anonymous component, `<x-mail.bilingual>`, with English and Arabic slots and the company footer. `mail/tenant-reminder.blade.php` is changed to use it, so the layout is shared. `tests/Feature/Reminders/EmailTest.php` guards that change.
10. **Photos.** A new `DocumentCategory::Maintenance` (`maintenance`, label "Maintenance photo") and a widened `documents_category_chk`. `Documents\Panel::ALLOWED` gains `MaintenanceRequest` and `WorkOrder`, and the panel defaults to the maintenance category on them. Before-photos go on the request. After-photos go on each work order, through the same panel under the work order on the request page. `DocumentsTest`'s label list gains "Maintenance photo".
11. **Dashboard.** Urgent requests get their own "Urgent maintenance" card: the count and the five oldest. It is not a tile, because `DashboardTilesTest` asserts the exact tile list.
12. **The work order sheet** is an HTML page printed from the browser (`maintenance.work-orders.sheet`), not a stored PDF. The sheet and the "Copy for WhatsApp" text are both built from `WorkOrder::sheetLines()`. That method reads no amount column, so "no amounts" holds by construction.
13. **Overdue** means `scheduled_on` is before today (Asia/Bahrain) and the work order is assigned or in progress (`WorkOrder::isOverdue()`).
14. **Lists.** "Urgent first, then oldest" is the scope `urgentFirst()`: `priority = 'urgent' DESC`, then `id`. The lists and the Open maintenance report share it. The vendor filter means requests with a work order for that vendor. Search covers the MR number, an exact unit code, and the tenant through `Customer::search` (name or mobile). Filters are untyped `mixed`. `building` is a `mixed` property rendered by `<x-building-filter>`, as on the Reminders log. The `FiltersByBuilding` trait's `?int` would throw a TypeError on a tampered value.
15. **Vendors are company-wide.** A vendor's work-order count and total cost count only work orders in buildings the viewer can see. Cost is the total of the linked expenses that are still recorded.
16. **Reports.** Both reports join `ReadyMade` (14 → 16), keyed `open_maintenance` and `maintenance_cost`. Both need `reports.operational` and `maintenance.view`, as the cheque report needs its extra permission. Maintenance cost uses recorded expenses linked to work orders, filtered by expense date. Company and tenant charges use Building profitability's rule (net when VAT-registered, else total), so that column agrees with Building profitability. Owner charges appear in their own column at their total, as owner statements charge them. Reversed expenses are left out. "Category" means the request's category (trade). Open maintenance lists open and in-progress requests. Age is in whole days since the day the request was logged.
17. **No edit screen for a request** (spec §5 lists none). A wrong detail is fixed with a note, or by cancelling and logging again.
18. **Ids from the browser.** `startWorkOrder`, `openCancel`, `openCompletion` and `pickTenant` look the id up inside the current request (or the tenants the user can see). They give a validation message when it is not found. Every action re-checks the policy.

## File Structure

```
app/Enums/PermissionName.php                          MOD  maintenance.view / .manage (Task 1)
database/seeders/RolesAndPermissionsSeeder.php        MOD  grants (Task 1)
app/Enums/Trade.php, app/Enums/VendorType.php         NEW  (Task 1)
database/migrations/2026_12_20_000100_create_vendors_table.php        NEW  vendors + grants on installed systems (Task 1)
app/Models/Vendor.php                                 NEW  (Task 1); workOrders() (Task 2)
app/Policies/VendorPolicy.php                         NEW  (Task 1)
app/Actions/Maintenance/SaveVendor.php                NEW  (Task 1)
app/Livewire/Maintenance/Vendors.php, VendorForm.php  NEW  (Task 1); totals and work orders (Task 7)
resources/views/livewire/maintenance/vendors.blade.php, vendor-form.blade.php  NEW (Task 1; Task 7)
routes/property.php                                   MOD  maintenance routes (Tasks 1, 5, 6, 7, 10)
resources/views/layouts/app/sidebar.blade.php         MOD  Maintenance group (Task 1; Task 7)
app/Enums/MaintenanceStatus.php, WorkOrderStatus.php, MaintenancePriority.php, MaintenanceChannel.php  NEW (Task 2)
app/Enums/NumberSequenceKey.php, app/Enums/DocumentCategory.php       MOD  (Task 2)
database/migrations/2026_12_20_000200_create_maintenance_tables.php   NEW  requests, work orders, events, documents CHECK (Task 2)
app/Models/MaintenanceRequest.php, WorkOrder.php, MaintenanceEvent.php NEW (Task 2); sheetLines() (Task 6)
app/Policies/MaintenanceRequestPolicy.php, WorkOrderPolicy.php        NEW  (Task 2)
app/Actions/Maintenance/LogMaintenanceRequest.php, AddWorkOrder.php   NEW  (Task 2); email (Task 8)
app/Actions/Maintenance/ChangeWorkOrderStatus.php, ResolveMaintenanceRequest.php, CancelMaintenanceRequest.php, AddMaintenanceNote.php  NEW (Task 3); email (Task 8)
app/Actions/Maintenance/CompleteWorkOrder.php         NEW  (Task 4)
app/Livewire/Maintenance/RequestShow.php              NEW  (Task 5); completion (Task 6)
resources/views/livewire/maintenance/request-show.blade.php  NEW (Task 5; Task 6)
app/Livewire/Documents/Panel.php                      MOD  allow requests and work orders (Task 5)
resources/views/components/status-badge.blade.php     MOD  maintenance statuses (Task 5)
app/Http/Controllers/WorkOrderSheetController.php     NEW  (Task 6)
resources/views/maintenance/work-order-sheet.blade.php NEW (Task 6)
app/Livewire/Maintenance/Requests.php, WorkOrders.php, RequestForm.php NEW (Task 7)
resources/views/livewire/maintenance/requests.blade.php, work-orders.blade.php, request-form.blade.php NEW (Task 7)
resources/views/components/mail/bilingual.blade.php   NEW  shared email layout (Task 8)
resources/views/mail/tenant-reminder.blade.php, app/Mail/TenantReminder.php  MOD  use the layout (Task 8)
app/Mail/MaintenanceUpdate.php, resources/views/mail/maintenance-update.blade.php  NEW (Task 8)
app/Livewire/Dashboard.php, resources/views/livewire/dashboard.blade.php  MOD  urgent card (Task 9)
resources/views/components/maintenance-requests.blade.php NEW  unit/tenant section (Task 9)
app/Livewire/Units/Form.php, app/Livewire/Customers/Form.php and their views  MOD  (Task 9)
app/Livewire/Reports/OpenMaintenanceReport.php, MaintenanceCostReport.php  NEW (Task 10)
app/Livewire/Reports/Index.php, app/Reports/Packages/ReadyMade.php  MOD  (Task 10)
tests/Pest.php                                        MOD  maintenanceVendor() (Task 1), maintenanceRequest(), workOrderFor() (Task 2)
tests/Feature/Auth/RolesAndPermissionsTest.php        MOD  27 permissions (Task 1)
tests/Feature/Numbering/EnsureNumberSequencesTest.php, tests/Feature/Install/InstallCommandTest.php, tests/Feature/Documents/DocumentsTest.php  MOD (Task 2)
tests/Feature/ReportPackages/HeadlessTest.php         MOD  16 reports (Task 10)
tests/Feature/Maintenance/VendorsTest.php             NEW  (Task 1)
tests/Feature/Maintenance/RequestsTest.php            NEW  (Task 2)
tests/Feature/Maintenance/LifecycleTest.php           NEW  (Task 3)
tests/Feature/Maintenance/CompletionTest.php          NEW  (Task 4)
tests/Feature/Maintenance/RequestPageTest.php         NEW  (Task 5)
tests/Feature/Maintenance/CompletionScreenTest.php    NEW  (Task 6)
tests/Feature/Maintenance/ListsTest.php               NEW  (Task 7)
tests/Feature/Maintenance/EmailsTest.php              NEW  (Task 8)
tests/Feature/Maintenance/SectionsTest.php            NEW  (Task 9)
tests/Feature/Maintenance/ReportsTest.php             NEW  (Task 10)
```

---

### Task 1: Permissions and vendors

**Files:**
- Modify: `app/Enums/PermissionName.php`, `database/seeders/RolesAndPermissionsSeeder.php`
- Create: `app/Enums/Trade.php`, `app/Enums/VendorType.php`
- Create: `database/migrations/2026_12_20_000100_create_vendors_table.php`
- Create: `app/Models/Vendor.php`, `app/Policies/VendorPolicy.php`, `app/Actions/Maintenance/SaveVendor.php`
- Create: `app/Livewire/Maintenance/Vendors.php`, `app/Livewire/Maintenance/VendorForm.php`, `resources/views/livewire/maintenance/vendors.blade.php`, `resources/views/livewire/maintenance/vendor-form.blade.php`
- Modify: `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`, `tests/Pest.php`, `tests/Feature/Auth/RolesAndPermissionsTest.php`
- Test: `tests/Feature/Maintenance/VendorsTest.php`

**Interfaces:**
- Consumes: `PermissionName`, `RoleName`, `WithActor`, `matrixUser()`, `ExpenseCategory`.
- Produces:
  - `PermissionName::MaintenanceView` (`maintenance.view`), `PermissionName::MaintenanceManage` (`maintenance.manage`).
  - `enum Trade: string` (cases `Plumbing`…`General`), with `label(): string` and `expenseCategory(): ExpenseCategory`.
  - `enum VendorType: string` (`Company`, `Individual`, `OwnStaff`), with `label(): string`.
  - `Vendor` model. Columns: `name`, `type` VendorType, `trades` list<string>, `mobile`, `email`, `cr_number`, `vat_number`, `notes`, `active` bool. Soft deletes, `LogsActivity`.
    - `handles(Trade $category): bool`, `tradeLabels(): string`.
    - Scope `availableFor(Trade $category)`.
  - `VendorPolicy`: `viewAny`, `view` (maintenance.view); `create`, `update` (maintenance.manage).
  - `SaveVendor::handle(User $actor, ?Vendor $vendor, array $data): Vendor`. It throws `AuthorizationException` or `ValidationException` (keys `name`, `type`, `trades`, `trades.N`, `mobile`, `email`, `cr_number`, `vat_number`, `notes`, `active`).
  - Routes `maintenance.vendors.index`, `maintenance.vendors.create`, `maintenance.vendors.edit` (`{vendor}`).
  - Pest helper `maintenanceVendor(array $attributes = []): Vendor`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/VendorsTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/VendorsTest.php`
Expected: FAIL with `Class "App\Enums\Trade" not found` (or `There is no permission named maintenance.view`).

- [ ] **Step 3: Permissions.** In `app/Enums/PermissionName.php` change the class docblock to `/** The 25 permissions of spec §8.1 plus the two of the maintenance spec (2026-10-17 §6). The values are the names stored by spatie/laravel-permission. */` and add after `case ImportRun = 'import.run';`:

```php
    case MaintenanceView = 'maintenance.view';
    case MaintenanceManage = 'maintenance.manage';
```

In `database/seeders/RolesAndPermissionsSeeder.php::matrix()`, change four roles (Finance and Vendor Support stay as they are; Vendor Support's filter already includes both new permissions):

```php
            RoleName::Admin->value => [
                P::UsersManage, P::RolesManage, P::SettingsManage, P::TemplatesManage, P::AuditView,
                P::BuildingsView, P::BuildingsManage, P::BuildingsViewAll,
                P::OwnersView, P::OwnersManage, P::OwnersBankManage,
                P::CustomersView, P::CustomersManage,
                P::AgreementsView, P::AgreementsManage,
                P::FinanceView, // invoices, payments, payments out, expenses: view only
                P::MaintenanceView, P::MaintenanceManage,
                ...$reports,
            ],
            RoleName::Management->value => [
                ...$view, P::BuildingsViewAll, P::ApprovalsDecide, P::AuditView, P::MaintenanceView, ...$reports,
            ],
```

```php
            RoleName::PropertyManager->value => [
                P::BuildingsView, P::BuildingsManage, P::BuildingsViewAll, P::OwnersView,
                P::CustomersView, P::CustomersManage, P::AgreementsView, P::AgreementsManage,
                P::ExpensesManage, P::ReportsOperational, P::MaintenanceView, P::MaintenanceManage,
            ],
            RoleName::Leasing->value => [ // assigned buildings only (spec §8.2)
                P::BuildingsView, P::CustomersView, P::CustomersManage,
                P::AgreementsView, P::AgreementsManage, P::ReportsOperational, P::MaintenanceView,
            ],
```

In `tests/Feature/Auth/RolesAndPermissionsTest.php`, rename the first test to `'exactly the 27 permissions and 6 roles exist'`. Add `'maintenance.view', 'maintenance.manage',` after `'approvals.decide', 'import.run',` in its list, and change `expect(Permission::count())->toBe(25)` to `toBe(27)`.

- [ ] **Step 4: The enums.** Create `app/Enums/Trade.php`:

```php
<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.1: a vendor's trades, also a request's category. */
enum Trade: string
{
    case Plumbing = 'plumbing';
    case Electrical = 'electrical';
    case Ac = 'ac';
    case Carpentry = 'carpentry';
    case Painting = 'painting';
    case Cleaning = 'cleaning';
    case PestControl = 'pest_control';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Plumbing => __('Plumbing'),
            self::Electrical => __('Electrical'),
            self::Ac => __('Air conditioning'),
            self::Carpentry => __('Carpentry'),
            self::Painting => __('Painting'),
            self::Cleaning => __('Cleaning'),
            self::PestControl => __('Pest control'),
            self::General => __('General'),
        };
    }

    /** Spec §4 step 4: cleaning → cleaning, all others → maintenance. */
    public function expenseCategory(): ExpenseCategory
    {
        return $this === self::Cleaning ? ExpenseCategory::Cleaning : ExpenseCategory::Maintenance;
    }
}
```

Create `app/Enums/VendorType.php`:

```php
<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.1. Own staff are listed as vendors, so they need no app login. */
enum VendorType: string
{
    case Company = 'company';
    case Individual = 'individual';
    case OwnStaff = 'own_staff';

    public function label(): string
    {
        return match ($this) {
            self::Company => __('Company'),
            self::Individual => __('Individual'),
            self::OwnStaff => __('Own staff'),
        };
    }
}
```

- [ ] **Step 5: The migration** `database/migrations/2026_12_20_000100_create_vendors_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Spec 2026-10-17 maintenance §3.1 and §6: vendors, and the two maintenance permissions on an installed system (plan
 * ruling 1). A fresh install has no roles yet and gets the permissions from RolesAndPermissionsSeeder (rms:install).
 */
return new class extends Migration
{
    /** role => permissions, as RolesAndPermissionsSeeder::matrix() grants them. */
    private const array GRANTS = [
        'admin' => ['maintenance.view', 'maintenance.manage'],
        'management' => ['maintenance.view'],
        'property-manager' => ['maintenance.view', 'maintenance.manage'],
        'leasing' => ['maintenance.view'],
        'vendor-support' => ['maintenance.view', 'maintenance.manage'],
    ];

    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('type', 20);
            $table->json('trades');
            $table->string('mobile', 30);
            $table->string('email')->nullable();
            $table->string('cr_number', 30)->nullable();
            $table->string('vat_number', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_type_chk CHECK (type IN ('company', 'individual', 'own_staff'))");

        if (Role::query()->exists()) {
            foreach (['maintenance.view', 'maintenance.manage'] as $name) {
                Permission::findOrCreate($name, 'web');
            }
            foreach (self::GRANTS as $role => $permissions) {
                Role::query()->where('name', $role)->first()?->givePermissionTo($permissions); // additive: keeps Admin's role edits
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
        Permission::query()->whereIn('name', ['maintenance.view', 'maintenance.manage'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
```

- [ ] **Step 6: Model, policy, action.** Create `app/Models/Vendor.php`:

```php
<?php

namespace App\Models;

use App\Enums\Trade;
use App\Enums\VendorType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec 2026-10-17 maintenance §3.1: an outside vendor or the company's own staff. Company-wide: no building scope.
 * Written only by SaveVendor.
 *
 * @property int $id
 * @property string $name
 * @property VendorType $type
 * @property list<string> $trades Trade values, in Trade::cases() order
 * @property string $mobile
 * @property string|null $email
 * @property string|null $cr_number
 * @property string|null $vat_number
 * @property string|null $notes
 * @property bool $active
 */
class Vendor extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['name', 'type', 'trades', 'mobile', 'email', 'cr_number', 'vat_number', 'notes', 'active'];

    protected function casts(): array
    {
        return ['type' => VendorType::class, 'trades' => 'array', 'active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** Spec §4 step 2: the vendor's trades include the category, or general. */
    public function handles(Trade $category): bool
    {
        return in_array($category->value, $this->trades, true) || in_array(Trade::General->value, $this->trades, true);
    }

    /** "Air conditioning, General". */
    public function tradeLabels(): string
    {
        return implode(', ', array_map(fn (string $t) => Trade::tryFrom($t)?->label() ?? $t, $this->trades));
    }

    /**
     * Active vendors who can take a work order for $category.
     *
     * @param  Builder<Vendor>  $query
     */
    #[Scope]
    protected function availableFor(Builder $query, Trade $category): void
    {
        $query->where('active', true)->where(fn (Builder $q) => $q
            ->whereJsonContains('trades', $category->value)
            ->orWhereJsonContains('trades', Trade::General->value));
    }
}
```

Create `app/Policies/VendorPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;
use App\Models\Vendor;

/** Spec 2026-10-17 maintenance §5-§6: vendors are company-wide; maintenance.manage edits them. */
class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::MaintenanceView);
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $user->can(PermissionName::MaintenanceView);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::MaintenanceManage);
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->can(PermissionName::MaintenanceManage);
    }
}
```

Create `app/Actions/Maintenance/SaveVendor.php`:

```php
<?php

namespace App\Actions\Maintenance;

use App\Enums\Trade;
use App\Enums\VendorType;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Spec 2026-10-17 maintenance §3.1: name ≤150, type, ≥1 trade, mobile, optional email, CR, VAT number, notes; active. */
final class SaveVendor
{
    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, ?Vendor $vendor, array $data): Vendor
    {
        if (! ($vendor ? $actor->can('update', $vendor) : $actor->can('create', Vendor::class))) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(VendorType::class)],
            'trades' => ['required', 'array', 'min:1'],
            'trades.*' => ['required', 'distinct', Rule::enum(Trade::class)],
            'mobile' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'cr_number' => ['nullable', 'string', 'max:30'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ])->validate();

        $trades = array_values(array_filter(array_map(fn (Trade $t) => $t->value, Trade::cases()), fn (string $t) => in_array($t, $validated['trades'], true)));

        $vendor ??= new Vendor;
        $vendor->fill([...$validated, 'trades' => $trades, 'active' => (bool) $validated['active']])->save();

        return $vendor;
    }
}
```

- [ ] **Step 7: The screens.** Create `app/Livewire/Maintenance/Vendors.php`:

```php
<?php

namespace App\Livewire\Maintenance;

use App\Enums\Trade;
use App\Enums\VendorType;
use App\Livewire\Concerns\WithActor;
use App\Models\Vendor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Spec 2026-10-17 maintenance §5: vendors, filtered by trade, type and active. */
#[Title('Vendors')]
class Vendors extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered value means "no filter" instead of a TypeError (a 500).
    #[Url]
    public mixed $trade = '';

    #[Url]
    public mixed $type = '';

    #[Url]
    public mixed $active = '1';

    #[Url]
    public mixed $search = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('viewAny', Vendor::class), 403);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $trade = Trade::tryFrom(is_string($this->trade) ? $this->trade : '');
        $type = VendorType::tryFrom(is_string($this->type) ? $this->type : '');
        $search = is_string($this->search) ? trim($this->search) : '';

        $vendors = Vendor::query()
            ->when($trade, fn ($q, Trade $t) => $q->whereJsonContains('trades', $t->value))
            ->when($type, fn ($q, VendorType $t) => $q->where('type', $t))
            ->when(in_array($this->active, ['0', '1'], true), fn ($q) => $q->where('active', $this->active === '1'))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('mobile', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.maintenance.vendors', ['vendors' => $vendors, 'trades' => Trade::cases(), 'types' => VendorType::cases()]);
    }
}
```

Create `resources/views/livewire/maintenance/vendors.blade.php`:

```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Vendors') }}</flux:heading>
        @can('create', \App\Models\Vendor::class)
            <flux:button variant="primary" :href="route('maintenance.vendors.create')" wire:navigate>{{ __('New vendor') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="trade" class="sm:max-w-48">
            <option value="">{{ __('All trades') }}</option>
            @foreach ($trades as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="type" class="sm:max-w-44">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="active" class="sm:max-w-40">
            <option value="1">{{ __('Active') }}</option>
            <option value="0">{{ __('Inactive') }}</option>
            <option value="">{{ __('All') }}</option>
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$vendors">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Trades') }}</flux:table.column>
                <flux:table.column>{{ __('Mobile') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($vendors as $vendor)
                    <flux:table.row :key="$vendor->id">
                        <flux:table.cell><flux:link :href="route('maintenance.vendors.edit', $vendor)" wire:navigate>{{ $vendor->name }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $vendor->type->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $vendor->tradeLabels() }}</flux:table.cell>
                        <flux:table.cell>{{ $vendor->mobile }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$vendor->active ? 'green' : 'zinc'">{{ $vendor->active ? __('Active') : __('Inactive') }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No vendors found.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```

Create `app/Livewire/Maintenance/VendorForm.php`:

```php
<?php

namespace App\Livewire\Maintenance;

use App\Actions\Maintenance\SaveVendor;
use App\Enums\Trade;
use App\Enums\VendorType;
use App\Livewire\Concerns\WithActor;
use App\Models\Vendor;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class VendorForm extends Component
{
    use WithActor;

    #[Locked]
    public ?int $vendorId = null;

    // Untyped on purpose: a tampered payload reaches SaveVendor's validation instead of a TypeError (a 500).
    public mixed $form = ['type' => 'company', 'trades' => [], 'active' => true];

    public function mount(?Vendor $vendor = null): void
    {
        if ($vendor?->exists) {
            abort_unless($this->actor()->can('view', $vendor), 403);
            $this->vendorId = $vendor->id;
            $this->form = [...$vendor->only(['name', 'mobile', 'email', 'cr_number', 'vat_number', 'notes', 'trades', 'active']), 'type' => $vendor->type->value];
        } else {
            abort_unless($this->actor()->can('create', Vendor::class), 403);
        }
    }

    public function save(SaveVendor $save): void
    {
        try {
            $vendor = $save->handle($this->actor(), $this->vendorId ? Vendor::findOrFail($this->vendorId) : null, is_array($this->form) ? $this->form : []);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            // "trades.0" belongs to the trades checkboxes.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['form.'.Str::before($k, '.') => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Vendor saved.'));
        $this->redirectRoute('maintenance.vendors.edit', $vendor, navigate: true);
    }

    public function render(): View
    {
        $vendor = $this->vendorId ? Vendor::findOrFail($this->vendorId) : null;

        return view('livewire.maintenance.vendor-form', [
            'vendor' => $vendor,
            'trades' => Trade::cases(),
            'types' => VendorType::cases(),
            'canEdit' => $vendor ? $this->actor()->can('update', $vendor) : true,
        ])->title($vendor ? $vendor->name : __('New vendor'));
    }
}
```

Create `resources/views/livewire/maintenance/vendor-form.blade.php`:

```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $vendor?->name ?? __('New vendor') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:input wire:model="form.name" :label="__('Name')" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.type" :label="__('Type')">
                    @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.mobile" :label="__('Mobile')" type="tel" required />
                <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                <flux:input wire:model="form.cr_number" :label="__('CR number')" />
                <flux:input wire:model="form.vat_number" :label="__('VAT number')" />
            </div>
            <flux:checkbox.group wire:model="form.trades" :label="__('Trades')" class="grid gap-2 sm:grid-cols-2">
                @foreach ($trades as $t)<flux:checkbox :value="$t->value" :label="$t->label()" />@endforeach
            </flux:checkbox.group>
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
            <flux:switch wire:model="form.active" :label="__('Active: can get new work orders')" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>
</section>
```

- [ ] **Step 8: Routes, menu and the test helper.** In `routes/property.php` add `use App\Livewire\Maintenance;` to the imports (alphabetical, after `use App\Livewire\Invoices;`), and before the `import` route:

```php
    Route::livewire('maintenance/vendors', Maintenance\Vendors::class)->middleware('can:maintenance.view')->name('maintenance.vendors.index');
    Route::livewire('maintenance/vendors/create', Maintenance\VendorForm::class)->middleware('can:maintenance.manage')->name('maintenance.vendors.create');
    Route::livewire('maintenance/vendors/{vendor}/edit', Maintenance\VendorForm::class)->middleware('can:maintenance.view')->name('maintenance.vendors.edit');
```

In `resources/views/layouts/app/sidebar.blade.php`, after the closing `</flux:sidebar.group>` of the Property group:

```blade
                @can('maintenance.view')
                    <flux:sidebar.group :heading="__('Maintenance')" class="grid">
                        <flux:sidebar.item icon="truck" :href="route('maintenance.vendors.index')" :current="request()->routeIs('maintenance.vendors.*')" wire:navigate>{{ __('Vendors') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan
```

In `tests/Pest.php` add `use App\Models\Vendor;` to the imports and at the end:

```php
/** A vendor (maintenance tests): an active company named "Fix-It Co" doing general work, unless $attributes say otherwise. */
function maintenanceVendor(array $attributes = []): Vendor
{
    return Vendor::create(['name' => 'Fix-It Co', 'type' => 'company', 'trades' => ['general'], 'mobile' => '+973 3300 0000', 'active' => true, ...$attributes]);
}
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Maintenance/VendorsTest.php tests/Feature/Auth/RolesAndPermissionsTest.php tests/Feature/Permissions tests/Feature/Roles`
Expected: PASS.

- [ ] **Step 10: Migrate the dev database and run the gates**

Run: `php artisan migrate --database=migrator --force`, then `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: the migration runs and grants the permissions to the existing roles. Pint fixes or passes, PHPStan reports no errors, and the build succeeds.

- [ ] **Step 11: Commit**

```bash
git add app/Enums/PermissionName.php database/seeders/RolesAndPermissionsSeeder.php app/Enums/Trade.php app/Enums/VendorType.php \
  database/migrations/2026_12_20_000100_create_vendors_table.php app/Models/Vendor.php app/Policies/VendorPolicy.php \
  app/Actions/Maintenance/SaveVendor.php app/Livewire/Maintenance/Vendors.php app/Livewire/Maintenance/VendorForm.php \
  resources/views/livewire/maintenance/vendors.blade.php resources/views/livewire/maintenance/vendor-form.blade.php \
  routes/property.php resources/views/layouts/app/sidebar.blade.php tests/Pest.php tests/Feature/Auth/RolesAndPermissionsTest.php \
  tests/Feature/Maintenance/VendorsTest.php
git commit -m "$(cat <<'EOF'
Maintenance permissions and vendors: maintenance.view / .manage, vendors table, list and form

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Requests and work orders: storage, numbering, logging a request, adding work orders

**Files:**
- Create: `app/Enums/MaintenanceStatus.php`, `app/Enums/WorkOrderStatus.php`, `app/Enums/MaintenancePriority.php`, `app/Enums/MaintenanceChannel.php`
- Modify: `app/Enums/NumberSequenceKey.php`, `app/Enums/DocumentCategory.php`
- Create: `database/migrations/2026_12_20_000200_create_maintenance_tables.php`
- Create: `app/Models/MaintenanceRequest.php`, `app/Models/WorkOrder.php`, `app/Models/MaintenanceEvent.php`
- Modify: `app/Models/Vendor.php` (`workOrders()`)
- Create: `app/Policies/MaintenanceRequestPolicy.php`, `app/Policies/WorkOrderPolicy.php`
- Create: `app/Actions/Maintenance/LogMaintenanceRequest.php`, `app/Actions/Maintenance/AddWorkOrder.php`
- Modify: `tests/Pest.php`, `tests/Feature/Numbering/EnsureNumberSequencesTest.php`, `tests/Feature/Install/InstallCommandTest.php`, `tests/Feature/Documents/DocumentsTest.php`
- Test: `tests/Feature/Maintenance/RequestsTest.php`

**Interfaces:**
- Consumes: `Trade`, `Vendor` (`handles()`), `maintenanceVendor()`, `NextDocumentNumber`, `EnsureNumberSequences`, `activeAgreement()`, `matrixUser()`.
- Produces:
  - Enums, each with `label(): string`:
    - `MaintenanceStatus` (`Open`, `InProgress`, `Resolved`, `Cancelled`), with `isClosed(): bool`.
    - `WorkOrderStatus` (`Assigned`, `InProgress`, `Completed`, `Cancelled`), with `isOpen(): bool`.
    - `MaintenancePriority` (`Urgent`, `High`, `Normal`, `Low`), with `color(): string`.
    - `MaintenanceChannel` (`Phone`, `Whatsapp`, `WalkIn`, `Inspection`, `Email`).
  - `NumberSequenceKey::MaintenanceRequest` (`maintenance_request`, prefix `MR`) and `NumberSequenceKey::WorkOrder` (`work_order`, prefix `WO`).
  - `DocumentCategory::Maintenance` (`maintenance`, "Maintenance photo").
  - `MaintenanceRequest` model.
    - Columns: `number`, `building_id`, `unit_id`?, `customer_id`?, `reported_by_name`, `channel`, `category` Trade, `priority`, `description`, `entry_permitted` bool, `status`, `resolved_at`?, `cancel_reason`?, `created_by`.
    - Relations: `building()`, `unit()`, `customer()`, `creator()`, `workOrders()`, `events()` (by id), `documents()`.
    - Scopes: `visibleTo(User)`, `inBuilding(int)`, `urgentFirst()`.
    - Helpers: `place(): string`, `summary(): string`, `static currentTenantId(int $unitId): ?int`.
  - `WorkOrder` model.
    - Columns: `number`, `maintenance_request_id`, `vendor_id`, `scheduled_on`?, `instructions`?, `estimate`?, `status`, `completed_on`?, `completion_note`?, `actual_net`?, `actual_tax`?, `expense_id`? (unique), `cancel_reason`?, `created_by`.
    - Relations: `maintenanceRequest()`, `vendor()` (with trashed), `expense()`, `creator()`, `documents()`.
    - Scope `visibleTo(User)`. Helper `isOverdue(): bool`.
  - `MaintenanceEvent` model (`kind` `'status'|'note'`, `from_status`, `to_status`, `note`, `user_id`, `created_at`).
    - `static record(MaintenanceRequest $request, User $user, ?WorkOrder $workOrder = null, ?string $from = null, ?string $to = null, ?string $note = null): self`. The kind is `note` when `$to` is null.
    - `describe(): string`, `user()`, `workOrder()`.
  - `Vendor::workOrders(): HasMany`.
  - `MaintenanceRequestPolicy`: `viewAny`, `view` (maintenance.view + building scope), `create` (maintenance.manage), `update` (maintenance.manage + building scope); `static inScope(User, int $buildingId): bool`.
  - `WorkOrderPolicy`: `view` and `update`, each delegating to the request.
  - `LogMaintenanceRequest::handle(User $actor, array $data): MaintenanceRequest`. Keys: `building_id`, `unit_id`, `customer_id`, `reported_by_name`, `channel`, `category`, `priority`, `description`, `entry_permitted`.
  - `AddWorkOrder::handle(User $actor, MaintenanceRequest $request, array $data): WorkOrder`. Keys: `vendor_id`, `scheduled_on`, `instructions`, `estimate`.
  - Pest helpers:
    - `maintenanceRequest(User $by, Unit $unit, array $data = []): MaintenanceRequest`
    - `workOrderFor(User $by, MaintenanceRequest $request, Vendor $vendor, array $data = []): WorkOrder`

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/RequestsTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\AddWorkOrder;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->plumber = maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]);
});

test('a request is numbered MR-YYYY-NNNNNN, starts open, takes the unit\'s current tenant and starts the timeline', function () {
    $request = maintenanceRequest($this->pm, $this->unit, ['priority' => 'urgent']);

    expect($request->number)->toBe('MR-2026-000001')
        ->and($request->status)->toBe(MaintenanceStatus::Open)
        ->and($request->customer_id)->toBe($this->tenant->id)
        ->and($request->priority)->toBe(MaintenancePriority::Urgent)
        ->and($request->created_by)->toBe($this->pm->id)
        ->and($request->place())->toBe('Seef Tower 101')
        ->and($request->events()->get(['kind', 'from_status', 'to_status'])->toArray())->toBe([['kind' => 'status', 'from_status' => null, 'to_status' => 'open']])
        ->and(Activity::query()->where('subject_type', MaintenanceRequest::class)->where('subject_id', $request->id)->where('event', 'created')->exists())->toBeTrue()
        ->and(maintenanceRequest($this->pm, $this->unit)->number)->toBe('MR-2026-000002');
});

test('a unit with no current agreement has no tenant; a common-area request has no unit', function () {
    $vacant = Unit::factory()->for($this->building)->create();
    expect(MaintenanceRequest::currentTenantId($vacant->id))->toBeNull();

    $common = maintenanceRequest($this->pm, $this->unit, ['unit_id' => null, 'customer_id' => null]);
    expect($common->unit_id)->toBeNull()->and($common->customer_id)->toBeNull()->and($common->place())->toBe('Seef Tower');
});

test('request input is validated and scoped to what the user can see', function (array $change, string $error) {
    $other = Building::factory()->create();
    $change = array_map(fn ($v) => match ($v) {
        ':other-building' => $other->id,
        ':other-unit' => Unit::factory()->for($other)->create()->id,
        ':hidden-tenant' => activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
            [Unit::factory()->for($other)->create()])->customer_id,
        default => $v,
    }, $change);
    $scoped = User::factory()->create()->givePermissionTo(['maintenance.view', 'maintenance.manage']);
    $scoped->buildings()->attach($this->building->id);

    try {
        maintenanceRequest($scoped, $this->unit, $change);
        $this->fail('The request was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($error);
    }
    expect(MaintenanceRequest::count())->toBe(0);
})->with([
    'a building the user cannot see' => [['building_id' => ':other-building', 'unit_id' => null], 'building_id'],
    'a unit of another building' => [['unit_id' => ':other-unit'], 'unit_id'],
    'a tenant the user cannot see' => [['customer_id' => ':hidden-tenant'], 'customer_id'],
    'unknown channel' => [['channel' => 'fax'], 'channel'],
    'unknown category' => [['category' => 'roofing'], 'category'],
    'unknown priority' => [['priority' => 'asap'], 'priority'],
    'no description' => [['description' => ''], 'description'],
    'description too long' => [['description' => str_repeat('x', 2001)], 'description'],
    'no reporter' => [['reported_by_name' => ''], 'reported_by_name'],
    'reporter too long' => [['reported_by_name' => str_repeat('x', 151)], 'reported_by_name'],
    'building as an array' => [['building_id' => ['1']], 'building_id'],
    'tenant as text' => [['customer_id' => 'abc'], 'customer_id'],
    'entry permission not a boolean' => [['entry_permitted' => 'maybe'], 'entry_permitted'],
]);

test('work orders are numbered, go to vendors whose trades include the category or general, and the first one starts the request', function () {
    $request = maintenanceRequest($this->pm, $this->unit);
    $first = workOrderFor($this->pm, $request, $this->plumber, ['scheduled_on' => '2026-10-19', 'instructions' => 'Replace the trap', 'estimate' => '25.5']);

    expect($first->number)->toBe('WO-2026-000001')
        ->and($first->status)->toBe(WorkOrderStatus::Assigned)
        ->and($first->estimate)->toBe('25.500')
        ->and($first->scheduled_on->format('d/m/Y'))->toBe('19/10/2026')
        ->and($request->fresh()->status)->toBe(MaintenanceStatus::InProgress);

    workOrderFor($this->pm, $request, maintenanceVendor(['name' => 'Handyman', 'trades' => ['general']]));
    expect($request->workOrders()->count())->toBe(2)
        ->and($request->events()->get()->map(fn ($e) => [$e->work_order_id !== null, $e->from_status, $e->to_status])->all())
        ->toBe([[false, null, 'open'], [true, null, 'assigned'], [false, 'open', 'in_progress'], [true, null, 'assigned']]);
});

test('an inactive vendor, or one without the trade, gets no work order', function () {
    $request = maintenanceRequest($this->pm, $this->unit);

    foreach ([maintenanceVendor(['trades' => ['plumbing'], 'active' => false]), maintenanceVendor(['trades' => ['ac', 'painting']])] as $vendor) {
        expect(fn () => workOrderFor($this->pm, $request, $vendor))->toThrow(ValidationException::class);
    }
    expect(WorkOrder::count())->toBe(0)->and($request->fresh()->status)->toBe(MaintenanceStatus::Open);
});

test('work order input is validated', function (array $data, string $error) {
    $request = maintenanceRequest($this->pm, $this->unit);

    try {
        app(AddWorkOrder::class)->handle($this->pm, $request, ['vendor_id' => $this->plumber->id, ...$data]);
        $this->fail('The work order was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($error);
    }
    expect(WorkOrder::count())->toBe(0);
})->with([
    'no vendor' => [['vendor_id' => null], 'vendor_id'],
    'unknown vendor' => [['vendor_id' => 999999], 'vendor_id'],
    'vendor as an array' => [['vendor_id' => ['1']], 'vendor_id'],
    'date not Y-m-d' => [['scheduled_on' => '19/10/2026'], 'scheduled_on'],
    'negative estimate' => [['estimate' => '-5'], 'estimate'],
    'four decimals' => [['estimate' => '1.2345'], 'estimate'],
    'estimate as an array' => [['estimate' => ['1']], 'estimate'],
    'instructions too long' => [['instructions' => str_repeat('x', 2001)], 'instructions'],
]);

test('only maintenance.manage, in the request\'s building, logs requests and adds work orders', function () {
    $request = maintenanceRequest($this->pm, $this->unit);

    foreach ([R::Leasing, R::Management, R::Finance] as $role) {
        $user = matrixUser($role, $this->building);
        expect(fn () => maintenanceRequest($user, $this->unit))->toThrow(AuthorizationException::class)
            ->and(fn () => workOrderFor($user, $request, $this->plumber))->toThrow(AuthorizationException::class);
    }
    $elsewhere = User::factory()->create()->givePermissionTo(['maintenance.view', 'maintenance.manage']); // no building assigned
    expect(fn () => workOrderFor($elsewhere, $request, $this->plumber))->toThrow(AuthorizationException::class)
        ->and(workOrderFor(matrixUser(R::Admin, $this->building), $request, $this->plumber)->exists)->toBeTrue();
});

test('the database refuses a completed work order without its completion date', function () {
    $wo = workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unit), $this->plumber);

    expect(fn () => $wo->forceFill(['status' => 'completed'])->save())->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/RequestsTest.php`
Expected: FAIL with `Class "App\Enums\MaintenanceStatus" not found` (or `Call to undefined function maintenanceRequest()`).

- [ ] **Step 3: The enums.** Create `app/Enums/MaintenanceStatus.php`:

```php
<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.2. Resolved and cancelled are final (plan ruling 4). */
enum MaintenanceStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::InProgress => __('In progress'),
            self::Resolved => __('Resolved'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Resolved || $this === self::Cancelled;
    }
}
```

Create `app/Enums/WorkOrderStatus.php`:

```php
<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.3. */
enum WorkOrderStatus: string
{
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => __('Assigned'),
            self::InProgress => __('In progress'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** Still to be done: it can start, be completed or be cancelled. */
    public function isOpen(): bool
    {
        return $this === self::Assigned || $this === self::InProgress;
    }
}
```

Create `app/Enums/MaintenancePriority.php`:

```php
<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.2. Urgent requests are listed first and shown on the dashboard. */
enum MaintenancePriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Urgent => __('Urgent'),
            self::High => __('High'),
            self::Normal => __('Normal'),
            self::Low => __('Low'),
        };
    }

    /** A Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Urgent => 'red',
            self::High => 'amber',
            default => 'zinc',
        };
    }
}
```

Create `app/Enums/MaintenanceChannel.php`:

```php
<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.2: how the problem was reported. */
enum MaintenanceChannel: string
{
    case Phone = 'phone';
    case Whatsapp = 'whatsapp';
    case WalkIn = 'walk_in';
    case Inspection = 'inspection';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Phone => __('Phone'),
            self::Whatsapp => __('WhatsApp'),
            self::WalkIn => __('Walk-in'),
            self::Inspection => __('Inspection'),
            self::Email => __('Email'),
        };
    }
}
```

In `app/Enums/NumberSequenceKey.php` add the cases after `case DepositSettlement = 'deposit_settlement';`:

```php
    case MaintenanceRequest = 'maintenance_request';
    case WorkOrder = 'work_order';
```

and the prefixes after `self::DepositSettlement => 'DS',`:

```php
            self::MaintenanceRequest => 'MR',
            self::WorkOrder => 'WO',
```

In `app/Enums/DocumentCategory.php` add `case Maintenance = 'maintenance';` after `case GeneratedPdf = 'generated_pdf';`, and `self::Maintenance => __('Maintenance photo'),` after `self::GeneratedPdf => __('Generated PDF'),` in `label()`.

- [ ] **Step 4: Update the tests that count sequences and categories.** In `tests/Feature/Numbering/EnsureNumberSequencesTest.php`, change `->toBe(8)` to `->toBe(10)` (twice: the first test's create count and the command test's 2027 count). Change the expected prefixes to:

```php
    expect(DB::table('number_sequences')->where('year', 2026)->orderBy('key')->pluck('prefix', 'key')->all())->toBe([
        'agreement' => 'AGR', 'credit_note' => 'CN', 'deposit_settlement' => 'DS', 'invoice' => 'INV', 'maintenance_request' => 'MR',
        'owner_contract' => 'OC', 'owner_statement' => 'OS', 'payment_out' => 'PO', 'receipt' => 'RCP', 'work_order' => 'WO',
    ]);
```

In `tests/Feature/Install/InstallCommandTest.php` change `->and(DB::table('number_sequences')->count())->toBe(16);` to `->toBe(20);` (ten keys, this year and next).

In `tests/Feature/Documents/DocumentsTest.php` change the label list to:

```php
        ->toBe(['Photo', 'ID copy', 'CR copy', 'Signed contract', 'Cheque image', 'Move-out photo', 'Owner approval', 'Generated PDF', 'Maintenance photo', 'Other']);
```

- [ ] **Step 5: The migration** `database/migrations/2026_12_20_000200_create_maintenance_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-17 maintenance §3.2-§3.4: requests, work orders and their timeline, plus the "maintenance" document
 * category. Not money records: no triggers (EXPECTED_TRIGGERS unchanged). The expense link lives on work_orders, so
 * the locked expenses table is not touched.
 */
return new class extends Migration
{
    private const string OLD_CATEGORIES = "'photo', 'id_copy', 'cr_copy', 'signed_contract', 'cheque_image', 'move_out_photo', 'owner_approval', 'generated_pdf', 'other'";

    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('unit_id')->nullable()->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('reported_by_name', 150);
            $table->string('channel', 20);
            $table->string('category', 20);
            $table->string('priority', 10);
            $table->string('description', 2000);
            $table->boolean('entry_permitted')->default(false);
            $table->string('status', 20)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['status', 'priority']);
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('maintenance_request_id')->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->date('scheduled_on')->nullable();
            $table->string('instructions', 2000)->nullable();
            $table->decimal('estimate', 12, 3)->nullable();
            $table->string('status', 20)->default('assigned');
            $table->date('completed_on')->nullable();
            $table->string('completion_note', 2000)->nullable();
            $table->decimal('actual_net', 12, 3)->nullable();
            $table->decimal('actual_tax', 12, 3)->nullable();
            $table->foreignId('expense_id')->nullable()->unique()->constrained();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['status', 'scheduled_on']);
        });

        Schema::create('maintenance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained();
            $table->foreignId('work_order_id')->nullable()->constrained();
            $table->string('kind', 10);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE maintenance_requests
                ADD CONSTRAINT maintenance_requests_channel_chk CHECK (channel IN ('phone', 'whatsapp', 'walk_in', 'inspection', 'email')),
                ADD CONSTRAINT maintenance_requests_category_chk CHECK (category IN ('plumbing', 'electrical', 'ac', 'carpentry', 'painting', 'cleaning', 'pest_control', 'general')),
                ADD CONSTRAINT maintenance_requests_priority_chk CHECK (priority IN ('urgent', 'high', 'normal', 'low')),
                ADD CONSTRAINT maintenance_requests_status_chk CHECK (status IN ('open', 'in_progress', 'resolved', 'cancelled'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE work_orders
                ADD CONSTRAINT work_orders_status_chk CHECK (status IN ('assigned', 'in_progress', 'completed', 'cancelled')),
                ADD CONSTRAINT work_orders_amounts_chk CHECK (COALESCE(estimate, 0) >= 0 AND COALESCE(actual_net, 0) >= 0 AND COALESCE(actual_tax, 0) >= 0),
                ADD CONSTRAINT work_orders_completed_chk CHECK (status <> 'completed' OR completed_on IS NOT NULL),
                ADD CONSTRAINT work_orders_expense_chk CHECK (expense_id IS NULL OR status = 'completed')
            SQL);
        DB::statement("ALTER TABLE maintenance_events ADD CONSTRAINT maintenance_events_kind_chk CHECK (kind IN ('status', 'note'))");

        DB::statement('ALTER TABLE documents DROP CHECK documents_category_chk');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_category_chk CHECK (category IN ('.self::OLD_CATEGORIES.", 'maintenance'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CHECK documents_category_chk');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_category_chk CHECK (category IN ('.self::OLD_CATEGORIES.'))');
        Schema::dropIfExists('maintenance_events');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('maintenance_requests');
    }
};
```

- [ ] **Step 6: The models.** Create `app/Models/MaintenanceRequest.php`:

```php
<?php

namespace App\Models;

use App\Enums\AgreementStatus;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\Trade;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec 2026-10-17 maintenance §3.2. Written only by the App\Actions\Maintenance actions; status and number with forceFill.
 *
 * @property int $id
 * @property string $number
 * @property int $building_id
 * @property int|null $unit_id
 * @property int|null $customer_id
 * @property string $reported_by_name
 * @property MaintenanceChannel $channel
 * @property Trade $category
 * @property MaintenancePriority $priority
 * @property string $description
 * @property bool $entry_permitted
 * @property MaintenanceStatus $status
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $cancel_reason
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read Building $building
 * @property-read Unit|null $unit
 * @property-read Customer|null $customer
 */
class MaintenanceRequest extends Model
{
    use LogsActivity;

    protected $fillable = ['building_id', 'unit_id', 'customer_id', 'reported_by_name', 'channel', 'category', 'priority', 'description', 'entry_permitted'];

    protected function casts(): array
    {
        return [
            'channel' => MaintenanceChannel::class,
            'category' => Trade::class,
            'priority' => MaintenancePriority::class,
            'status' => MaintenanceStatus::class,
            'entry_permitted' => 'boolean',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<WorkOrder, $this> */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class)->orderBy('id');
    }

    /** @return HasMany<MaintenanceEvent, $this> the timeline, oldest first */
    public function events(): HasMany
    {
        return $this->hasMany(MaintenanceEvent::class)->orderBy('id');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @param  Builder<MaintenanceRequest>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('building', fn (Builder $q) => $q->visibleTo($user));
    }

    /** @param  Builder<MaintenanceRequest>  $query */
    #[Scope]
    protected function inBuilding(Builder $query, int $buildingId): void
    {
        $query->where('building_id', $buildingId);
    }

    /**
     * Spec §5: urgent first, then oldest (plan ruling 14).
     *
     * @param  Builder<MaintenanceRequest>  $query
     */
    #[Scope]
    protected function urgentFirst(Builder $query): void
    {
        $query->orderByRaw("priority = 'urgent' DESC")->orderBy('id');
    }

    /** "Seef Tower 101", or the building alone for a common area. */
    public function place(): string
    {
        return trim($this->building->name.' '.($this->unit?->code ?? ''));
    }

    /** The description shortened for lists and the expense description. */
    public function summary(): string
    {
        return Str::limit($this->description, 60);
    }

    /** Spec §3.2: the tenant of the unit's active agreement today (Asia/Bahrain), found as RecordExpense finds it. */
    public static function currentTenantId(int $unitId): ?int
    {
        $today = now('Asia/Bahrain')->toDateString();

        return AgreementUnit::query()
            ->with('agreement:id,customer_id')
            ->where('unit_id', $unitId)
            ->whereHas('agreement', fn (Builder $q) => $q->where('status', AgreementStatus::Active))
            ->where('start_date', '<=', $today)
            ->whereRaw('COALESCE(move_out_date, end_date) >= ?', [$today])
            ->first()?->agreement?->customer_id;
    }
}
```

Create `app/Models/WorkOrder.php`:

```php
<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec 2026-10-17 maintenance §3.3. Written only by the App\Actions\Maintenance actions; status, amounts and the
 * expense link with forceFill. completed_on is the day the work was done (plan ruling 3).
 *
 * @property int $id
 * @property string $number
 * @property int $maintenance_request_id
 * @property int $vendor_id
 * @property CarbonImmutable|null $scheduled_on
 * @property string|null $instructions
 * @property string|null $estimate
 * @property WorkOrderStatus $status
 * @property CarbonImmutable|null $completed_on
 * @property string|null $completion_note
 * @property string|null $actual_net
 * @property string|null $actual_tax
 * @property int|null $expense_id
 * @property string|null $cancel_reason
 * @property int $created_by
 * @property-read MaintenanceRequest $maintenanceRequest
 * @property-read Vendor $vendor
 * @property-read Expense|null $expense
 */
class WorkOrder extends Model
{
    use LogsActivity;

    protected $fillable = ['vendor_id', 'scheduled_on', 'instructions', 'estimate'];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'scheduled_on' => 'immutable_date',
            'completed_on' => 'immutable_date',
            'estimate' => 'decimal:3',
            'actual_net' => 'decimal:3',
            'actual_tax' => 'decimal:3',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<MaintenanceRequest, $this> */
    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return MorphMany<Document, $this> after-photos */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @param  Builder<WorkOrder>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('maintenanceRequest', fn (Builder $q) => $q->visibleTo($user));
    }

    /** Spec §5 (plan ruling 13): scheduled before today and still assigned or in progress. */
    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->scheduled_on !== null && $this->scheduled_on->toDateString() < now('Asia/Bahrain')->toDateString();
    }
}
```

Create `app/Models/MaintenanceEvent.php`:

```php
<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\WorkOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec 2026-10-17 maintenance §3.4: one row per status change or note on a request or its work orders. Each row is
 * also an audit entry (LogsActivity, plan ruling 8).
 *
 * @property int $id
 * @property int $maintenance_request_id
 * @property int|null $work_order_id
 * @property string $kind 'status' | 'note'
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note
 * @property int $user_id
 * @property CarbonImmutable $created_at
 * @property-read WorkOrder|null $workOrder
 * @property-read User $user
 */
class MaintenanceEvent extends Model
{
    use LogsActivity;

    public const UPDATED_AT = null; // rows are never edited

    protected $fillable = ['maintenance_request_id', 'work_order_id', 'kind', 'from_status', 'to_status', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** A status change when $to is given, otherwise a note. */
    public static function record(MaintenanceRequest $request, User $user, ?WorkOrder $workOrder = null, ?string $from = null, ?string $to = null, ?string $note = null): self
    {
        return self::create([
            'maintenance_request_id' => $request->id, 'work_order_id' => $workOrder?->id, 'kind' => $to === null ? 'note' : 'status',
            'from_status' => $from, 'to_status' => $to, 'note' => $note, 'user_id' => $user->id,
        ]);
    }

    /** "WO-2026-000001: Assigned → In progress · reason", or the note. */
    public function describe(): string
    {
        if ($this->kind === 'note') {
            return (string) $this->note;
        }
        $label = fn (?string $s) => $s === null ? '' : ($this->work_order_id !== null ? WorkOrderStatus::from($s)->label() : MaintenanceStatus::from($s)->label());
        $change = $this->from_status === null ? $label($this->to_status) : $label($this->from_status).' → '.$label($this->to_status);

        return ($this->workOrder ? $this->workOrder->number.': ' : '').$change.($this->note ? ' · '.$this->note : '');
    }
}
```

In `app/Models/Vendor.php` add `use Illuminate\Database\Eloquent\Relations\HasMany;` and after `getActivitylogOptions()`:

```php
    /** @return HasMany<WorkOrder, $this> */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
```

- [ ] **Step 7: Policies and actions.** Create `app/Policies/MaintenanceRequestPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\User;

/** Spec 2026-10-17 maintenance §6: maintenance.view to see, maintenance.manage to change; building scope for both. */
class MaintenanceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::MaintenanceView);
    }

    public function view(User $user, MaintenanceRequest $request): bool
    {
        return $this->viewAny($user) && self::inScope($user, $request->building_id);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::MaintenanceManage);
    }

    /** Work orders, status changes, notes and photos. */
    public function update(User $user, MaintenanceRequest $request): bool
    {
        return $user->can(PermissionName::MaintenanceManage) && self::inScope($user, $request->building_id);
    }

    public static function inScope(User $user, int $buildingId): bool
    {
        return Building::visibleTo($user)->whereKey($buildingId)->exists();
    }
}
```

Create `app/Policies/WorkOrderPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

/** A work order follows its request (spec 2026-10-17 maintenance §6). */
class WorkOrderPolicy
{
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('view', $workOrder->maintenanceRequest);
    }

    /** Status changes, completion and after-photos. */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('update', $workOrder->maintenanceRequest);
    }
}
```

Create `app/Actions/Maintenance/LogMaintenanceRequest.php`:

```php
<?php

namespace App\Actions\Maintenance;

use App\Actions\NextDocumentNumber;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\Trade;
use App\Models\Building;
use App\Models\Customer;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 1: log a request (maintenance.manage, in the user's buildings). */
final class LogMaintenanceRequest
{
    public function __construct(private NextDocumentNumber $next) {}

    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, array $data): MaintenanceRequest
    {
        if (! $actor->can('create', MaintenanceRequest::class)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data);
        $buildingId = is_numeric($data['building_id'] ?? null) ? (int) $data['building_id'] : 0;

        $v = Validator::make($data, [
            'building_id' => ['bail', 'required', 'integer', Rule::in(Building::visibleTo($actor)->pluck('id')->all())],
            'unit_id' => ['bail', 'nullable', 'integer', Rule::exists('units', 'id')->where('building_id', $buildingId)->whereNull('deleted_at')],
            'customer_id' => ['bail', 'nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'reported_by_name' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::enum(MaintenanceChannel::class)],
            'category' => ['required', Rule::enum(Trade::class)],
            'priority' => ['required', Rule::enum(MaintenancePriority::class)],
            'description' => ['required', 'string', 'max:2000'],
            'entry_permitted' => ['required', 'boolean'],
        ])->validate();

        if (isset($v['customer_id']) && ! Customer::visibleTo($actor)->whereKey($v['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => __('Choose a tenant from the list.')]);
        }

        return DB::transaction(function () use ($actor, $v) {
            $request = new MaintenanceRequest([
                ...Arr::only($v, ['building_id', 'unit_id', 'customer_id', 'reported_by_name', 'channel', 'category', 'priority', 'description']),
                'entry_permitted' => (bool) $v['entry_permitted'],
            ]);
            $request->forceFill([
                'number' => ($this->next)(NumberSequenceKey::MaintenanceRequest), 'status' => MaintenanceStatus::Open, 'created_by' => $actor->id,
            ])->save();
            MaintenanceEvent::record($request, $actor, to: MaintenanceStatus::Open->value);

            return $request;
        });
    }
}
```

Create `app/Actions/Maintenance/AddWorkOrder.php`:

```php
<?php

namespace App\Actions\Maintenance;

use App\Actions\NextDocumentNumber;
use App\Enums\MaintenanceStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 2: a work order to an active vendor doing the request's trade (or general). */
final class AddWorkOrder
{
    public function __construct(private NextDocumentNumber $next) {}

    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, MaintenanceRequest $request, array $data): WorkOrder
    {
        if (! $actor->can('update', $request)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data);
        $v = Validator::make($data, [
            'vendor_id' => ['bail', 'required', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'scheduled_on' => ['nullable', 'date_format:Y-m-d'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'estimate' => ['nullable', Fils::rule()],
        ])->validate();

        $vendor = Vendor::query()->findOrFail($v['vendor_id']);
        if (! $vendor->active) {
            throw ValidationException::withMessages(['vendor_id' => __('This vendor is inactive and cannot get new work orders.')]);
        }
        if (! $vendor->handles($request->category)) {
            throw ValidationException::withMessages(['vendor_id' => __('This vendor does not do :trade work.', ['trade' => mb_strtolower($request->category->label())])]);
        }

        return DB::transaction(function () use ($actor, $request, $v) {
            $locked = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status->isClosed()) {
                throw ValidationException::withMessages(['vendor_id' => __('This request is closed.')]);
            }

            $workOrder = new WorkOrder([
                'vendor_id' => $v['vendor_id'],
                'scheduled_on' => $v['scheduled_on'] ?? null,
                'instructions' => $v['instructions'] ?? null,
                'estimate' => isset($v['estimate']) ? Fils::toDecimal(Fils::fromDecimal((string) $v['estimate'])) : null,
            ]);
            $workOrder->forceFill([
                'maintenance_request_id' => $locked->id, 'number' => ($this->next)(NumberSequenceKey::WorkOrder),
                'status' => WorkOrderStatus::Assigned, 'created_by' => $actor->id,
            ])->save();
            MaintenanceEvent::record($locked, $actor, $workOrder, to: WorkOrderStatus::Assigned->value);

            if ($locked->status === MaintenanceStatus::Open) { // spec §4 step 2: the first work order starts the request
                $locked->forceFill(['status' => MaintenanceStatus::InProgress])->save();
                MaintenanceEvent::record($locked, $actor, from: MaintenanceStatus::Open->value, to: MaintenanceStatus::InProgress->value);
            }

            return $workOrder;
        });
    }
}
```

- [ ] **Step 8: Test helpers.** In `tests/Pest.php` add `use App\Actions\Maintenance\AddWorkOrder;`, `use App\Actions\Maintenance\LogMaintenanceRequest;`, `use App\Models\MaintenanceRequest;` and `use App\Models\WorkOrder;` to the imports, and at the end:

```php
/** A request logged by $by on $unit with its current tenant (maintenance tests): plumbing, normal, entry permitted. Needs this year's number sequences. */
function maintenanceRequest(User $by, Unit $unit, array $data = []): MaintenanceRequest
{
    return app(LogMaintenanceRequest::class)->handle($by, [
        'building_id' => $unit->building_id, 'unit_id' => $unit->id, 'customer_id' => MaintenanceRequest::currentTenantId($unit->id),
        'reported_by_name' => 'Front desk', 'channel' => 'phone', 'category' => 'plumbing', 'priority' => 'normal',
        'description' => 'Kitchen sink leaking', 'entry_permitted' => true, ...$data,
    ]);
}

/** A work order on $request for $vendor, added by $by (maintenance tests). */
function workOrderFor(User $by, MaintenanceRequest $request, Vendor $vendor, array $data = []): WorkOrder
{
    return app(AddWorkOrder::class)->handle($by, $request, ['vendor_id' => $vendor->id, ...$data]);
}
```

- [ ] **Step 9: Run the tests to verify they pass, then migrate the dev database**

Run: `php artisan test tests/Feature/Maintenance tests/Feature/Numbering tests/Feature/Install tests/Feature/Documents tests/Feature/Integrity`
Expected: PASS. The integrity check still counts 61 triggers.

Run: `php artisan migrate --database=migrator --force`, then `php artisan rms:number-sequences --year=2026` and `php artisan rms:number-sequences --year=2027`.
Expected: "Created 2 number sequence rows for 2026." (and for 2027 if its rows already exist, otherwise 10).

- [ ] **Step 10: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`.
Expected: no errors.

- [ ] **Step 11: Commit**

```bash
git add app/Enums/MaintenanceStatus.php app/Enums/WorkOrderStatus.php app/Enums/MaintenancePriority.php app/Enums/MaintenanceChannel.php \
  app/Enums/NumberSequenceKey.php app/Enums/DocumentCategory.php database/migrations/2026_12_20_000200_create_maintenance_tables.php \
  app/Models/MaintenanceRequest.php app/Models/WorkOrder.php app/Models/MaintenanceEvent.php app/Models/Vendor.php \
  app/Policies/MaintenanceRequestPolicy.php app/Policies/WorkOrderPolicy.php \
  app/Actions/Maintenance/LogMaintenanceRequest.php app/Actions/Maintenance/AddWorkOrder.php tests/Pest.php \
  tests/Feature/Numbering/EnsureNumberSequencesTest.php tests/Feature/Install/InstallCommandTest.php tests/Feature/Documents/DocumentsTest.php \
  tests/Feature/Maintenance/RequestsTest.php
git commit -m "$(cat <<'EOF'
Maintenance requests and work orders: tables, MR/WO numbers, timeline, logging a request and adding work orders

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Progress: start and cancel work orders, resolve (automatic and by hand), cancel a request, notes

**Files:**
- Create: `app/Actions/Maintenance/ChangeWorkOrderStatus.php`, `app/Actions/Maintenance/ResolveMaintenanceRequest.php`, `app/Actions/Maintenance/CancelMaintenanceRequest.php`, `app/Actions/Maintenance/AddMaintenanceNote.php`
- Test: `tests/Feature/Maintenance/LifecycleTest.php`

**Interfaces:**
- Consumes: `MaintenanceRequest`, `WorkOrder`, `MaintenanceEvent::record()`, `MaintenanceStatus`, `WorkOrderStatus`, `maintenanceRequest()`, `workOrderFor()`, `maintenanceVendor()`.
- Produces:
  - `ChangeWorkOrderStatus::handle(User $actor, WorkOrder $workOrder, string $to, ?string $reason = null): WorkOrder`. `$to` is `'in_progress'` (only from assigned) or `'cancelled'` (from assigned or in progress, with a reason of 1–500 characters). Error keys: `status`, `reason`.
  - `ResolveMaintenanceRequest::handle(User $actor, MaintenanceRequest $request): MaintenanceRequest` resolves by hand. It is refused while a work order is open or the request is closed (error key `request`).
  - `ResolveMaintenanceRequest::ifDone(User $actor, MaintenanceRequest $locked): void` is called with the request row locked. When every work order is completed or cancelled and at least one is completed, it resolves the request. Task 4 calls it after a completion.
  - `CancelMaintenanceRequest::handle(User $actor, MaintenanceRequest $request, string $reason): MaintenanceRequest` cancels the open work orders too. Error key `reason`.
  - `AddMaintenanceNote::handle(User $actor, MaintenanceRequest $request, string $note): MaintenanceEvent`, 1–1000 characters. Error key `note`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/LifecycleTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\AddMaintenanceNote;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Actions\Maintenance\ChangeWorkOrderStatus;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\MaintenanceEvent;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->vendor = maintenanceVendor();
    $this->request = maintenanceRequest($this->pm, $this->unit);
    $this->change = fn (WorkOrder $wo, string $to, ?string $reason = null) => app(ChangeWorkOrderStatus::class)->handle($this->pm, $wo, $to, $reason);
    // Completing with a cost is Task 4's action; these tests only need a completed row.
    $this->markCompleted = fn (WorkOrder $wo) => $wo->forceFill(['status' => 'completed', 'completed_on' => '2026-10-17', 'completion_note' => 'Done'])->save();
});

test('a work order starts, then is cancelled with a reason; both changes are on the timeline and in the audit log', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->vendor);

    ($this->change)($wo, 'in_progress');
    ($this->change)($wo, 'cancelled', '  Tenant fixed it  ');

    expect($wo->fresh()->status)->toBe(WorkOrderStatus::Cancelled)
        ->and($wo->fresh()->cancel_reason)->toBe('Tenant fixed it')
        ->and(MaintenanceEvent::query()->where('work_order_id', $wo->id)->orderBy('id')->get()->map(fn ($e) => $e->describe())->all())
        ->toBe([$wo->number.': Assigned', $wo->number.': Assigned → In progress', $wo->number.': In progress → Cancelled · Tenant fixed it'])
        ->and(Activity::query()->where('subject_type', WorkOrder::class)->where('subject_id', $wo->id)->where('event', 'updated')->count())->toBe(2);
});

test('status changes are refused when they make no sense, and cancelling needs a reason', function (string $from, string $to, ?string $reason, string $error) {
    $wo = workOrderFor($this->pm, $this->request, $this->vendor);
    $wo->forceFill(['status' => $from, 'completed_on' => $from === 'completed' ? '2026-10-17' : null])->save();

    try {
        ($this->change)($wo, $to, $reason);
        $this->fail('The change was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($error);
    }
    expect($wo->fresh()->status->value)->toBe($from);
})->with([
    'start twice' => ['in_progress', 'in_progress', null, 'status'],
    'start a cancelled one' => ['cancelled', 'in_progress', null, 'status'],
    'cancel a completed one' => ['completed', 'cancelled', 'Oops', 'status'],
    'complete through this action' => ['assigned', 'completed', null, 'status'],
    'an unknown status' => ['assigned', 'paused', null, 'status'],
    'cancel without a reason' => ['assigned', 'cancelled', '   ', 'reason'],
    'a reason too long' => ['assigned', 'cancelled', str_repeat('x', 501), 'reason'],
]);

test('when every work order is completed or cancelled, with one completed, the request resolves itself', function () {
    $done = workOrderFor($this->pm, $this->request, $this->vendor);
    $other = workOrderFor($this->pm, $this->request, $this->vendor);
    ($this->markCompleted)($done);

    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::InProgress);
    ($this->change)($other, 'cancelled', 'Not needed');

    $request = $this->request->fresh();
    expect($request->status)->toBe(MaintenanceStatus::Resolved)
        ->and($request->resolved_at)->not->toBeNull()
        ->and($request->events()->get()->last()->describe())->toBe('In progress → Resolved');
});

test('with every work order cancelled and none completed, the request stays in progress', function () {
    ($this->change)(workOrderFor($this->pm, $this->request, $this->vendor), 'cancelled', 'Vendor did not come');

    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::InProgress);
});

test('resolving by hand is refused while a work order is open, and allowed once none is', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->vendor);
    $resolve = fn () => app(ResolveMaintenanceRequest::class)->handle($this->pm, $this->request);

    expect($resolve)->toThrow(ValidationException::class, 'Complete or cancel its open work orders first.');
    ($this->change)($wo, 'cancelled', 'Fixed by the tenant');
    $resolve();

    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved)
        ->and($resolve)->toThrow(ValidationException::class, 'This request is already closed.');

    $plain = maintenanceRequest($this->pm, $this->unit); // no work orders at all: fixed by phone
    app(ResolveMaintenanceRequest::class)->handle($this->pm, $plain);
    expect($plain->fresh()->status)->toBe(MaintenanceStatus::Resolved);
});

test('cancelling a request needs a reason and cancels its open work orders, keeping completed ones', function () {
    $done = workOrderFor($this->pm, $this->request, $this->vendor);
    $started = workOrderFor($this->pm, $this->request, $this->vendor);
    $assigned = workOrderFor($this->pm, $this->request, $this->vendor);
    ($this->markCompleted)($done);
    ($this->change)($started, 'in_progress');

    expect(fn () => app(CancelMaintenanceRequest::class)->handle($this->pm, $this->request, ' '))->toThrow(ValidationException::class);
    app(CancelMaintenanceRequest::class)->handle($this->pm, $this->request, 'Duplicate of MR-2026-000002');

    $request = $this->request->fresh();
    expect($request->status)->toBe(MaintenanceStatus::Cancelled)
        ->and($request->cancel_reason)->toBe('Duplicate of MR-2026-000002')
        ->and($done->fresh()->status)->toBe(WorkOrderStatus::Completed)
        ->and($started->fresh()->status)->toBe(WorkOrderStatus::Cancelled)
        ->and($assigned->fresh()->cancel_reason)->toBe('Duplicate of MR-2026-000002')
        ->and($request->events()->get()->last()->describe())->toBe('In progress → Cancelled · Duplicate of MR-2026-000002');
});

test('a closed request takes no work order and no change, but still takes notes', function () {
    app(CancelMaintenanceRequest::class)->handle($this->pm, $this->request, 'Logged twice');

    expect(fn () => workOrderFor($this->pm, $this->request, $this->vendor))->toThrow(ValidationException::class, 'This request is closed.')
        ->and(fn () => app(CancelMaintenanceRequest::class)->handle($this->pm, $this->request, 'Again'))->toThrow(ValidationException::class)
        ->and(fn () => app(ResolveMaintenanceRequest::class)->handle($this->pm, $this->request))->toThrow(ValidationException::class);

    $note = app(AddMaintenanceNote::class)->handle($this->pm, $this->request, 'Tenant called again; told them it is a duplicate.');
    expect($note->kind)->toBe('note')->and($note->describe())->toBe('Tenant called again; told them it is a duplicate.');
});

test('notes are 1 to 1000 characters', function (string $note) {
    expect(fn () => app(AddMaintenanceNote::class)->handle($this->pm, $this->request, $note))->toThrow(ValidationException::class);
})->with(['empty' => [''], 'spaces' => ['   '], 'too long' => [str_repeat('x', 1001)]]);

test('Leasing and users outside the building cannot change anything', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->vendor);
    $other = Building::factory()->create();

    foreach ([matrixUser(R::Leasing, $this->building), matrixUser(R::Management, $this->building)] as $user) {
        expect(fn () => app(ChangeWorkOrderStatus::class)->handle($user, $wo, 'in_progress'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ResolveMaintenanceRequest::class)->handle($user, $this->request))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CancelMaintenanceRequest::class)->handle($user, $this->request, 'x'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(AddMaintenanceNote::class)->handle($user, $this->request, 'x'))->toThrow(AuthorizationException::class);
    }
    $outsider = User::factory()->create()->givePermissionTo(['maintenance.view', 'maintenance.manage']);
    $outsider->buildings()->attach($other->id);
    expect(fn () => app(ChangeWorkOrderStatus::class)->handle($outsider, $wo, 'in_progress'))->toThrow(AuthorizationException::class)
        ->and($wo->fresh()->status)->toBe(WorkOrderStatus::Assigned);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/LifecycleTest.php`
Expected: FAIL with `Class "App\Actions\Maintenance\ChangeWorkOrderStatus" not found`.

- [ ] **Step 3: Write the actions.** Create `app/Actions/Maintenance/ResolveMaintenanceRequest.php`:

```php
<?php

namespace App\Actions\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 5: a request resolves itself when its work is done, or staff resolve it (ruling 4). */
final class ResolveMaintenanceRequest
{
    public function handle(User $actor, MaintenanceRequest $request): MaintenanceRequest
    {
        if (! $actor->can('update', $request)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $request) {
            $locked = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status->isClosed()) {
                throw ValidationException::withMessages(['request' => __('This request is already closed.')]);
            }
            if ($locked->workOrders()->whereIn('status', [WorkOrderStatus::Assigned, WorkOrderStatus::InProgress])->exists()) {
                throw ValidationException::withMessages(['request' => __('Complete or cancel its open work orders first.')]);
            }
            $this->resolve($actor, $locked);

            return $locked;
        });
    }

    /** Called inside a transaction with the request row locked, after one of its work orders closed. */
    public function ifDone(User $actor, MaintenanceRequest $locked): void
    {
        $statuses = $locked->workOrders()->toBase()->pluck('status')->all();
        $closed = array_diff($statuses, [WorkOrderStatus::Completed->value, WorkOrderStatus::Cancelled->value]) === [];

        if (! $locked->status->isClosed() && $statuses !== [] && $closed && in_array(WorkOrderStatus::Completed->value, $statuses, true)) {
            $this->resolve($actor, $locked);
        }
    }

    private function resolve(User $actor, MaintenanceRequest $locked): void
    {
        $from = $locked->status;
        $locked->forceFill(['status' => MaintenanceStatus::Resolved, 'resolved_at' => now()])->save();
        MaintenanceEvent::record($locked, $actor, from: $from->value, to: MaintenanceStatus::Resolved->value);
    }
}
```

Create `app/Actions/Maintenance/ChangeWorkOrderStatus.php`:

```php
<?php

namespace App\Actions\Maintenance;

use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 3: start a work order, or cancel it with a reason. Completing is CompleteWorkOrder. */
final class ChangeWorkOrderStatus
{
    public function __construct(private ResolveMaintenanceRequest $resolve) {}

    public function handle(User $actor, WorkOrder $workOrder, string $to, ?string $reason = null): WorkOrder
    {
        if (! $actor->can('update', $workOrder)) {
            throw new AuthorizationException;
        }

        $target = WorkOrderStatus::tryFrom($to);
        if ($target !== WorkOrderStatus::InProgress && $target !== WorkOrderStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => __('A work order can only be started or cancelled here.')]);
        }
        $reason = trim((string) $reason);
        if ($target === WorkOrderStatus::Cancelled && ($reason === '' || mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['reason' => __('Give a reason for cancelling, up to 500 characters.')]);
        }

        return DB::transaction(function () use ($actor, $workOrder, $target, $reason) {
            $request = MaintenanceRequest::query()->lockForUpdate()->findOrFail($workOrder->maintenance_request_id);
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $from = $locked->status;
            $allowed = $target === WorkOrderStatus::InProgress ? $from === WorkOrderStatus::Assigned : $from->isOpen();
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => __('This work order is :status.', ['status' => mb_strtolower($from->label())])]);
            }

            $cancelled = $target === WorkOrderStatus::Cancelled;
            $locked->forceFill(['status' => $target, 'cancel_reason' => $cancelled ? $reason : null])->save();
            MaintenanceEvent::record($request, $actor, $locked, $from->value, $target->value, $cancelled ? $reason : null);
            $this->resolve->ifDone($actor, $request);

            return $locked;
        });
    }
}
```

Create `app/Actions/Maintenance/CancelMaintenanceRequest.php`:

```php
<?php

namespace App\Actions\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 5: cancel with a reason; its open work orders are cancelled with it. */
final class CancelMaintenanceRequest
{
    public function handle(User $actor, MaintenanceRequest $request, string $reason): MaintenanceRequest
    {
        if (! $actor->can('update', $request)) {
            throw new AuthorizationException;
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason for cancelling, up to 500 characters.')]);
        }

        return DB::transaction(function () use ($actor, $request, $reason) {
            $locked = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status->isClosed()) {
                throw ValidationException::withMessages(['reason' => __('This request is already closed.')]);
            }

            $open = WorkOrder::query()->where('maintenance_request_id', $locked->id)
                ->whereIn('status', [WorkOrderStatus::Assigned, WorkOrderStatus::InProgress])->orderBy('id')->lockForUpdate()->get();
            foreach ($open as $workOrder) {
                $from = $workOrder->status;
                $workOrder->forceFill(['status' => WorkOrderStatus::Cancelled, 'cancel_reason' => $reason])->save();
                MaintenanceEvent::record($locked, $actor, $workOrder, $from->value, WorkOrderStatus::Cancelled->value, $reason);
            }

            $from = $locked->status;
            $locked->forceFill(['status' => MaintenanceStatus::Cancelled, 'cancel_reason' => $reason])->save();
            MaintenanceEvent::record($locked, $actor, null, $from->value, MaintenanceStatus::Cancelled->value, $reason);

            return $locked;
        });
    }
}
```

Create `app/Actions/Maintenance/AddMaintenanceNote.php`:

```php
<?php

namespace App\Actions\Maintenance;

use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §5: "timeline with add-note"; allowed on closed requests too (plan ruling 4). */
final class AddMaintenanceNote
{
    public function handle(User $actor, MaintenanceRequest $request, string $note): MaintenanceEvent
    {
        if (! $actor->can('update', $request)) {
            throw new AuthorizationException;
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => __('Write a note of up to 1,000 characters.')]);
        }

        return MaintenanceEvent::record($request, $actor, note: $note);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Maintenance`
Expected: PASS.

- [ ] **Step 5: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`.
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Maintenance/ChangeWorkOrderStatus.php app/Actions/Maintenance/ResolveMaintenanceRequest.php \
  app/Actions/Maintenance/CancelMaintenanceRequest.php app/Actions/Maintenance/AddMaintenanceNote.php tests/Feature/Maintenance/LifecycleTest.php
git commit -m "$(cat <<'EOF'
Maintenance progress: start and cancel work orders, auto and manual resolve, cancel with cascade, notes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Completion with cost: `CompleteWorkOrder` calling `RecordExpense` in one transaction

**Files:**
- Create: `app/Actions/Maintenance/CompleteWorkOrder.php`
- Test: `tests/Feature/Maintenance/CompletionTest.php`

**Interfaces:**
- Consumes:
  - `RecordExpense::handle(User $actor, array $data, ?UploadedFile $ownerApproval = null): Expense`. Keys: `building_id`, `unit_id`, `category`, `description`, `expense_date`, `net`, `tax_amount`, `charge_to`, `owner_approval_note`. It opens its own `DB::transaction` and throws `ValidationException` or `AuthorizationException`.
  - `ResolveMaintenanceRequest::ifDone(User, MaintenanceRequest $locked): void`.
  - `Trade::expenseCategory()`, `MaintenanceRequest::summary()`, `MaintenanceEvent::record()`.
  - `App\Billing\Tax::amount(int $netFils, TaxCategory, bool $vatRegistered, string $rate): int`, `CompanySetting::current()`.
- Produces:
  - `CompleteWorkOrder::handle(User $actor, WorkOrder $workOrder, array $data, ?UploadedFile $ownerApproval = null): WorkOrder`.
    - Keys: `completion_note`, `completed_on` (Y-m-d), `actual_net`, `actual_tax`. With a cost, also `charge_to`, `category`, `description`, `owner_approval_note`.
    - Error keys are those names, plus `owner_approval` for the file.
    - It throws `AuthorizationException('Recording the cost needs expenses.manage.')` when there is a cost and the actor lacks `expenses.manage`.
  - `CompleteWorkOrder::defaultVat(mixed $net): string`: '0.000' unless VAT-registered and `$net` is a valid amount.
  - `CompleteWorkOrder::costFils(mixed $net, mixed $tax): int`: 0 for an invalid amount.
  - `CompleteWorkOrder::expenseDescription(WorkOrder $workOrder): string`: "WO-… · vendor · request summary", at most 500 characters.

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/CompletionTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Enums\ChargeTo;
use App\Enums\DocumentCategory;
use App\Enums\ExpenseCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MaintenanceEvent;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101', 'default_tax_category' => 'standard']);
    $this->tenant = Customer::factory()->create();
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->contract = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01',
        'end_date' => '2026-12-31', 'expense_approval_limit' => '200.000'], [$this->unit]);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->request = maintenanceRequest($this->pm, $this->unit);
    $this->workOrder = workOrderFor($this->pm, $this->request, maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]));
    $this->done = fn (array $over = []) => ['completion_note' => 'Trap replaced', 'completed_on' => '2026-10-17', 'actual_net' => '50', 'actual_tax' => '5',
        'charge_to' => 'company', 'category' => 'maintenance', 'description' => CompleteWorkOrder::expenseDescription($this->workOrder), ...$over];
    $this->complete = fn (array $over = [], ?UploadedFile $file = null) => app(CompleteWorkOrder::class)->handle($this->pm, $this->workOrder, ($this->done)($over), $file);
});

test('with a cost, RecordExpense records the expense, the work order links it, and the request resolves', function () {
    $wo = ($this->complete)();

    $expense = Expense::query()->sole();
    expect($wo->status)->toBe(WorkOrderStatus::Completed)
        ->and($wo->completed_on->format('d/m/Y'))->toBe('17/10/2026')
        ->and($wo->completion_note)->toBe('Trap replaced')
        ->and([$wo->actual_net, $wo->actual_tax])->toBe(['50.000', '5.000'])
        ->and($wo->expense_id)->toBe($expense->id)
        ->and($expense->description)->toBe('WO-2026-000001 · Ali Plumbing · Kitchen sink leaking')
        ->and([$expense->building_id, $expense->unit_id])->toBe([$this->building->id, $this->unit->id])
        ->and($expense->category)->toBe(ExpenseCategory::Maintenance)
        ->and($expense->expense_date->toDateString())->toBe('2026-10-17')
        ->and([$expense->net, $expense->tax_amount, $expense->total])->toBe(['50.000', '5.000', '55.000'])
        ->and($expense->charge_to)->toBe(ChargeTo::Company)
        ->and($expense->recorded_by)->toBe($this->pm->id)
        ->and($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved)
        ->and(MaintenanceEvent::query()->where('work_order_id', $wo->id)->orderByDesc('id')->first()->describe())->toBe('WO-2026-000001: Assigned → Completed');
});

test('charged to the owner: above the contract limit RecordExpense refuses, and nothing at all is completed', function () {
    $big = ['charge_to' => 'owner', 'actual_net' => '250', 'actual_tax' => '0'];

    try {
        ($this->complete)($big);
        $this->fail('Accepted above the owner limit without approval.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('owner_approval_note');
    }
    expect(Expense::count())->toBe(0)
        ->and($this->workOrder->fresh()->status)->toBe(WorkOrderStatus::Assigned)
        ->and($this->workOrder->fresh()->expense_id)->toBeNull()
        ->and($this->request->fresh()->status)->toBe(MaintenanceStatus::InProgress)
        ->and(MaintenanceEvent::query()->where('to_status', 'completed')->exists())->toBeFalse();

    $wo = ($this->complete)([...$big, 'owner_approval_note' => 'Owner agreed by phone'], UploadedFile::fake()->create('owner-ok.pdf', 20, 'application/pdf'));

    expect($wo->expense->owner_contract_id)->toBe($this->contract->id)
        ->and($wo->expense->total)->toBe('250.000')
        ->and(Document::query()->where('documentable_type', Expense::class)->where('documentable_id', $wo->expense_id)->sole()->category)->toBe(DocumentCategory::OwnerApproval);
});

test('charged to the tenant: refused without invoices.manage, then RecordExpense invoices the unit\'s tenant', function () {
    expect(fn () => ($this->complete)(['charge_to' => 'tenant']))->toThrow(AuthorizationException::class)
        ->and($this->workOrder->fresh()->status)->toBe(WorkOrderStatus::Assigned)
        ->and(Invoice::count())->toBe(0);

    $this->pm->givePermissionTo('invoices.manage'); // no default role has both maintenance.manage and invoices.manage (plan ruling 7)
    $wo = ($this->complete)(['charge_to' => 'tenant']);

    expect($wo->expense->invoice->customer_id)->toBe($this->tenant->id)
        ->and($wo->expense->invoice->status->value)->toBe('issued')
        ->and($wo->expense->invoice->lines->sole()->net)->toBe('50.000');
});

test('a common-area request cannot charge a tenant: the message points at the payer', function () {
    $this->pm->givePermissionTo('invoices.manage');
    $common = maintenanceRequest($this->pm, $this->unit, ['unit_id' => null, 'customer_id' => null]);
    $wo = workOrderFor($this->pm, $common, maintenanceVendor());

    try {
        app(CompleteWorkOrder::class)->handle($this->pm, $wo, ($this->done)(['charge_to' => 'tenant']));
        $this->fail('A tenant was charged for a common area.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charge_to');
    }
});

test('with no cost (warranty, own staff) there is no expense; Admin, without expenses.manage, can only complete that way', function () {
    $admin = matrixUser(R::Admin, $this->building);
    expect(fn () => app(CompleteWorkOrder::class)->handle($admin, $this->workOrder, ($this->done)()))
        ->toThrow(AuthorizationException::class, 'Recording the cost needs expenses.manage.');

    $wo = app(CompleteWorkOrder::class)->handle($admin, $this->workOrder, ($this->done)(['actual_net' => '', 'actual_tax' => '0']));

    expect($wo->status)->toBe(WorkOrderStatus::Completed)
        ->and([$wo->expense_id, $wo->actual_net, $wo->actual_tax])->toBe([null, null, null])
        ->and(Expense::count())->toBe(0)
        ->and($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved);
});

test('the request resolves only when its last open work order is done, and a completed work order cannot be completed again', function () {
    $second = workOrderFor($this->pm, $this->request, maintenanceVendor());
    ($this->complete)();
    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::InProgress)
        ->and(fn () => ($this->complete)())->toThrow(ValidationException::class, 'This work order is completed.');

    app(CompleteWorkOrder::class)->handle($this->pm, $second, ($this->done)(['actual_net' => '0', 'actual_tax' => '0']));
    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved)->and(Expense::count())->toBe(1);
});

test('an expense links to one work order only', function () {
    $wo = ($this->complete)();
    $other = workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unit), maintenanceVendor());

    expect(fn () => $other->forceFill(['status' => 'completed', 'completed_on' => '2026-10-17', 'expense_id' => $wo->expense_id])->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('completion input is checked, and a refused completion changes nothing', function (array $change, string $error) {
    try {
        ($this->complete)($change);
        $this->fail('The completion was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($error);
    }
    expect(Expense::count())->toBe(0)->and($this->workOrder->fresh()->status)->toBe(WorkOrderStatus::Assigned);
})->with([
    'no completion note' => [['completion_note' => ''], 'completion_note'],
    'note too long' => [['completion_note' => str_repeat('x', 2001)], 'completion_note'],
    'completed tomorrow' => [['completed_on' => '2026-10-18'], 'completed_on'],
    'completed before the request was logged' => [['completed_on' => '2026-10-16'], 'completed_on'],
    'date not Y-m-d' => [['completed_on' => '17/10/2026'], 'completed_on'],
    'date as an array' => [['completed_on' => ['2026-10-17']], 'completed_on'],
    'negative cost' => [['actual_net' => '-1'], 'actual_net'],
    'four decimals' => [['actual_tax' => '0.0001'], 'actual_tax'],
    'cost as an array' => [['actual_net' => ['50']], 'actual_net'],
    'no payer' => [['charge_to' => null], 'charge_to'],
    'unknown payer' => [['charge_to' => 'vendor'], 'charge_to'],
    'unknown category' => [['category' => 'food'], 'category'],
    'no description' => [['description' => ''], 'description'],
]);

test('VAT defaults from the company rate when VAT-registered, rounded to the fil', function () {
    expect(CompleteWorkOrder::defaultVat('50'))->toBe('5.000')
        ->and(CompleteWorkOrder::defaultVat('0.125'))->toBe('0.013')
        ->and(CompleteWorkOrder::defaultVat('abc'))->toBe('0.000')
        ->and(CompleteWorkOrder::defaultVat(['50']))->toBe('0.000')
        ->and(CompleteWorkOrder::costFils('50', '5.5'))->toBe(55500)
        ->and(CompleteWorkOrder::costFils('x', null))->toBe(0);

    CompanySetting::current()->forceFill(['vat_registered' => false])->save();
    expect(CompleteWorkOrder::defaultVat('50'))->toBe('0.000');
});

test('Leasing and users outside the building cannot complete', function () {
    expect(fn () => app(CompleteWorkOrder::class)->handle(matrixUser(R::Leasing, $this->building), $this->workOrder, ($this->done)(['actual_net' => ''])))
        ->toThrow(AuthorizationException::class)
        ->and($this->workOrder->fresh()->status)->toBe(WorkOrderStatus::Assigned);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/CompletionTest.php`
Expected: FAIL with `Class "App\Actions\Maintenance\CompleteWorkOrder" not found`.

- [ ] **Step 3: Write the action** `app/Actions/Maintenance/CompleteWorkOrder.php`:

```php
<?php

namespace App\Actions\Maintenance;

use App\Actions\Expenses\RecordExpense;
use App\Billing\Tax;
use App\Enums\TaxCategory;
use App\Enums\WorkOrderStatus;
use App\Models\CompanySetting;
use App\Models\Expense;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-17 maintenance §4 step 4. A cost is recorded by RecordExpense inside this transaction, so every expense
 * rule applies and a refusal leaves the work order not completed (plan ruling 5). No cost: no expense.
 */
final class CompleteWorkOrder
{
    /** RecordExpense's field => the completion form's field, so a refusal points at what the user typed. */
    private const array ERROR_KEYS = [
        'net' => 'actual_net', 'tax_amount' => 'actual_tax', 'expense_date' => 'completed_on', 'unit_id' => 'charge_to', 'building_id' => 'charge_to',
    ];

    public function __construct(private RecordExpense $record, private ResolveMaintenanceRequest $resolve) {}

    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, WorkOrder $workOrder, array $data, ?UploadedFile $ownerApproval = null): WorkOrder
    {
        if (! $actor->can('update', $workOrder)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data);
        $v = Validator::make($data, [
            'completion_note' => ['required', 'string', 'max:2000'],
            'completed_on' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'actual_net' => ['nullable', Fils::rule()],
            'actual_tax' => ['nullable', Fils::rule()],
        ])->validate();

        if ($v['completed_on'] < $workOrder->maintenanceRequest->created_at->timezone('Asia/Bahrain')->toDateString()) {
            throw ValidationException::withMessages(['completed_on' => __('The work cannot be completed before the request was logged.')]);
        }

        $net = Fils::fromDecimal((string) ($v['actual_net'] ?? '0'));
        $tax = Fils::fromDecimal((string) ($v['actual_tax'] ?? '0'));
        $hasCost = $net + $tax > 0;
        if ($hasCost && ! $actor->can('create', Expense::class)) {
            throw new AuthorizationException(__('Recording the cost needs expenses.manage.'));
        }

        return DB::transaction(function () use ($actor, $workOrder, $data, $v, $net, $tax, $hasCost, $ownerApproval) {
            $request = MaintenanceRequest::query()->lockForUpdate()->findOrFail($workOrder->maintenance_request_id);
            $locked = WorkOrder::query()->with('vendor')->lockForUpdate()->findOrFail($workOrder->id);
            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['completion_note' => __('This work order is :status.', ['status' => mb_strtolower($locked->status->label())])]);
            }

            $expense = $hasCost ? $this->expense($actor, $request, $data, (string) $v['completed_on'], $net, $tax, $ownerApproval) : null;

            $from = $locked->status;
            $locked->forceFill([
                'status' => WorkOrderStatus::Completed,
                'completed_on' => $v['completed_on'],
                'completion_note' => $v['completion_note'],
                'actual_net' => $hasCost ? Fils::toDecimal($net) : null,
                'actual_tax' => $hasCost ? Fils::toDecimal($tax) : null,
                'expense_id' => $expense?->id,
            ])->save();
            MaintenanceEvent::record($request, $actor, $locked, $from->value, WorkOrderStatus::Completed->value);
            $this->resolve->ifDone($actor, $request);

            return $locked;
        });
    }

    /**
     * Plan ruling 6: building, unit, date, net and VAT from the work order; category, description and payer from the form.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function expense(User $actor, MaintenanceRequest $request, array $data, string $date, int $net, int $tax, ?UploadedFile $ownerApproval): Expense
    {
        try {
            return $this->record->handle($actor, [
                'building_id' => $request->building_id,
                'unit_id' => $request->unit_id,
                'category' => $data['category'] ?? null,
                'description' => $data['description'] ?? null,
                'expense_date' => $date,
                'net' => Fils::toDecimal($net),
                'tax_amount' => Fils::toDecimal($tax),
                'charge_to' => $data['charge_to'] ?? null,
                'owner_approval_note' => $data['owner_approval_note'] ?? null,
            ], $ownerApproval);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $m, string $k) => [self::ERROR_KEYS[$k] ?? $k => $m])->all());
        }
    }

    /** Spec §4 step 4: VAT at the company rate when VAT-registered; '0.000' otherwise, or while $net is not a valid amount. */
    public static function defaultVat(mixed $net): string
    {
        $settings = CompanySetting::current();

        return Fils::toDecimal(Tax::amount(self::fils($net), TaxCategory::Standard, $settings->vat_registered, (string) $settings->vat_rate));
    }

    /** Net + VAT in fils, counting an amount that is not valid yet as 0. */
    public static function costFils(mixed $net, mixed $tax): int
    {
        return self::fils($net) + self::fils($tax);
    }

    /** "WO-2026-000001 · Ali Plumbing · Kitchen sink leaking" (spec §4 step 4), within the expense's 500 characters. */
    public static function expenseDescription(WorkOrder $workOrder): string
    {
        return Str::limit($workOrder->number.' · '.$workOrder->vendor->name.' · '.$workOrder->maintenanceRequest->summary(), 500, '');
    }

    private static function fils(mixed $amount): int
    {
        return is_string($amount) && preg_match(Str::after(Fils::rule(), 'regex:'), trim($amount)) === 1 ? Fils::fromDecimal(trim($amount)) : 0;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Maintenance tests/Feature/Expenses tests/Feature/Integrity`
Expected: PASS. The expense tests are unchanged and the trigger count is still 61.

- [ ] **Step 5: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`.
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Maintenance/CompleteWorkOrder.php tests/Feature/Maintenance/CompletionTest.php
git commit -m "$(cat <<'EOF'
Work order completion: the cost goes through RecordExpense in the same transaction, expense_id linked, VAT default, refusal rolls back

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: The request page: details, photos, work orders, timeline and notes, resolve and cancel, linked expenses

**Files:**
- Create: `app/Livewire/Maintenance/RequestShow.php`, `resources/views/livewire/maintenance/request-show.blade.php`
- Modify: `app/Livewire/Documents/Panel.php`, `resources/views/components/status-badge.blade.php`, `routes/property.php`
- Test: `tests/Feature/Maintenance/RequestPageTest.php`

**Interfaces:**
- Consumes:
  - From Task 2: `AddWorkOrder::handle()`, `Vendor::availableFor()`.
  - From Task 3: `ChangeWorkOrderStatus::handle()`, `ResolveMaintenanceRequest::handle()`, `CancelMaintenanceRequest::handle()`, `AddMaintenanceNote::handle()`.
  - Also: `MaintenanceEvent::describe()`, `WorkOrder::isOverdue()`, `MaintenancePriority::color()`, `ExpensePolicy::viewAny`.
- Produces:
  - Route `maintenance.requests.show` (`maintenance/requests/{maintenanceRequest}`), served by `App\Livewire\Maintenance\RequestShow`.
  - `RequestShow` public state:
    - `#[Locked] int $requestId`.
    - `mixed $workOrder` (`vendor_id`, `scheduled_on`, `instructions`, `estimate`).
    - `mixed $note`, `mixed $cancelling` (`work_order_id`, `reason`), `mixed $cancelReason`.
  - `RequestShow` public actions: `addWorkOrder()`, `startWorkOrder(mixed $workOrderId)`, `openCancel(mixed $workOrderId)`, `cancelWorkOrder()`, `addNote()`, `resolve()`, `cancelRequest()`.
  - Error keys: `workOrder.*`, `workOrders`, `cancelling.reason`, `note`, `request`, `cancelReason`.
  - `RequestShow` private helpers, which Task 6 reuses: `request(): MaintenanceRequest` and `workOrderOf(mixed $id, string $field): WorkOrder`.
  - `Documents\Panel` accepts `MaintenanceRequest` and `WorkOrder`, and defaults `category` to `maintenance` on them.
  - `<x-status-badge>` colours `open` amber, `in_progress` and `assigned` blue, `resolved` green.

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/RequestPageTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Livewire\Documents\Panel;
use App\Livewire\Maintenance\RequestShow;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->plumber = maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]);
    $this->request = maintenanceRequest($this->pm, $this->unit);
    $this->page = fn (User $as) => Livewire::actingAs($as)->test(RequestShow::class, ['maintenanceRequest' => $this->request]);
});

test('the page opens for maintenance.view in the request\'s building only', function () {
    foreach ([R::Admin, R::Management, R::PropertyManager, R::Leasing, R::VendorSupport] as $role) {
        $this->actingAs(matrixUser($role, $this->building))->get(route('maintenance.requests.show', $this->request))
            ->assertOk()->assertSee('MR-2026-000001')->assertSee('Sara Tenant')->assertSee('Kitchen sink leaking');
    }
    $this->actingAs(matrixUser(R::Finance, $this->building))->get(route('maintenance.requests.show', $this->request))->assertForbidden();

    $elsewhere = User::factory()->create()->givePermissionTo('maintenance.view');
    $elsewhere->buildings()->attach(Building::factory()->create()->id);
    $this->actingAs($elsewhere)->get(route('maintenance.requests.show', $this->request))->assertForbidden();
});

test('a property manager adds a work order; only active vendors doing the trade, or general work, are offered', function () {
    maintenanceVendor(['name' => 'Gulf Cool', 'trades' => ['ac']]);
    maintenanceVendor(['name' => 'Retired Plumber', 'trades' => ['plumbing'], 'active' => false]);
    maintenanceVendor(['name' => 'Our Handyman', 'trades' => ['general'], 'type' => 'own_staff']);

    $page = ($this->page)($this->pm)
        ->assertSee('Ali Plumbing')->assertSee('Our Handyman')->assertDontSee('Gulf Cool')->assertDontSee('Retired Plumber')
        ->set('workOrder.vendor_id', (string) $this->plumber->id)->set('workOrder.scheduled_on', '2026-10-19')->set('workOrder.estimate', '30')
        ->call('addWorkOrder')->assertHasNoErrors();

    expect($this->request->workOrders()->sole()->estimate)->toBe('30.000');
    $page->assertSee('WO-2026-000001')->assertSee('19/10/2026')->assertSee('In progress');
});

test('start, cancel with a reason, a note and resolve, each on the timeline', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->plumber);
    $page = ($this->page)($this->pm);

    $page->call('startWorkOrder', $wo->id)->assertHasNoErrors();
    expect($wo->fresh()->status)->toBe(WorkOrderStatus::InProgress);

    $page->call('openCancel', $wo->id)->call('cancelWorkOrder')->assertHasErrors('cancelling.reason');
    $page->set('cancelling.reason', 'Vendor unavailable')->call('cancelWorkOrder')->assertHasNoErrors();
    expect($wo->fresh()->status)->toBe(WorkOrderStatus::Cancelled);

    $page->set('note', 'Called the tenant to rebook.')->call('addNote')->assertHasNoErrors()
        ->assertSee('Called the tenant to rebook.')->assertSee($wo->number.': In progress → Cancelled · Vendor unavailable');

    $page->call('resolve')->assertHasNoErrors()->assertSee('In progress → Resolved')->assertDontSee('Add work order');
    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved);
});

test('resolving with an open work order is a message; cancelling the request needs a reason', function () {
    workOrderFor($this->pm, $this->request, $this->plumber);
    $page = ($this->page)($this->pm);

    $page->call('resolve')->assertHasErrors('request');
    $page->call('cancelRequest')->assertHasErrors('cancelReason');
    $page->set('cancelReason', 'Duplicate')->call('cancelRequest')->assertHasNoErrors()->assertSee('Duplicate');
    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::Cancelled);
});

test('Leasing sees the page without any action, and a call from the browser is refused', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->plumber);

    ($this->page)(matrixUser(R::Leasing, $this->building))
        ->assertSee($wo->number)->assertDontSee('Add work order')->assertDontSee('Add note')->assertDontSee('Cancel request')
        ->call('startWorkOrder', $wo->id)->assertForbidden();
    expect($wo->fresh()->status)->toBe(WorkOrderStatus::Assigned);
});

test('tampered input is a message, never a 500', function (Closure $tamper, string $error) {
    $this->otherWo = workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unit), $this->plumber);

    $tamper->call($this, ($this->page)($this->pm))->assertOk()->assertHasErrors($error);
    expect($this->otherWo->fresh()->status)->toBe(WorkOrderStatus::Assigned);
})->with([
    'start a work order of another request' => [fn ($page) => $page->call('startWorkOrder', $this->otherWo->id), 'workOrders'],
    'a work order id as text' => [fn ($page) => $page->call('startWorkOrder', 'abc'), 'workOrders'],
    'a work order id as an array' => [fn ($page) => $page->call('openCancel', ['1']), 'workOrders'],
    'the cancel form as text' => [fn ($page) => $page->set('cancelling', 'x')->call('cancelWorkOrder'), 'cancelling.reason'],
    'cancel another request\'s work order' => [fn ($page) => $page->set('cancelling', ['work_order_id' => $this->otherWo->id, 'reason' => 'x'])->call('cancelWorkOrder'), 'cancelling.reason'],
    'the work order form as text' => [fn ($page) => $page->set('workOrder', 'x')->call('addWorkOrder'), 'workOrder.vendor_id'],
    'an inactive vendor' => [fn ($page) => $page->set('workOrder.vendor_id', (string) maintenanceVendor(['trades' => ['plumbing'], 'active' => false])->id)->call('addWorkOrder'), 'workOrder.vendor_id'],
    'a note as an array' => [fn ($page) => $page->set('note', ['x'])->call('addNote'), 'note'],
    'a cancel reason as an array' => [fn ($page) => $page->set('cancelReason', ['x'])->call('cancelRequest'), 'cancelReason'],
]);

test('photos: maintenance photos on the request and on a work order; Leasing only sees them', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->plumber);

    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $this->request])->assertSet('category', 'maintenance')
        ->set('upload', UploadedFile::fake()->image('leak.jpg'))->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $wo])
        ->set('upload', UploadedFile::fake()->image('fixed.jpg'))->call('save')->assertHasNoErrors();

    expect($this->request->documents()->sole()->category)->toBe(DocumentCategory::Maintenance)
        ->and($wo->documents()->sole()->original_name)->toBe('fixed.jpg');
    Livewire::actingAs(matrixUser(R::Leasing, $this->building))->test(Panel::class, ['documentable' => $this->request])
        ->assertSee('leak.jpg')->assertDontSee('Upload');
});

test('linked expenses and their total show to those who may see expenses', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->plumber);
    app(CompleteWorkOrder::class)->handle($this->pm, $wo, ['completion_note' => 'Done', 'completed_on' => '2026-10-17', 'actual_net' => '40',
        'actual_tax' => '0', 'charge_to' => 'company', 'category' => 'maintenance', 'description' => 'Sink repair']);

    ($this->page)($this->pm)->assertSee('Sink repair')->assertSee('Total: 40.000 BHD');
    ($this->page)(matrixUser(R::Leasing, $this->building))->assertDontSee('Total: 40.000 BHD');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/RequestPageTest.php`
Expected: FAIL with `Route [maintenance.requests.show] not defined`.

- [ ] **Step 3: Documents panel and status badge.** In `app/Livewire/Documents/Panel.php` add `use App\Models\MaintenanceRequest;` and `use App\Models\WorkOrder;` to the imports. Replace the `ALLOWED` constant with:

```php
    public const array ALLOWED = [Agreement::class, AgreementUnit::class, Building::class, Cheque::class, Customer::class, Expense::class,
        MaintenanceRequest::class, Owner::class, OwnerContract::class, Payment::class, Unit::class, WorkOrder::class];
```

At the end of `mount()`, after `$this->recordId = …;`, add:

```php
        // Spec 2026-10-17 maintenance §3.2-§3.3: before- and after-photos (plan ruling 10).
        $this->category = in_array($documentable::class, [MaintenanceRequest::class, WorkOrder::class], true) ? DocumentCategory::Maintenance->value : 'other';
```

In `resources/views/components/status-badge.blade.php` replace the three coloured `match` arms with:

```php
        'active', 'paid', 'cleared', 'confirmed', 'available', 'finalised', 'approved', 'completed', 'recorded', 'resolved' => 'green',
        'issued', 'occupied', 'deposited', 'unpaid', 'partially_paid', 'in_progress', 'assigned' => 'blue',
        'pending_approval', 'reserved', 'held', 'notice_given', 'draft', 'open' => 'amber',
```

- [ ] **Step 4: The component** `app/Livewire/Maintenance/RequestShow.php`:

```php
<?php

namespace App\Livewire\Maintenance;

use App\Actions\Maintenance\AddMaintenanceNote;
use App\Actions\Maintenance\AddWorkOrder;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Actions\Maintenance\ChangeWorkOrderStatus;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\ExpenseStatus;
use App\Enums\WorkOrderStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Spec 2026-10-17 maintenance §5: a request with its photos, work orders, timeline and linked expenses. Every action
 * re-checks permission and building scope through its Action; ids from the browser are looked up inside this request.
 */
class RequestShow extends Component
{
    use WithActor;

    private const array NEW_WORK_ORDER = ['vendor_id' => '', 'scheduled_on' => '', 'instructions' => '', 'estimate' => ''];

    #[Locked]
    public int $requestId;

    // Untyped on purpose: a tampered payload reaches the Actions' validation instead of a TypeError (a 500).
    public mixed $workOrder = self::NEW_WORK_ORDER;

    public mixed $note = '';

    public mixed $cancelling = ['work_order_id' => null, 'reason' => ''];

    public mixed $cancelReason = '';

    public function mount(MaintenanceRequest $maintenanceRequest): void
    {
        abort_unless($this->actor()->can('view', $maintenanceRequest), 403);
        $this->requestId = $maintenanceRequest->id;
    }

    private function request(): MaintenanceRequest
    {
        $request = MaintenanceRequest::query()->findOrFail($this->requestId);
        abort_unless($this->actor()->can('view', $request), 403);

        return $request;
    }

    /** A work order of this request, or a validation message on $field: a tampered id is never a 500 (plan ruling 18). */
    private function workOrderOf(mixed $id, string $field): WorkOrder
    {
        $workOrder = is_int($id) || (is_string($id) && ctype_digit($id)) ? $this->request()->workOrders()->find((int) $id) : null;
        if ($workOrder === null) {
            throw ValidationException::withMessages([$field => __('Choose a work order of this request.')]);
        }

        return $workOrder;
    }

    /** Re-throws an Action's messages under the form field that shows them. */
    private static function fail(ValidationException $e, string $field): never
    {
        throw ValidationException::withMessages([$field => Arr::flatten($e->errors())]);
    }

    public function addWorkOrder(AddWorkOrder $add): void
    {
        try {
            $workOrder = $add->handle($this->actor(), $this->request(), is_array($this->workOrder) ? $this->workOrder : []);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["workOrder.{$k}" => $m])->all());
        }
        $this->workOrder = self::NEW_WORK_ORDER;
        Flux::modal('add-work-order')->close();
        Flux::toast(variant: 'success', text: __('Work order :number added.', ['number' => $workOrder->number]));
    }

    public function startWorkOrder(mixed $workOrderId, ChangeWorkOrderStatus $change): void
    {
        try {
            $change->handle($this->actor(), $this->workOrderOf($workOrderId, 'workOrders'), WorkOrderStatus::InProgress->value);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'workOrders');
        }
    }

    public function openCancel(mixed $workOrderId): void
    {
        $this->cancelling = ['work_order_id' => $this->workOrderOf($workOrderId, 'workOrders')->id, 'reason' => ''];
        Flux::modal('cancel-work-order')->show();
    }

    public function cancelWorkOrder(ChangeWorkOrderStatus $change): void
    {
        $input = is_array($this->cancelling) ? $this->cancelling : [];
        try {
            $change->handle($this->actor(), $this->workOrderOf($input['work_order_id'] ?? null, 'cancelling.reason'),
                WorkOrderStatus::Cancelled->value, is_string($input['reason'] ?? null) ? $input['reason'] : '');
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'cancelling.reason');
        }
        Flux::modal('cancel-work-order')->close();
        Flux::toast(variant: 'success', text: __('Work order cancelled.'));
    }

    public function addNote(AddMaintenanceNote $add): void
    {
        try {
            $add->handle($this->actor(), $this->request(), is_string($this->note) ? $this->note : '');
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'note');
        }
        $this->note = '';
    }

    public function resolve(ResolveMaintenanceRequest $resolve): void
    {
        try {
            $resolve->handle($this->actor(), $this->request());
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'request');
        }
        Flux::toast(variant: 'success', text: __('Request resolved.'));
    }

    public function cancelRequest(CancelMaintenanceRequest $cancel): void
    {
        try {
            $cancel->handle($this->actor(), $this->request(), is_string($this->cancelReason) ? $this->cancelReason : '');
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'cancelReason');
        }
        $this->cancelReason = '';
        Flux::modal('cancel-request')->close();
        Flux::toast(variant: 'success', text: __('Request cancelled.'));
    }

    public function render(): View
    {
        $request = MaintenanceRequest::query()
            ->with(['building', 'unit', 'customer', 'creator:id,name', 'workOrders.vendor', 'workOrders.expense', 'events.user:id,name', 'events.workOrder:id,number'])
            ->findOrFail($this->requestId);
        abort_unless($this->actor()->can('view', $request), 403);
        $canManage = $this->actor()->can('update', $request);
        $open = ! $request->status->isClosed();
        $expenses = $request->workOrders->pluck('expense')->filter()->values();

        return view('livewire.maintenance.request-show', [
            'request' => $request,
            'canManage' => $canManage,
            'open' => $open,
            'vendors' => $canManage && $open ? Vendor::query()->availableFor($request->category)->orderBy('name')->get(['id', 'name']) : collect(),
            'canResolve' => $canManage && $open && $request->workOrders->every(fn (WorkOrder $w) => ! $w->status->isOpen()),
            'canSeeExpenses' => $this->actor()->can('viewAny', Expense::class),
            'expenses' => $expenses,
            'expenseTotal' => Fils::toDecimal($expenses->filter(fn (Expense $e) => $e->status === ExpenseStatus::Recorded)->sum(fn (Expense $e) => Fils::fromDecimal($e->total))),
        ])->title($request->number);
    }
}
```

- [ ] **Step 5: The view** `resources/views/livewire/maintenance/request-show.blade.php`:

```blade
<section class="w-full max-w-4xl space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $request->number }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                <x-status-badge :status="$request->status" />
                <flux:badge size="sm" :color="$request->priority->color()">{{ $request->priority->label() }}</flux:badge>
                <flux:text class="text-sm">{{ $request->category->label() }} · {{ $request->place() }}</flux:text>
            </div>
        </div>
        @if ($canManage && $open)
            <div class="flex flex-wrap gap-2">
                <flux:modal.trigger name="add-work-order"><flux:button variant="primary">{{ __('Add work order') }}</flux:button></flux:modal.trigger>
                @if ($canResolve)
                    <flux:button wire:click="resolve" wire:confirm="{{ __('Mark this request resolved?') }}">{{ __('Resolve') }}</flux:button>
                @endif
                <flux:modal.trigger name="cancel-request"><flux:button variant="ghost">{{ __('Cancel request') }}</flux:button></flux:modal.trigger>
            </div>
        @endif
    </div>
    <flux:error name="request" />

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Building / unit') }}</dt><dd>{{ $request->building->code }} / {{ $request->unit?->code ?? __('Common area') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Tenant') }}</dt><dd>{{ $request->customer ? $request->customer->name_en.' · '.$request->customer->mobile : '—' }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Reported by') }}</dt><dd>{{ $request->reported_by_name }} · {{ $request->channel->label() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Logged') }}</dt><dd>{{ $request->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }} · {{ $request->creator->name }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Entry permitted') }}</dt><dd>{{ $request->entry_permitted ? __('Yes') : __('No') }}</dd></div>
        @if ($request->resolved_at)
            <div><dt class="text-sm text-zinc-500">{{ __('Resolved') }}</dt><dd>{{ $request->resolved_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</dd></div>
        @endif
        @if ($request->cancel_reason)
            <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Cancelled') }}</dt><dd>{{ $request->cancel_reason }}</dd></div>
        @endif
        <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Problem') }}</dt><dd class="whitespace-pre-line">{{ $request->description }}</dd></div>
    </dl>

    <livewire:documents.panel :documentable="$request" :key="'docs-request-'.$request->id" />

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Work orders') }}</flux:heading>
        <flux:error name="workOrders" />
        @forelse ($request->workOrders as $wo)
            <div wire:key="wo-{{ $wo->id }}" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ $wo->number }}</span>
                        <x-status-badge :status="$wo->status" />
                        @if ($wo->isOverdue())
                            <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                        @endif
                        <span class="text-sm text-zinc-500">{{ $wo->vendor->name }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($canManage && $wo->status->isOpen())
                            @if ($wo->status->value === 'assigned')
                                <flux:button size="sm" wire:click="startWorkOrder({{ $wo->id }})">{{ __('Start') }}</flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" wire:click="openCancel({{ $wo->id }})">{{ __('Cancel') }}</flux:button>
                        @endif
                    </div>
                </div>
                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-zinc-500">{{ __('Scheduled') }}</dt><dd>{{ $wo->scheduled_on?->format('d/m/Y') ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Estimate (BHD)') }}</dt><dd class="tabular-nums">{{ $wo->estimate ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Actual (BHD)') }}</dt><dd class="tabular-nums">{{ $wo->actual_net !== null ? __(':net + :vat VAT', ['net' => $wo->actual_net, 'vat' => $wo->actual_tax]) : '—' }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Completed') }}</dt><dd>{{ $wo->completed_on?->format('d/m/Y') ?? '—' }}</dd></div>
                    @if ($wo->instructions)
                        <div class="sm:col-span-4"><dt class="text-zinc-500">{{ __('Instructions') }}</dt><dd class="whitespace-pre-line">{{ $wo->instructions }}</dd></div>
                    @endif
                    @if ($wo->completion_note)
                        <div class="sm:col-span-4"><dt class="text-zinc-500">{{ __('Completion note') }}</dt><dd class="whitespace-pre-line">{{ $wo->completion_note }}</dd></div>
                    @endif
                    @if ($wo->cancel_reason)
                        <div class="sm:col-span-4"><dt class="text-zinc-500">{{ __('Cancelled') }}</dt><dd>{{ $wo->cancel_reason }}</dd></div>
                    @endif
                </dl>
                <livewire:documents.panel :documentable="$wo" :key="'docs-wo-'.$wo->id" />
            </div>
        @empty
            <flux:text>{{ __('No work orders yet.') }}</flux:text>
        @endforelse
    </div>

    @if ($canSeeExpenses && $expenses->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Expenses') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($expenses as $expense)
                    <li wire:key="exp-{{ $expense->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <flux:link :href="route('expenses.show', $expense)" wire:navigate>{{ $expense->description }}</flux:link>
                        <span class="flex items-center gap-2 tabular-nums">{{ $expense->total }} <x-status-badge :status="$expense->status" /></span>
                    </li>
                @endforeach
            </ul>
            <flux:text class="text-sm font-medium">{{ __('Total: :amount BHD', ['amount' => $expenseTotal]) }}</flux:text>
        </div>
    @endif

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Timeline') }}</flux:heading>
        <ul class="space-y-2">
            @foreach ($request->events as $event)
                <li wire:key="ev-{{ $event->id }}" class="text-sm">
                    <span class="text-zinc-500">{{ $event->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }} · {{ $event->user->name }}</span>
                    <span class="whitespace-pre-line">{{ $event->describe() }}</span>
                </li>
            @endforeach
        </ul>
        @if ($canManage)
            <form wire:submit="addNote" class="space-y-2">
                <flux:textarea wire:model="note" :label="__('Note')" rows="2" />
                <flux:button type="submit" size="sm">{{ __('Add note') }}</flux:button>
            </form>
        @endif
    </div>

    @if ($canManage && $open)
        <flux:modal name="add-work-order" class="md:w-[32rem]">
            <form wire:submit="addWorkOrder" class="space-y-4">
                <flux:heading size="lg">{{ __('Add work order') }}</flux:heading>
                <flux:select wire:model="workOrder.vendor_id" :label="__('Vendor')">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                </flux:select>
                @if ($vendors->isEmpty())
                    <flux:text class="text-sm">{{ __('No active vendor does this work yet. Add one under Vendors.') }}</flux:text>
                @endif
                <x-date-input wire:model="workOrder.scheduled_on" :label="__('Scheduled for')" />
                <flux:textarea wire:model="workOrder.instructions" :label="__('Instructions for the vendor')" rows="3" />
                <flux:input wire:model="workOrder.estimate" inputmode="decimal" :label="__('Estimate (BHD)')" />
                <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
            </form>
        </flux:modal>

        <flux:modal name="cancel-request" class="md:w-96">
            <form wire:submit="cancelRequest" class="space-y-4">
                <flux:heading size="lg">{{ __('Cancel request') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Its open work orders are cancelled too.') }}</flux:text>
                <flux:textarea wire:model="cancelReason" :label="__('Reason')" rows="2" />
                <flux:button type="submit" variant="danger">{{ __('Cancel request') }}</flux:button>
            </form>
        </flux:modal>
    @endif

    @if ($canManage)
        <flux:modal name="cancel-work-order" class="md:w-96">
            <form wire:submit="cancelWorkOrder" class="space-y-4">
                <flux:heading size="lg">{{ __('Cancel work order') }}</flux:heading>
                <flux:textarea wire:model="cancelling.reason" :label="__('Reason')" rows="2" />
                <flux:button type="submit" variant="danger">{{ __('Cancel work order') }}</flux:button>
            </form>
        </flux:modal>
    @endif
</section>
```

- [ ] **Step 6: Route.** In `routes/property.php`, after the vendor routes:

```php
    Route::livewire('maintenance/requests/{maintenanceRequest}', Maintenance\RequestShow::class)->middleware('can:maintenance.view')->name('maintenance.requests.show');
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Maintenance tests/Feature/Documents`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: no errors; the build succeeds.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Maintenance/RequestShow.php resources/views/livewire/maintenance/request-show.blade.php app/Livewire/Documents/Panel.php \
  resources/views/components/status-badge.blade.php routes/property.php tests/Feature/Maintenance/RequestPageTest.php
git commit -m "$(cat <<'EOF'
Maintenance request page: details, photos, work orders with start and cancel, timeline with notes, resolve and cancel, linked expenses

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: The completion form on the request page, the printable work order sheet, "Copy for WhatsApp"

**Files:**
- Modify: `app/Livewire/Maintenance/RequestShow.php`, `resources/views/livewire/maintenance/request-show.blade.php`
- Modify: `app/Models/WorkOrder.php` (`sheetLines()`, `sheetText()`)
- Create: `app/Http/Controllers/WorkOrderSheetController.php`, `resources/views/maintenance/work-order-sheet.blade.php`
- Modify: `routes/property.php`
- Test: `tests/Feature/Maintenance/CompletionScreenTest.php`

**Interfaces:**
- Consumes:
  - From Task 4: `CompleteWorkOrder::handle()`, `::defaultVat()`, `::costFils()`, `::expenseDescription()`.
  - From Task 5: `RequestShow::request()` and `RequestShow::workOrderOf()`.
  - Also `Trade::expenseCategory()`.
- Produces:
  - `RequestShow` public state:
    - `mixed $completion` (`work_order_id`, `completion_note`, `completed_on`, `actual_net`, `actual_tax`, `charge_to`, `category`, `description`, `owner_approval_note`).
    - `$ownerApproval` (an upload).
  - `RequestShow` public actions: `openCompletion(mixed $workOrderId)`, `complete()`. Error keys: `completion.*`, `ownerApproval`.
  - `WorkOrder::sheetLines(): list<array{0: string, 1: string}>` and `WorkOrder::sheetText(): string`, with no amounts.
  - Route `maintenance.work-orders.sheet` (`maintenance/work-orders/{workOrder}/sheet`).

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/CompletionScreenTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Livewire\Maintenance\RequestShow;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'mobile' => '+973 3900 1111']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'expense_approval_limit' => '200.000'], [$this->unit]);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->plumber = maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]);
    $this->request = maintenanceRequest($this->pm, $this->unit);
    $this->wo = workOrderFor($this->pm, $this->request, $this->plumber, ['scheduled_on' => '2026-10-19', 'instructions' => 'Bring a new trap', 'estimate' => '25.500']);
    $this->page = fn (User $as, ?MaintenanceRequest $request = null) => Livewire::actingAs($as)->test(RequestShow::class, ['maintenanceRequest' => $request ?? $this->request]);
});

test('opening the form prefills the expense from the work order, and VAT follows the net', function () {
    $cleaning = maintenanceRequest($this->pm, $this->unit, ['category' => 'cleaning', 'description' => 'Deep clean after move-out']);
    $wo = workOrderFor($this->pm, $cleaning, maintenanceVendor(['name' => 'Shine Co', 'trades' => ['cleaning']]));

    ($this->page)($this->pm, $cleaning)->call('openCompletion', $wo->id)
        ->assertSet('completion.work_order_id', $wo->id)
        ->assertSet('completion.completed_on', '2026-10-17')
        ->assertSet('completion.category', 'cleaning')
        ->assertSet('completion.description', $wo->number.' · Shine Co · Deep clean after move-out')
        ->assertSet('completion.charge_to', 'company')
        ->set('completion.actual_net', '120')->assertSet('completion.actual_tax', '12.000')
        ->assertSee('Record expense')
        ->assertDontSeeHtml('value="tenant"'); // a property manager has no invoices.manage, so no tenant charge
});

test('completing with a cost from the page records the expense and resolves the request', function () {
    ($this->page)($this->pm)->call('openCompletion', $this->wo->id)
        ->set('completion.completion_note', 'Replaced the trap')->set('completion.actual_net', '50')
        ->call('complete')->assertHasNoErrors()
        ->assertSee('Resolved')->assertSee('50.000 + 5.000 VAT');

    expect($this->wo->fresh()->expense->total)->toBe('55.000')->and($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved);
});

test('above the owner limit the form asks for the approval; with the note and the document it is recorded', function () {
    $page = ($this->page)($this->pm)->call('openCompletion', $this->wo->id)
        ->set('completion.completion_note', 'New water heater')->set('completion.actual_net', '300')->set('completion.charge_to', 'owner')
        ->assertSee('Owner approval document')
        ->call('complete')->assertHasErrors('completion.owner_approval_note');
    expect($this->wo->fresh()->status)->toBe(WorkOrderStatus::Assigned)->and(Expense::count())->toBe(0);

    $page->set('completion.owner_approval_note', 'Owner approved by email')
        ->set('ownerApproval', UploadedFile::fake()->create('approval.pdf', 20, 'application/pdf'))
        ->call('complete')->assertHasNoErrors();
    expect($this->wo->fresh()->expense->owner_contract_id)->not->toBeNull()->and($this->wo->fresh()->expense->total)->toBe('330.000');
});

test('Admin is told a cost needs expenses.manage, and can complete with no cost', function () {
    $page = ($this->page)(matrixUser(R::Admin, $this->building))->call('openCompletion', $this->wo->id)
        ->set('completion.completion_note', 'Done under warranty')->set('completion.actual_net', '50')
        ->assertSee('Recording a cost needs expenses.manage.')
        ->call('complete')->assertHasErrors('completion.charge_to');
    expect($this->wo->fresh()->status)->toBe(WorkOrderStatus::Assigned);

    $page->set('completion.actual_net', '')->set('completion.actual_tax', '0')->call('complete')->assertHasNoErrors();
    expect($this->wo->fresh()->status)->toBe(WorkOrderStatus::Completed)->and($this->wo->fresh()->expense_id)->toBeNull();
});

test('tampered completion input is a message, never a 500', function (Closure $tamper, string $error) {
    $this->otherWo = workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unit), $this->plumber);

    $tamper->call($this, ($this->page)($this->pm))->assertOk()->assertHasErrors($error);
    expect($this->otherWo->fresh()->status)->toBe(WorkOrderStatus::Assigned)->and($this->wo->fresh()->status)->toBe(WorkOrderStatus::Assigned);
})->with([
    'the form as text' => [fn ($page) => $page->set('completion', 'x')->call('complete'), 'completion.completion_note'],
    'another request\'s work order' => [fn ($page) => $page->call('openCompletion', $this->wo->id)->set('completion.work_order_id', $this->otherWo->id)->call('complete'), 'completion.completion_note'],
    'open another request\'s work order' => [fn ($page) => $page->call('openCompletion', $this->otherWo->id), 'workOrders'],
    'a payer as an array' => [fn ($page) => $page->call('openCompletion', $this->wo->id)->set('completion.completion_note', 'x')->set('completion.actual_net', '5')
        ->set('completion.charge_to', ['owner'])->call('complete'), 'completion.charge_to'],
    'a net as an array' => [fn ($page) => $page->call('openCompletion', $this->wo->id)->set('completion.completion_note', 'x')->set('completion.actual_net', ['5'])->call('complete'), 'completion.actual_net'],
]);

test('the work order sheet and the WhatsApp text carry what the vendor needs, and no amounts', function () {
    $this->actingAs($this->pm)->get(route('maintenance.work-orders.sheet', $this->wo))->assertOk()
        ->assertSee($this->wo->number)->assertSee('Seef Tower')->assertSee('101')->assertSee('Sara Tenant · +973 3900 1111')
        ->assertSee('Bring a new trap')->assertSee('19/10/2026')->assertSee('Kitchen sink leaking')->assertSee('Entry permitted')
        ->assertDontSee('25.500')->assertDontSee('BHD');

    expect($this->wo->sheetText())
        ->toContain('Tenant: Sara Tenant · +973 3900 1111')
        ->toContain('Entry permitted: Yes')
        ->toContain('Scheduled: 19/10/2026')
        ->toContain('Instructions: Bring a new trap')
        ->not->toContain('25.5');
    ($this->page)($this->pm)->assertSee('Copy for WhatsApp')->assertSee(route('maintenance.work-orders.sheet', $this->wo));
});

test('the sheet needs maintenance.view in the request\'s building', function () {
    $this->actingAs(matrixUser(R::Leasing, $this->building))->get(route('maintenance.work-orders.sheet', $this->wo))->assertOk();
    $this->actingAs(matrixUser(R::Finance, $this->building))->get(route('maintenance.work-orders.sheet', $this->wo))->assertForbidden();

    $elsewhere = User::factory()->create()->givePermissionTo('maintenance.view');
    $elsewhere->buildings()->attach(Building::factory()->create()->id);
    $this->actingAs($elsewhere)->get(route('maintenance.work-orders.sheet', $this->wo))->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/CompletionScreenTest.php`
Expected: FAIL with `Method App\Livewire\Maintenance\RequestShow::openCompletion does not exist` (or `Route [maintenance.work-orders.sheet] not defined`).

- [ ] **Step 3: The sheet lines.** In `app/Models/WorkOrder.php`, add after `isOverdue()`:

```php
    /**
     * Spec §4 step 2 (plan ruling 12): what the vendor needs, for the printed sheet and "Copy for WhatsApp". It reads no
     * amount column, so neither ever shows one.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function sheetLines(): array
    {
        $request = $this->maintenanceRequest;
        $unit = $request->unit;
        $tenant = $request->customer;

        return array_values(array_filter([
            [__('Work order'), $this->number.' ('.$request->number.')'],
            [__('Vendor'), $this->vendor->name],
            [__('Address'), implode(', ', array_filter([$request->building->name, $request->building->address ?: $request->building->location]))],
            [__('Unit'), $unit ? $unit->code.($unit->floor !== null ? ' · '.__('floor :floor', ['floor' => $unit->floor]) : '') : __('Common area')],
            $tenant ? [__('Tenant'), $tenant->name_en.' · '.$tenant->mobile] : null,
            [__('Entry permitted'), $request->entry_permitted ? __('Yes') : __('No: arrange a time with the tenant')],
            [__('Category'), $request->category->label()],
            [__('Problem'), $request->description],
            [__('Instructions'), $this->instructions ?: '—'],
            [__('Scheduled'), $this->scheduled_on?->format('d/m/Y') ?? __('Not scheduled')],
        ]));
    }

    /** The sheet as plain text, one "Label: value" line each. */
    public function sheetText(): string
    {
        return implode("\n", array_map(fn (array $line) => $line[0].': '.$line[1], $this->sheetLines()));
    }
```

- [ ] **Step 4: The sheet page.** Create `app/Http/Controllers/WorkOrderSheetController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\WorkOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Spec 2026-10-17 maintenance §4 step 2: the printable work order sheet. No amounts (plan ruling 12). */
final class WorkOrderSheetController
{
    public function __invoke(Request $request, WorkOrder $workOrder): View
    {
        abort_unless($request->user()?->can('view', $workOrder) === true, 403);
        $workOrder->load(['vendor', 'maintenanceRequest.building', 'maintenanceRequest.unit', 'maintenanceRequest.customer']);

        return view('maintenance.work-order-sheet', ['workOrder' => $workOrder, 'lines' => $workOrder->sheetLines(), 'company' => CompanySetting::current()]);
    }
}
```

Create `resources/views/maintenance/work-order-sheet.blade.php`:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Work order :number', ['number' => $workOrder->number]) }}</title>
    <style>
        body { margin: 24px; color: #18181b; background: #ffffff; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.5; }
        table { width: 100%; max-width: 720px; border-collapse: collapse; }
        th, td { padding: 8px; border-bottom: 1px solid #e4e4e7; text-align: start; vertical-align: top; }
        th { width: 30%; color: #52525b; font-weight: normal; }
        td { white-space: pre-line; }
        .no-print { margin-bottom: 16px; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="no-print"><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
    <h1 style="margin: 0 0 4px; font-size: 20px;">{{ $company->name_en }}</h1>
    <h2 style="margin: 0 0 16px; font-size: 16px;">{{ __('Work order :number', ['number' => $workOrder->number]) }}</h2>
    <table>
        @foreach ($lines as [$label, $value])
            <tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td></tr>
        @endforeach
    </table>
</body>
</html>
```

In `routes/property.php` add `use App\Http\Controllers\WorkOrderSheetController;` to the imports (alphabetical, after `use App\Http\Controllers\ReceiptPdfController;`), and after the `maintenance.requests.show` route:

```php
    Route::get('maintenance/work-orders/{workOrder}/sheet', WorkOrderSheetController::class)->middleware('can:maintenance.view')->name('maintenance.work-orders.sheet');
```

- [ ] **Step 5: The completion actions.** In `app/Livewire/Maintenance/RequestShow.php`:

Add these imports: `use App\Actions\Maintenance\CompleteWorkOrder;`, `use App\Enums\ExpenseCategory;`, `use App\Enums\PermissionName;`, `use Illuminate\Http\UploadedFile;`, `use Livewire\WithFileUploads;`.

Change `use WithActor;` to `use WithActor, WithFileUploads;`.

Add after `public mixed $cancelReason = '';`:

```php
    public mixed $completion = [];

    /** @var UploadedFile|null */
    public $ownerApproval = null;
```

Add after `cancelRequest()`:

```php
    /** Spec §4 step 4: the completion form, with the expense prefilled from the work order (plan ruling 6). */
    public function openCompletion(mixed $workOrderId): void
    {
        $workOrder = $this->workOrderOf($workOrderId, 'workOrders');
        $this->completion = [
            'work_order_id' => $workOrder->id,
            'completion_note' => '',
            'completed_on' => now('Asia/Bahrain')->toDateString(),
            'actual_net' => '',
            'actual_tax' => '0.000',
            'charge_to' => 'company',
            'category' => $this->request()->category->expenseCategory()->value,
            'description' => CompleteWorkOrder::expenseDescription($workOrder),
            'owner_approval_note' => '',
        ];
        $this->ownerApproval = null;
        $this->resetErrorBag();
        Flux::modal('complete-work-order')->show();
    }

    /** VAT follows the net at the company rate and can still be changed. $key is null when the whole form is replaced. */
    public function updatedCompletion(mixed $value, ?string $key = null): void
    {
        if (! is_array($this->completion)) {
            $this->completion = []; // a tampered payload starts the form again

            return;
        }
        if ($key === 'actual_net') {
            $this->completion['actual_tax'] = CompleteWorkOrder::defaultVat($value);
        }
    }

    public function complete(CompleteWorkOrder $complete): void
    {
        abort_unless($this->actor()->can('update', $this->request()), 403);
        $input = is_array($this->completion) ? $this->completion : [];
        $workOrder = $this->workOrderOf($input['work_order_id'] ?? null, 'completion.completion_note'); // outside the try: its key is final

        try {
            $complete->handle($this->actor(), $workOrder, $input, $this->ownerApproval instanceof UploadedFile ? $this->ownerApproval : null);
        } catch (AuthorizationException) {
            // The user may manage the request, so this is about the cost (plan ruling 7).
            throw ValidationException::withMessages(['completion.charge_to' => __('You cannot record this cost: it needs expenses.manage, and charging the tenant also needs invoices.manage.')]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$k === 'owner_approval' ? 'ownerApproval' : "completion.{$k}" => $m])->all());
        }

        $this->completion = [];
        $this->ownerApproval = null;
        Flux::modal('complete-work-order')->close();
        Flux::toast(variant: 'success', text: __('Work order completed.'));
    }
```

In `render()`, add to the view data:

```php
            'completion' => is_array($this->completion) ? $this->completion : [],
            'hasCost' => is_array($this->completion) && CompleteWorkOrder::costFils($this->completion['actual_net'] ?? null, $this->completion['actual_tax'] ?? null) > 0,
            'canRecordCost' => $this->actor()->can('create', Expense::class),
            'canChargeTenant' => $this->actor()->can(PermissionName::InvoicesManage),
            'expenseCategories' => ExpenseCategory::cases(),
```

- [ ] **Step 6: The view.** In `resources/views/livewire/maintenance/request-show.blade.php`, replace the work order's button block:

```blade
                    <div class="flex flex-wrap gap-2">
                        @if ($canManage && $wo->status->isOpen())
                            @if ($wo->status->value === 'assigned')
                                <flux:button size="sm" wire:click="startWorkOrder({{ $wo->id }})">{{ __('Start') }}</flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" wire:click="openCancel({{ $wo->id }})">{{ __('Cancel') }}</flux:button>
                        @endif
                    </div>
```

with:

```blade
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" variant="ghost" icon="printer" :href="route('maintenance.work-orders.sheet', $wo)" target="_blank">{{ __('Sheet') }}</flux:button>
                        <div x-data="{ text: @js($wo->sheetText()) }">
                            <flux:button size="sm" variant="ghost" icon="clipboard-document" x-on:click="navigator.clipboard.writeText(text); $flux.toast(@js(__('Copied. Paste it into WhatsApp.')))">{{ __('Copy for WhatsApp') }}</flux:button>
                        </div>
                        @if ($canManage && $wo->status->isOpen())
                            @if ($wo->status->value === 'assigned')
                                <flux:button size="sm" wire:click="startWorkOrder({{ $wo->id }})">{{ __('Start') }}</flux:button>
                            @endif
                            <flux:button size="sm" variant="primary" wire:click="openCompletion({{ $wo->id }})">{{ __('Complete') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="openCancel({{ $wo->id }})">{{ __('Cancel') }}</flux:button>
                        @endif
                    </div>
```

Inside the `@if ($canManage)` block at the end, after the `cancel-work-order` modal, add:

```blade
        <flux:modal name="complete-work-order" class="md:w-[36rem]">
            <form wire:submit="complete" class="space-y-4">
                <flux:heading size="lg">{{ __('Complete work order') }}</flux:heading>
                <flux:textarea wire:model="completion.completion_note" :label="__('What was done')" rows="3" />
                <x-date-input wire:model="completion.completed_on" :label="__('Completed on')" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.500ms="completion.actual_net" inputmode="decimal" :label="__('Actual cost, net (BHD)')" />
                    <flux:input wire:model.live.debounce.500ms="completion.actual_tax" inputmode="decimal" :label="__('VAT (BHD)')" />
                </div>
                <flux:text class="text-sm">{{ __('Leave the cost empty for work with no charge, such as warranty work or own staff.') }}</flux:text>

                @if ($hasCost)
                    <flux:fieldset>
                        <flux:legend>{{ __('Record expense') }}</flux:legend>
                        <div class="space-y-4">
                            @unless ($canRecordCost)
                                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Recording a cost needs expenses.manage. Complete it with no cost, or ask a property manager.')" />
                            @endunless
                            <flux:text class="text-sm">{{ __('For :place, dated the completion date.', ['place' => $request->place()]) }}</flux:text>
                            <flux:select wire:model="completion.category" :label="__('Category')">
                                @foreach ($expenseCategories as $c)<option value="{{ $c->value }}">{{ str($c->value)->headline() }}</option>@endforeach
                            </flux:select>
                            <flux:input wire:model="completion.description" :label="__('Description')" />
                            <flux:radio.group wire:model.live="completion.charge_to" :label="__('Charge to')" variant="segmented">
                                <flux:radio value="company" :label="__('Company')" />
                                <flux:radio value="owner" :label="__('Owner')" />
                                @if ($canChargeTenant)
                                    <flux:radio value="tenant" :label="__('Tenant')" />
                                @endif
                            </flux:radio.group>
                            @if (($completion['charge_to'] ?? '') === 'owner')
                                <flux:textarea wire:model="completion.owner_approval_note" :label="__('Owner approval note (needed above the contract limit)')" rows="2" />
                                <flux:field>
                                    <flux:label>{{ __('Owner approval document') }}</flux:label>
                                    <x-file-button wire:model="ownerApproval" />
                                    <flux:error name="ownerApproval" />
                                </flux:field>
                            @endif
                            @if (($completion['charge_to'] ?? '') === 'tenant')
                                <flux:text class="text-sm">{{ __('The unit\'s current tenant is invoiced for the net amount.') }}</flux:text>
                            @endif
                        </div>
                    </flux:fieldset>
                @endif

                <flux:button type="submit" variant="primary">{{ __('Complete') }}</flux:button>
            </form>
        </flux:modal>
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Maintenance`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: no errors; the build succeeds.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Maintenance/RequestShow.php resources/views/livewire/maintenance/request-show.blade.php app/Models/WorkOrder.php \
  app/Http/Controllers/WorkOrderSheetController.php resources/views/maintenance/work-order-sheet.blade.php routes/property.php \
  tests/Feature/Maintenance/CompletionScreenTest.php
git commit -m "$(cat <<'EOF'
Work order completion form with the prefilled expense and payer choice, printable sheet and Copy for WhatsApp, both without amounts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Lists: requests and work orders with tabs and filters, the new request form, vendor totals, the menu

**Files:**
- Create: `app/Livewire/Maintenance/Requests.php`, `app/Livewire/Maintenance/WorkOrders.php`, `app/Livewire/Maintenance/RequestForm.php`
- Create: `resources/views/livewire/maintenance/requests.blade.php`, `resources/views/livewire/maintenance/work-orders.blade.php`, `resources/views/livewire/maintenance/request-form.blade.php`
- Modify: `app/Livewire/Maintenance/Vendors.php`, `resources/views/livewire/maintenance/vendors.blade.php`, `app/Livewire/Maintenance/VendorForm.php`, `resources/views/livewire/maintenance/vendor-form.blade.php`
- Modify: `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/Maintenance/ListsTest.php`

**Interfaces:**
- Consumes:
  - From Task 2: `MaintenanceRequest::urgentFirst()`, `::inBuilding()`, `::visibleTo()`, `::currentTenantId()`, `::place()`, `::summary()`; `WorkOrder::visibleTo()`, `::isOverdue()`.
  - From Task 2: `LogMaintenanceRequest::handle()`.
  - Also `Customer::findFor()`, `Customer::search()` (scope), `<x-customer-picker>`, `<x-building-filter>`, `MaintenancePriority::color()`.
- Produces:
  - Routes `maintenance.requests.index`, `maintenance.requests.create` and `maintenance.work-orders.index`.
  - `Requests` (`TABS = ['open', 'in_progress', 'resolved', 'all']`). Public `mixed` filters: `$tab`, `$building`, `$priority`, `$category`, `$vendor`, `$search`. View data `requests` and `counts`.
  - `WorkOrders`. The same filters, plus `$status`. View data `workOrders`.
  - `RequestForm`. Public `mixed $form` and `mixed $customerSearch`. Actions `pickTenant(mixed $customerId)`, `clearTenant()`, `save()`.
  - `Vendors` rows carry `work_orders_count` and `cost`. `VendorForm` shows that vendor's work orders and total cost.

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/ListsTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\RoleName as R;
use App\Livewire\Maintenance\RequestForm;
use App\Livewire\Maintenance\Requests;
use App\Livewire\Maintenance\VendorForm;
use App\Livewire\Maintenance\Vendors;
use App\Livewire\Maintenance\WorkOrders;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['code' => 'AAA', 'name' => 'Seef Tower']);
    $this->b = Building::factory()->create(['code' => 'BBB', 'name' => 'Juffair Heights']);
    $this->unitA = Unit::factory()->for($this->a)->create(['code' => '101']);
    $this->unitB = Unit::factory()->for($this->b)->create(['code' => '201']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'mobile' => '+973 3900 1111']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unitA]);
    $this->pm = matrixUser(R::PropertyManager, $this->a);
    $this->plumber = maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]);
    $this->cool = maintenanceVendor(['name' => 'Gulf Cool', 'trades' => ['ac']]);
    // MR-1 in progress (A), MR-2 urgent and open (B), MR-3 open (A), MR-4 resolved (A).
    $this->started = maintenanceRequest($this->pm, $this->unitA, ['description' => 'Old leak']);
    $this->firstWo = workOrderFor($this->pm, $this->started, $this->plumber);
    $this->urgent = maintenanceRequest($this->pm, $this->unitB, ['priority' => 'urgent', 'category' => 'ac', 'description' => 'AC not cooling']);
    $this->waiting = maintenanceRequest($this->pm, $this->unitA, ['description' => 'Dripping tap']);
    $this->done = maintenanceRequest($this->pm, $this->unitA, ['description' => 'Door handle']);
    app(ResolveMaintenanceRequest::class)->handle($this->pm, $this->done);
    $this->numbers = fn ($page, string $key = 'requests') => $page->viewData($key)->pluck('number')->all();
});

test('tabs show their counts; urgent first, then oldest; an unknown tab falls back to Open', function () {
    $page = Livewire::actingAs($this->pm)->test(Requests::class);

    expect($page->viewData('counts'))->toBe(['open' => 2, 'in_progress' => 1, 'resolved' => 1, 'all' => 4])
        ->and(($this->numbers)($page))->toBe(['MR-2026-000002', 'MR-2026-000003']);
    $page->set('tab', 'all');
    expect(($this->numbers)($page))->toBe(['MR-2026-000002', 'MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
    $page->set('tab', 'nonsense')->assertOk();
    expect(($this->numbers)($page))->toBe(['MR-2026-000002', 'MR-2026-000003']);
});

test('filters by building, priority, category and vendor; search by number, unit code or tenant', function () {
    $page = Livewire::actingAs($this->pm)->test(Requests::class)->set('tab', 'all');
    $see = fn (array $set, array $numbers) => expect(($this->numbers)(tap($page, fn ($p) => collect($set)->each(fn ($v, $k) => $p->set($k, $v)))))->toBe($numbers);

    $see(['building' => (string) $this->b->id], ['MR-2026-000002']);
    $see(['building' => '', 'priority' => 'urgent'], ['MR-2026-000002']);
    $see(['priority' => '', 'category' => 'plumbing'], ['MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
    $see(['category' => '', 'vendor' => (string) $this->plumber->id], ['MR-2026-000001']);
    $see(['vendor' => '', 'search' => '000003'], ['MR-2026-000003']);
    $see(['search' => '201'], ['MR-2026-000002']);
    $see(['search' => 'Sara'], ['MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
    $see(['search' => '3900 1111'], ['MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
});

test('tampered filters mean no filter, never a 500', function () {
    $page = Livewire::actingAs($this->pm)->test(Requests::class)
        ->set('tab', ['x'])->set('building', ['x'])->set('priority', ['x'])->set('category', 'roofing')->set('vendor', 'abc')->set('search', ['x'])->assertOk();

    expect(($this->numbers)($page))->toBe(['MR-2026-000002', 'MR-2026-000003']);
    Livewire::actingAs($this->pm)->test(WorkOrders::class)->set('status', ['x'])->set('building', 'abc')->set('vendor', ['1'])->assertOk();
});

test('Leasing sees only the requests in its buildings and cannot log one', function () {
    $leasing = matrixUser(R::Leasing, $this->a);
    $page = Livewire::actingAs($leasing)->test(Requests::class)->set('tab', 'all')->assertDontSee('New request');

    expect(($this->numbers)($page))->toBe(['MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
    $page->set('building', (string) $this->b->id);
    expect(($this->numbers)($page))->toBe([]);
    $this->actingAs($leasing)->get(route('maintenance.requests.index'))->assertOk();
    $this->actingAs($leasing)->get(route('maintenance.requests.create'))->assertForbidden();
    $this->actingAs(matrixUser(R::Finance, $this->a))->get(route('maintenance.work-orders.index'))->assertForbidden();
});

test('the work orders list filters by status and vendor and flags overdue work', function () {
    $late = workOrderFor($this->pm, $this->waiting, $this->plumber, ['scheduled_on' => '2026-10-16']);
    workOrderFor($this->pm, $this->urgent, $this->cool, ['scheduled_on' => '2026-10-20']);

    $page = Livewire::actingAs($this->pm)->test(WorkOrders::class)->assertSee('Overdue');
    expect(($this->numbers)($page, 'workOrders'))->toBe(['WO-2026-000002', 'WO-2026-000003', 'WO-2026-000001']); // by scheduled date, unscheduled last

    $page->set('vendor', (string) $this->cool->id)->assertDontSee('Overdue');
    expect(($this->numbers)($page, 'workOrders'))->toBe(['WO-2026-000003']);
    $page->set('vendor', '')->set('status', 'assigned')->set('building', (string) $this->a->id);
    expect(($this->numbers)($page, 'workOrders'))->toBe(['WO-2026-000002', 'WO-2026-000001']);

    $late->forceFill(['status' => 'completed', 'completed_on' => '2026-10-17'])->save();
    expect($late->fresh()->isOverdue())->toBeFalse();
});

test('new request: choosing the unit fills in its tenant, who can be changed or cleared; saving opens the request', function () {
    $visitor = Customer::factory()->create(['name_en' => 'Omar Visitor']);

    $page = Livewire::actingAs($this->pm)->test(RequestForm::class)
        ->set('form.building_id', (string) $this->a->id)->set('form.unit_id', (string) $this->unitA->id)
        ->assertSet('form.customer_id', $this->tenant->id)->assertSee('Sara Tenant')
        ->set('customerSearch', 'Omar')->assertSee('Omar Visitor')
        ->call('pickTenant', $visitor->id)->assertSet('form.customer_id', $visitor->id)->assertSet('customerSearch', '')
        ->call('clearTenant')->assertSet('form.customer_id', null)
        ->call('pickTenant', $this->tenant->id)
        ->set('form.reported_by_name', 'Sara (tenant)')->set('form.channel', 'whatsapp')->set('form.category', 'plumbing')
        ->set('form.priority', 'high')->set('form.description', 'Water heater leaking')->set('form.entry_permitted', true)
        ->call('save')->assertHasNoErrors();

    $request = MaintenanceRequest::query()->latest('id')->first();
    $page->assertRedirect(route('maintenance.requests.show', $request));
    expect($request->number)->toBe('MR-2026-000005')
        ->and($request->customer_id)->toBe($this->tenant->id)
        ->and($request->priority)->toBe(MaintenancePriority::High)
        ->and($request->channel)->toBe(MaintenanceChannel::Whatsapp)
        ->and($request->entry_permitted)->toBeTrue();
});

test('the new request form refuses a tenant the user cannot see and tampered input, never a 500', function () {
    $scoped = User::factory()->create()->givePermissionTo(['maintenance.view', 'maintenance.manage']);
    $scoped->buildings()->attach($this->a->id);
    $hidden = Customer::factory()->create();
    activeAgreement(['customer_id' => $hidden->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unitB]);

    Livewire::actingAs($scoped)->test(RequestForm::class)
        ->call('pickTenant', $hidden->id)->assertHasErrors('form.customer_id')->assertSet('form.customer_id', null)
        ->call('pickTenant', 'abc')->assertHasErrors('form.customer_id')
        ->call('pickTenant', ['1'])->assertHasErrors('form.customer_id')
        ->set('form.unit_id', (string) $this->unitB->id)->assertSet('form.customer_id', null) // a unit outside the chosen building fills nothing
        ->set('customerSearch', ['x'])->assertOk()
        ->set('form', 'x')->assertOk()->call('save')->assertHasErrors('form.building_id');
    expect(MaintenanceRequest::count())->toBe(4);
});

test('each vendor shows its work orders and their total cost, counting only buildings the viewer can see', function () {
    $done = fn ($wo, string $net) => app(CompleteWorkOrder::class)->handle($this->pm, $wo, ['completion_note' => 'Done', 'completed_on' => '2026-10-17',
        'actual_net' => $net, 'actual_tax' => '0', 'charge_to' => 'company', 'category' => 'maintenance', 'description' => 'Repair']);
    $done($this->firstWo, '55'); // building A
    $inB = maintenanceRequest($this->pm, $this->unitB, ['customer_id' => null]);
    $done(workOrderFor($this->pm, $inB, $this->plumber), '20'); // building B

    $row = fn (User $as) => Livewire::actingAs($as)->test(Vendors::class)->viewData('vendors')->firstWhere('name', 'Ali Plumbing');
    expect([$row($this->pm)->work_orders_count, $row($this->pm)->cost])->toBe([2, '75.000']);
    $leasing = matrixUser(R::Leasing, $this->a);
    expect([$row($leasing)->work_orders_count, $row($leasing)->cost])->toBe([1, '55.000']);

    Livewire::actingAs($leasing)->test(VendorForm::class, ['vendor' => $this->plumber])
        ->assertSee($this->firstWo->number)->assertDontSee('MR-2026-000005')->assertSee('Total cost: 55.000 BHD');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/ListsTest.php`
Expected: FAIL with `Class "App\Livewire\Maintenance\Requests" not found`.

- [ ] **Step 3: The requests list.** Create `app/Livewire/Maintenance/Requests.php`:

```php
<?php

namespace App\Livewire\Maintenance;

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\Trade;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\Vendor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Spec 2026-10-17 maintenance §5: tabs with counts, filters and search; urgent first, then oldest (plan ruling 14). */
#[Title('Maintenance requests')]
class Requests extends Component
{
    use WithActor, WithPagination;

    public const array TABS = ['open', 'in_progress', 'resolved', 'all'];

    // Untyped on purpose: a tampered value means "no filter" instead of a TypeError (a 500).
    #[Url]
    public mixed $tab = 'open';

    #[Url]
    public mixed $building = '';

    #[Url]
    public mixed $priority = '';

    #[Url]
    public mixed $category = '';

    #[Url]
    public mixed $vendor = '';

    #[Url]
    public mixed $search = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('viewAny', MaintenanceRequest::class), 403);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    /** "12" → 12; anything else → null. */
    private static function id(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /** @return Builder<MaintenanceRequest> every filter except the tab, shared by each tab and its count */
    private function filtered(): Builder
    {
        $priority = MaintenancePriority::tryFrom(is_string($this->priority) ? $this->priority : '');
        $category = Trade::tryFrom(is_string($this->category) ? $this->category : '');
        $search = is_string($this->search) ? trim($this->search) : '';

        return MaintenanceRequest::query()->visibleTo($this->actor())
            ->when(self::id($this->building), fn (Builder $q, int $b) => $q->inBuilding($b))
            ->when($priority, fn (Builder $q, MaintenancePriority $p) => $q->where('priority', $p))
            ->when($category, fn (Builder $q, Trade $c) => $q->where('category', $c))
            ->when(self::id($this->vendor), fn (Builder $q, int $v) => $q->whereHas('workOrders', fn (Builder $w) => $w->where('vendor_id', $v)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', '%'.$search.'%')
                ->orWhereHas('unit', fn (Builder $u) => $u->where('code', $search))
                ->orWhereHas('customer', fn (Builder $c) => $c->search($search))));
    }

    /**
     * @param  Builder<MaintenanceRequest>  $query
     * @return Builder<MaintenanceRequest>
     */
    private static function inTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'open' => $query->where('status', MaintenanceStatus::Open),
            'in_progress' => $query->where('status', MaintenanceStatus::InProgress),
            'resolved' => $query->where('status', MaintenanceStatus::Resolved),
            default => $query,
        };
    }

    public function render(): View
    {
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'open';

        return view('livewire.maintenance.requests', [
            'requests' => self::inTab($this->filtered(), $tab)->with(['building:id,code,name', 'unit:id,code', 'customer:id,name_en'])->urgentFirst()->paginate(50),
            'currentTab' => $tab,
            'tabLabels' => ['open' => __('Open'), 'in_progress' => __('In progress'), 'resolved' => __('Resolved'), 'all' => __('All')],
            'counts' => collect(self::TABS)->mapWithKeys(fn (string $t) => [$t => self::inTab($this->filtered(), $t)->count()])->all(),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'priorities' => MaintenancePriority::cases(),
            'categories' => Trade::cases(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
```

Create `resources/views/livewire/maintenance/requests.blade.php`:

```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Maintenance requests') }}</flux:heading>
        @can('create', \App\Models\MaintenanceRequest::class)
            <flux:button variant="primary" :href="route('maintenance.requests.create')" wire:navigate>{{ __('New request') }}</flux:button>
        @endcan
    </div>

    <div class="border-b border-zinc-200 dark:border-zinc-700">
        <div role="tablist" aria-label="{{ __('Maintenance requests') }}" class="-mb-px flex max-w-full gap-1 overflow-x-auto">
            @foreach ($tabLabels as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $currentTab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'flex shrink-0 items-center gap-2 rounded-t-md border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-inset',
                        'border-zinc-800 text-zinc-900 dark:border-white dark:text-white' => $currentTab === $key,
                        'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $currentTab !== $key,
                    ])>
                    {{ $label }}
                    <flux:badge size="sm">{{ $counts[$key] }}</flux:badge>
                </button>
            @endforeach
        </div>
    </div>

    <div class="flex flex-col flex-wrap gap-3 sm:flex-row">
        <x-building-filter :buildings="$buildings" label="" />
        <flux:select wire:model.live="priority" class="sm:max-w-40">
            <option value="">{{ __('All priorities') }}</option>
            @foreach ($priorities as $p)<option value="{{ $p->value }}">{{ $p->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="category" class="sm:max-w-48">
            <option value="">{{ __('All categories') }}</option>
            @foreach ($categories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="vendor" class="sm:max-w-48">
            <option value="">{{ __('All vendors') }}</option>
            @foreach ($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number, unit code, tenant name or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$requests">
            <flux:table.columns>
                <flux:table.column>{{ __('Request') }}</flux:table.column>
                <flux:table.column>{{ __('Building / unit') }}</flux:table.column>
                <flux:table.column>{{ __('Category') }}</flux:table.column>
                <flux:table.column>{{ __('Priority') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Logged') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($requests as $r)
                    <flux:table.row :key="$r->id">
                        <flux:table.cell>
                            <flux:link :href="route('maintenance.requests.show', $r)" wire:navigate>{{ $r->number }}</flux:link>
                            <div class="text-xs text-zinc-500">{{ $r->summary() }}</div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $r->building->code }} / {{ $r->unit?->code ?? __('Common area') }}</flux:table.cell>
                        <flux:table.cell>{{ $r->category->label() }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$r->priority->color()">{{ $r->priority->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $r->customer?->name_en ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $r->created_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell><x-status-badge :status="$r->status" /></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="7">{{ __('No requests found.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```

- [ ] **Step 4: The work orders list.** Create `app/Livewire/Maintenance/WorkOrders.php`:

```php
<?php

namespace App\Livewire\Maintenance;

use App\Enums\MaintenancePriority;
use App\Enums\Trade;
use App\Enums\WorkOrderStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Spec 2026-10-17 maintenance §5: the requests list's filters plus status; "Overdue" when scheduled before today and still open. */
#[Title('Work orders')]
class WorkOrders extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered value means "no filter" instead of a TypeError (a 500).
    #[Url]
    public mixed $status = '';

    #[Url]
    public mixed $building = '';

    #[Url]
    public mixed $priority = '';

    #[Url]
    public mixed $category = '';

    #[Url]
    public mixed $vendor = '';

    #[Url]
    public mixed $search = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('maintenance.view'), 403);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    private static function id(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    public function render(): View
    {
        $status = WorkOrderStatus::tryFrom(is_string($this->status) ? $this->status : '');
        $priority = MaintenancePriority::tryFrom(is_string($this->priority) ? $this->priority : '');
        $category = Trade::tryFrom(is_string($this->category) ? $this->category : '');
        $search = is_string($this->search) ? trim($this->search) : '';

        $workOrders = WorkOrder::query()->visibleTo($this->actor())
            ->when($status, fn (Builder $q, WorkOrderStatus $s) => $q->where('status', $s))
            ->when(self::id($this->vendor), fn (Builder $q, int $v) => $q->where('vendor_id', $v))
            ->whereHas('maintenanceRequest', fn (Builder $r) => $r
                ->when(self::id($this->building), fn (Builder $q, int $b) => $q->inBuilding($b))
                ->when($priority, fn (Builder $q, MaintenancePriority $p) => $q->where('priority', $p))
                ->when($category, fn (Builder $q, Trade $c) => $q->where('category', $c)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', '%'.$search.'%')
                ->orWhereHas('maintenanceRequest', fn (Builder $r) => $r->where('number', 'like', '%'.$search.'%'))))
            ->with(['vendor:id,name', 'maintenanceRequest:id,number,building_id,unit_id,priority', 'maintenanceRequest.building:id,code', 'maintenanceRequest.unit:id,code'])
            ->orderByRaw('scheduled_on IS NULL')->orderBy('scheduled_on')->orderBy('id')
            ->paginate(50);

        return view('livewire.maintenance.work-orders', [
            'workOrders' => $workOrders,
            'statuses' => WorkOrderStatus::cases(),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'priorities' => MaintenancePriority::cases(),
            'categories' => Trade::cases(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
```

Create `resources/views/livewire/maintenance/work-orders.blade.php`:

```blade
<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Work orders') }}</flux:heading>

    <div class="flex flex-col flex-wrap gap-3 sm:flex-row">
        <flux:select wire:model.live="status" class="sm:max-w-40">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </flux:select>
        <x-building-filter :buildings="$buildings" label="" />
        <flux:select wire:model.live="priority" class="sm:max-w-40">
            <option value="">{{ __('All priorities') }}</option>
            @foreach ($priorities as $p)<option value="{{ $p->value }}">{{ $p->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="category" class="sm:max-w-48">
            <option value="">{{ __('All categories') }}</option>
            @foreach ($categories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="vendor" class="sm:max-w-48">
            <option value="">{{ __('All vendors') }}</option>
            @foreach ($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Work order or request number')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$workOrders">
            <flux:table.columns>
                <flux:table.column>{{ __('Work order') }}</flux:table.column>
                <flux:table.column>{{ __('Request') }}</flux:table.column>
                <flux:table.column>{{ __('Building / unit') }}</flux:table.column>
                <flux:table.column>{{ __('Vendor') }}</flux:table.column>
                <flux:table.column>{{ __('Scheduled') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Estimate') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($workOrders as $wo)
                    <flux:table.row :key="$wo->id">
                        <flux:table.cell><flux:link :href="route('maintenance.requests.show', $wo->maintenance_request_id)" wire:navigate>{{ $wo->number }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $wo->maintenanceRequest->number }}</flux:table.cell>
                        <flux:table.cell>{{ $wo->maintenanceRequest->building->code }} / {{ $wo->maintenanceRequest->unit?->code ?? __('Common area') }}</flux:table.cell>
                        <flux:table.cell>{{ $wo->vendor->name }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            {{ $wo->scheduled_on?->format('d/m/Y') ?? '—' }}
                            @if ($wo->isOverdue())
                                <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell><x-status-badge :status="$wo->status" /></flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $wo->estimate ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="7">{{ __('No work orders found.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```

- [ ] **Step 5: The new request form.** Create `app/Livewire/Maintenance/RequestForm.php`:

```php
<?php

namespace App\Livewire\Maintenance;

use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\Trade;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Spec 2026-10-17 maintenance §3.2 and §4 step 1: log a request; the unit's current tenant is filled in and can be changed. */
#[Title('New maintenance request')]
class RequestForm extends Component
{
    use WithActor;

    private const array NEW_FORM = ['channel' => 'phone', 'category' => 'general', 'priority' => 'normal', 'entry_permitted' => false];

    // Untyped on purpose: a tampered payload reaches LogMaintenanceRequest's validation instead of a TypeError (a 500).
    public mixed $form = self::NEW_FORM;

    public mixed $customerSearch = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', MaintenanceRequest::class), 403);
    }

    /** A new building clears the unit and tenant; a unit of the chosen building fills in its tenant. $key is null when the whole form is replaced. */
    public function updatedForm(mixed $value, ?string $key = null): void
    {
        if (! is_array($this->form)) {
            $this->form = self::NEW_FORM; // a tampered payload starts the form again

            return;
        }
        if ($key === 'building_id') {
            $this->form['unit_id'] = null;
            $this->form['customer_id'] = null;
        }
        if ($key === 'unit_id') {
            $unit = is_numeric($value) ? $this->units()->firstWhere('id', (int) $value) : null;
            $this->form['customer_id'] = $unit ? MaintenanceRequest::currentTenantId($unit->id) : null;
        }
    }

    /** The tenant picker's choice: only a tenant this user can see (plan ruling 18). */
    public function pickTenant(mixed $customerId): void
    {
        $customer = is_int($customerId) || (is_string($customerId) && ctype_digit($customerId))
            ? Customer::query()->visibleTo($this->actor())->find((int) $customerId) : null;
        if ($customer === null) {
            $this->addError('form.customer_id', __('Choose a tenant from the list.'));

            return;
        }
        $this->form = [...(is_array($this->form) ? $this->form : self::NEW_FORM), 'customer_id' => $customer->id];
        $this->customerSearch = '';
    }

    public function clearTenant(): void
    {
        $this->form = [...(is_array($this->form) ? $this->form : self::NEW_FORM), 'customer_id' => null];
    }

    public function save(LogMaintenanceRequest $log): void
    {
        try {
            $request = $log->handle($this->actor(), is_array($this->form) ? $this->form : []);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.{$k}" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Request :number logged.', ['number' => $request->number]));
        $this->redirectRoute('maintenance.requests.show', $request, navigate: true);
    }

    /** @return Collection<int, Unit> the units of the chosen building, if the user can see it */
    private function units(): Collection
    {
        $buildingId = is_array($this->form) && is_numeric($this->form['building_id'] ?? null) ? (int) $this->form['building_id'] : 0;

        return Unit::query()->where('building_id', $buildingId)->whereHas('building', fn ($q) => $q->visibleTo($this->actor()))->orderBy('code')->get(['id', 'code']);
    }

    public function render(): View
    {
        $customerId = is_array($this->form) && is_numeric($this->form['customer_id'] ?? null) ? (int) $this->form['customer_id'] : null;
        $term = is_string($this->customerSearch) ? $this->customerSearch : '';

        return view('livewire.maintenance.request-form', [
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'units' => $this->units(),
            'tenant' => $customerId ? Customer::query()->visibleTo($this->actor())->find($customerId, ['id', 'name_en', 'mobile']) : null,
            'term' => $term,
            'customerResults' => Customer::findFor($this->actor(), $term),
            'channels' => MaintenanceChannel::cases(),
            'categories' => Trade::cases(),
            'priorities' => MaintenancePriority::cases(),
        ]);
    }
}
```

Create `resources/views/livewire/maintenance/request-form.blade.php`:

```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New maintenance request') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model.live="form.building_id" :label="__('Building')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($buildings as $b)<option value="{{ $b->id }}">{{ $b->code }} — {{ $b->name }}</option>@endforeach
            </flux:select>
            <flux:select wire:model.live="form.unit_id" :label="__('Unit')">
                <option value="">{{ __('Common area') }}</option>
                @foreach ($units as $u)<option value="{{ $u->id }}">{{ $u->code }}</option>@endforeach
            </flux:select>
        </div>

        <flux:field>
            <flux:label>{{ __('Tenant') }}</flux:label>
            @if ($tenant)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium">{{ $tenant->name_en }}</span>
                    <span class="text-sm text-zinc-500">· {{ $tenant->mobile }}</span>
                    <flux:button size="xs" variant="ghost" wire:click="clearTenant">{{ __('Remove') }}</flux:button>
                </div>
            @else
                <flux:text class="text-sm">{{ __('No tenant: a common area, or a vacant unit. Search below to add one.') }}</flux:text>
            @endif
            <flux:error name="form.customer_id" />
        </flux:field>
        <x-customer-picker :results="$customerResults" :term="$term" action="pickTenant" />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.reported_by_name" :label="__('Reported by')" />
            <flux:select wire:model="form.channel" :label="__('How it was reported')">
                @foreach ($channels as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
            </flux:select>
            <flux:select wire:model="form.category" :label="__('Category')">
                @foreach ($categories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
            </flux:select>
            <flux:select wire:model="form.priority" :label="__('Priority')">
                @foreach ($priorities as $p)<option value="{{ $p->value }}">{{ $p->label() }}</option>@endforeach
            </flux:select>
        </div>
        <flux:textarea wire:model="form.description" :label="__('Problem')" rows="4" />
        <flux:switch wire:model="form.entry_permitted" :label="__('The vendor may enter when the tenant is out')" />

        <flux:button variant="primary" type="submit">{{ __('Log request') }}</flux:button>
    </form>
</section>
```

- [ ] **Step 6: Vendor totals.** In `app/Livewire/Maintenance/Vendors.php` add `use App\Enums\ExpenseStatus;`, `use App\Models\Building;` and `use App\Models\Expense;`, and in `render()` replace `->orderBy('name')` with:

```php
            // Plan ruling 15: only work orders in buildings this user sees; cost = their recorded expenses' totals.
            ->withCount(['workOrders' => fn ($q) => $q->visibleTo($this->actor())])
            ->addSelect(['cost' => Expense::query()->selectRaw('COALESCE(SUM(expenses.total), 0)')
                ->join('work_orders', 'work_orders.expense_id', '=', 'expenses.id')
                ->whereColumn('work_orders.vendor_id', 'vendors.id')
                ->where('expenses.status', ExpenseStatus::Recorded)
                ->whereIn('expenses.building_id', Building::query()->visibleTo($this->actor())->select('buildings.id'))])
            ->orderBy('name')
```

In `resources/views/livewire/maintenance/vendors.blade.php` add two columns after `Mobile`:

```blade
                <flux:table.column align="end">{{ __('Work orders') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Cost (BHD)') }}</flux:table.column>
```

and, in the row after the mobile cell:

```blade
                        <flux:table.cell class="text-end tabular-nums">{{ $vendor->work_orders_count }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ \App\Support\Fils::grouped(\App\Support\Fils::fromDecimal((string) $vendor->cost)) }}</flux:table.cell>
```

Change the empty row's `colspan="5"` to `colspan="7"`.

In `app/Livewire/Maintenance/VendorForm.php` add `use App\Enums\ExpenseStatus;`, `use App\Models\Building;`, `use App\Models\Expense;`, `use App\Models\WorkOrder;` and `use App\Support\Fils;`, and add to the view data in `render()`:

```php
            'workOrders' => $vendor ? WorkOrder::query()->visibleTo($this->actor())->where('vendor_id', $vendor->id)
                ->with(['maintenanceRequest:id,number,building_id,unit_id', 'maintenanceRequest.building:id,code', 'maintenanceRequest.unit:id,code'])
                ->latest('id')->limit(50)->get() : collect(),
            'totalCost' => $vendor ? Fils::toDecimal(Fils::fromDecimal((string) (Expense::query()
                ->join('work_orders', 'work_orders.expense_id', '=', 'expenses.id')
                ->where('work_orders.vendor_id', $vendor->id)
                ->where('expenses.status', ExpenseStatus::Recorded)
                ->whereIn('expenses.building_id', Building::query()->visibleTo($this->actor())->select('buildings.id'))
                ->sum('expenses.total') ?: '0'))) : null,
```

In `resources/views/livewire/maintenance/vendor-form.blade.php`, before `</section>`:

```blade
    @if ($vendor)
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Work orders') }}</flux:heading>
            <flux:text class="text-sm font-medium">{{ __('Total cost: :amount BHD', ['amount' => $totalCost]) }}</flux:text>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($workOrders as $wo)
                    <li wire:key="vwo-{{ $wo->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span>
                            <flux:link :href="route('maintenance.requests.show', $wo->maintenance_request_id)" wire:navigate>{{ $wo->number }}</flux:link>
                            · {{ $wo->maintenanceRequest->number }} · {{ $wo->maintenanceRequest->building->code }} / {{ $wo->maintenanceRequest->unit?->code ?? __('Common area') }}
                        </span>
                        <x-status-badge :status="$wo->status" />
                    </li>
                @empty
                    <li class="py-2"><flux:text>{{ __('No work orders yet.') }}</flux:text></li>
                @endforelse
            </ul>
        </div>
    @endif
```

- [ ] **Step 7: Routes and menu.** In `routes/property.php`, add these three routes immediately before the `maintenance.requests.show` route. `create` must come before `{maintenanceRequest}`, or "create" would be read as a request id:

```php
    Route::livewire('maintenance/requests', Maintenance\Requests::class)->middleware('can:maintenance.view')->name('maintenance.requests.index');
    Route::livewire('maintenance/requests/create', Maintenance\RequestForm::class)->middleware('can:maintenance.manage')->name('maintenance.requests.create');
    Route::livewire('maintenance/work-orders', Maintenance\WorkOrders::class)->middleware('can:maintenance.view')->name('maintenance.work-orders.index');
```

In `resources/views/layouts/app/sidebar.blade.php`, inside the Maintenance group before the Vendors item:

```blade
                        <flux:sidebar.item icon="wrench-screwdriver" :href="route('maintenance.requests.index')" :current="request()->routeIs('maintenance.requests.*')" wire:navigate>{{ __('Requests') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('maintenance.work-orders.index')" :current="request()->routeIs('maintenance.work-orders.*')" wire:navigate>{{ __('Work orders') }}</flux:sidebar.item>
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test tests/Feature/Maintenance tests/Feature/Shell`
Expected: PASS.

- [ ] **Step 9: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: no errors; the build succeeds.

- [ ] **Step 10: Commit**

```bash
git add app/Livewire/Maintenance/Requests.php app/Livewire/Maintenance/WorkOrders.php app/Livewire/Maintenance/RequestForm.php \
  resources/views/livewire/maintenance/requests.blade.php resources/views/livewire/maintenance/work-orders.blade.php \
  resources/views/livewire/maintenance/request-form.blade.php app/Livewire/Maintenance/Vendors.php app/Livewire/Maintenance/VendorForm.php \
  resources/views/livewire/maintenance/vendors.blade.php resources/views/livewire/maintenance/vendor-form.blade.php \
  routes/property.php resources/views/layouts/app/sidebar.blade.php tests/Feature/Maintenance/ListsTest.php
git commit -m "$(cat <<'EOF'
Maintenance lists: requests with tabs, counts, filters and search, work orders with the overdue badge, new request form, vendor totals

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Tenant emails, "request received" and "request completed", in the reminders' layout and under their rules

**Files:**
- Create: `resources/views/components/mail/bilingual.blade.php`
- Modify: `resources/views/mail/tenant-reminder.blade.php`, `app/Mail/TenantReminder.php` (`content()`)
- Create: `app/Mail/MaintenanceUpdate.php`, `resources/views/mail/maintenance-update.blade.php`
- Modify: `app/Actions/Maintenance/LogMaintenanceRequest.php`, `app/Actions/Maintenance/ResolveMaintenanceRequest.php`
- Test: `tests/Feature/Maintenance/EmailsTest.php` (and the existing `tests/Feature/Reminders/EmailTest.php`)

**Interfaces:**
- Consumes:
  - `App\Reminders\EmailChannel::ready(): bool` (false while `mail.default` is `log` or `array`).
  - `Customer::$no_reminders`, `Customer::displayName('ar')`, `CompanySetting::current()`, `MaintenanceRequest::place()`.
- Produces:
  - `<x-mail.bilingual :subject-line="…">` with slots `en` and `ar`. The component renders the whole HTML page and the company footer.
  - `MaintenanceUpdate extends Mailable implements ShouldQueue`. Constructor `(MaintenanceRequest $request, string $kind)`, with `$tries = 1`.
    - Constants `RECEIVED = 'received'` and `COMPLETED = 'completed'`.
    - `subjectLine(): string`.
    - `static sendFor(MaintenanceRequest $request, string $kind): bool` queues the mail after commit and returns true when it was queued.

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/EmailsTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Actions\Maintenance\ChangeWorkOrderStatus;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Mail\MaintenanceUpdate;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'sara@tenant.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->vendor = maintenanceVendor();
    $this->finish = fn ($wo) => app(CompleteWorkOrder::class)->handle($this->pm, $wo, ['completion_note' => 'Done', 'completed_on' => '2026-10-17', 'actual_net' => '']);
});

test('logging a request queues one bilingual "request received" email to the tenant, with no amounts', function () {
    $request = maintenanceRequest($this->pm, $this->unit);

    Mail::assertQueued(MaintenanceUpdate::class, 1);
    Mail::assertQueued(MaintenanceUpdate::class, fn (MaintenanceUpdate $m) => $m->hasTo('sara@tenant.test', 'Sara Tenant')
        && $m->kind === MaintenanceUpdate::RECEIVED && $m->request->is($request));

    $mail = new MaintenanceUpdate($request, MaintenanceUpdate::RECEIVED);
    $mail->assertHasSubject('Maintenance request received · تم استلام طلب الصيانة · Demo Properties W.L.L.')
        ->assertFrom('no-reply@rms.test', 'Demo Properties W.L.L.')
        ->assertHasReplyTo('office@demo.test')
        ->assertSeeInOrderInHtml([
            'dir="ltr"', 'Dear Sara Tenant,', 'We have received your maintenance request MR-2026-000001 for Seef Tower 101 on 17/10/2026.', 'Kitchen sink leaking',
            'dir="rtl"', 'عزيزي المستأجر سارة،', 'تم استلام طلب الصيانة رقم MR-2026-000001 الخاص بـ Seef Tower 101 بتاريخ 17/10/2026.',
            'Demo Properties W.L.L.',
        ], false)
        ->assertDontSeeInHtml('BHD');
    expect($mail->tries)->toBe(1)->and($mail)->toBeInstanceOf(ShouldQueue::class);
});

test('resolving, automatically or by hand, queues one "request completed" email; cancelling sends none', function () {
    $auto = maintenanceRequest($this->pm, $this->unit);
    ($this->finish)(workOrderFor($this->pm, $auto, $this->vendor));
    $manual = maintenanceRequest($this->pm, $this->unit);
    app(ResolveMaintenanceRequest::class)->handle($this->pm, $manual);
    app(CancelMaintenanceRequest::class)->handle($this->pm, maintenanceRequest($this->pm, $this->unit), 'Logged twice');

    $completed = fn ($request) => fn (MaintenanceUpdate $m) => $m->kind === MaintenanceUpdate::COMPLETED && $m->request->is($request);
    Mail::assertQueued(MaintenanceUpdate::class, 5); // three received, two completed
    Mail::assertQueued(MaintenanceUpdate::class, $completed($auto));
    Mail::assertQueued(MaintenanceUpdate::class, $completed($manual));

    (new MaintenanceUpdate($auto->fresh(), MaintenanceUpdate::COMPLETED))
        ->assertHasSubject('Maintenance request completed · تم إنجاز طلب الصيانة · Demo Properties W.L.L.')
        ->assertSeeInOrderInHtml(['Your maintenance request MR-2026-000001 for Seef Tower 101 was completed on 17/10/2026.', 'dir="rtl"',
            'تم إنجاز طلب الصيانة رقم MR-2026-000001 الخاص بـ Seef Tower 101 بتاريخ 17/10/2026.'], false);
});

test('no email for a tenant with no address, one who opted out of reminders, a common area, or while mail is not set up', function (Closure $setUp) {
    $setUp->call($this);
    $request = maintenanceRequest($this->pm, $this->unit, $this->data ?? []);
    ($this->finish)(workOrderFor($this->pm, $request, $this->vendor));

    Mail::assertNothingQueued();
})->with([
    'no email' => [fn () => $this->tenant->update(['email' => null])],
    'opted out' => [fn () => $this->tenant->update(['no_reminders' => true])],
    'a common area' => [fn () => $this->data = ['unit_id' => null, 'customer_id' => null]],
    'mail not set up' => [fn () => config(['mail.default' => 'log'])],
]);

test('a refused request sends nothing, and a work order change that does not resolve sends nothing', function () {
    $request = maintenanceRequest($this->pm, $this->unit);
    $wo = workOrderFor($this->pm, $request, $this->vendor);
    app(ChangeWorkOrderStatus::class)->handle($this->pm, $wo, 'in_progress');
    app(ChangeWorkOrderStatus::class)->handle($this->pm, $wo, 'cancelled', 'Vendor did not come'); // none completed: still in progress

    try {
        maintenanceRequest($this->pm, $this->unit, ['description' => '']);
    } catch (ValidationException) {
        // refused before anything was saved
    }
    Mail::assertQueued(MaintenanceUpdate::class, 1); // only the first request's "received"
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/EmailsTest.php`
Expected: FAIL with `Class "App\Mail\MaintenanceUpdate" not found`.

- [ ] **Step 3: The shared layout.** Create `resources/views/components/mail/bilingual.blade.php`:

```blade
@props(['subjectLine'])
@php
    $company = \App\Models\CompanySetting::current();
@endphp
{{-- The tenant emails' page (plan ruling 9): English, then Arabic right to left, then the company footer. Blade escapes every value. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin: 0; padding: 24px; background: #ffffff; color: #18181b; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.6;">
    <div dir="ltr" lang="en" style="text-align: left;">
        {{ $en }}
    </div>

    <hr style="border: 0; border-top: 1px solid #e4e4e7; margin: 24px 0;">

    <div dir="rtl" lang="ar" style="text-align: right;">
        {{ $ar }}
    </div>

    <hr style="border: 0; border-top: 1px solid #e4e4e7; margin: 24px 0;">

    <div style="font-size: 13px; color: #52525b;">
        <p style="margin: 0;">{{ $company->name_en }}</p>
        @if (filled($company->phone))<p style="margin: 0;">{{ $company->phone }}</p>@endif
        @if (filled($company->email))<p style="margin: 0;">{{ $company->email }}</p>@endif
        @if (filled($company->address_en))<p style="margin: 0;">{{ $company->address_en }}</p>@endif
        @if (filled($company->name_ar) || filled($company->address_ar))
            <p dir="rtl" lang="ar" style="margin: 8px 0 0; text-align: right;">{{ $company->name_ar }}@if (filled($company->name_ar) && filled($company->address_ar)) · @endif{{ $company->address_ar }}</p>
        @endif
    </div>
</body>
</html>
```

Replace the whole of `resources/views/mail/tenant-reminder.blade.php` with:

```blade
<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        @foreach ($sections as $section)
            <p>{{ $section['intro_en'] }}</p>
            <ul>
                @foreach ($section['lines'] as $line)
                    <li>{{ $line['en'] }}</li>
                @endforeach
            </ul>
        @endforeach
        <p>{{ __('Thank you.') }}</p>
    </x-slot:en>
    <x-slot:ar>
        <p>عزيزي المستأجر {{ $nameAr }}،</p>
        @foreach ($sections as $section)
            <p>{{ $section['intro_ar'] }}</p>
            <ul>
                @foreach ($section['lines'] as $line)
                    <li>{{ $line['ar'] }}</li>
                @endforeach
            </ul>
        @endforeach
        <p>شكراً لكم.</p>
    </x-slot:ar>
</x-mail.bilingual>
```

In `app/Mail/TenantReminder.php::content()`, delete the line `$company = CompanySetting::current();` and the `'company' => [...]` entry (two lines) from the `with:` array. The layout now reads the company itself. `CompanySetting` is still used by `envelope()` and `subjectLine()`, so keep its import.

- [ ] **Step 4: The mailable.** Create `app/Mail/MaintenanceUpdate.php`:

```php
<?php

namespace App\Mail;

use App\Models\CompanySetting;
use App\Models\MaintenanceRequest;
use App\Reminders\EmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Spec 2026-10-17 maintenance §4 steps 1 and 5: "request received" and "request completed", English then Arabic, in the
 * tenant reminders' layout and under their rules (plan ruling 9). No amounts.
 */
class MaintenanceUpdate extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const string RECEIVED = 'received';

    public const string COMPLETED = 'completed';

    public int $tries = 1;

    public function __construct(public MaintenanceRequest $request, public string $kind) {}

    /**
     * Queues the email, sent once the transaction commits, when the tenant can get it: an email on the tenant record,
     * not opted out of reminders, and mail set up. True when it was queued.
     */
    public static function sendFor(MaintenanceRequest $request, string $kind): bool
    {
        $tenant = $request->customer;
        if ($tenant === null || blank($tenant->email) || $tenant->no_reminders || ! (new EmailChannel)->ready()) {
            return false;
        }
        Mail::to((string) $tenant->email, $tenant->name_en)->queue((new self($request, $kind))->afterCommit());

        return true;
    }

    public function envelope(): Envelope
    {
        $company = CompanySetting::current();

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $company->name_en),
            replyTo: filled($company->email) ? [new Address((string) $company->email, $company->name_en)] : [],
            subject: $this->subjectLine(),
        );
    }

    public function subjectLine(): string
    {
        [$en, $ar] = $this->kind === self::COMPLETED
            ? [__('Maintenance request completed'), 'تم إنجاز طلب الصيانة']
            : [__('Maintenance request received'), 'تم استلام طلب الصيانة'];

        return implode(' · ', [$en, $ar, CompanySetting::current()->name_en]);
    }

    public function content(): Content
    {
        $r = $this->request;
        $date = $this->kind === self::COMPLETED ? ($r->resolved_at ?? now()) : $r->created_at;

        return new Content(view: 'mail.maintenance-update', with: [
            'subjectLine' => $this->subjectLine(),
            'completed' => $this->kind === self::COMPLETED,
            'nameEn' => $r->customer?->name_en ?? '',
            'nameAr' => $r->customer?->displayName('ar') ?? '',
            'number' => $r->number,
            'place' => $r->place(),
            'description' => $r->description,
            'date' => $date->timezone('Asia/Bahrain')->format('d/m/Y'),
        ]);
    }
}
```

Create `resources/views/mail/maintenance-update.blade.php`:

```blade
<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        @if ($completed)
            <p>{{ __('Your maintenance request :number for :place was completed on :date.', ['number' => $number, 'place' => $place, 'date' => $date]) }}</p>
            <p>{{ __('If the problem is not fixed, please contact us.') }}</p>
        @else
            <p>{{ __('We have received your maintenance request :number for :place on :date.', ['number' => $number, 'place' => $place, 'date' => $date]) }}</p>
            <p style="white-space: pre-line;">{{ $description }}</p>
            <p>{{ __('We will contact you to arrange the visit.') }}</p>
        @endif
        <p>{{ __('Thank you.') }}</p>
    </x-slot:en>
    <x-slot:ar>
        <p>عزيزي المستأجر {{ $nameAr }}،</p>
        @if ($completed)
            <p>تم إنجاز طلب الصيانة رقم {{ $number }} الخاص بـ {{ $place }} بتاريخ {{ $date }}.</p>
            <p>إذا لم تُحل المشكلة، يرجى التواصل معنا.</p>
        @else
            <p>تم استلام طلب الصيانة رقم {{ $number }} الخاص بـ {{ $place }} بتاريخ {{ $date }}.</p>
            <p style="white-space: pre-line;">{{ $description }}</p>
            <p>سنتواصل معكم لتحديد موعد الزيارة.</p>
        @endif
        <p>شكراً لكم.</p>
    </x-slot:ar>
</x-mail.bilingual>
```

- [ ] **Step 5: Send from the actions.** In `app/Actions/Maintenance/LogMaintenanceRequest.php` add `use App\Mail\MaintenanceUpdate;`, and inside the transaction after `MaintenanceEvent::record($request, $actor, to: MaintenanceStatus::Open->value);`:

```php
            MaintenanceUpdate::sendFor($request, MaintenanceUpdate::RECEIVED); // spec §4 step 1; after commit
```

In `app/Actions/Maintenance/ResolveMaintenanceRequest.php` add `use App\Mail\MaintenanceUpdate;`, and at the end of the private `resolve()`:

```php
        MaintenanceUpdate::sendFor($locked, MaintenanceUpdate::COMPLETED); // spec §4 step 5: resolution happens once (ruling 4)
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Maintenance tests/Feature/Reminders`
Expected: PASS. The reminder email still renders the same page and footer through the shared layout.

- [ ] **Step 7: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: no errors; the build succeeds.

- [ ] **Step 8: Commit**

```bash
git add resources/views/components/mail/bilingual.blade.php resources/views/mail/tenant-reminder.blade.php app/Mail/TenantReminder.php \
  app/Mail/MaintenanceUpdate.php resources/views/mail/maintenance-update.blade.php app/Actions/Maintenance/LogMaintenanceRequest.php \
  app/Actions/Maintenance/ResolveMaintenanceRequest.php tests/Feature/Maintenance/EmailsTest.php
git commit -m "$(cat <<'EOF'
Maintenance tenant emails: request received and request completed, bilingual, in the shared reminder layout, skipped when opted out or mail is not set up

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: The dashboard's urgent card, and the Maintenance section on unit and tenant pages

**Files:**
- Modify: `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`
- Create: `resources/views/components/maintenance-requests.blade.php`
- Modify: `app/Livewire/Units/Form.php`, `resources/views/livewire/units/form.blade.php`, `app/Livewire/Customers/Form.php`, `resources/views/livewire/customers/form.blade.php`
- Test: `tests/Feature/Maintenance/SectionsTest.php`

**Interfaces:**
- Consumes: `MaintenanceRequest::visibleTo()`, `::urgentFirst()`, `::summary()`, `::place()`, `MaintenancePriority`, `MaintenanceStatus`, route `maintenance.requests.show`.
- Produces:
  - `Dashboard::urgentMaintenance(User $user): ?array{count: int, requests: Collection<int, MaintenanceRequest>}`. It returns null without `maintenance.view`, and covers open and in-progress urgent requests, the five oldest.
  - `<x-maintenance-requests :requests="…">`, a section listing requests.

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/SectionsTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Dashboard;
use App\Livewire\Units\Form as UnitForm;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['name' => 'Seef Tower']);
    $this->b = Building::factory()->create(['name' => 'Juffair Heights']);
    $this->unitA = Unit::factory()->for($this->a)->create(['code' => '101']);
    $this->unitB = Unit::factory()->for($this->b)->create(['code' => '201']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unitA]);
    $this->pm = matrixUser(R::PropertyManager, $this->a);
});

test('the dashboard counts open urgent requests and lists the oldest five, in the viewer\'s buildings', function () {
    $urgentA = collect(range(1, 6))->map(fn (int $i) => maintenanceRequest($this->pm, $this->unitA, ['priority' => 'urgent', 'description' => "Urgent A{$i}"]));
    maintenanceRequest($this->pm, $this->unitB, ['priority' => 'urgent', 'description' => 'Urgent B']);
    maintenanceRequest($this->pm, $this->unitA, ['priority' => 'high', 'description' => 'Only high']);
    app(CancelMaintenanceRequest::class)->handle($this->pm, $urgentA[5], 'Duplicate');

    $card = Dashboard::urgentMaintenance(matrixUser(R::Leasing, $this->a));
    expect($card['count'])->toBe(5)
        ->and($card['requests']->pluck('description')->all())->toBe(['Urgent A1', 'Urgent A2', 'Urgent A3', 'Urgent A4', 'Urgent A5'])
        ->and(Dashboard::urgentMaintenance($this->pm)['count'])->toBe(6) // the property manager sees every building
        ->and(Dashboard::urgentMaintenance(matrixUser(R::Finance, $this->a)))->toBeNull();

    Livewire::actingAs($this->pm)->test(Dashboard::class)->assertSee('Urgent maintenance')->assertSee($urgentA[0]->number)->assertDontSee('Only high');
    Livewire::actingAs(matrixUser(R::Finance, $this->a))->test(Dashboard::class)->assertDontSee('Urgent maintenance');
});

test('the unit and tenant pages list their maintenance requests for maintenance.view holders', function () {
    $mine = maintenanceRequest($this->pm, $this->unitA, ['description' => 'Kitchen sink leaking']);
    $other = maintenanceRequest($this->pm, $this->unitB, ['description' => 'Lift noise']);

    Livewire::actingAs($this->pm)->test(UnitForm::class, ['unit' => $this->unitA])
        ->assertSee('Maintenance')->assertSee($mine->number)->assertSee('Kitchen sink leaking')->assertDontSee($other->number);
    Livewire::actingAs($this->pm)->test(CustomerForm::class, ['customer' => $this->tenant])
        ->assertSee($mine->number)->assertDontSee($other->number);

    $finance = matrixUser(R::Finance, $this->a); // can open both pages, has no maintenance.view
    Livewire::actingAs($finance)->test(UnitForm::class, ['unit' => $this->unitA])->assertDontSee($mine->number);
    Livewire::actingAs($finance)->test(CustomerForm::class, ['customer' => $this->tenant])->assertDontSee($mine->number);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/SectionsTest.php`
Expected: FAIL with `Call to undefined method App\Livewire\Dashboard::urgentMaintenance()`.

- [ ] **Step 3: The dashboard card.** In `app/Livewire/Dashboard.php` add `use App\Enums\MaintenancePriority;`, `use App\Enums\MaintenanceStatus;`, `use App\Models\MaintenanceRequest;` and `use Illuminate\Database\Eloquent\Collection;`. Add after `occupancy()`:

```php
    /**
     * Spec 2026-10-17 maintenance §4 step 1: open and in-progress urgent requests in the user's buildings, the five oldest.
     * A card of its own, not a tile (plan ruling 11). Null without maintenance.view.
     *
     * @return array{count: int, requests: Collection<int, MaintenanceRequest>}|null
     */
    public static function urgentMaintenance(User $user): ?array
    {
        if (! $user->can('maintenance.view')) {
            return null;
        }
        $urgent = fn () => MaintenanceRequest::query()->visibleTo($user)->where('priority', MaintenancePriority::Urgent)
            ->whereIn('status', [MaintenanceStatus::Open, MaintenanceStatus::InProgress]);

        return ['count' => $urgent()->count(), 'requests' => $urgent()->with(['building:id,name', 'unit:id,code'])->orderBy('id')->limit(5)->get()];
    }
```

and in `render()` add `'urgent' => self::urgentMaintenance($user),` to the view data.

In `resources/views/livewire/dashboard.blade.php`, before the final `</div>`:

```blade
    @if ($urgent)
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Urgent maintenance') }}</span>
                <flux:link :href="route('maintenance.requests.index', ['priority' => 'urgent', 'tab' => 'all'])" wire:navigate class="text-xs">{{ __('Open: :n', ['n' => $urgent['count']]) }}</flux:link>
            </div>
            @if ($urgent['requests']->isEmpty())
                <flux:text class="mt-2 text-sm">{{ __('No urgent requests.') }}</flux:text>
            @else
                <ul class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($urgent['requests'] as $r)
                        <li wire:key="urgent-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <span><flux:link :href="route('maintenance.requests.show', $r)" wire:navigate>{{ $r->number }}</flux:link> · {{ $r->place() }} · {{ $r->summary() }}</span>
                            <span class="text-zinc-500">{{ $r->created_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
```

The "All" tab shows both open and in-progress urgent requests, which matches the count. It also includes resolved ones, below them.

- [ ] **Step 4: The section on unit and tenant pages.** Create `resources/views/components/maintenance-requests.blade.php`:

```blade
@props(['requests'])
{{-- Spec 2026-10-17 maintenance §5: the "Maintenance" section on the unit and tenant pages, latest first. --}}
<div class="space-y-3">
    <flux:heading size="lg">{{ __('Maintenance') }}</flux:heading>
    @if ($requests->isEmpty())
        <flux:text>{{ __('No maintenance requests.') }}</flux:text>
    @else
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($requests as $r)
                <li wire:key="mr-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span><flux:link :href="route('maintenance.requests.show', $r)" wire:navigate>{{ $r->number }}</flux:link> · {{ $r->category->label() }} · {{ $r->summary() }}</span>
                    <span class="flex items-center gap-2">
                        <span class="text-zinc-500">{{ $r->created_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</span>
                        <x-status-badge :status="$r->status" />
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
```

In `app/Livewire/Units/Form.php` add `use App\Models\MaintenanceRequest;`, and in `render()` add to the view data:

```php
            'maintenance' => $unit && $this->actor()->can('maintenance.view')
                ? MaintenanceRequest::query()->visibleTo($this->actor())->where('unit_id', $unit->id)->latest('id')->limit(20)->get() : null,
```

In `resources/views/livewire/units/form.blade.php`, inside `@if ($unit)` before the documents panel:

```blade
        @if ($maintenance)
            <x-maintenance-requests :requests="$maintenance" />
        @endif
```

In `app/Livewire/Customers/Form.php` add `use App\Models\MaintenanceRequest;`, and in `render()` add to the view data:

```php
            'maintenance' => $customer && $this->actor()->can('maintenance.view')
                ? MaintenanceRequest::query()->visibleTo($this->actor())->where('customer_id', $customer->id)->latest('id')->limit(20)->get() : null,
```

In `resources/views/livewire/customers/form.blade.php`, inside `@if ($customer)` before the reminders panel:

```blade
        @if ($maintenance)
            <x-maintenance-requests :requests="$maintenance" />
        @endif
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Maintenance tests/Feature/Reports/DashboardTilesTest.php tests/Feature/DashboardTest.php tests/Feature/Units tests/Feature/Customers`
Expected: PASS. The tile list is unchanged.

- [ ] **Step 6: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: no errors; the build succeeds.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Dashboard.php resources/views/livewire/dashboard.blade.php resources/views/components/maintenance-requests.blade.php \
  app/Livewire/Units/Form.php resources/views/livewire/units/form.blade.php app/Livewire/Customers/Form.php \
  resources/views/livewire/customers/form.blade.php tests/Feature/Maintenance/SectionsTest.php
git commit -m "$(cat <<'EOF'
Urgent maintenance on the dashboard, and a Maintenance section on unit and tenant pages

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: The two reports, Excel export, the catalogue, report packages, and the full suite

**Files:**
- Create: `app/Livewire/Reports/OpenMaintenanceReport.php`, `app/Livewire/Reports/MaintenanceCostReport.php`
- Modify: `app/Livewire/Reports/Index.php`, `app/Reports/Packages/ReadyMade.php`, `routes/property.php`
- Modify: `tests/Feature/ReportPackages/HeadlessTest.php`
- Test: `tests/Feature/Maintenance/ReportsTest.php`

**Interfaces:**
- Consumes:
  - `ReportPage` trait: `title()`, `permission()`, `dateMode()`, `options()`, `columns()`, `numeric()`, `rows()`, `export()`, plus the static `packageForm` / `checkSettings` / `sheet`.
  - `PackageReport`.
  - `MaintenanceRequest::urgentFirst()`, `WorkOrder::visibleTo()`, `CompanySetting::current()->vat_registered`, `BuildingProfitability::for()`.
- Produces:
  - Routes `reports.open-maintenance` and `reports.maintenance-cost`.
  - `ReadyMade::REPORTS['open_maintenance']` and `['maintenance_cost']` (16 reports).
  - `MaintenanceCostReport::$group` is `'building' | 'unit' | 'category'`. Columns: `group`, `orders`, `cost` (company and tenant, Building profitability's rule), `owner` (owner charges at total). The last row is a `_kind => 'total'` row.

- [ ] **Step 1: Write the failing test** `tests/Feature/Maintenance/ReportsTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Livewire\Reports\Index;
use App\Livewire\Reports\MaintenanceCostReport;
use App\Livewire\Reports\OpenMaintenanceReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use App\Models\WorkOrder;
use App\Reports\BuildingProfitability;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->unitA = Unit::factory()->for($this->a)->create(['code' => '101']);
    $this->unitB = Unit::factory()->for($this->b)->create(['code' => '201']);
    activeOwnerContract(['building_id' => $this->a->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unitA]);
    $this->pm = matrixUser(R::PropertyManager, $this->a);
    $this->plumber = maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]);
    $this->cool = maintenanceVendor(['name' => 'Gulf Cool', 'trades' => ['ac']]);
    $this->old = maintenanceRequest($this->pm, $this->unitA, ['priority' => 'urgent']); // logged 10/10
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    $this->complete = fn (WorkOrder $wo, string $net, string $tax, string $chargeTo = 'company') => app(CompleteWorkOrder::class)->handle($this->pm, $wo, [
        'completion_note' => 'Done', 'completed_on' => '2026-10-17', 'actual_net' => $net, 'actual_tax' => $tax, 'charge_to' => $chargeTo,
        'category' => 'maintenance', 'description' => 'Repair']);
});

test('Open maintenance: open and in-progress requests, urgent first, with their age in days and open vendors', function () {
    $acB = maintenanceRequest($this->pm, $this->unitB, ['category' => 'ac']);
    workOrderFor($this->pm, $acB, $this->cool);
    $resolved = app(ResolveMaintenanceRequest::class)->handle($this->pm, maintenanceRequest($this->pm, $this->unitA));

    $html = Livewire::actingAs($this->pm)->test(OpenMaintenanceReport::class)->html();
    expect(reportRowText($html, $this->old->number))->toBe("{$this->old->number} AAA 101 Plumbing Urgent 7")
        ->and(reportRowText($html, $acB->number))->toBe("{$acB->number} BBB 201 Air conditioning Normal 0 Gulf Cool")
        ->and(strpos($html, $this->old->number))->toBeLessThan(strpos($html, $acB->number))
        ->and($html)->not->toContain($resolved->number);

    $leasing = Livewire::actingAs(matrixUser(R::Leasing, $this->a))->test(OpenMaintenanceReport::class)->html();
    expect($leasing)->toContain($this->old->number)->not->toContain($acB->number);
});

test('Maintenance cost: linked, recorded expenses by building, unit or category; company and tenant at Building profitability\'s rule, owner apart', function () {
    ($this->complete)(workOrderFor($this->pm, $this->old, $this->plumber), '100', '10');
    ($this->complete)(workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitA), $this->plumber), '50', '5', 'owner');
    $acB = maintenanceRequest($this->pm, $this->unitB, ['category' => 'ac']);
    ($this->complete)(workOrderFor($this->pm, $acB, $this->cool), '20', '2');
    $reversed = ($this->complete)(workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitB, ['category' => 'ac']), $this->cool), '30', '3');
    app(ReverseExpense::class)->handle(matrixUser(R::Finance, $this->a), $reversed->expense, 'Entered twice');

    $report = fn (array $set) => tap(Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class), fn ($p) => collect(['from' => '2026-10-01', 'to' => '2026-10-31', ...$set])->each(fn ($v, $k) => $p->set($k, $v)))->html();

    $byBuilding = $report(['group' => 'building']);
    expect(reportRowText($byBuilding, 'AAA'))->toBe('AAA 2 100.000 55.000')
        ->and(reportRowText($byBuilding, 'BBB'))->toBe('BBB 1 20.000 0.000')
        ->and(reportRowText($byBuilding, 'Total'))->toBe('Total 3 120.000 55.000');
    expect(reportRowText($report(['group' => 'unit']), 'BBB/201'))->toBe('BBB/201 1 20.000 0.000');
    $byCategory = $report(['group' => 'category']);
    expect(reportRowText($byCategory, 'Air conditioning'))->toBe('Air conditioning 1 20.000 0.000')
        ->and(reportRowText($byCategory, 'Plumbing'))->toBe('Plumbing 2 100.000 55.000');
    expect(reportRowText($report(['group' => 'building', 'from' => '2026-10-18', 'to' => '2026-10-31']), 'AAA'))->toBe(''); // completed on 17/10

    // Spec §8: the company-and-tenant column is Building profitability's expense figure for the same building and dates.
    expect(Fils::fromDecimal('100.000'))->toBe(BuildingProfitability::for($this->a, '2026-10-01', '2026-10-31')['expenses']);
    CompanySetting::current()->forceFill(['vat_registered' => false])->save();
    expect(reportRowText($report(['group' => 'building']), 'AAA'))->toBe('AAA 2 110.000 55.000')
        ->and(Fils::fromDecimal('110.000'))->toBe(BuildingProfitability::for($this->a, '2026-10-01', '2026-10-31')['expenses']);
});

test('both reports export to Excel, audited, need maintenance.view as well, and appear in the catalogue', function () {
    Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class)->set('group', 'unit')->call('export')->assertFileDownloaded();
    Livewire::actingAs($this->pm)->test(OpenMaintenanceReport::class)->call('export')->assertFileDownloaded();

    expect(Activity::query()->where('event', 'report.exported')->where('properties->report', 'maintenance_cost')->sole()->properties['group'])->toBe('unit')
        ->and(Activity::query()->where('event', 'report.exported')->where('properties->report', 'open_maintenance')->exists())->toBeTrue();

    $finance = matrixUser(R::Finance, $this->a); // reports.operational without maintenance.view
    $this->actingAs($finance)->get(route('reports.open-maintenance'))->assertForbidden();
    $this->actingAs($finance)->get(route('reports.maintenance-cost'))->assertForbidden();
    $this->actingAs(matrixUser(R::Leasing, $this->a))->get(route('reports.maintenance-cost'))->assertOk();

    Livewire::actingAs($this->pm)->test(Index::class)->assertSee(route('reports.open-maintenance'))->assertSee(route('reports.maintenance-cost'));
    Livewire::actingAs($finance)->test(Index::class)->assertDontSee(route('reports.open-maintenance'));
});

test('a tampered grouping or date is a message, never a 500', function () {
    Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class)->set('group', 'vendor')->assertOk()->assertHasErrors('group');
    Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class)->set('group', ['unit'])->assertOk()->assertHasErrors('group');
    Livewire::actingAs($this->pm)->test(MaintenanceCostReport::class)->set('from', '31/10/2026')->assertOk()->assertHasErrors('from');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/ReportsTest.php`
Expected: FAIL with `Class "App\Livewire\Reports\OpenMaintenanceReport" not found`.

- [ ] **Step 3: The reports.** Create `app/Livewire/Reports/OpenMaintenanceReport.php`:

```php
<?php

namespace App\Livewire\Reports;

use App\Enums\MaintenanceStatus;
use App\Enums\WorkOrderStatus;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use App\Reports\Packages\PackageReport;
use Livewire\Component;

/** Spec 2026-10-17 maintenance §5: requests still open or in progress, urgent first, then oldest, with their age and vendors. */
class OpenMaintenanceReport extends Component implements PackageReport
{
    use ReportPage;

    protected function title(): string
    {
        return __('Open maintenance');
    }

    /** Plan ruling 16: like the cheque reports, this one also needs its records' permission. */
    protected function permission(): bool
    {
        $user = $this->actor();

        return $user->can('reports.operational') && $user->can('maintenance.view');
    }

    protected function dateMode(): string
    {
        return 'none';
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['number' => __('Request'), 'building' => __('Building'), 'unit' => __('Unit'), 'category' => __('Category'),
            'priority' => __('Priority'), 'age' => __('Age (days)'), 'vendor' => __('Vendor')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['age'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $today = now('Asia/Bahrain')->startOfDay();

        return array_values(MaintenanceRequest::query()->visibleTo($this->actor())
            ->whereIn('status', [MaintenanceStatus::Open, MaintenanceStatus::InProgress])
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->with(['building:id,code', 'unit:id,code', 'workOrders' => fn ($q) => $q->whereIn('status', [WorkOrderStatus::Assigned, WorkOrderStatus::InProgress]), 'workOrders.vendor:id,name'])
            ->urgentFirst()->get()
            ->map(fn (MaintenanceRequest $r) => [
                '_url' => route('maintenance.requests.show', $r),
                'number' => $r->number,
                'building' => $r->building->code,
                'unit' => $r->unit?->code ?? __('Common area'),
                'category' => $r->category->label(),
                'priority' => $r->priority->label(),
                'age' => (int) $r->created_at->timezone('Asia/Bahrain')->startOfDay()->diffInDays($today),
                'vendor' => $r->workOrders->map(fn (WorkOrder $w) => $w->vendor->name)->unique()->implode(', '),
            ])->all());
    }
}
```

Create `app/Livewire/Reports/MaintenanceCostReport.php`:

```php
<?php

namespace App\Livewire\Reports;

use App\Enums\ChargeTo;
use App\Enums\ExpenseStatus;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\CompanySetting;
use App\Models\WorkOrder;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Spec 2026-10-17 maintenance §5 (plan ruling 16): what maintenance cost, from the recorded expenses linked to work
 * orders, by expense date. Company and tenant charges use Building profitability's rule (net when VAT-registered,
 * else total), so they agree with it; owner charges are shown apart at their total, as owner statements charge them.
 */
class MaintenanceCostReport extends Component implements PackageReport
{
    use ReportPage;

    // Untyped on purpose: a tampered value reaches the filter validation instead of a TypeError (a 500).
    #[Url]
    public mixed $group = 'building';

    protected function title(): string
    {
        return __('Maintenance cost');
    }

    protected function permission(): bool
    {
        $user = $this->actor();

        return $user->can('reports.operational') && $user->can('maintenance.view');
    }

    /** @return array<string, array<array-key, string>> */
    protected function options(): array
    {
        return ['group' => ['building' => __('By building'), 'unit' => __('By unit'), 'category' => __('By category')]];
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return [
            'group' => match ($this->group) {
                'unit' => __('Unit'),
                'category' => __('Category'),
                default => __('Building'),
            },
            'orders' => __('Work orders'),
            'cost' => __('Cost: company and tenant'),
            'owner' => __('Charged to owners'),
        ];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['orders', 'cost', 'owner'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $net = CompanySetting::current()->vat_registered;
        // ponytail: grouped in PHP over one company's work orders; move to SQL GROUP BY if it ever runs to many thousands.
        $workOrders = WorkOrder::query()->visibleTo($this->actor())
            ->whereHas('expense', fn ($e) => $e->where('status', ExpenseStatus::Recorded)->whereBetween('expense_date', [$this->from, $this->to])
                ->when($this->building, fn ($q, $b) => $q->where('building_id', $b)))
            ->with(['expense', 'expense.building:id,code', 'expense.unit:id,code', 'maintenanceRequest:id,category'])
            ->get();

        $groups = [];
        foreach ($workOrders as $wo) {
            $e = $wo->expense;
            [$key, $label] = match ($this->group) {
                'unit' => [$e->building_id.'/'.$e->unit_id, $e->building->code.'/'.($e->unit?->code ?? __('Common area'))],
                'category' => [$wo->maintenanceRequest->category->value, $wo->maintenanceRequest->category->label()],
                default => [(string) $e->building_id, $e->building->code],
            };
            $groups[$key] ??= ['group' => $label, 'orders' => 0, 'cost' => 0, 'owner' => 0];
            $groups[$key]['orders']++;
            if ($e->charge_to === ChargeTo::Owner) {
                $groups[$key]['owner'] += Fils::fromDecimal($e->total);
            } else {
                $groups[$key]['cost'] += Fils::fromDecimal($net ? $e->net : $e->total);
            }
        }
        if ($groups === []) {
            return [];
        }
        usort($groups, fn (array $x, array $y) => strcmp($x['group'], $y['group']));
        $money = fn (array $g) => [...$g, 'cost' => Fils::toDecimal($g['cost']), 'owner' => Fils::toDecimal($g['owner'])];

        return [...array_map($money, $groups), $money(['_kind' => 'total', 'group' => __('Total'), 'orders' => array_sum(array_column($groups, 'orders')),
            'cost' => array_sum(array_column($groups, 'cost')), 'owner' => array_sum(array_column($groups, 'owner'))])];
    }
}
```

- [ ] **Step 4: Routes, catalogue, packages.** In `routes/property.php`, after the `reports.cheques` route:

```php
    Route::livewire('reports/open-maintenance', Reports\OpenMaintenanceReport::class)->middleware('can:reports.operational')->name('reports.open-maintenance');
    Route::livewire('reports/maintenance-cost', Reports\MaintenanceCostReport::class)->middleware('can:reports.operational')->name('reports.maintenance-cost');
```

In `app/Livewire/Reports/Index.php::catalogue()`, add at the end of `'operational'`, after the Cheques entry:

```php
                ['route' => 'reports.open-maintenance', 'label' => __('Open maintenance'), 'icon' => 'wrench-screwdriver', 'about' => __('Requests still open, urgent first, with their age and vendor.')],
                ['route' => 'reports.maintenance-cost', 'label' => __('Maintenance cost'), 'icon' => 'wrench', 'about' => __('What maintenance cost, by building, unit or category.')],
```

and in `render()` replace the `$operational = …` line with:

```php
        $maintenance = $user->can('maintenance.view'); // the maintenance reports also need it (plan ruling 16)
        $operational = array_values(array_filter(self::catalogue()['operational'], fn (array $r) => ($r['route'] !== 'reports.cheques' || $cheques)
            && (! in_array($r['route'], ['reports.open-maintenance', 'reports.maintenance-cost'], true) || $maintenance)));
```

In `app/Reports/Packages/ReadyMade.php`:
- Add `use App\Livewire\Reports\MaintenanceCostReport;` and `use App\Livewire\Reports\OpenMaintenanceReport;` to the imports.
- Change the docblock to `/** Spec 2026-10-17 §3: the 16 reports on ReportPage / StatementPage, keyed as their exports audit them, in Reports page order. */`.
- After `'cheques' => ChequesReport::class,` add:

```php
        'open_maintenance' => OpenMaintenanceReport::class,
        'maintenance_cost' => MaintenanceCostReport::class,
```

- [ ] **Step 5: Update the package tests.** In `tests/Feature/ReportPackages/HeadlessTest.php`:
- In `beforeEach`, after `[$this->a, $this->b, $this->finance, $this->owner] = reportPackageFixture();`, add `$this->finance->givePermissionTo('maintenance.view'); // the maintenance reports need it`.
- In the first test, rename it to `'the registry lists the 16 reports under the keys their exports audit'` and change `->toHaveCount(14)` to `->toHaveCount(16)`.
- In the C1 test, rename it to `'final review C1: the browser cannot call the package entry points on any of the 16 report pages'` and change `$this->scoped->givePermissionTo('finance.view'); // so the cheques page opens too` to `$this->scoped->givePermissionTo(['finance.view', 'maintenance.view']); // so the cheque and maintenance pages open too`.
- Add to the headless dataset, after `'cheques' => ['cheques', ['kind' => 'bounced'], false],`:

```php
    'open maintenance' => ['open_maintenance', [], false],
    'maintenance cost' => ['maintenance_cost', ['from' => '2026-09-01', 'to' => '2026-10-31', 'group' => 'building'], false],
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Maintenance tests/Feature/ReportPackages tests/Feature/Reports`
Expected: PASS.

- [ ] **Step 7: Full suite and gates.** Run these serially:
  - `php artisan test`: everything passes. That includes `tests/Feature/Integrity` (still 61 triggers), `tests/Feature/Auth/RolesAndPermissionsTest.php` (27 permissions), the numbering and install tests (10 keys), and `tests/Feature/Reminders/EmailTest.php` (the shared layout).
  - `php vendor/bin/pint --dirty`
  - `php vendor/bin/phpstan analyse --memory-limit=2G`
  - `npm run build`

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Reports/OpenMaintenanceReport.php app/Livewire/Reports/MaintenanceCostReport.php app/Livewire/Reports/Index.php \
  app/Reports/Packages/ReadyMade.php routes/property.php tests/Feature/ReportPackages/HeadlessTest.php tests/Feature/Maintenance/ReportsTest.php
git commit -m "$(cat <<'EOF'
Maintenance reports: Open maintenance and Maintenance cost, Excel export audited, in the catalogue and report packages

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (done while writing)

- **Spec coverage:**
  - §1 purpose and §2 approach. Requests and work orders are their own records with no triggers (Task 2). The cost goes through `RecordExpense` (Task 4). The link is `work_orders.expense_id`, and `expenses` is untouched (Task 2 migration).
  - §3.1 vendors: Task 1. All the fields, trades as a JSON list with at least one, soft deletes and the active flag. Inactive vendors are refused new work orders (Task 2). Trades double as request categories (`Trade` enum).
  - §3.2 requests: Task 2. The MR number, nullable unit, tenant prefilled from the current agreement and editable (Task 7 form), every field and status, and photos as documents with category `maintenance` (Tasks 2 and 5).
  - §3.3 work orders: Task 2. The WO number, every field, `expense_id` unique, after-photos (Task 5). The one renamed column is `completed_on`, an exact date (ruling 3).
  - §3.4 timeline: `MaintenanceEvent` (Task 2), written by every action (Tasks 2–4), and audited through `LogsActivity` (ruling 8).
  - §4 step 1 (log, urgent on the dashboard, "received" email): Tasks 2, 9 and 8.
  - §4 step 2 (active vendor with the trade or general, the first work order starts the request, the sheet with no amounts, WhatsApp text): Tasks 2 and 6.
  - §4 step 3 (status changes on the timeline, cancel needs a reason): Task 3.
  - §4 step 4 (completion with note and cost, VAT default, prefilled expense, payer choice, `expenses.manage`, no-cost completion, one transaction): Tasks 4 and 6.
  - §4 step 5 (auto-resolve, manual resolve or cancel with a reason and cascade, "completed" email): Tasks 3 and 8.
  - §5 screens. The sidebar group (Tasks 1 and 7). The requests list with tabs, counts, filters, search and order (Task 7). The request page (Tasks 5 and 6). The work orders list with the overdue badge (Task 7). Vendors with filters, their work orders and total cost (Tasks 1 and 7). The unit and tenant sections (Task 9). Both reports with audited Excel (Task 10).
  - §6 permissions: Task 1, with the installed-system migration (ruling 1). Building scope sits in the policies and the `visibleTo` scopes. Vendors are company-wide. Vendor Support's rule is unchanged.
  - §7 safety. Money is created only through `RecordExpense`. Livewire inputs are untyped `mixed` and validated. Ids are looked up inside the request (ruling 18). Every string goes through `__()`, the UI says "tenant", dates are dd/mm/yyyy, money is `Fils`, and `EXPECTED_TRIGGERS` stays 61 (Task 10 Step 7).
  - §8 testing. Each bullet maps to a test file:
    - `VendorsTest`: vendors CRUD and validation.
    - `RequestsTest`: inactive vendors and trade match.
    - `LifecycleTest`: the lifecycle, cascade, reasons, timeline and audits.
    - `CompletionTest` and `CompletionScreenTest`: completion with each payer, rollback, no-cost completion and the unique `expense_id`.
    - Scope and permissions on every list, page, action and report: `RequestPageTest`, `ListsTest`, `ReportsTest` and the `Leasing` cases.
    - `EmailsTest`: the emails.
    - `CompletionScreenTest`: the sheet and WhatsApp text without amounts. `ListsTest`: the overdue badge.
    - `ReportsTest`: the reports, including the Building profitability match and the audited Excel.
    - Tampered input: a dataset in each screen test.
- **Spec conflicts found:** only ruling 7. With the default roles only a Property manager can record a cost, and nobody can charge the tenant from a work order without an Admin role edit. Everything else is a clarification (rulings 3, 4, 9, 11, 12, 16).
- **Placeholder scan:** none. Every step has its code or its exact command.
- **Type consistency:**
  - `maintenanceRequest(User, Unit, array)`, `workOrderFor(User, MaintenanceRequest, Vendor, array)` and `maintenanceVendor(array)` are the same in every test.
  - `MaintenanceEvent::record(request, user, ?workOrder, ?from, ?to, ?note)` is the same in Tasks 2, 3 and 4.
  - `ResolveMaintenanceRequest::ifDone(User, MaintenanceRequest)` is the same in Tasks 3 and 4. `CompleteWorkOrder::handle(User, WorkOrder, array, ?UploadedFile)`, `::defaultVat`, `::costFils` and `::expenseDescription` are the same in Tasks 4, 5 and 6.
  - `RequestShow::request()` and `::workOrderOf(mixed, string)` are defined in Task 5 and reused in Task 6.
  - `MaintenanceUpdate::sendFor(MaintenanceRequest, string)` with `RECEIVED` / `COMPLETED` is the same in Task 8.
  - Routes: `maintenance.vendors.{index,create,edit}`, `maintenance.requests.{index,create,show}`, `maintenance.work-orders.{index,sheet}`, `reports.open-maintenance` and `reports.maintenance-cost`.
- **Fixture arithmetic:**
  - `ReportsTest`: building A has 100 + 10 company and 50 + 5 owner; B has 20 + 2 company, plus a reversed 30 + 3.
    - VAT-registered: AAA cost 100.000, owner 55.000; BBB 20.000; total 120.000 / 55.000, 3 work orders.
    - Not registered: AAA cost 110.000, which equals Building profitability's expenses for A.
    - The request logged 10/10 is 7 days old on 17/10.
  - `ListsTest`: MR-1 is in progress, MR-2 urgent (B), MR-3 open and MR-4 resolved. The counts are 2/1/1/4, the Open order is MR-2, MR-3, and All is MR-2, MR-1, MR-3, MR-4. The new request is MR-2026-000005.
  - `EmailsTest`: three requests queue three "received" emails, and the two resolutions queue two "completed", 5 in all.

