# Tenant Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let tenants sign in at `/portal` with an emailed code and see their own agreements, invoices, receipts, cheques, statement and shared documents, and raise and follow maintenance requests, in English or Arabic, without calling the office.

**Architecture:** A separate `tenant` session guard backed by a new `PortalAccount` model (provider `portal_accounts`). Tenants are never staff `User`s. Staff routes move to `auth:web`. The staff middlewares read only the `web` guard. Portal routes (`routes/portal.php`) use only `auth:tenant` plus one `PortalSession` middleware, which is also Livewire-persistent. That middleware ends a disabled or deleted tenant's session on every request and sets the account's locale. Portal Livewire components get the account through a `protected` trait method, so every query starts from `customer_id` and the browser can call nothing that takes a customer id. PDFs reuse the existing generators and stored copies through one scoped `Portal\DownloadController`. A portal maintenance request goes through `LogMaintenanceRequest::forPortal()`, which shares the staff entry's private write path.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, Fortify (staff only), spatie/laravel-activitylog, `App\Audit\Audit`, `App\Support\Fils`, `App\Billing\CustomerStatement`, `App\Billing\CustomerCredit`, `App\Pdf\InvoicePdf`, `App\Jobs\StoreReceipt`, `App\Jobs\StoreApprovedContract`, `App\Actions\Documents\StoreDocument`, `App\Actions\Maintenance\LogMaintenanceRequest`, `App\Mail\MaintenanceUpdate`, `<x-mail.bilingual>`, `App\Reminders\EmailChannel`, `Illuminate\Support\Facades\RateLimiter`, `Illuminate\Support\Timebox`.

**Spec:** `docs/superpowers/specs/2026-10-18-tenant-portal-design.md`. Read it first; this plan argues from it.

## Global Constraints

- "A separate `tenant` auth guard at `/portal` with its own `PortalAccount` model, session and layout. Tenants are never staff `User`s" (spec §2). "Portal routes use only the `tenant` guard; staff routes reject the `tenant` guard" (spec §6).
- "Every portal query is scoped from `auth('tenant')->user()->customer_id`. A record id in a URL that does not belong to the tenant returns 404. Public Livewire methods on portal components never accept a customer id" (spec §6). Public non-static Livewire methods are browser-callable. They must never accept a customer id or return data outside the signed-in tenant's records. Internals are `private`, `protected` or `static`.
- Sign-in (spec §3): a 6-digit code, "valid 10 minutes, once; a code allows 5 attempts, then it is spent". "5 code requests per email per hour and 20 per IP per hour; 10 code checks per IP per minute. Codes are stored hashed." The screen always says "If this email has an account, we've sent a code". "Remember this device" lasts 30 days and is on by default. Codes are "compared in constant time" (spec §6).
- "Each request re-checks the account is not disabled and the tenant not soft-deleted — otherwise the session ends" (spec §3).
- "Mail not set up (same check as reminders)": `EmailChannel::ready()`. Invite and sign-in then show "Portal email is not set up".
- Audited (spec §3, §6): `portal.invited`, `portal.disabled`, `portal.enabled`, `portal.login` (account as causer, IP), `portal.logout`. PDF downloads, document downloads and maintenance requests are audited with the portal account as causer.
- Portal maintenance (spec §4): unit is one of the tenant's units on a current agreement, or "Building common area" of a current agreement's building; description ≤2000; up to 5 photos (jpg, png, webp, ≤10 MB each); channel `portal`; customer = the tenant; `reported_by_name` = the tenant's name; priority `normal`; the "received" email as for staff requests; a "Portal" badge for staff; 10 requests per account per day. The tenant view shows "no amounts, estimates, vendor details or staff notes".
- Money is BHD in fils with 3 decimals. In PHP use `App\Support\Fils` (`fromDecimal`, `toDecimal`, `grouped`) and never floats.
- Timezone is Asia/Bahrain. Dates display as dd/mm/yyyy (`format('d/m/Y')`). "Today" is `now('Asia/Bahrain')`.
- Actions are the only write path. "No money tables touched; `EXPECTED_TRIGGERS` unchanged" (spec §5). `IntegrityCheck::EXPECTED_TRIGGERS` stays 61.
- All UI strings go through `__()`. Write "tenant", never "customer", in UI copy. The portal is bilingual EN / AR with RTL and mobile first (spec §4).
- "Tampered input gives a validation message, never a 500" (spec §6). Livewire form and filter properties are untyped `mixed` and checked before use.
- Tests run serially (`php artisan test`, never `--parallel`) against `rms_test`. Never run `migrate:fresh` on the dev DB `rms`. Dev migrations use `php artisan migrate --database=migrator --force`. Seeded lookup tables go into `TestCase::$exceptTables`; this feature seeds none.
- PHP binary: `/c/Users/ababy/.config/herd/bin/php85/php.exe` (written `php` below). Gates before each commit: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, and `npm run build` when a view changed.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file. Alpine attributes on Flux components need `:x-show`-style binding. Put plain `x-data` on a wrapping `<div>`, not on a Flux component.
- Jobs and queued mail use `tries = 1`. The queue's `retry_after` is 330.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Rulings made while planning

1. **Guards never mix.** Staff routes change from `auth` to `auth:web` in `routes/web.php`, `settings.php`, `admin.php` and `property.php`. Fortify already uses `config('fortify.guard') = 'web'`. Without the change, `auth` means "the default guard", and `Auth::shouldUse('tenant')` (which `auth:tenant` and `actingAs($account, 'tenant')` call) would let a tenant through. `EnsureUserIsActive` and `EnsureTwoFactorIsConfirmed` read `Auth::guard('web')->user()` instead of `$request->user()`, so a `PortalAccount` never reaches them. `EnsureTwoFactorIsConfirmed` also exempts `portal.*`, so a staff member without 2FA can still open the portal sign-in. Guests are sent to the matching sign-in through `redirectGuestsTo` and `redirectUsersTo` callbacks in `bootstrap/app.php`, keyed on the path `portal` / `portal/*`.
2. **Livewire under the tenant guard.** Livewire's update endpoint runs the `web` group, then re-applies persistent middleware against the component's original route. `Authenticate` (`auth:tenant`, with its parameter) is persistent by default. `PortalSession` is added with `Livewire::addPersistentMiddleware()`. Livewire does not apply persistent middleware under `Livewire::test()`. So every portal component also checks the guard itself: the `InPortal` trait's `protected account()` aborts 403 unless `Auth::guard('tenant')->user()` is an active `PortalAccount`.
3. **One session cookie, two guards.** The guards keep separate session keys and remember cookies (`login_tenant_…`, `remember_tenant_…`). Portal sign-out logs out the `tenant` guard and regenerates the session id and CSRF token. It does not invalidate the session, so a staff session in the same browser survives. Staff sign-out (Fortify) invalidates the whole session, which also signs the tenant out. That is accepted: "independent" (spec §6) means neither guard authenticates the other's routes.
4. **Columns the spec did not list.**
   - `portal_accounts.remember_token` is needed for "remember this device". The 30 days are the guard's `remember` option (43200 minutes).
   - `maintenance_events.user_id` becomes nullable, with `portal_account_id` and an exactly-one CHECK, as spec §5 does for requests and documents. A portal request's first timeline row ("received") has no staff user. `MaintenanceEvent::record()` takes `User|PortalAccount`.
5. **Codes.**
   - 6 digits from `random_int`. Stored as `hash_hmac('sha256', account_id|code, APP_KEY)` and compared with `hash_equals`.
   - A new code deletes the account's unused codes. A wrong try increments `attempts`. A code is usable while `used_at` is null, `expires_at > now` and `attempts < 5`. The 5th wrong try spends it.
   - Rate limits use `RateLimiter` keys `portal-code-email:{sha256(email)}` (5 per 3600 s), `portal-code-ip:{ip}` (20 per 3600 s) and `portal-verify-ip:{ip}` (10 per 60 s). Every request counts, for known and unknown emails alike.
   - The lookup and send run inside a 200 ms `Timebox`, as Fortify's login does, so unknown emails are not faster.
   - Unknown, disabled and deleted all give the same answer.
   - Emails are compared in lower case. Account emails are stored in lower case.
6. **Portal emails** (`PortalInvitation`, `PortalSignInCode`) use `<x-mail.bilingual>`, `tries = 1`, are queued, and render in `en` (the layout carries both languages). They ignore `no_reminders`, which only stops reminders. The tenant asked for these mails. The code sits in the queued job's payload until it is sent; it expires in 10 minutes. `MaintenanceUpdate::sendFor` also pins `locale('en')`, so a request sent from an Arabic portal page still renders its English half in English.
7. **Invite, re-send, disable, enable** (spec §3). One `InviteToPortal` action covers Invite and Re-send.
   - It refuses: without `customers.manage` on the tenant (`CustomerPolicy::update`, building scope included); for a deleted tenant or one with no email; while mail is not set up; and when another tenant's account already has the email.
   - On an enabled account it is a re-send (`portal.invited`, `resent: true`). Otherwise it creates or re-enables the account (`resent: false`).
   - `SetPortalAccess` disables or enables. Enable re-syncs the email and needs one. Disable deletes unused codes, and the next request ends any session (ruling 2). A no-op writes nothing and is not audited.
   - The buttons sit on the tenant page (`Customers\Form`) in a "Tenant portal" card.
8. **Email sync** (spec §3). `SaveCustomer` copies a changed tenant email, in lower case, to the account inside its transaction. It refuses an email that another tenant's account uses. It refuses clearing the email while the account is enabled ("Disable their portal access before removing the email"). After disabling, the email may be cleared and the account keeps its old address, which cannot sign in.
9. **Audit.**
   - `AuthEventSubscriber` turns `Login`/`Logout` on the `tenant` guard into `portal.login`/`portal.logout`, with the account as subject and causer. `LogActivityAction` adds the IP on real HTTP requests, as it does for staff.
   - Downloads reuse the staff event names with the account as causer: `invoice.pdf_viewed`, `document.downloaded` (stored contracts, stored receipts, shared documents) and `customer.statement_exported`.
   - A portal request writes `portal.maintenance_requested` with the account as causer, besides the model's own `created` entry.
   - `PortalAccount` has a `name` accessor ("Tenant portal · Sara Tenant"), so the existing audit log viewer can show it as a causer.
10. **PDFs.**
    - The invoice is rendered by `InvoicePdf` (issued invoices only, as staff).
    - The receipt and the agreement are the stored copies (`StoreReceipt::stored`, `StoreApprovedContract::stored`). If the queue has not built them yet, they are built now with `dispatchSync`, using the payment's `recorded_by` or the agreement's `created_by` as `uploaded_by`.
    - Draft and pending agreements are not shown. Imported agreements (`import_ref`) have no PDF, as staff. Receipts are offered for confirmed payments only.
    - The statement PDF comes from `CustomerStatement::pdf()`, which the staff controller then uses too.
    - The PDFs stay as staff print them (English). They are the company's documents.
    - A statement PDF request with a bad date range returns 404. It is a plain link, not a form.
11. **Lists.**
    - Invoices: issued, credit notes left out (they appear on the statement). "Paid" is total − balance (payments and credits), so total = paid + balance. Unpaid means balance > 0.
    - Payments: all, with their status.
    - Cheques: `direction = received` only.
    - Agreements: anything past pending approval.
    - No pagination. A tenant has tens of rows, not thousands.
12. **Home** (spec §4).
    - "Balance owed" is `Customer::outstandingFils()`, moved out of the tenant page so both use one rule. Credit is `CustomerCredit::fils()`.
    - Next due: the unpaid invoice with the earliest due date.
    - Upcoming held cheques: held, dated today or later, the next 5.
    - Open requests: open or in progress, the latest 5.
    - Agreements ending within 90 days: active, `end_date` from today to today + 90.
13. **Documents.** Sharing is offered only on documents attached to a tenant, an agreement or an invoice (`Document::SHAREABLE`; invoices have no panel today). It is offered in the documents panel to users who can update the record. `ShareDocumentWithTenant` writes `shared_with_tenant`. `Document`'s `LogsActivity` now includes that column, so each toggle is an audit entry with the staff causer. A stored contract or receipt is shown only through Agreements and Payments, unless staff share it.
14. **Maintenance from the portal** (spec §4). `LogMaintenanceRequest::forPortal(PortalAccount, array, mixed $photos)` validates the tenant's input, then calls the same private `create()` as `handle()`. Inside one transaction, `create()` numbers the request, writes it, writes the timeline row, stores the photos (`StoreDocument::forPortal`, category `maintenance`) and queues the "received" email.
    - The place is `unit:{id}` or `building:{id}`, from `LogMaintenanceRequest::portalPlaces($customerId)`. That is the current-agreement rule of `MaintenanceRequest::currentTenantId`: active, started, not past `COALESCE(move_out_date, end_date)`.
    - The daily limit counts the account's requests created since midnight today (Asia/Bahrain): 10.
    - Staff cannot choose `portal`. The staff form drops it from its list, and `handle()` refuses it.
    - `StoreDocument` writes the file before the insert, so a rolled-back request can leave an unreferenced photo file. That is existing behaviour (maintenance plan ruling 5).
15. **Tenant timeline** (spec §4), from the request's status events only:
    - the request becoming `open` → "Request received";
    - a work order becoming `assigned` → "Work assigned";
    - the request becoming `resolved` → "Completed";
    - the request becoming `cancelled` → "Cancelled".
    Notes, reasons, work order numbers, vendors and amounts are never read.
16. **Locale and RTL.**
    - `PortalSession` sets the locale from the account, or from `session('portal_locale')` for a guest. A POST to `portal.locale` switches it, through `SetPortalLocale` when signed in.
    - `lang/ar.json` holds the portal strings and the labels of shared enums the portal shows. Staff stay in English because their locale never changes.
    - `<x-status-badge>` passes its label through `__()`, which changes nothing in English.
    - The language buttons read "العربية" and "English", each in its own language, and are the only portal text outside `__()`.
    - A test checks that every `__()` string in the portal files has an Arabic entry.
17. **Security headers.** The app sets none itself; HSTS is set at the web server (go-live runbook). The portal is served by the same app and host, so it gets the same headers. CSRF covers every portal form, and the Livewire calls, through the `web` group.
18. **Ids in portal URLs** have `whereNumber` constraints and are looked up with `where('customer_id', …)->findOrFail()`. Another tenant's id, and a made-up one, give 404. Controller parameters are `string`, so a 30-digit id is a 404, not a `TypeError`.

## File Structure

```
database/migrations/2026_12_22_000100_create_portal_tables.php   NEW  portal_accounts, portal_login_codes, creator columns, CHECKs (Task 1)
app/Models/PortalAccount.php, app/Models/PortalLoginCode.php      NEW  (Task 1)
config/auth.php                                                   MOD  tenant guard, portal_accounts provider (Task 1)
app/Enums/MaintenanceChannel.php                                  MOD  Portal case, staffCases() (Task 1)
app/Models/MaintenanceRequest.php                                 MOD  portalAccount(), creatorName() (Task 1); tenantTimeline() (Task 7)
app/Models/MaintenanceEvent.php                                   MOD  record(User|PortalAccount), byName() (Task 1)
app/Models/Document.php                                           MOD  portal_account_id, shared_with_tenant (Task 1); SHAREABLE, sharedWithTenant() (Task 6)
app/Models/Customer.php                                           MOD  portalAccount() (Task 4); outstandingFils() (Task 5)
app/Http/Middleware/EnsureUserIsActive.php, EnsureTwoFactorIsConfirmed.php  MOD  web guard only (Task 1); portal.* exempt (Task 3)
routes/web.php, settings.php, admin.php, property.php             MOD  auth:web (Task 1); web.php requires portal.php (Task 3)
app/Actions/Maintenance/LogMaintenanceRequest.php                 MOD  staff refuse portal (Task 1); forPortal, portalPlaces, create() (Task 7)
app/Livewire/Maintenance/RequestForm.php                          MOD  staff channels (Task 1)
resources/views/livewire/maintenance/request-show.blade.php       MOD  creator names (Task 1); Portal badge (Task 7)
resources/views/livewire/maintenance/requests.blade.php           MOD  Portal badge (Task 7)
tests/Concurrency/VendorsMigrationTest.php                        MOD  real rollback order with the portal migration (Task 1)
app/Actions/Portal/SendLoginCode.php, VerifyLoginCode.php         NEW  (Task 2)
app/Mail/PortalSignInCode.php, resources/views/mail/portal-sign-in-code.blade.php  NEW (Task 2)
app/Http/Middleware/PortalSession.php                             NEW  (Task 3)
routes/portal.php                                                 NEW  (Task 3; Tasks 5, 6, 8 add routes)
bootstrap/app.php, app/Providers/AppServiceProvider.php           MOD  redirects, persistent middleware (Task 3)
app/Audit/AuthEventSubscriber.php                                 MOD  portal.login / portal.logout (Task 3)
app/Livewire/Concerns/InPortal.php                                NEW  (Task 3)
app/Livewire/Portal/Login.php, Home.php                           NEW  (Task 3); Home figures (Task 5)
app/Http/Controllers/Portal/LogoutController.php, LocaleController.php  NEW (Task 3)
app/Actions/Portal/SetPortalLocale.php                            NEW  (Task 3)
resources/views/layouts/portal.blade.php                          NEW  (Task 3; nav lines Tasks 5, 6, 8)
resources/views/livewire/portal/login.blade.php, home.blade.php   NEW  (Task 3); home (Tasks 5, 8)
lang/ar.json                                                      NEW  every portal string (Task 3)
app/Actions/Portal/InviteToPortal.php, SetPortalAccess.php        NEW  (Task 4)
app/Mail/PortalInvitation.php, resources/views/mail/portal-invitation.blade.php  NEW (Task 4)
app/Actions/Customers/SaveCustomer.php                            MOD  email sync (Task 4)
app/Livewire/Customers/Form.php, resources/views/livewire/customers/form.blade.php  MOD  portal card (Task 4); outstandingFils (Task 5)
app/Livewire/Portal/Agreements.php, Invoices.php, Payments.php, Cheques.php and views  NEW (Task 5)
app/Http/Controllers/Portal/DownloadController.php                NEW  agreement, invoice, receipt (Task 5); statement, document (Task 6)
resources/views/components/status-badge.blade.php                 MOD  label through __() (Task 5)
app/Billing/CustomerStatement.php, app/Http/Controllers/CustomerStatementPdfController.php  MOD  pdf() shared (Task 6)
app/Livewire/Portal/Statement.php, Documents.php and views        NEW  (Task 6)
app/Actions/Documents/ShareDocumentWithTenant.php                 NEW  (Task 6)
app/Livewire/Documents/Panel.php, resources/views/livewire/documents/panel.blade.php  MOD  share toggle (Task 6)
app/Actions/Documents/StoreDocument.php                           MOD  forPortal(), private store() (Task 7)
app/Mail/MaintenanceUpdate.php                                    MOD  locale('en') (Task 7)
app/Livewire/Portal/Maintenance.php, MaintenanceShow.php, NewMaintenanceRequest.php and views  NEW (Task 8)
tests/Pest.php                                                    MOD  portalAccount() (Task 1), portalCodeFor() (Task 2), portalStoredContract() (Task 5)
tests/Feature/Portal/StorageTest.php                              NEW  (Task 1)
tests/Feature/Portal/CodesTest.php                                NEW  (Task 2)
tests/Feature/Portal/SignInTest.php, ArabicTest.php               NEW  (Task 3)
tests/Feature/Portal/InviteTest.php                               NEW  (Task 4)
tests/Feature/Portal/PagesTest.php                                NEW  (Task 5)
tests/Feature/Portal/StatementAndDocumentsTest.php                NEW  (Task 6)
tests/Feature/Portal/PortalRequestsTest.php                       NEW  (Task 7)
tests/Feature/Portal/MaintenancePagesTest.php                     NEW  (Task 8)
tests/Feature/Portal/IsolationTest.php                            NEW  (Task 9)
```

---

### Task 1: Storage, the `tenant` guard, and staff routes that only take the `web` guard

**Files:**
- Create: `database/migrations/2026_12_22_000100_create_portal_tables.php`
- Create: `app/Models/PortalAccount.php`, `app/Models/PortalLoginCode.php`
- Modify: `config/auth.php`, `app/Enums/MaintenanceChannel.php`, `app/Models/MaintenanceRequest.php`, `app/Models/MaintenanceEvent.php`, `app/Models/Document.php`
- Modify: `app/Http/Middleware/EnsureUserIsActive.php`, `app/Http/Middleware/EnsureTwoFactorIsConfirmed.php`
- Modify: `routes/web.php`, `routes/settings.php`, `routes/admin.php`, `routes/property.php`
- Modify: `app/Actions/Maintenance/LogMaintenanceRequest.php`, `app/Livewire/Maintenance/RequestForm.php`, `resources/views/livewire/maintenance/request-show.blade.php`
- Modify: `tests/Pest.php`, `tests/Concurrency/VendorsMigrationTest.php`
- Test: `tests/Feature/Portal/StorageTest.php`

**Interfaces:**
- Consumes: `Customer`, `MaintenanceRequest`, `MaintenanceEvent`, `Document`, `StoreDocument::handle()`, `maintenanceRequest()`, `matrixUser()`, `EnsureNumberSequences`.
- Produces:
  - Tables `portal_accounts` (id, customer_id unique FK, email unique, locale `en`|`ar`, remember_token, invited_at, invited_by FK users, last_login_at, disabled_at, timestamps) and `portal_login_codes` (id, portal_account_id FK, code_hash char 64, expires_at, used_at, attempts, created_at).
  - `maintenance_requests.created_by` and `maintenance_events.user_id` and `documents.uploaded_by` nullable. Each table gets `portal_account_id` (nullable FK) and a CHECK "exactly one". `documents.shared_with_tenant` bool default false. Channel CHECK allows `portal`.
  - `PortalAccount` (implements `Illuminate\Contracts\Auth\Authenticatable`): `const LOCALES = ['en', 'ar']`, `customer(): BelongsTo` (with trashed), `isActive(): bool`, `name` attribute, `getAuthPassword(): string`.
  - `PortalLoginCode`: `static hash(PortalAccount $account, string $code): string`; columns as above, `UPDATED_AT = null`.
  - Guard `tenant` (`session`, provider `portal_accounts`, `remember` 43200).
  - `MaintenanceChannel::Portal` (`portal`, label "Tenant portal"), `MaintenanceChannel::staffCases(): list<self>`.
  - `MaintenanceRequest::portalAccount(): BelongsTo`, `creatorName(): string`.
  - `MaintenanceEvent::record(MaintenanceRequest $request, User|PortalAccount $by, ?WorkOrder $workOrder = null, ?string $from = null, ?string $to = null, ?string $note = null): self`, `portalAccount(): BelongsTo`, `byName(): string`.
  - `Document`: fillable `portal_account_id`, cast `shared_with_tenant` bool, `portalAccount(): BelongsTo`.
  - Pest helper `portalAccount(Customer $tenant, array $attributes = []): PortalAccount`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/StorageTest.php`:

```php
<?php

use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceChannel;
use App\Enums\RoleName as R;
use App\Livewire\Maintenance\RequestForm;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'Sara@Tenant.test']);
});

test('the tenant guard signs in portal accounts, remembers for 30 days, and is not the default', function () {
    expect(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.guards.tenant'))->toBe(['driver' => 'session', 'provider' => 'portal_accounts', 'remember' => 43200])
        ->and(config('auth.providers.portal_accounts'))->toBe(['driver' => 'eloquent', 'model' => PortalAccount::class])
        ->and(Auth::guard('tenant')->getRecallerName())->not->toBe(Auth::guard('web')->getRecallerName()); // its own remember cookie
    // The 30-day cookie itself is checked in SignInTest.
});

test('one account per tenant and per email; the locale is en or ar', function () {
    $account = portalAccount($this->tenant);
    $other = Customer::factory()->create(['email' => 'other@tenant.test']);

    expect($account->email)->toBe('sara@tenant.test')->and($account->locale)->toBe('en')
        ->and(fn () => portalAccount($this->tenant, ['email' => 'second@tenant.test']))->toThrow(QueryException::class)
        ->and(fn () => portalAccount($other, ['email' => 'sara@tenant.test']))->toThrow(QueryException::class)
        ->and(fn () => portalAccount($other, ['locale' => 'fr']))->toThrow(QueryException::class);
});

test('an account is active until it is disabled or its tenant is deleted, and names its tenant in the audit log', function () {
    $account = portalAccount($this->tenant);
    expect($account->isActive())->toBeTrue()->and($account->getAuthPassword())->toBe('');

    $account->forceFill(['disabled_at' => now()])->save();
    expect($account->isActive())->toBeFalse();

    $account->forceFill(['disabled_at' => null])->save();
    $this->tenant->delete();
    expect($account->fresh()->isActive())->toBeFalse()
        ->and($account->fresh()->name)->toBe('Tenant portal · Sara Tenant');
});

test('a request and a timeline row have exactly one creator: a staff user or a portal account', function () {
    $request = maintenanceRequest($this->pm, $this->unit);
    $event = $request->events()->sole();
    $account = portalAccount($this->tenant);
    $rows = fn (string $table, int $id) => DB::table($table)->where('id', $id);

    expect(fn () => $rows('maintenance_requests', $request->id)->update(['portal_account_id' => $account->id]))->toThrow(QueryException::class)
        ->and(fn () => $rows('maintenance_requests', $request->id)->update(['created_by' => null]))->toThrow(QueryException::class)
        ->and(fn () => $rows('maintenance_events', $event->id)->update(['portal_account_id' => $account->id]))->toThrow(QueryException::class)
        ->and(fn () => $rows('maintenance_events', $event->id)->update(['user_id' => null]))->toThrow(QueryException::class)
        ->and(fn () => $rows('maintenance_requests', $request->id)->update(['channel' => 'fax']))->toThrow(QueryException::class);

    $rows('maintenance_requests', $request->id)->update(['created_by' => null, 'portal_account_id' => $account->id, 'channel' => 'portal']);
    $rows('maintenance_events', $event->id)->update(['user_id' => null, 'portal_account_id' => $account->id]);

    expect($request->fresh()->channel)->toBe(MaintenanceChannel::Portal)
        ->and($request->fresh()->creatorName())->toBe('Tenant portal')
        ->and($event->fresh()->byName())->toBe('Tenant portal');

    // The staff page names the portal as the creator instead of failing on a missing user.
    $this->actingAs($this->pm)->get(route('maintenance.requests.show', $request))->assertOk()->assertSee('Tenant portal');
});

test('a document has exactly one uploader and is not shared with the tenant until staff share it', function () {
    Storage::fake('local');
    $document = app(StoreDocument::class)->handle($this->pm, $this->tenant, UploadedFile::fake()->create('rules.pdf', 10, 'application/pdf'), DocumentCategory::Other);
    $account = portalAccount($this->tenant);

    expect($document->fresh()->shared_with_tenant)->toBeFalse()
        ->and(fn () => DB::table('documents')->where('id', $document->id)->update(['uploaded_by' => null]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('documents')->where('id', $document->id)->update(['portal_account_id' => $account->id]))->toThrow(QueryException::class);
});

test('staff cannot log a request as coming from the portal, and their form does not offer it', function () {
    expect(fn () => maintenanceRequest($this->pm, $this->unit, ['channel' => 'portal']))->toThrow(ValidationException::class)
        ->and(MaintenanceChannel::staffCases())->not->toContain(MaintenanceChannel::Portal);

    Livewire::actingAs($this->pm)->test(RequestForm::class)->assertSee('WhatsApp')->assertDontSee('Tenant portal');
});

test('a tenant session never opens staff pages, and staff middleware leaves the tenant guard alone', function () {
    $account = portalAccount($this->tenant);
    $this->actingAs($account, 'tenant');

    foreach (['dashboard', 'customers.index', 'maintenance.requests.index', 'profile.edit'] as $name) {
        $this->get(route($name))->assertRedirect(route('login'))->assertSessionHasNoErrors(); // no "deactivated" message
    }
    expect(Auth::guard('tenant')->user()?->is($account))->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/StorageTest.php`
Expected: FAIL with `Call to undefined function portalAccount()` (and `Class "App\Models\PortalAccount" not found`).

- [ ] **Step 3: The migration** `database/migrations/2026_12_22_000100_create_portal_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-18 tenant portal §3 and §5: portal accounts and sign-in codes, and the portal account as the other
 * possible creator of a maintenance request, its timeline rows (plan ruling 4) and a document. Not money tables: no
 * triggers (EXPECTED_TRIGGERS unchanged).
 */
return new class extends Migration
{
    private const string OLD_CHANNELS = "'phone', 'whatsapp', 'walk_in', 'inspection', 'email'";

    /** table => the staff creator column that becomes nullable */
    private const array CREATORS = ['maintenance_requests' => 'created_by', 'maintenance_events' => 'user_id', 'documents' => 'uploaded_by'];

    public function up(): void
    {
        Schema::create('portal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained(); // no cascade: customers are soft-deleted
            $table->string('email')->unique();
            $table->string('locale', 2)->default('en');
            $table->rememberToken();
            $table->timestamp('invited_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE portal_accounts ADD CONSTRAINT portal_accounts_locale_chk CHECK (locale IN ('en', 'ar'))");

        Schema::create('portal_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_account_id')->constrained();
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['portal_account_id', 'used_at']);
        });

        foreach (self::CREATORS as $name => $creator) {
            Schema::table($name, function (Blueprint $table) use ($creator) {
                $table->unsignedBigInteger($creator)->nullable()->change();
                $table->foreignId('portal_account_id')->nullable()->after($creator)->constrained();
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_creator_chk CHECK (({$creator} IS NULL) <> (portal_account_id IS NULL))");
        }
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('shared_with_tenant')->default(false)->after('expires_on');
        });

        DB::statement('ALTER TABLE maintenance_requests DROP CHECK maintenance_requests_channel_chk');
        DB::statement('ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_channel_chk CHECK (channel IN ('.self::OLD_CHANNELS.", 'portal'))");
    }

    /** ponytail: only reversible before any portal row exists; with portal rows the NOT NULL changes fail, which is the safe outcome. */
    public function down(): void
    {
        DB::statement('ALTER TABLE maintenance_requests DROP CHECK maintenance_requests_channel_chk');
        DB::statement('ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_channel_chk CHECK (channel IN ('.self::OLD_CHANNELS.'))');
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('shared_with_tenant'));

        foreach (self::CREATORS as $name => $creator) {
            DB::statement("ALTER TABLE {$name} DROP CHECK {$name}_creator_chk");
            Schema::table($name, function (Blueprint $table) use ($creator) {
                $table->dropConstrainedForeignId('portal_account_id');
                $table->unsignedBigInteger($creator)->nullable(false)->change();
            });
        }

        Schema::dropIfExists('portal_login_codes');
        Schema::dropIfExists('portal_accounts');
    }
};
```

- [ ] **Step 4: The models.** Create `app/Models/PortalAccount.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec 2026-10-18 tenant portal §3: a tenant's sign-in to the portal, the `tenant` guard's user. Never a staff User.
 * Written only by App\Actions\Portal (and SaveCustomer's email sync); no password, sign-in is by emailed code.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $email lower case (plan ruling 5)
 * @property string $locale 'en' | 'ar'
 * @property CarbonImmutable|null $invited_at
 * @property int|null $invited_by
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $disabled_at
 * @property-read Customer $customer
 * @property-read string $name
 */
class PortalAccount extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public const array LOCALES = ['en', 'ar'];

    protected $fillable = ['customer_id', 'email', 'locale'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return ['invited_at' => 'immutable_datetime', 'last_login_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Customer, $this> with trashed: a deleted tenant's account still names them in the audit log */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** Spec §3: checked on every request. Not disabled, and the tenant not soft-deleted (read fresh, not from a loaded relation). */
    public function isActive(): bool
    {
        return $this->disabled_at === null && Customer::query()->whereKey($this->customer_id)->exists();
    }

    /** How the audit log viewer names this causer (plan ruling 9). */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => __('Tenant portal').' · '.$this->customer->name_en);
    }

    /** No password: sign-in is by emailed code (spec §3). */
    public function getAuthPassword(): string
    {
        return '';
    }
}
```

Create `app/Models/PortalLoginCode.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec 2026-10-18 tenant portal §3: one emailed sign-in code, stored hashed (plan ruling 5). Written only by
 * App\Actions\Portal\SendLoginCode and VerifyLoginCode.
 *
 * @property int $id
 * @property int $portal_account_id
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property int $attempts
 * @property CarbonImmutable $created_at
 */
class PortalLoginCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['portal_account_id', 'code_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }

    /** HMAC-SHA256 with the app key, bound to the account; compared with hash_equals (spec §6). */
    public static function hash(PortalAccount $account, string $code): string
    {
        return hash_hmac('sha256', $account->id.'|'.$code, (string) config('app.key'));
    }
}
```

- [ ] **Step 5: The guard.** In `config/auth.php`, add `use App\Models\PortalAccount;` under `use App\Models\User;`, and change the `guards` and `providers` arrays:

```php
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        // Spec 2026-10-18 tenant portal §3: tenants sign in here, never as staff Users. 30 days of "remember this device".
        'tenant' => [
            'driver' => 'session',
            'provider' => 'portal_accounts',
            'remember' => 43200,
        ],
    ],
```

```php
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],
        'portal_accounts' => [
            'driver' => 'eloquent',
            'model' => PortalAccount::class,
        ],
    ],
```

- [ ] **Step 6: The channel.** In `app/Enums/MaintenanceChannel.php` add the case after `case Email = 'email';`, its label, and `staffCases()`:

```php
    case Portal = 'portal'; // spec 2026-10-18 tenant portal §4: only LogMaintenanceRequest::forPortal() writes it
```

```php
            self::Email => __('Email'),
            self::Portal => __('Tenant portal'),
```

```php
    /** @return list<self> the channels staff choose from; "portal" is set only by the portal (plan ruling 14) */
    public static function staffCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => $c !== self::Portal));
    }
```

In `app/Actions/Maintenance/LogMaintenanceRequest.php` change the channel rule in `handle()` to:

```php
            'channel' => ['required', Rule::enum(MaintenanceChannel::class)->except([MaintenanceChannel::Portal])],
```

In `app/Livewire/Maintenance/RequestForm.php::render()` change `'channels' => MaintenanceChannel::cases(),` to `'channels' => MaintenanceChannel::staffCases(),`.

- [ ] **Step 7: Requests, timeline rows and documents may come from a portal account.** In `app/Models/MaintenanceRequest.php`:
  - Change the docblock line `@property int $created_by` to `@property int|null $created_by` and add `@property int|null $portal_account_id` after it.
  - Add after `creator()`:

```php
    /** @return BelongsTo<PortalAccount, $this> set when the tenant logged it in the portal (spec 2026-10-18 tenant portal §5) */
    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class);
    }

    /** Who logged it, for the staff request page. */
    public function creatorName(): string
    {
        return $this->creator?->name ?? __('Tenant portal');
    }
```

In `app/Models/MaintenanceEvent.php`:
  - Change `@property int $user_id` to `@property int|null $user_id` and add `@property int|null $portal_account_id`. Change `@property-read User $user` to `@property-read User|null $user`.
  - Add `'portal_account_id'` to `$fillable` after `'user_id'`.
  - Replace `record()` and add `portalAccount()` and `byName()`:

```php
    /** @return BelongsTo<PortalAccount, $this> */
    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class);
    }

    /** A status change when $to is given, otherwise a note. $by is staff, or the tenant's portal account (plan ruling 4). */
    public static function record(MaintenanceRequest $request, User|PortalAccount $by, ?WorkOrder $workOrder = null, ?string $from = null, ?string $to = null, ?string $note = null): self
    {
        return self::create([
            'maintenance_request_id' => $request->id, 'work_order_id' => $workOrder?->id, 'kind' => $to === null ? 'note' : 'status',
            'from_status' => $from, 'to_status' => $to, 'note' => $note,
            'user_id' => $by instanceof User ? $by->id : null, 'portal_account_id' => $by instanceof PortalAccount ? $by->id : null,
        ]);
    }

    /** Who wrote the row, for the staff timeline. */
    public function byName(): string
    {
        return $this->user?->name ?? __('Tenant portal');
    }
```

In `app/Models/Document.php`:
  - Add `'portal_account_id'` to `$fillable` after `'uploaded_by'`.
  - Change `casts()` to `return ['category' => DocumentCategory::class, 'expires_on' => 'date', 'size' => 'integer', 'shared_with_tenant' => 'boolean'];`.
  - Change the activity options to `LogOptions::defaults()->logOnly(['category', 'expires_on', 'shared_with_tenant'])->logOnlyDirty()->dontLogEmptyChanges();`.
  - Add the docblock lines `@property bool $shared_with_tenant` and `@property int|null $portal_account_id`, and after `uploader()`:

```php
    /** @return BelongsTo<PortalAccount, $this> set for a photo a tenant sent with a portal request */
    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class);
    }
```

In `resources/views/livewire/maintenance/request-show.blade.php` replace `{{ $request->creator->name }}` with `{{ $request->creatorName() }}`, and `{{ $event->user->name }}` with `{{ $event->byName() }}`.

- [ ] **Step 8: Staff middleware and routes take only the `web` guard** (plan ruling 1). In `app/Http/Middleware/EnsureUserIsActive.php` replace `handle()`:

```php
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user(); // staff only: a tenant (guard "tenant") never reaches staff checks (plan ruling 1)

        if ($user && ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('This account has been deactivated.')]);
        }

        return $next($request);
    }
``` In `app/Http/Middleware/EnsureTwoFactorIsConfirmed.php` add `use Illuminate\Support\Facades\Auth;` and change `$user = $request->user();` to:

```php
        $user = Auth::guard('web')->user(); // staff only (plan ruling 1)
```

In `routes/web.php`, `routes/settings.php`, `routes/admin.php` and `routes/property.php` change `Route::middleware(['auth'])` to `Route::middleware(['auth:web'])`.

- [ ] **Step 9: The Pest helper and the migration test.** In `tests/Pest.php` add `use App\Models\PortalAccount;` to the imports and at the end:

```php
/** A portal account for $tenant (portal tests): enabled, English, with the tenant's email in lower case, unless $attributes say otherwise. */
function portalAccount(Customer $tenant, array $attributes = []): PortalAccount
{
    $account = (new PortalAccount)->forceFill([
        'customer_id' => $tenant->id, 'email' => Str::lower((string) $tenant->email), 'locale' => 'en', 'invited_at' => now(), ...$attributes,
    ]);
    $account->save();

    return $account;
}
```

The portal migration alters `maintenance_requests`, `maintenance_events` and `documents`, so `tests/Concurrency/VendorsMigrationTest.php` must roll it back first and bring it up last. In that file:
  - In `beforeEach`, add after the `$this->maintenance = …` line:

```php
    $this->portal = require database_path('migrations/2026_12_22_000100_create_portal_tables.php');
```

  - In `afterEach`, add after the `work_orders` block:

```php
    if (! Schema::hasTable('portal_accounts')) { // the portal migration last, in up order
        $this->portal->up();
    }
```

  - In the test there are two `if (Schema::hasTable('work_orders')) {` blocks. Put this before each of them:

```php
    if (Schema::hasTable('portal_accounts')) {
        $this->portal->down(); // real rollback order: the portal migration alters maintenance_requests, so it goes back first
    }
```

  - At the end of the test, after `expect(Schema::hasTable('work_orders'))->toBeTrue();`, add:

```php
    $this->portal->up();
    expect(Schema::hasTable('portal_accounts'))->toBeTrue();
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Portal/StorageTest.php tests/Feature/Maintenance tests/Feature/Documents tests/Feature/Auth tests/Concurrency/VendorsMigrationTest.php`
Expected: PASS.

- [ ] **Step 11: Migrate the dev database.** Run `php artisan migrate --database=migrator --force`.
Expected: `2026_12_22_000100_create_portal_tables ... DONE`.

- [ ] **Step 12: Gates and commit.** Run `php vendor/bin/pint --dirty`, then `php vendor/bin/phpstan analyse --memory-limit=2G`, then `npm run build`.

```bash
git add database/migrations/2026_12_22_000100_create_portal_tables.php app/Models/PortalAccount.php app/Models/PortalLoginCode.php \
  config/auth.php app/Enums/MaintenanceChannel.php app/Models/MaintenanceRequest.php app/Models/MaintenanceEvent.php app/Models/Document.php \
  app/Http/Middleware/EnsureUserIsActive.php app/Http/Middleware/EnsureTwoFactorIsConfirmed.php \
  routes/web.php routes/settings.php routes/admin.php routes/property.php \
  app/Actions/Maintenance/LogMaintenanceRequest.php app/Livewire/Maintenance/RequestForm.php resources/views/livewire/maintenance/request-show.blade.php \
  tests/Pest.php tests/Concurrency/VendorsMigrationTest.php tests/Feature/Portal/StorageTest.php
git commit -m "$(cat <<'EOF'
Portal storage: portal accounts and sign-in codes, the tenant guard, portal creators on requests, timeline and documents; staff routes take only the web guard

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---
### Task 2: Sign-in codes: request, email, check (expiry, single use, attempts, rate limits, hashing, no enumeration)

**Files:**
- Create: `app/Actions/Portal/SendLoginCode.php`, `app/Actions/Portal/VerifyLoginCode.php`
- Create: `app/Mail/PortalSignInCode.php`, `resources/views/mail/portal-sign-in-code.blade.php`
- Modify: `tests/Pest.php`
- Test: `tests/Feature/Portal/CodesTest.php`

**Interfaces:**
- Consumes: `PortalAccount` (`isActive()`, `customer`), `PortalLoginCode::hash()`, `EmailChannel::ready()`, `<x-mail.bilingual>`, `CompanySetting::current()`, `Customer::displayName()`, `portalAccount()`.
- Produces:
  - `SendLoginCode::handle(mixed $email, string $ip): void`. `const VALID_MINUTES = 10`. Throws `ValidationException` (key `email`) when mail is not set up, the email is invalid, or a rate limit is hit. Otherwise it returns normally, whether or not a code was sent.
  - `VerifyLoginCode::handle(mixed $email, mixed $code, string $ip): ?PortalAccount`. `const MAX_ATTEMPTS = 5`. Returns the account and sets `last_login_at`, or null. Throws `ValidationException` (key `code`) when the IP limit is hit. It does not sign anyone in; the screen does (Task 3).
  - `App\Mail\PortalSignInCode(string $code, string $nameEn, string $nameAr)`: queued, `tries = 1`, public `$code`.
  - Rate-limit keys `portal-code-email:{sha256}`, `portal-code-ip:{ip}`, `portal-verify-ip:{ip}`.
  - Pest helper `portalCodeFor(string $email): string`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/CodesTest.php`:

```php
<?php

use App\Actions\Portal\SendLoginCode;
use App\Actions\Portal\VerifyLoginCode;
use App\Mail\PortalSignInCode;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\PortalLoginCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'sara@tenant.test']);
    $this->account = portalAccount($this->tenant);
    $this->send = fn (mixed $email = 'sara@tenant.test', string $ip = '10.0.0.1') => app(SendLoginCode::class)->handle($email, $ip);
    $this->verify = fn (mixed $code, mixed $email = 'sara@tenant.test', string $ip = '10.0.0.1') => app(VerifyLoginCode::class)->handle($email, $code, $ip);
});

test('a 6-digit code is emailed in both languages, stored only as a hash, and signs in once', function () {
    ($this->send)('  Sara@Tenant.TEST ');
    $code = portalCodeFor('sara@tenant.test');

    expect($code)->toMatch('/^\d{6}$/');
    $row = PortalLoginCode::query()->sole();
    expect($row->code_hash)->toBe(PortalLoginCode::hash($this->account, $code))->not->toBe($code)
        ->and(DB::table('portal_login_codes')->where('code_hash', $code)->exists())->toBeFalse()
        ->and($row->expires_at->equalTo(now()->addMinutes(10)))->toBeTrue()
        ->and($row->attempts)->toBe(0);

    $mail = Mail::queued(PortalSignInCode::class)->sole();
    expect($mail)->toBeInstanceOf(ShouldQueue::class)->and($mail->tries)->toBe(1)
        ->and($mail->hasTo('sara@tenant.test', 'Sara Tenant'))->toBeTrue();
    $mail->assertHasSubject('Your sign-in code · رمز الدخول · Demo Properties W.L.L.')
        ->assertSeeInOrderInHtml(['dir="ltr"', 'Dear Sara Tenant,', 'Your sign-in code is', $code, 'dir="rtl"', 'سارة', 'رمز الدخول', $code], false);

    expect(($this->verify)($code)?->is($this->account))->toBeTrue()
        ->and($this->account->fresh()->last_login_at?->equalTo(now()))->toBeTrue()
        ->and(($this->verify)($code))->toBeNull(); // once
});

test('a code works for 10 minutes', function (int $minutes, bool $works) {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    $this->travel($minutes)->minutes();

    expect(($this->verify)($code) !== null)->toBe($works);
})->with([
    'after 9 minutes' => [9, true],
    'after 10 minutes' => [10, false],
]);

test('a new code replaces the unused one', function () {
    ($this->send)();
    ($this->send)();
    $second = portalCodeFor('sara@tenant.test');

    expect(PortalLoginCode::query()->sole()->code_hash)->toBe(PortalLoginCode::hash($this->account, $second))
        ->and(($this->verify)($second))->not->toBeNull();
});

test('five wrong tries spend the code', function () {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $try) {
        expect(($this->verify)($wrong))->toBeNull();
    }
    expect(($this->verify)($code))->toBeNull()
        ->and(PortalLoginCode::query()->sole()->attempts)->toBe(5);
});

test('the right code on the fifth try still works', function () {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $try) {
        ($this->verify)($wrong);
    }
    expect(($this->verify)($code))->not->toBeNull();
});

test('unknown, disabled and deleted tenants get the same answer and no email', function () {
    portalAccount(Customer::factory()->create(['email' => 'off@tenant.test']), ['disabled_at' => now()]);
    $gone = Customer::factory()->create(['email' => 'gone@tenant.test']);
    portalAccount($gone);
    $gone->delete();

    foreach (['nobody@tenant.test', 'off@tenant.test', 'gone@tenant.test'] as $email) {
        ($this->send)($email); // returns normally: nothing tells them apart
        expect(($this->verify)('123456', $email))->toBeNull();
    }
    Mail::assertNothingQueued();
    expect(PortalLoginCode::count())->toBe(0);
});

test('a code stops working when the account is disabled or the tenant deleted', function (Closure $end) {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    $end->call($this);

    expect(($this->verify)($code))->toBeNull();
})->with([
    'disabled' => [fn () => $this->account->forceFill(['disabled_at' => now()])->save()],
    'tenant deleted' => [fn () => $this->tenant->delete()],
]);

test('5 code requests per email per hour and 20 per IP per hour', function () {
    foreach (range(1, 5) as $i) {
        ($this->send)('sara@tenant.test', '10.0.1.'.$i);
    }
    expect(fn () => ($this->send)('sara@tenant.test', '10.0.1.99'))->toThrow(ValidationException::class);
    Mail::assertQueued(PortalSignInCode::class, 5);

    RateLimiter::increment('portal-code-ip:10.0.2.1', 3600, 20);
    expect(fn () => ($this->send)('other@tenant.test', '10.0.2.1'))->toThrow(ValidationException::class);
    ($this->send)('other@tenant.test', '10.0.2.2'); // another IP is fine

    $this->travel(61)->minutes();
    ($this->send)('sara@tenant.test', '10.0.1.99');
    Mail::assertQueued(PortalSignInCode::class, 6);
});

test('10 code checks per IP per minute; a refused check costs the code nothing', function () {
    ($this->send)();
    $code = portalCodeFor('sara@tenant.test');
    RateLimiter::increment('portal-verify-ip:10.0.0.1', 60, 10);

    expect(fn () => ($this->verify)($code))->toThrow(ValidationException::class)
        ->and(PortalLoginCode::query()->sole()->attempts)->toBe(0)
        ->and(($this->verify)($code, 'sara@tenant.test', '10.0.0.2'))->not->toBeNull();
});

test('asking for a code needs an email address', function (mixed $email) {
    expect(fn () => ($this->send)($email))->toThrow(ValidationException::class);
    Mail::assertNothingQueued();
})->with([
    'empty' => [''],
    'not an email' => ['sara'],
    'an array' => [['sara@tenant.test']],
    'too long' => [str_repeat('a', 250).'@x.test'],
]);

test('tampered or malformed input is only a wrong code, and costs no attempt', function (mixed $code, mixed $email) {
    ($this->send)();

    expect(($this->verify)($code, $email))->toBeNull()
        ->and(PortalLoginCode::query()->sole()->attempts)->toBe(0);
})->with([
    'code as an array' => [['123456'], 'sara@tenant.test'],
    'five digits' => ['12345', 'sara@tenant.test'],
    'letters' => ['12a456', 'sara@tenant.test'],
    'email as an array' => ['123456', ['sara@tenant.test']],
    'no email' => ['123456', ''],
]);

test('no codes while portal email is not set up', function () {
    config(['mail.default' => 'log']);

    expect(fn () => ($this->send)())->toThrow(ValidationException::class);
    Mail::assertNothingQueued();
    expect(PortalLoginCode::count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/CodesTest.php`
Expected: FAIL with `Call to undefined function portalCodeFor()` (then `Class "App\Actions\Portal\SendLoginCode" not found`).

- [ ] **Step 3: The Pest helper.** In `tests/Pest.php` add `use App\Mail\PortalSignInCode;` and `use Illuminate\Support\Facades\Mail;` to the imports and at the end:

```php
/** The code in the last sign-in email queued to $email (portal tests; needs Mail::fake()). */
function portalCodeFor(string $email): string
{
    $mail = Mail::queued(PortalSignInCode::class, fn (PortalSignInCode $m) => $m->hasTo($email))->last();
    if ($mail === null) {
        throw new RuntimeException("No sign-in code was queued to {$email}");
    }

    return $mail->code;
}
```

- [ ] **Step 4: The email.** Create `app/Mail/PortalSignInCode.php`:

```php
<?php

namespace App\Mail;

use App\Actions\Portal\SendLoginCode;
use App\Models\CompanySetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Spec 2026-10-18 tenant portal §3: the 6-digit sign-in code, English then Arabic, in the tenant emails' layout (plan ruling 6). */
class PortalSignInCode extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $code, public string $nameEn, public string $nameAr)
    {
        $this->locale('en'); // the layout carries both languages; the English half stays English
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
        return str_replace(["\r", "\n"], ' ', implode(' · ', [__('Your sign-in code'), 'رمز الدخول', CompanySetting::current()->name_en]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.portal-sign-in-code', with: [
            'subjectLine' => $this->subjectLine(),
            'code' => $this->code,
            'nameEn' => $this->nameEn,
            'nameAr' => $this->nameAr,
            'minutes' => SendLoginCode::VALID_MINUTES,
        ]);
    }
}
```

Create `resources/views/mail/portal-sign-in-code.blade.php`:

```blade
<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        <p>{{ __('Your sign-in code is') }} <strong style="font-size: 20px; letter-spacing: 4px;">{{ $code }}</strong></p>
        <p>{{ __('It works once, for :minutes minutes. If you did not ask for it, you can ignore this email.', ['minutes' => $minutes]) }}</p>
    </x-slot:en>
    <x-slot:ar>
        <p>عزيزي المستأجر {{ $nameAr }}،</p>
        <p>رمز الدخول الخاص بكم هو <strong style="font-size: 20px; letter-spacing: 4px;">{{ $code }}</strong></p>
        <p>يمكن استخدام الرمز مرة واحدة خلال {{ $minutes }} دقائق. إذا لم تطلبوا هذا الرمز، يمكنكم تجاهل هذه الرسالة.</p>
    </x-slot:ar>
</x-mail.bilingual>
```

- [ ] **Step 5: Asking for a code.** Create `app/Actions/Portal/SendLoginCode.php`:

```php
<?php

namespace App\Actions\Portal;

use App\Mail\PortalSignInCode;
use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use App\Reminders\EmailChannel;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §3: email a 6-digit sign-in code to an active account. It never says whether the account
 * exists: unknown, disabled and deleted return the same way, inside the same time box (plan ruling 5).
 */
final class SendLoginCode
{
    public const int VALID_MINUTES = 10;

    public function handle(mixed $email, string $ip): void
    {
        if (! (new EmailChannel)->ready()) {
            throw ValidationException::withMessages(['email' => __('Portal email is not set up. Please contact the office.')]);
        }

        $email = is_string($email) ? Str::lower(trim($email)) : '';
        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']], ['email.*' => __('Enter your email address.')])->validate();

        // Every request counts, for known and unknown emails alike.
        $limits = ['portal-code-email:'.hash('sha256', $email) => 5, 'portal-code-ip:'.$ip => 20];
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['email' => __('Too many codes asked for. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
            }
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 3600);
        }

        (new Timebox)->call(function () use ($email) {
            $account = PortalAccount::query()->where('email', $email)->first();
            if ($account === null || ! $account->isActive()) {
                return;
            }

            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            DB::transaction(function () use ($account, $code) {
                PortalLoginCode::query()->where('portal_account_id', $account->id)->whereNull('used_at')->delete(); // a new code replaces the unused one
                PortalLoginCode::create([
                    'portal_account_id' => $account->id, 'code_hash' => PortalLoginCode::hash($account, $code), 'expires_at' => now()->addMinutes(self::VALID_MINUTES),
                ]);
            });

            $tenant = $account->customer;
            Mail::to(new Address($account->email, $tenant->name_en))->queue(new PortalSignInCode($code, $tenant->name_en, $tenant->displayName('ar')));
        }, 200_000);
    }
}
```

- [ ] **Step 6: Checking a code.** Create `app/Actions/Portal/VerifyLoginCode.php`:

```php
<?php

namespace App\Actions\Portal;

use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §3: a code is valid 10 minutes, once, for 5 tries. Returns the account it signs in, or
 * null for every wrong, spent, expired or unknown combination alike. Signing in is the screen's job (Task 3).
 */
final class VerifyLoginCode
{
    public const int MAX_ATTEMPTS = 5;

    public function handle(mixed $email, mixed $code, string $ip): ?PortalAccount
    {
        $key = 'portal-verify-ip:'.$ip;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['code' => __('Too many tries. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        RateLimiter::hit($key, 60);

        $email = is_string($email) ? Str::lower(trim($email)) : '';
        $code = is_string($code) ? trim($code) : '';
        if ($email === '' || preg_match('/^\d{6}$/', $code) !== 1) {
            return null; // malformed: no attempt is spent
        }

        return DB::transaction(function () use ($email, $code): ?PortalAccount {
            $account = PortalAccount::query()->where('email', $email)->first();
            if ($account === null || ! $account->isActive()) {
                return null;
            }

            $row = PortalLoginCode::query()->where('portal_account_id', $account->id)->whereNull('used_at')
                ->where('expires_at', '>', now())->where('attempts', '<', self::MAX_ATTEMPTS)
                ->latest('id')->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }

            $row->increment('attempts'); // the 5th wrong try spends the code
            if (! hash_equals($row->code_hash, PortalLoginCode::hash($account, $code))) {
                return null;
            }

            $row->forceFill(['used_at' => now()])->save();
            $account->forceFill(['last_login_at' => now()])->save();

            return $account;
        });
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Portal/CodesTest.php`
Expected: PASS.

- [ ] **Step 8: Gates and commit.** Run `php vendor/bin/pint --dirty`, then `php vendor/bin/phpstan analyse --memory-limit=2G`, then `npm run build`.

```bash
git add app/Actions/Portal/SendLoginCode.php app/Actions/Portal/VerifyLoginCode.php app/Mail/PortalSignInCode.php \
  resources/views/mail/portal-sign-in-code.blade.php tests/Pest.php tests/Feature/Portal/CodesTest.php
git commit -m "$(cat <<'EOF'
Portal sign-in codes: emailed in both languages, hashed, 10 minutes, once, 5 tries, rate limited, no account enumeration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---
### Task 3: The portal session: sign-in screen, layout in English and Arabic, sign out, the every-request check, audits

**Files:**
- Create: `routes/portal.php`, `app/Http/Middleware/PortalSession.php`
- Create: `app/Livewire/Concerns/InPortal.php`, `app/Livewire/Portal/Login.php`, `app/Livewire/Portal/Home.php`
- Create: `app/Http/Controllers/Portal/LogoutController.php`, `app/Http/Controllers/Portal/LocaleController.php`, `app/Actions/Portal/SetPortalLocale.php`
- Create: `resources/views/layouts/portal.blade.php`, `resources/views/livewire/portal/login.blade.php`, `resources/views/livewire/portal/home.blade.php`
- Create: `lang/ar.json`
- Modify: `routes/web.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `app/Audit/AuthEventSubscriber.php`, `app/Http/Middleware/EnsureTwoFactorIsConfirmed.php`
- Test: `tests/Feature/Portal/SignInTest.php`, `tests/Feature/Portal/ArabicTest.php`

**Interfaces:**
- Consumes: `SendLoginCode`, `VerifyLoginCode`, `PortalAccount` (`isActive()`, `LOCALES`), `EmailChannel::ready()`, `portalAccount()`, `portalCodeFor()`.
- Produces:
  - Routes (prefix `portal`, name `portal.`, middleware `PortalSession`): `portal.login` (guest:tenant), `portal.locale` (POST), and under `auth:tenant` `portal.home`, `portal.logout` (POST). Later tasks add their routes inside the `auth:tenant` group.
  - `App\Http\Middleware\PortalSession`: persistent for Livewire.
  - `trait App\Livewire\Concerns\InPortal { protected function account(): PortalAccount; protected function customerId(): int; }`. Both abort 403 unless the `tenant` guard holds an active account.
  - `App\Livewire\Portal\Login`: public `mixed $email`, `mixed $code`, `mixed $remember = true`, `#[Locked] bool $codeSent`; methods `sendCode()`, `signIn()`, `startAgain()`.
  - `App\Livewire\Portal\Home` (`#[Layout('layouts::portal')]`); Task 5 adds its figures.
  - `SetPortalLocale::handle(PortalAccount $account, string $locale): void`.
  - Layout `layouts::portal` with a `$nav` list of `[route name, label]` that Tasks 5, 6 and 8 extend.
  - `lang/ar.json` with every portal string of this plan.
  - Audit events `portal.login`, `portal.logout`.

- [ ] **Step 1: Write the failing tests.** `tests/Feature/Portal/SignInTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Portal\Home;
use App\Livewire\Portal\Login;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'sara@tenant.test']);
    $this->account = portalAccount($this->tenant);
});

test('the sign-in screen sends a code, signs in on the tenant guard, and remembers the device for 30 days by default', function () {
    $page = Livewire::test(Login::class)->set('email', 'sara@tenant.test')->call('sendCode')
        ->assertHasNoErrors()->assertSee("If this email has an account, we've sent a code.");
    $page->set('code', portalCodeFor('sara@tenant.test'))->call('signIn')->assertHasNoErrors()->assertRedirect(route('portal.home'));

    expect(Auth::guard('tenant')->user()?->is($this->account))->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();
    $cookie = Cookie::queued(Auth::guard('tenant')->getRecallerName());
    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBe(now()->addMinutes(43200)->getTimestamp());

    $login = Activity::query()->where('event', 'portal.login')->sole();
    expect($login->causer?->is($this->account))->toBeTrue()->and($login->subject?->is($this->account))->toBeTrue();
});

test('without "remember this device" no remember cookie is set', function () {
    Livewire::test(Login::class)->set('email', 'sara@tenant.test')->call('sendCode')
        ->set('code', portalCodeFor('sara@tenant.test'))->set('remember', false)->call('signIn')->assertRedirect(route('portal.home'));

    expect(Auth::guard('tenant')->check())->toBeTrue()
        ->and(Cookie::queued(Auth::guard('tenant')->getRecallerName()))->toBeNull();
});

test('an unknown email sees the same screen, and a wrong code signs nobody in', function () {
    Livewire::test(Login::class)->set('email', 'nobody@tenant.test')->call('sendCode')
        ->assertHasNoErrors()->assertSee("If this email has an account, we've sent a code.")
        ->set('code', '123456')->call('signIn')->assertHasErrors('code');

    Mail::assertNothingQueued();
    expect(Auth::guard('tenant')->check())->toBeFalse();
});

test('tampered sign-in input is a message, never a 500', function (string $property, mixed $value, string $step) {
    $page = Livewire::test(Login::class);
    if ($step === 'signIn') {
        $page->set('email', 'sara@tenant.test')->call('sendCode');
    }

    $page->set($property, $value)->call($step)->assertOk()->assertHasErrors($step === 'sendCode' ? 'email' : 'code');
    expect(Auth::guard('tenant')->check())->toBeFalse();
})->with([
    'email as an array' => ['email', ['x'], 'sendCode'],
    'code as an array' => ['code', ['123456'], 'signIn'],
    'remember as an array with a bad code' => ['remember', ['x'], 'signIn'],
]);

test('while portal email is not set up the screen says so and offers no form', function () {
    config(['mail.default' => 'log']);

    Livewire::test(Login::class)->assertSee('Portal email is not set up. Please contact the office.')->assertDontSee('Send code');
});

test('portal pages send guests to the portal sign-in, and signed-in tenants skip it', function () {
    $this->get(route('portal.home'))->assertRedirect(route('portal.login'));
    $this->get(route('portal.login'))->assertOk()->assertSee('Sign in to the tenant portal');

    $this->actingAs($this->account, 'tenant');
    $this->get(route('portal.login'))->assertRedirect(route('portal.home'));
    $this->get(route('portal.home'))->assertOk()->assertSee('Welcome, Sara Tenant');
});

test('a staff session does not open the portal, and staff without 2FA can still reach the portal sign-in', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $finance = User::factory()->create()->assignRole(RoleName::Finance); // needs 2FA, has none

    $this->actingAs($finance)->get(route('portal.home'))->assertRedirect(route('portal.login'));
    $this->get(route('portal.login'))->assertOk();
});

test('disabling the account or deleting the tenant ends the session on the next request', function (Closure $end) {
    $this->actingAs($this->account, 'tenant')->get(route('portal.home'))->assertOk();
    $end->call($this);

    $this->get(route('portal.home'))->assertRedirect(route('portal.login'))->assertSessionHas('status');
    expect(Auth::guard('tenant')->check())->toBeFalse();
    $this->get(route('portal.login'))->assertSee('Your portal access has ended. Please contact the office.');
})->with([
    'disabled' => [fn () => $this->account->forceFill(['disabled_at' => now()])->save()],
    'tenant deleted' => [fn () => $this->tenant->delete()],
]);

test('portal components refuse a disabled account themselves (Livewire tests run no middleware)', function () {
    $this->account->forceFill(['disabled_at' => now()])->save();

    Livewire::actingAs($this->account, 'tenant')->test(Home::class)->assertForbidden();
});

test('signing out ends only the tenant session and is audited', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff)->actingAs($this->account, 'tenant');

    $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));

    expect(Auth::guard('tenant')->check())->toBeFalse()
        ->and(Auth::guard('web')->user()?->is($staff))->toBeTrue()
        ->and(Activity::query()->where('event', 'portal.logout')->sole()->causer?->is($this->account))->toBeTrue();
    $this->get(route('profile.edit'))->assertOk();
});

test('the tenant switches to Arabic: the page turns right to left and the choice stays on the account', function () {
    $this->actingAs($this->account, 'tenant');

    $this->post(route('portal.locale'), ['locale' => 'ar'])->assertRedirect();
    expect($this->account->fresh()->locale)->toBe('ar');
    $this->get(route('portal.home'))->assertOk()
        ->assertSee('<html lang="ar" dir="rtl">', false)
        ->assertSee('أهلاً، سارة')
        ->assertSee('English');

    $this->post(route('portal.locale'), ['locale' => 'fr'])->assertRedirect(); // ignored
    expect($this->account->fresh()->locale)->toBe('ar');
});

test('a guest can read the sign-in screen in Arabic', function () {
    $this->post(route('portal.locale'), ['locale' => 'ar'])->assertRedirect();

    $this->get(route('portal.login'))->assertOk()->assertSee('dir="rtl"', false)->assertSee('تسجيل الدخول إلى بوابة المستأجر');
});
```

`tests/Feature/Portal/ArabicTest.php`:

```php
<?php

use App\Enums\AgreementStatus;
use App\Enums\ChequeStatus;
use App\Enums\DepositMovementType;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentMethod;
use App\Enums\Trade;

/** @return array<string, string> */
function portalArabic(): array
{
    return json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('every __() string in the portal files has an Arabic translation', function () {
    $files = [
        ...glob(resource_path('views/livewire/portal/*.blade.php')),
        resource_path('views/layouts/portal.blade.php'),
        ...glob(app_path('Livewire/Portal/*.php')),
        app_path('Http/Middleware/PortalSession.php'),
        app_path('Actions/Portal/SendLoginCode.php'),
        app_path('Actions/Portal/VerifyLoginCode.php'),
    ];
    $ar = portalArabic();
    $missing = [];
    foreach ($files as $file) {
        preg_match_all('/__\(\'((?:\\\\.|[^\'\\\\])*)\'/', (string) file_get_contents($file), $m);
        foreach ($m[1] as $key) {
            if (! array_key_exists(stripslashes($key), $ar)) {
                $missing[] = basename($file).': '.stripslashes($key);
            }
        }
    }

    expect($files)->not->toBeEmpty()->and($missing)->toBe([]);
});

test('the labels the portal shows from shared code have Arabic translations', function () {
    $labels = [
        ...array_map(fn (Trade $t) => $t->label(), Trade::cases()),
        ...array_map(fn (MaintenanceStatus $s) => $s->label(), MaintenanceStatus::cases()),
        ...array_map(fn (AgreementStatus $s) => $s->label(), [AgreementStatus::Active, AgreementStatus::Expired, AgreementStatus::Renewed, AgreementStatus::Closed, AgreementStatus::Terminated]),
        ...array_map(fn (ChequeStatus $s) => $s->label(), ChequeStatus::cases()),
        ...array_map(fn (PaymentMethod $m) => $m->label(), PaymentMethod::cases()),
        ...array_map(fn (DocumentCategory $c) => $c->label(), DocumentCategory::cases()),
        ...array_map(fn (DepositMovementType $t) => str($t->value)->headline()->toString(), DepositMovementType::cases()),
        'Confirmed', 'Reversed', // payment statuses, through <x-status-badge>
        'Paid', 'Unpaid', 'Partially paid', 'Overdue', // Invoice::displayLabel()
        'Invoice', 'Credit note', 'Payment', 'Payment reversal', 'Credit refund', 'Credit refund reversal', // CustomerStatement kinds
        'Building common area — :building', // LogMaintenanceRequest::portalPlaces()
        'You have sent :count requests today. Please call the office for anything else.', // LogMaintenanceRequest::forPortal()
        'Choose one of your units or a building common area.', 'Choose a category.', 'Describe the problem in up to 2000 characters.',
        'Say whether the vendor may enter.', 'Send up to 5 photos.', 'Each photo must be a JPG, PNG or WEBP picture of up to 10 MB.',
    ];

    expect(array_values(array_diff($labels, array_keys(portalArabic()))))->toBe([]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Portal/SignInTest.php tests/Feature/Portal/ArabicTest.php`
Expected: FAIL with `Route [portal.login] not defined` and `Class "App\Livewire\Portal\Login" not found` (ArabicTest: `file_get_contents(…/lang/ar.json): Failed to open stream`).

- [ ] **Step 3: Routes and redirects.** Create `routes/portal.php`:

```php
<?php

use App\Http\Controllers\Portal\LocaleController;
use App\Http\Controllers\Portal\LogoutController;
use App\Http\Middleware\PortalSession;
use App\Livewire\Portal;
use Illuminate\Support\Facades\Route;

/*
 * Spec 2026-10-18 tenant portal: the `tenant` guard only (plan ruling 1). Every record id is looked up inside the
 * signed-in tenant's records (plan ruling 18).
 */
Route::prefix('portal')->name('portal.')->middleware(PortalSession::class)->group(function () {
    Route::livewire('login', Portal\Login::class)->middleware('guest:tenant')->name('login');
    Route::post('locale', LocaleController::class)->middleware('throttle:30,1')->name('locale');

    Route::middleware('auth:tenant')->group(function () {
        Route::livewire('/', Portal\Home::class)->name('home');
        Route::post('logout', LogoutController::class)->name('logout');
    });
});
```

In `routes/web.php` add at the end:

```php

require __DIR__.'/portal.php';
```

In `bootstrap/app.php`, inside `withMiddleware`, after the `trustHosts(...)` line:

```php
        // Spec 2026-10-18 tenant portal: each guard has its own sign-in page (plan ruling 1).
        $portal = fn (Request $request): bool => $request->is('portal', 'portal/*');
        $middleware->redirectGuestsTo(fn (Request $request) => $portal($request) ? route('portal.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $portal($request) ? route('portal.home') : route('dashboard'));
```

In `app/Providers/AppServiceProvider.php` add `use App\Http\Middleware\PortalSession;` and change the persistent middleware line to:

```php
        // Livewire update requests re-run these against the component's original page route.
        Livewire::addPersistentMiddleware([EnsureTwoFactorIsConfirmed::class, PortalSession::class]);
```

In `app/Http/Middleware/EnsureTwoFactorIsConfirmed.php` add to `EXEMPT_ROUTES` after `'*livewire.update',`:

```php
        'portal.*', // the tenant portal: no staff 2FA (spec 2026-10-18 tenant portal, plan ruling 1)
```

- [ ] **Step 4: The every-request check and the locale.** Create `app/Http/Middleware/PortalSession.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\PortalAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 2026-10-18 tenant portal §3 and §4, on every portal request and (persistent) every portal Livewire update: the page
 * speaks the account's language, or the guest's choice; a disabled account or a deleted tenant ends the tenant session
 * (plan rulings 2 and 16). Uses session() rather than $request->session(): Livewire re-runs this on a copied request.
 */
final class PortalSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('tenant');
        $account = $guard->user();

        $locale = $account instanceof PortalAccount ? $account->locale : session('portal_locale');
        App::setLocale(in_array($locale, PortalAccount::LOCALES, true) ? $locale : 'en');

        if ($account instanceof PortalAccount && ! $account->isActive()) {
            $guard->logout(); // the tenant guard only (plan ruling 3)
            session()->regenerateToken();

            return redirect()->route('portal.login')->with('status', __('Your portal access has ended. Please contact the office.'));
        }

        return $next($request);
    }
}
```

- [ ] **Step 5: Audit the tenant guard's sign-in and sign-out.** In `app/Audit/AuthEventSubscriber.php` replace `logUserEvent()`:

```php
    public function logUserEvent(Login|Logout|PasswordReset|TwoFactorAuthenticationEvent|RecoveryCodesGenerated $event): void
    {
        $user = $event->user instanceof Model ? $event->user : null;
        $name = self::USER_EVENTS[$event::class];
        // Spec 2026-10-18 tenant portal §3: the tenant guard's events are the portal's own (plan ruling 9).
        if (($event instanceof Login || $event instanceof Logout) && $event->guard === 'tenant') {
            $name = $event instanceof Login ? 'portal.login' : 'portal.logout';
        }

        Audit::log($name, $user, causer: $user);
    }
```

- [ ] **Step 6: The trait every portal component uses.** Create `app/Livewire/Concerns/InPortal.php`:

```php
<?php

namespace App\Livewire\Concerns;

use App\Models\PortalAccount;
use Illuminate\Support\Facades\Auth;

/**
 * Spec 2026-10-18 tenant portal §6: every portal query starts from the signed-in account. Protected, so the browser
 * cannot call these; they check the guard themselves because Livewire tests run no middleware (plan ruling 2).
 */
trait InPortal
{
    protected function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);

        return $account;
    }

    protected function customerId(): int
    {
        return $this->account()->customer_id;
    }
}
```

- [ ] **Step 7: Sign-in, home, sign-out and language.** Create `app/Livewire/Portal/Login.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Actions\Portal\SendLoginCode;
use App\Actions\Portal\VerifyLoginCode;
use App\Reminders\EmailChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §3: email → code → session on the tenant guard. Nothing here says whether an email has an account. */
#[Layout('layouts::portal')]
class Login extends Component
{
    // Untyped on purpose: a tampered payload reaches the Actions' checks instead of a TypeError (a 500).
    public mixed $email = '';

    public mixed $code = '';

    public mixed $remember = true; // spec §3: "remember this device" defaults on

    #[Locked]
    public bool $codeSent = false;

    public function sendCode(SendLoginCode $send): void
    {
        $send->handle($this->email, (string) request()->ip());

        $this->codeSent = true;
        $this->code = '';
    }

    public function signIn(VerifyLoginCode $verify): void
    {
        $account = $verify->handle($this->email, $this->code, (string) request()->ip());
        if ($account === null) {
            throw ValidationException::withMessages(['code' => __('That code is wrong or has expired. Ask for a new one.')]);
        }

        Auth::guard('tenant')->login($account, $this->remember === true);
        session()->regenerate();
        session()->put('portal_locale', $account->locale);

        $this->redirectRoute('portal.home');
    }

    public function startAgain(): void
    {
        $this->codeSent = false;
        $this->code = '';
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.portal.login', ['mailReady' => (new EmailChannel)->ready()])->title(__('Sign in'));
    }
}
```

Create `app/Livewire/Portal/Home.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the tenant's home page. */
#[Layout('layouts::portal')]
class Home extends Component
{
    use InPortal;

    public function render(): View
    {
        $customer = $this->account()->customer;

        return view('livewire.portal.home', [
            'name' => $customer->displayName(app()->getLocale()),
        ])->title(__('Home'));
    }
}
```

Create `app/Http/Controllers/Portal/LogoutController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Spec 2026-10-18 tenant portal §3: sign out ends the tenant guard only; a staff session in the same browser survives (plan ruling 3). */
final class LogoutController
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('tenant')->logout(); // audited portal.logout by AuthEventSubscriber
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
```

Create `app/Actions/Portal/SetPortalLocale.php`:

```php
<?php

namespace App\Actions\Portal;

use App\Models\PortalAccount;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-18 tenant portal §3: the account's language, en or ar. */
final class SetPortalLocale
{
    public function handle(PortalAccount $account, string $locale): void
    {
        if (! in_array($locale, PortalAccount::LOCALES, true)) {
            throw ValidationException::withMessages(['locale' => __('Choose English or Arabic.')]);
        }

        $account->forceFill(['locale' => $locale])->save();
    }
}
```

Create `app/Http/Controllers/Portal/LocaleController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\SetPortalLocale;
use App\Models\PortalAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Spec 2026-10-18 tenant portal §4: switch the portal language; kept on the account when signed in, in the session otherwise. */
final class LocaleController
{
    public function __invoke(Request $request, SetPortalLocale $set): RedirectResponse
    {
        $locale = $request->input('locale');
        if (is_string($locale) && in_array($locale, PortalAccount::LOCALES, true)) {
            $request->session()->put('portal_locale', $locale);
            $account = Auth::guard('tenant')->user();
            if ($account instanceof PortalAccount) {
                $set->handle($account, $locale);
            }
        }

        return redirect()->back(fallback: route('portal.login'));
    }
}
```

- [ ] **Step 8: The layout and the two pages.** Create `resources/views/layouts/portal.blade.php`:

```blade
@php
    $account = auth('tenant')->user();
    $rtl = app()->getLocale() === 'ar';
    $company = \App\Models\CompanySetting::query()->find(1);
    $companyName = $company ? ($rtl && filled($company->name_ar) ? $company->name_ar : $company->name_en) : config('app.name');
    // [route, label]; each page's task adds its line (plan File Structure).
    $nav = [
        ['portal.home', __('Home')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        {{-- Spec 2026-10-18 tenant portal §4: mobile first; the 16px gutter is px-4; only the menu scrolls sideways. --}}
        <header class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                <a href="{{ route($account ? 'portal.home' : 'portal.login') }}" class="font-semibold">{{ $companyName }}</a>
                <div class="flex items-center gap-1">
                    {{-- Each language is named in its own language (plan ruling 16). --}}
                    <form method="POST" action="{{ route('portal.locale') }}">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $rtl ? 'en' : 'ar' }}">
                        <flux:button type="submit" size="sm" variant="ghost">{{ $rtl ? 'English' : 'العربية' }}</flux:button>
                    </form>
                    @if ($account)
                        <form method="POST" action="{{ route('portal.logout') }}">
                            @csrf
                            <flux:button type="submit" size="sm" variant="ghost">{{ __('Sign out') }}</flux:button>
                        </form>
                    @endif
                </div>
            </div>
            @if ($account)
                <nav aria-label="{{ __('Tenant portal') }}" class="mx-auto max-w-3xl overflow-x-auto px-4">
                    <ul class="flex gap-1 pb-2">
                        @foreach ($nav as [$route, $label])
                            <li class="shrink-0">
                                <a href="{{ route($route) }}" @if (request()->routeIs($route.'*')) aria-current="page" @endif
                                    @class([
                                        'block rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                                        'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => request()->routeIs($route.'*'),
                                        'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700' => ! request()->routeIs($route.'*'),
                                    ])>{{ $label }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6">
            {{ $slot }}
        </main>

        @fluxScripts
    </body>
</html>
```

Create `resources/views/livewire/portal/login.blade.php`:

```blade
<section class="mx-auto w-full max-w-sm space-y-6">
    <flux:heading size="xl" level="1">{{ __('Sign in to the tenant portal') }}</flux:heading>

    @if (session('status'))
        <flux:text>{{ session('status') }}</flux:text>
    @endif

    @if (! $mailReady)
        <flux:text>{{ __('Portal email is not set up. Please contact the office.') }}</flux:text>
    @elseif (! $codeSent)
        <form wire:submit="sendCode" class="space-y-4">
            <flux:input wire:model="email" type="email" :label="__('Email address')" autocomplete="email" required autofocus />
            <flux:button type="submit" variant="primary" class="w-full">{{ __('Send code') }}</flux:button>
        </form>
    @else
        <flux:text>{{ __('If this email has an account, we\'ve sent a code.') }} {{ __('It works once, for 10 minutes.') }}</flux:text>
        <form wire:submit="signIn" class="space-y-4">
            <flux:input wire:model="code" :label="__('Code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
            <flux:checkbox wire:model="remember" :label="__('Remember this device for 30 days')" />
            <flux:button type="submit" variant="primary" class="w-full">{{ __('Sign in') }}</flux:button>
        </form>
        <flux:button variant="ghost" size="sm" wire:click="startAgain">{{ __('Use another email or get a new code') }}</flux:button>
    @endif
</section>
```

Portal files write every `__()` string in single quotes (an apostrophe as `\'`), because `ArabicTest` reads only those.

Create `resources/views/livewire/portal/home.blade.php`:

```blade
<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>
</section>
```

- [ ] **Step 9: The Arabic strings.** Create `lang/ar.json` with every portal string of this plan. Later tasks use these keys exactly, and `ArabicTest` fails on any key that is missing:

```json
{
    ":amount BHD": ":amount د.ب",
    ":amount BHD a month": ":amount د.ب شهرياً",
    "Agreement PDF": "العقد بصيغة PDF",
    "Agreements": "العقود",
    "Agreements ending soon": "عقود تنتهي قريباً",
    "All": "الكل",
    "All requests": "كل الطلبات",
    "Amounts are in BHD.": "المبالغ بالدينار البحريني.",
    "Balance": "الرصيد",
    "Balance :amount": "الرصيد :amount",
    "Balance owed": "المبلغ المستحق",
    "Building common area — :building": "المنطقة المشتركة في المبنى — :building",
    "Cancelled": "ملغى",
    "Category": "الفئة",
    "Cheques": "الشيكات",
    "Choose English or Arabic.": "اختر الإنجليزية أو العربية.",
    "Choose a category.": "اختر الفئة.",
    "Choose a date": "اختر تاريخاً",
    "Choose a valid date range.": "اختر فترة تواريخ صحيحة.",
    "Choose one of your units or a building common area.": "اختر إحدى وحداتك أو منطقة مشتركة في المبنى.",
    "Choose…": "اختر…",
    "Closing balance :amount BHD. A negative balance is credit in your favour.": "الرصيد الختامي :amount د.ب. الرصيد السالب رصيد دائن لصالحك.",
    "Code": "الرمز",
    "Completed": "تم الإنجاز",
    "Completed :date": "أُنجز في :date",
    "Credit": "الرصيد الدائن",
    "Deposit — :unit": "التأمين — :unit",
    "Describe the problem in up to 2000 characters.": "صف المشكلة في 2000 حرف كحد أقصى.",
    "Documents": "المستندات",
    "Due :date": "الاستحقاق :date",
    "Each photo must be a JPG, PNG or WEBP picture of up to 10 MB.": "يجب أن تكون كل صورة بصيغة JPG أو PNG أو WEBP وبحجم لا يتجاوز 10 ميغابايت.",
    "Email address": "البريد الإلكتروني",
    "Ends :date": "ينتهي في :date",
    "Enter your email address.": "أدخل بريدك الإلكتروني.",
    "Entry permitted": "يُسمح بالدخول",
    "From": "من",
    "Held :amount": "المحتجز :amount",
    "Home": "الرئيسية",
    "If this email has an account, we've sent a code.": "إذا كان لهذا البريد حساب، فقد أرسلنا إليه رمزاً.",
    "Invoice PDF": "الفاتورة بصيغة PDF",
    "Invoices": "الفواتير",
    "Issued :date": "صدرت في :date",
    "It works once, for 10 minutes.": "يُستخدم مرة واحدة خلال 10 دقائق.",
    "Maintenance": "الصيانة",
    "New maintenance request": "طلب صيانة جديد",
    "New request": "طلب جديد",
    "Next invoice due": "الفاتورة المستحقة التالية",
    "No": "لا",
    "No agreement ends in the next 90 days.": "لا ينتهي أي عقد خلال الأيام التسعين القادمة.",
    "No agreements yet.": "لا توجد عقود بعد.",
    "No cheques are waiting to be deposited.": "لا توجد شيكات بانتظار الإيداع.",
    "No cheques yet.": "لا توجد شيكات بعد.",
    "No documents have been shared with you yet.": "لم تتم مشاركة أي مستندات معك بعد.",
    "No invoices here.": "لا توجد فواتير هنا.",
    "No open requests.": "لا توجد طلبات مفتوحة.",
    "No payments yet.": "لا توجد مدفوعات بعد.",
    "No requests yet.": "لا توجد طلبات بعد.",
    "Nothing is due.": "لا يوجد مبلغ مستحق.",
    "Open maintenance requests": "طلبات الصيانة المفتوحة",
    "Opening balance": "الرصيد الافتتاحي",
    "PDF": "PDF",
    "Paid": "مدفوع",
    "Payments": "المدفوعات",
    "Photos (up to 5; JPG, PNG or WEBP; 10 MB each)": "الصور (حتى 5؛ بصيغة JPG أو PNG أو WEBP؛ 10 ميغابايت لكل صورة)",
    "Portal email is not set up. Please contact the office.": "البريد الإلكتروني للبوابة غير مُعدّ. يرجى التواصل مع المكتب.",
    "Problem": "المشكلة",
    "Progress": "سير العمل",
    "Receipt PDF": "الإيصال بصيغة PDF",
    "Remember this device for 30 days": "تذكّر هذا الجهاز لمدة 30 يوماً",
    "Request received": "تم استلام الطلب",
    "Say whether the vendor may enter.": "حدّد ما إذا كان يُسمح للفني بالدخول.",
    "Send code": "أرسل الرمز",
    "Send request": "أرسل الطلب",
    "Send up to 5 photos.": "أرسل 5 صور كحد أقصى.",
    "Sent": "أُرسل",
    "Sent :date": "أُرسل في :date",
    "Sign in": "تسجيل الدخول",
    "Sign in to the tenant portal": "تسجيل الدخول إلى بوابة المستأجر",
    "Sign out": "تسجيل الخروج",
    "Statement": "كشف الحساب",
    "Tenant portal": "بوابة المستأجر",
    "That code is wrong or has expired. Ask for a new one.": "الرمز غير صحيح أو انتهت صلاحيته. اطلب رمزاً جديداً.",
    "The vendor may enter when I am out": "يُسمح للفني بالدخول في غيابي",
    "To": "إلى",
    "Too many codes asked for. Try again in :minutes minutes.": "طلبت رموزاً كثيرة. حاول مجدداً بعد :minutes دقيقة.",
    "Too many tries. Try again in :seconds seconds.": "محاولات كثيرة. حاول مجدداً بعد :seconds ثانية.",
    "Total": "الإجمالي",
    "Unpaid": "غير مدفوع",
    "Upcoming cheques": "الشيكات القادمة",
    "Uploading…": "جارٍ الرفع…",
    "Use another email or get a new code": "استخدم بريداً آخر أو اطلب رمزاً جديداً",
    "Welcome, :name": "أهلاً، :name",
    "What is the problem?": "ما المشكلة؟",
    "Where is the problem?": "أين المشكلة؟",
    "Work assigned": "تم إسناد العمل",
    "Yes": "نعم",
    "You have no current agreement, so requests cannot be sent here. Please call the office.": "ليس لديك عقد ساري، لذا لا يمكن إرسال الطلبات من هنا. يرجى الاتصال بالمكتب.",
    "You have sent :count requests today. Please call the office for anything else.": "لقد أرسلت :count طلبات اليوم. يرجى الاتصال بالمكتب لأي طلب آخر.",
    "Your portal access has ended. Please contact the office.": "انتهى وصولك إلى البوابة. يرجى التواصل مع المكتب.",

    "Plumbing": "السباكة",
    "Electrical": "الكهرباء",
    "Air conditioning": "التكييف",
    "Carpentry": "النجارة",
    "Painting": "الدهان",
    "Cleaning": "التنظيف",
    "Pest control": "مكافحة الحشرات",
    "General": "عام",
    "Open": "مفتوح",
    "In progress": "قيد التنفيذ",
    "Resolved": "تم الحل",
    "Active": "ساري",
    "Expired": "منتهي",
    "Renewed": "مُجدَّد",
    "Closed": "مغلق",
    "Terminated": "مُنهى",
    "Held": "محتجز",
    "Issued": "صادر",
    "Deposited": "مودَع",
    "Cleared": "محصَّل",
    "Bounced": "مرتجع",
    "Replaced": "مستبدَل",
    "Returned": "مُعاد",
    "Cash": "نقداً",
    "Bank Transfer": "تحويل بنكي",
    "Cheque": "شيك",
    "Card": "بطاقة",
    "Deposit Applied": "خصم من التأمين",
    "Split": "مجزأ",
    "Photo": "صورة",
    "ID copy": "نسخة الهوية",
    "CR copy": "نسخة السجل التجاري",
    "Signed contract": "العقد الموقّع",
    "Cheque image": "صورة الشيك",
    "Move-out photo": "صورة الإخلاء",
    "Owner approval": "موافقة المالك",
    "Generated PDF": "مستند PDF",
    "Maintenance photo": "صورة الصيانة",
    "Other": "أخرى",
    "Received": "مستلم",
    "Applied": "مستخدم",
    "Refunded": "مسترد",
    "Transfer In": "تحويل وارد",
    "Transfer Out": "تحويل صادر",
    "Opening": "رصيد افتتاحي",
    "Confirmed": "مؤكد",
    "Reversed": "معكوس",
    "Partially paid": "مدفوع جزئياً",
    "Overdue": "متأخر",
    "Invoice": "فاتورة",
    "Credit note": "إشعار دائن",
    "Payment": "دفعة",
    "Payment reversal": "عكس دفعة",
    "Credit refund": "استرداد رصيد",
    "Credit refund reversal": "عكس استرداد رصيد"
}
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Portal tests/Feature/Auth tests/Feature/Audit`
Expected: PASS. `ArabicTest` passes because `lang/ar.json` already holds the keys of later tasks; its first test checks only files that exist.

- [ ] **Step 11: Gates and commit.** Run `php vendor/bin/pint --dirty`, then `php vendor/bin/phpstan analyse --memory-limit=2G`, then `npm run build`.

```bash
git add routes/portal.php routes/web.php bootstrap/app.php app/Providers/AppServiceProvider.php app/Audit/AuthEventSubscriber.php \
  app/Http/Middleware/PortalSession.php app/Http/Middleware/EnsureTwoFactorIsConfirmed.php app/Livewire/Concerns/InPortal.php \
  app/Livewire/Portal/Login.php app/Livewire/Portal/Home.php app/Http/Controllers/Portal/LogoutController.php app/Http/Controllers/Portal/LocaleController.php \
  app/Actions/Portal/SetPortalLocale.php resources/views/layouts/portal.blade.php resources/views/livewire/portal/login.blade.php \
  resources/views/livewire/portal/home.blade.php lang/ar.json tests/Feature/Portal/SignInTest.php tests/Feature/Portal/ArabicTest.php
git commit -m "$(cat <<'EOF'
Portal session: code sign-in screen on the tenant guard, remember 30 days, sign out, every-request account check, EN/AR with RTL, portal.login/logout audits

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---
### Task 4: Invite, re-send, disable and enable on the tenant page; the invite email; the tenant form keeps the account's email in step

**Files:**
- Create: `app/Actions/Portal/InviteToPortal.php`, `app/Actions/Portal/SetPortalAccess.php`
- Create: `app/Mail/PortalInvitation.php`, `resources/views/mail/portal-invitation.blade.php`
- Modify: `app/Models/Customer.php`, `app/Actions/Customers/SaveCustomer.php`
- Modify: `app/Livewire/Customers/Form.php`, `resources/views/livewire/customers/form.blade.php`
- Test: `tests/Feature/Portal/InviteTest.php`

**Interfaces:**
- Consumes: `PortalAccount`, `PortalLoginCode`, `SendLoginCode` (to show codes are cleared), `CustomerPolicy::update`, `EmailChannel::ready()`, `Audit::log()`, `<x-mail.bilingual>`, route `portal.login`, `matrixUser()`, `activeAgreement()`, `portalAccount()`.
- Produces:
  - `Customer::portalAccount(): HasOne`.
  - `InviteToPortal::handle(User $actor, Customer $customer): PortalAccount`. Audit `portal.invited` (properties `customer_id`, `email`, `resent`). Throws `AuthorizationException`, or `ValidationException` with key `portal`.
  - `SetPortalAccess::handle(User $actor, Customer $customer, bool $enabled): PortalAccount`. Audit `portal.enabled` / `portal.disabled`. Same exceptions.
  - `App\Mail\PortalInvitation(PortalAccount $account)`: queued, `tries = 1`.
  - `Customers\Form` public methods `invitePortal()`, `disablePortal()`, `enablePortal()`, with no arguments. Errors go under `portal`.
  - `SaveCustomer`: email sync and the two new refusals, key `email`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/InviteTest.php`:

```php
<?php

use App\Actions\Portal\InviteToPortal;
use App\Actions\Portal\SendLoginCode;
use App\Actions\Portal\SetPortalAccess;
use App\Enums\RoleName as R;
use App\Livewire\Customers\Form;
use App\Mail\PortalInvitation;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create();
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'Sara@Tenant.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($this->building)->create()]);
    $this->leasing = matrixUser(R::Leasing, $this->building);
    $this->invite = fn (User $by) => app(InviteToPortal::class)->handle($by, $this->tenant->fresh());
    $this->page = fn () => Livewire::actingAs($this->leasing)->test(Form::class, ['customer' => $this->tenant->fresh()]);
});

test('staff invite a tenant from the tenant page: the account takes the tenant email and a bilingual invite is queued', function () {
    ($this->page)()->assertSee('Tenant portal')->assertSee('Not invited yet.')->assertSee('Invite to portal')
        ->call('invitePortal')->assertHasNoErrors()->assertSee('Re-send invite')->assertSee('Disable');

    $account = PortalAccount::query()->sole();
    expect($account->customer_id)->toBe($this->tenant->id)
        ->and($account->email)->toBe('sara@tenant.test')
        ->and($account->invited_by)->toBe($this->leasing->id)
        ->and($account->invited_at?->equalTo(now()))->toBeTrue()
        ->and($account->isActive())->toBeTrue()
        ->and($this->tenant->fresh()->portalAccount?->is($account))->toBeTrue();

    $mail = Mail::queued(PortalInvitation::class)->sole();
    expect($mail)->toBeInstanceOf(ShouldQueue::class)->and($mail->tries)->toBe(1)
        ->and($mail->hasTo('sara@tenant.test', 'Sara Tenant'))->toBeTrue();
    $mail->assertHasSubject('Your tenant portal · بوابة المستأجر · Demo Properties W.L.L.')
        ->assertSeeInOrderInHtml(['dir="ltr"', 'Dear Sara Tenant,', route('portal.login'), 'dir="rtl"', 'سارة', route('portal.login')], false);

    $audit = Activity::query()->where('event', 'portal.invited')->sole();
    expect($audit->causer?->is($this->leasing))->toBeTrue()
        ->and($audit->subject?->is($account))->toBeTrue()
        ->and($audit->getProperty('resent'))->toBeFalse();
});

test('re-sending keeps one account, queues another invite and is audited as a re-send', function () {
    ($this->invite)($this->leasing);
    ($this->invite)($this->leasing);

    expect(PortalAccount::count())->toBe(1);
    Mail::assertQueued(PortalInvitation::class, 2);
    expect(Activity::query()->where('event', 'portal.invited')->orderBy('id')->get()->map->getProperty('resent')->all())->toBe([false, true]);
});

test('disable and enable are audited, disabling deletes unused codes, and inviting re-enables a disabled account', function () {
    ($this->invite)($this->leasing);
    app(SendLoginCode::class)->handle('sara@tenant.test', '10.0.0.1');
    expect(PortalLoginCode::count())->toBe(1);

    $page = ($this->page)();
    $page->call('disablePortal')->assertHasNoErrors()->assertSee('Portal access is disabled.')->assertSee('Enable')->assertSee('Invite to portal');
    expect(PortalAccount::query()->sole()->disabled_at)->not->toBeNull()
        ->and(PortalLoginCode::count())->toBe(0);

    $page->call('disablePortal'); // already disabled: nothing written, nothing audited
    $page->call('enablePortal')->assertHasNoErrors()->assertSee('Re-send invite');
    expect(PortalAccount::query()->sole()->disabled_at)->toBeNull();

    $page->call('disablePortal');
    ($this->invite)($this->leasing);
    expect(PortalAccount::query()->sole()->disabled_at)->toBeNull()
        ->and(Activity::query()->where('event', 'like', 'portal.%')->orderBy('id')->pluck('event')->all())
        ->toBe(['portal.invited', 'portal.disabled', 'portal.enabled', 'portal.disabled', 'portal.invited']);
});

test('only staff who can manage the tenant can invite, disable or enable', function () {
    $finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance); // sees tenants, cannot manage them
    $elsewhere = matrixUser(R::Leasing, Building::factory()->create());

    foreach ([$finance, $elsewhere] as $user) {
        expect(fn () => ($this->invite)($user))->toThrow(AuthorizationException::class);
    }
    Livewire::actingAs($finance)->test(Form::class, ['customer' => $this->tenant])
        ->assertDontSee('Invite to portal')->call('invitePortal')->assertForbidden();

    ($this->invite)($this->leasing);
    expect(fn () => app(SetPortalAccess::class)->handle($finance, $this->tenant, false))->toThrow(AuthorizationException::class)
        ->and(PortalAccount::query()->sole()->disabled_at)->toBeNull();
});

test('no invite for a tenant without an email, or while mail is not set up', function () {
    $this->tenant->update(['email' => null]);
    ($this->page)()->assertDontSee('Invite to portal')->call('invitePortal')->assertHasErrors('portal');

    $this->tenant->update(['email' => 'sara@tenant.test']);
    config(['mail.default' => 'log']);
    ($this->page)()->assertSee('Portal email is not set up.')->assertDontSee('Invite to portal')->call('invitePortal')->assertHasErrors('portal');

    expect(PortalAccount::count())->toBe(0);
    Mail::assertNothingQueued();
});

test('an email that another tenant\'s portal account uses is refused', function () {
    portalAccount(Customer::factory()->create(['email' => 'sara@tenant.test']));

    expect(fn () => ($this->invite)($this->leasing))->toThrow(ValidationException::class)
        ->and(PortalAccount::query()->where('customer_id', $this->tenant->id)->exists())->toBeFalse();
});

test('enabling needs the tenant to have an email, and takes the current one', function () {
    ($this->invite)($this->leasing);
    app(SetPortalAccess::class)->handle($this->leasing, $this->tenant, false);
    $this->tenant->update(['email' => null]);

    expect(fn () => app(SetPortalAccess::class)->handle($this->leasing, $this->tenant->fresh(), true))->toThrow(ValidationException::class);

    $this->tenant->update(['email' => 'Sara.New@Tenant.test']);
    app(SetPortalAccess::class)->handle($this->leasing, $this->tenant->fresh(), true);
    expect(PortalAccount::query()->sole()->email)->toBe('sara.new@tenant.test');
});

test('changing the tenant email on the tenant form changes the portal account email', function () {
    ($this->invite)($this->leasing);

    ($this->page)()->set('form.email', 'New@Mail.test')->call('save')->assertHasNoErrors();

    expect(PortalAccount::query()->sole()->email)->toBe('new@mail.test');
});

test('the tenant form refuses an email another portal account uses, and removing the email while the portal is enabled', function () {
    ($this->invite)($this->leasing);
    portalAccount(Customer::factory()->create(['email' => 'taken@mail.test']));

    ($this->page)()->set('form.email', 'TAKEN@mail.test')->call('save')->assertHasErrors('form.email');
    ($this->page)()->set('form.email', '')->call('save')->assertHasErrors('form.email');
    expect(PortalAccount::query()->where('customer_id', $this->tenant->id)->sole()->email)->toBe('sara@tenant.test');

    app(SetPortalAccess::class)->handle($this->leasing, $this->tenant, false);
    ($this->page)()->set('form.email', '')->call('save')->assertHasNoErrors();
    expect($this->tenant->fresh()->email)->toBeNull()
        ->and(PortalAccount::query()->where('customer_id', $this->tenant->id)->sole()->email)->toBe('sara@tenant.test');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/InviteTest.php`
Expected: FAIL with `Class "App\Actions\Portal\InviteToPortal" not found`.

- [ ] **Step 3: The relation.** In `app/Models/Customer.php` add `use Illuminate\Database\Eloquent\Relations\HasOne;` and after `agreements()`:

```php
    /** @return HasOne<PortalAccount, $this> spec 2026-10-18 tenant portal §3 */
    public function portalAccount(): HasOne
    {
        return $this->hasOne(PortalAccount::class);
    }
```

- [ ] **Step 4: The invite email.** Create `app/Mail/PortalInvitation.php`:

```php
<?php

namespace App\Mail;

use App\Models\CompanySetting;
use App\Models\PortalAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Spec 2026-10-18 tenant portal §3: the invite, English then Arabic, linking to /portal/login (plan ruling 6). */
class PortalInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public PortalAccount $account)
    {
        $this->locale('en'); // the layout carries both languages; the English half stays English
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
        return str_replace(["\r", "\n"], ' ', implode(' · ', [__('Your tenant portal'), 'بوابة المستأجر', CompanySetting::current()->name_en]));
    }

    public function content(): Content
    {
        $tenant = $this->account->customer;

        return new Content(view: 'mail.portal-invitation', with: [
            'subjectLine' => $this->subjectLine(),
            'nameEn' => $tenant->name_en,
            'nameAr' => $tenant->displayName('ar'),
            'url' => route('portal.login'),
        ]);
    }
}
```

Create `resources/views/mail/portal-invitation.blade.php`:

```blade
<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        <p>{{ __('You can now see your agreements, invoices, receipts, cheques and statement, and send maintenance requests, in our tenant portal.') }}</p>
        <p>{{ __('Sign in with this email address. We email you a code each time; there is no password.') }}</p>
        <p><a href="{{ $url }}">{{ $url }}</a></p>
        <p>{{ __('Thank you.') }}</p>
    </x-slot:en>
    <x-slot:ar>
        <p>عزيزي المستأجر {{ $nameAr }}،</p>
        <p>يمكنكم الآن الاطلاع على عقودكم وفواتيركم وإيصالاتكم وشيكاتكم وكشف حسابكم، وإرسال طلبات الصيانة، عبر بوابة المستأجر.</p>
        <p>سجّلوا الدخول باستخدام هذا البريد الإلكتروني. نرسل إليكم رمزاً في كل مرة، ولا حاجة إلى كلمة مرور.</p>
        <p><a href="{{ $url }}">{{ $url }}</a></p>
        <p>شكراً لكم.</p>
    </x-slot:ar>
</x-mail.bilingual>
```

- [ ] **Step 5: The actions.** Create `app/Actions/Portal/InviteToPortal.php`:

```php
<?php

namespace App\Actions\Portal;

use App\Audit\Audit;
use App\Mail\PortalInvitation;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\User;
use App\Reminders\EmailChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Spec 2026-10-18 tenant portal §3: "Invite to portal" and "Re-send invite". Creates or re-enables the tenant's account
 * with their current email, or re-sends to an enabled one, then queues the bilingual invite (plan ruling 7).
 */
final class InviteToPortal
{
    public function handle(User $actor, Customer $customer): PortalAccount
    {
        if (! $actor->can('update', $customer)) {
            throw new AuthorizationException;
        }
        if (! (new EmailChannel)->ready()) {
            throw ValidationException::withMessages(['portal' => __('Portal email is not set up.')]);
        }
        $email = Str::lower(trim((string) $customer->email));
        if ($email === '' || $customer->trashed()) {
            throw ValidationException::withMessages(['portal' => __('Add the tenant\'s email before inviting them.')]);
        }
        if (PortalAccount::query()->where('email', $email)->where('customer_id', '!=', $customer->id)->exists()) {
            throw ValidationException::withMessages(['portal' => __('Another tenant\'s portal account uses this email.')]);
        }

        $account = DB::transaction(function () use ($actor, $customer, $email) {
            $account = PortalAccount::query()->where('customer_id', $customer->id)->lockForUpdate()->first() ?? new PortalAccount;
            $resent = $account->exists && $account->disabled_at === null;
            $account->forceFill([
                'customer_id' => $customer->id, 'email' => $email, 'disabled_at' => null, 'invited_at' => now(), 'invited_by' => $actor->id,
            ])->save();
            Audit::log('portal.invited', $account, properties: ['customer_id' => $customer->id, 'email' => $email, 'resent' => $resent], causer: $actor);

            return $account;
        });

        try {
            Mail::to(new Address($email, $customer->name_en))->queue(new PortalInvitation($account));
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['portal' => __('The invite could not be sent. Please try again.')]);
        }

        return $account;
    }
}
```

Create `app/Actions/Portal/SetPortalAccess.php`:

```php
<?php

namespace App\Actions\Portal;

use App\Audit\Audit;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §3: "Disable" / "Enable" on the tenant page. A disabled account's session ends on its next
 * request (PortalSession); enabling takes the tenant's current email. A no-op writes and audits nothing (plan ruling 7).
 */
final class SetPortalAccess
{
    public function handle(User $actor, Customer $customer, bool $enabled): PortalAccount
    {
        if (! $actor->can('update', $customer)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $customer, $enabled) {
            $account = PortalAccount::query()->where('customer_id', $customer->id)->lockForUpdate()->first();
            if ($account === null) {
                throw ValidationException::withMessages(['portal' => __('This tenant has not been invited to the portal.')]);
            }
            if ($enabled === ($account->disabled_at === null)) {
                return $account;
            }

            if ($enabled) {
                $email = Str::lower(trim((string) $customer->email));
                if ($email === '' || $customer->trashed()) {
                    throw ValidationException::withMessages(['portal' => __('Add the tenant\'s email before enabling the portal.')]);
                }
                if (PortalAccount::query()->where('email', $email)->whereKeyNot($account->id)->exists()) {
                    throw ValidationException::withMessages(['portal' => __('Another tenant\'s portal account uses this email.')]);
                }
                $account->forceFill(['email' => $email, 'disabled_at' => null])->save();
            } else {
                $account->forceFill(['disabled_at' => now()])->save();
                PortalLoginCode::query()->where('portal_account_id', $account->id)->whereNull('used_at')->delete();
            }
            Audit::log($enabled ? 'portal.enabled' : 'portal.disabled', $account, properties: ['customer_id' => $customer->id], causer: $actor);

            return $account;
        });
    }
}
```

- [ ] **Step 6: The tenant form keeps the account's email in step** (plan ruling 8). In `app/Actions/Customers/SaveCustomer.php` add `use App\Models\PortalAccount;` and `use Illuminate\Support\Str;`. At the end of the `after()` closure, after the `if ($existing) { … }` block, add:

```php
            // Spec 2026-10-18 tenant portal §3: the portal account follows the tenant's email (plan ruling 8).
            $account = $customer?->exists ? PortalAccount::query()->where('customer_id', $customer->getKey())->first() : null;
            $email = is_string($data['email'] ?? null) ? Str::lower($data['email']) : null;
            if ($account !== null && $email === null && $account->disabled_at === null) {
                $validator->errors()->add('email', __('This tenant uses the portal. Disable their portal access before removing the email.'));
            } elseif ($account !== null && $email !== null && PortalAccount::query()->where('email', $email)->whereKeyNot($account->id)->exists()) {
                $validator->errors()->add('email', __('Another tenant\'s portal account uses this email.'));
            }
```

In the transaction, after `$customer->fill($validated)->save();`, add:

```php
            if (filled($customer->email)) {
                PortalAccount::query()->where('customer_id', $customer->id)->update(['email' => Str::lower((string) $customer->email)]);
            }
```

- [ ] **Step 7: The tenant page.** In `app/Livewire/Customers/Form.php` add the imports `use App\Actions\Portal\InviteToPortal;`, `use App\Actions\Portal\SetPortalAccess;`, `use App\Models\PortalAccount;`, `use App\Reminders\EmailChannel;` and `use Closure;`. Add the methods after `save()`:

```php
    /** Spec 2026-10-18 tenant portal §3: Invite / Re-send. No arguments: always this page's tenant. */
    public function invitePortal(InviteToPortal $invite): void
    {
        $this->portal(fn (Customer $c) => $invite->handle($this->actor(), $c), __('Portal invite sent.'));
    }

    public function disablePortal(SetPortalAccess $access): void
    {
        $this->portal(fn (Customer $c) => $access->handle($this->actor(), $c, false), __('Portal access disabled.'));
    }

    public function enablePortal(SetPortalAccess $access): void
    {
        $this->portal(fn (Customer $c) => $access->handle($this->actor(), $c, true), __('Portal access enabled.'));
    }

    /** @param  Closure(Customer): PortalAccount  $run */
    private function portal(Closure $run, string $done): void
    {
        abort_if($this->customerId === null, 404);
        try {
            $run(Customer::findOrFail($this->customerId));
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: $done);
    }
```

In `render()`, add to the view data after `'canEdit' => …`:

```php
            'portal' => $customer && $this->actor()->can('update', $customer) ? [
                'account' => PortalAccount::query()->where('customer_id', $customer->id)->first(),
                'mailReady' => (new EmailChannel)->ready(),
            ] : null,
```

In `resources/views/livewire/customers/form.blade.php`, insert after the closing `</form>` and before `@if ($customer)`:

```blade
    @if ($portal)
        <flux:card class="space-y-3">
            <flux:heading size="lg">{{ __('Tenant portal') }}</flux:heading>
            @if ($portal['account'] === null)
                <flux:text>{{ __('Not invited yet.') }}</flux:text>
            @elseif ($portal['account']->disabled_at)
                <flux:text>{{ __('Portal access is disabled.') }}</flux:text>
            @else
                <flux:text>
                    {{ __('Invited :date, signs in with :email.', ['date' => $portal['account']->invited_at?->format('d/m/Y') ?? '—', 'email' => $portal['account']->email]) }}
                    @if ($portal['account']->last_login_at)
                        {{ __('Last signed in :date.', ['date' => $portal['account']->last_login_at->format('d/m/Y')]) }}
                    @endif
                </flux:text>
            @endif
            @if (! $portal['mailReady'])
                <flux:text>{{ __('Portal email is not set up.') }}</flux:text>
            @endif
            <div class="flex flex-wrap gap-2">
                @if ($portal['mailReady'] && filled($customer?->email))
                    <flux:button variant="primary" wire:click="invitePortal">
                        {{ $portal['account'] && ! $portal['account']->disabled_at ? __('Re-send invite') : __('Invite to portal') }}
                    </flux:button>
                @endif
                @if ($portal['account'] && ! $portal['account']->disabled_at)
                    <flux:button variant="ghost" wire:click="disablePortal" wire:confirm="{{ __('Disable this tenant\'s portal access?') }}">{{ __('Disable') }}</flux:button>
                @elseif ($portal['account'])
                    <flux:button variant="ghost" wire:click="enablePortal">{{ __('Enable') }}</flux:button>
                @endif
            </div>
            <flux:error name="portal" />
        </flux:card>
    @endif
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Portal/InviteTest.php tests/Feature/Customers`
Expected: PASS.

- [ ] **Step 9: Gates and commit.** Run `php vendor/bin/pint --dirty`, then `php vendor/bin/phpstan analyse --memory-limit=2G`, then `npm run build`.

```bash
git add app/Actions/Portal/InviteToPortal.php app/Actions/Portal/SetPortalAccess.php app/Mail/PortalInvitation.php \
  resources/views/mail/portal-invitation.blade.php app/Models/Customer.php app/Actions/Customers/SaveCustomer.php \
  app/Livewire/Customers/Form.php resources/views/livewire/customers/form.blade.php tests/Feature/Portal/InviteTest.php
git commit -m "$(cat <<'EOF'
Portal invites: invite, re-send, disable and enable on the tenant page, bilingual invite email, tenant email kept in step, audited

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---
### Task 5: Home, Agreements, Invoices, Payments and Cheques, with scoped PDF downloads

**Files:**
- Create: `app/Livewire/Portal/Agreements.php`, `Invoices.php`, `Payments.php`, `Cheques.php`
- Create: `resources/views/livewire/portal/agreements.blade.php`, `invoices.blade.php`, `payments.blade.php`, `cheques.blade.php`
- Create: `app/Http/Controllers/Portal/DownloadController.php`
- Modify: `app/Livewire/Portal/Home.php`, `resources/views/livewire/portal/home.blade.php`, `resources/views/layouts/portal.blade.php`, `routes/portal.php`
- Modify: `app/Models/Customer.php`, `app/Livewire/Customers/Form.php`, `resources/views/components/status-badge.blade.php`, `tests/Pest.php`
- Test: `tests/Feature/Portal/PagesTest.php`

**Interfaces:**
- Consumes: `InPortal`, `layouts::portal` `$nav`, `CustomerCredit::fils()`, `Agreement::monthlyRentFils()`, `Invoice::displayLabel()`, `InvoicePdf::render()`, `StoreReceipt::stored()/dispatchSync`, `StoreApprovedContract::stored()/dispatchSync`, `Audit::log()`, `issuedInvoice()`, `activeAgreement()`, `RecordPayment`, `maintenanceRequest()`, `portalAccount()`.
- Produces:
  - `Customer::outstandingFils(): int`. The tenant page's "Outstanding" and the portal's "Balance owed" both use it.
  - Routes `portal.agreements`, `portal.agreements.pdf` (`{agreement}`), `portal.invoices` (`?tab=unpaid|paid|all`), `portal.invoices.pdf` (`{invoice}`), `portal.payments`, `portal.payments.receipt` (`{payment}`), `portal.cheques`.
  - `Portal\DownloadController`: `agreement(string $agreement)`, `invoice(string $invoice, InvoicePdf $pdf)`, `receipt(string $payment)`; private `account(): PortalAccount` and `serve(?Document, PortalAccount): Response`. Task 6 adds `statement()` and `document()`.
  - `Portal\Invoices` public `mixed $tab` (`#[Url]`).
  - `<x-status-badge>` translates its label.
  - Pest helper `portalStoredContract(Agreement $agreement): Document`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/PagesTest.php`:

```php
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
        ->assertSeeInOrder(['INV-2026-000002', '01/10/2026', '01/10/2026', '400.000', '0.000', '400.000'])
        ->assertDontSee('INV-2026-000001');
    $page->set('tab', 'paid')->assertSeeInOrder(['INV-2026-000001', '01/09/2026', '400.000', '400.000', '0.000'])->assertDontSee('INV-2026-000002');
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/PagesTest.php`
Expected: FAIL with `Call to undefined function portalStoredContract()` and `Route [portal.agreements] not defined`.

- [ ] **Step 3: One rule for what the tenant owes.** In `app/Models/Customer.php` add `use App\Enums\InvoiceStatus;`, `use App\Enums\InvoiceType;` and `use App\Support\Fils;`, and after `portalAccount()`:

```php
    /** Σ balance of issued invoices, credit notes left out: the tenant page's "Outstanding" and the portal's "Balance owed" (plan ruling 12). */
    public function outstandingFils(): int
    {
        return Fils::fromDecimal((string) (Invoice::query()->where('customer_id', $this->id)
            ->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote)->sum('balance') ?: '0'));
    }
```

In `app/Livewire/Customers/Form.php::render()` replace the `'outstanding' => Fils::toDecimal(Fils::fromDecimal((string) (Invoice::query()->where('customer_id', $customer->id)` … `->sum('balance') ?: '0'))),` entry (two lines) with:

```php
                'outstanding' => Fils::toDecimal($customer->outstandingFils()),
```

Then delete the imports that are now unused: `App\Enums\InvoiceStatus`, `App\Enums\InvoiceType` and `App\Models\Invoice`. Pint's `no_unused_imports` flags any that remain.

- [ ] **Step 4: The status badge speaks the page's language.** In `resources/views/components/status-badge.blade.php` replace the first two lines of the `@php` block with:

```blade
    $english = $status instanceof \BackedEnum ? (method_exists($status, 'label') ? $status->label() : str($status->value)->headline()->toString()) : (string) $status;
    $key = $status instanceof \BackedEnum ? $status->value : str($english)->snake()->toString();
    $label = __($english); // the portal's Arabic (spec 2026-10-18 tenant portal §4); English is unchanged
```

- [ ] **Step 5: The Pest helper.** In `tests/Pest.php` add `use App\Enums\DocumentCategory;`, `use App\Models\Document;` and `use Illuminate\Support\Facades\Storage;`, and at the end:

```php
/** A stored contract PDF on $agreement, as StoreApprovedContract leaves it (portal tests; needs Storage::fake('local')). */
function portalStoredContract(Agreement $agreement): Document
{
    $path = 'documents/2026/10/'.Str::uuid().'.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 contract');
    $document = new Document([
        'category' => DocumentCategory::GeneratedPdf, 'disk' => 'local', 'path' => $path, 'original_name' => $agreement->number.'.pdf',
        'mime' => 'application/pdf', 'size' => 17, 'uploaded_by' => $agreement->created_by,
    ]);
    $document->documentable()->associate($agreement);
    $document->save();

    return $document;
}
```

- [ ] **Step 6: Downloads.** Create `app/Http/Controllers/Portal/DownloadController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Audit\Audit;
use App\Enums\AgreementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Jobs\StoreApprovedContract;
use App\Jobs\StoreReceipt;
use App\Models\Agreement;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PortalAccount;
use App\Pdf\InvoicePdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 2026-10-18 tenant portal §4 and §6: the existing PDFs, looked up inside the signed-in tenant's records, so any
 * other id is a 404 (plan rulings 10 and 18). Each download is audited with the portal account as causer.
 */
final class DownloadController
{
    public function agreement(string $agreement): Response
    {
        $account = $this->account();
        $model = Agreement::query()->where('customer_id', $account->customer_id)
            ->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval])
            ->whereNull('import_ref') // an imported agreement's signed contract lives in the old system, as for staff
            ->findOrFail($agreement);
        if (! StoreApprovedContract::stored($model)) {
            StoreApprovedContract::dispatchSync($model->id, $model->created_by); // the queue has not built it yet
        }

        return $this->serve(StoreApprovedContract::stored($model), $account);
    }

    public function invoice(string $invoice, InvoicePdf $pdf): Response
    {
        $account = $this->account();
        $model = Invoice::query()->where('customer_id', $account->customer_id)->where('status', InvoiceStatus::Issued)->findOrFail($invoice);
        Audit::log('invoice.pdf_viewed', $model, causer: $account);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$model->number.'.pdf"',
        ]);
    }

    public function receipt(string $payment): Response
    {
        $account = $this->account();
        $model = Payment::query()->where('customer_id', $account->customer_id)->where('status', PaymentStatus::Confirmed)->findOrFail($payment);
        if (! StoreReceipt::stored($model)) {
            StoreReceipt::dispatchSync($model->id, $model->recorded_by);
        }

        return $this->serve(StoreReceipt::stored($model), $account);
    }

    private function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);

        return $account;
    }

    /** A stored PDF, in the browser, with a fixed type. */
    private function serve(?Document $document, PortalAccount $account): Response
    {
        abort_if($document === null || $document->trashed(), 404);
        Audit::log('document.downloaded', $document, causer: $account);

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
```

In `routes/portal.php` add `use App\Http\Controllers\Portal\DownloadController;` and, inside the `auth:tenant` group after the `home` route:

```php
        Route::livewire('agreements', Portal\Agreements::class)->name('agreements');
        Route::get('agreements/{agreement}/contract', [DownloadController::class, 'agreement'])->whereNumber('agreement')->name('agreements.pdf');
        Route::livewire('invoices', Portal\Invoices::class)->name('invoices');
        Route::get('invoices/{invoice}/pdf', [DownloadController::class, 'invoice'])->whereNumber('invoice')->name('invoices.pdf');
        Route::livewire('payments', Portal\Payments::class)->name('payments');
        Route::get('payments/{payment}/receipt', [DownloadController::class, 'receipt'])->whereNumber('payment')->name('payments.receipt');
        Route::livewire('cheques', Portal\Cheques::class)->name('cheques');
```

In `resources/views/layouts/portal.blade.php` extend `$nav`:

```php
    $nav = [
        ['portal.home', __('Home')],
        ['portal.agreements', __('Agreements')],
        ['portal.invoices', __('Invoices')],
        ['portal.payments', __('Payments')],
        ['portal.cheques', __('Cheques')],
    ];
```

- [ ] **Step 7: Home.** Replace `app/Livewire/Portal/Home.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Billing\CustomerCredit;
use App\Enums\AgreementStatus;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\MaintenanceStatus;
use App\Livewire\Concerns\InPortal;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Support\Fils;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: what the tenant owes, what is next, and what is open (plan ruling 12). */
#[Layout('layouts::portal')]
class Home extends Component
{
    use InPortal;

    public function render(): View
    {
        $customer = $this->account()->customer;
        $today = now('Asia/Bahrain');

        return view('livewire.portal.home', [
            'name' => $customer->displayName(app()->getLocale()),
            'owed' => Fils::grouped($customer->outstandingFils()),
            'credit' => Fils::grouped(CustomerCredit::fils($customer->id)),
            'nextDue' => Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Issued)
                ->where('type', '!=', InvoiceType::CreditNote)->where('balance', '>', 0)->orderBy('due_date')->orderBy('id')->first(),
            'cheques' => Cheque::query()->where('customer_id', $customer->id)->where('direction', ChequeDirection::Received)
                ->where('status', ChequeStatus::Held)->where('cheque_date', '>=', $today->toDateString())->orderBy('cheque_date')->orderBy('id')->limit(5)->get(),
            'requests' => MaintenanceRequest::query()->where('customer_id', $customer->id)
                ->whereIn('status', [MaintenanceStatus::Open, MaintenanceStatus::InProgress])->latest('id')->limit(5)->get(),
            'ending' => Agreement::query()->where('customer_id', $customer->id)->where('status', AgreementStatus::Active)
                ->whereBetween('end_date', [$today->toDateString(), $today->addDays(90)->toDateString()])->orderBy('end_date')->get(),
        ])->title(__('Home'));
    }
}
```

Replace `resources/views/livewire/portal/home.blade.php`:

```blade
<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>

    <div class="grid grid-cols-2 gap-3">
        <flux:card>
            <flux:text size="sm">{{ __('Balance owed') }}</flux:text>
            <div class="text-lg font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => $owed]) }}</div>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Credit') }}</flux:text>
            <div class="text-lg font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => $credit]) }}</div>
        </flux:card>
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Next invoice due') }}</flux:heading>
        @if ($nextDue)
            <flux:card class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium">{{ $nextDue->number }}</span>
                <span class="text-sm">{{ __('Due :date', ['date' => $nextDue->due_date->format('d/m/Y')]) }}</span>
                <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($nextDue->balance))]) }}</span>
                <flux:link :href="route('portal.invoices.pdf', $nextDue)">{{ __('Invoice PDF') }}</flux:link>
            </flux:card>
        @else
            <flux:text>{{ __('Nothing is due.') }}</flux:text>
        @endif
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Upcoming cheques') }}</flux:heading>
        @forelse ($cheques as $c)
            <div wire:key="ch-{{ $c->id }}" class="flex flex-wrap justify-between gap-2 text-sm">
                <span>{{ $c->cheque_no }} · {{ $c->bank_name }}</span>
                <span>{{ $c->cheque_date->format('d/m/Y') }}</span>
                <span class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($c->amount))]) }}</span>
            </div>
        @empty
            <flux:text>{{ __('No cheques are waiting to be deposited.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Open maintenance requests') }}</flux:heading>
        @forelse ($requests as $r)
            <div wire:key="mr-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span>{{ $r->number }} · {{ $r->category->label() }}</span>
                <x-status-badge :status="$r->status" />
            </div>
        @empty
            <flux:text>{{ __('No open requests.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Agreements ending soon') }}</flux:heading>
        @forelse ($ending as $a)
            <div wire:key="ag-{{ $a->id }}" class="flex flex-wrap justify-between gap-2 text-sm">
                <span>{{ $a->number }}</span>
                <span>{{ __('Ends :date', ['date' => $a->end_date->format('d/m/Y')]) }}</span>
            </div>
        @empty
            <flux:text>{{ __('No agreement ends in the next 90 days.') }}</flux:text>
        @endforelse
    </div>
</section>
```

- [ ] **Step 8: The four lists.** Create `app/Livewire/Portal/Agreements.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Enums\AgreementStatus;
use App\Livewire\Concerns\InPortal;
use App\Models\Agreement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the tenant's agreements past approval (plan ruling 11). */
#[Layout('layouts::portal')]
class Agreements extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.agreements', [
            'agreements' => Agreement::query()->where('customer_id', $this->customerId())
                ->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval])
                ->with(['agreementUnits.unit.building', 'agreementUnits.charges'])
                ->orderByDesc('start_date')->orderByDesc('id')->get(),
        ])->title(__('Agreements'));
    }
}
```

Create `resources/views/livewire/portal/agreements.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Agreements') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($agreements as $a)
            <li wire:key="ag-{{ $a->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $a->number }}</span>
                    <x-status-badge :status="$a->status" />
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $a->agreementUnits->map(fn ($au) => $au->unit->building->name.' '.$au->unit->code)->implode(', ') }}</div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $a->start_date->format('d/m/Y') }} – {{ $a->end_date->format('d/m/Y') }}</span>
                    <span class="tabular-nums">{{ __(':amount BHD a month', ['amount' => App\Support\Fils::grouped($a->monthlyRentFils())]) }}</span>
                </div>
                @if ($a->import_ref === null)
                    <flux:link :href="route('portal.agreements.pdf', $a)" class="text-sm">{{ __('Agreement PDF') }}</flux:link>
                @endif
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No agreements yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
```

Create `app/Livewire/Portal/Invoices.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Livewire\Concerns\InPortal;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: issued invoices, credit notes left out, in tabs Unpaid / Paid / All (plan ruling 11). */
#[Layout('layouts::portal')]
class Invoices extends Component
{
    use InPortal;

    private const array TABS = ['unpaid', 'paid', 'all'];

    #[Url]
    public mixed $tab = 'unpaid'; // untyped: a tampered value falls back to Unpaid

    public function render(): View
    {
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'unpaid';

        return view('livewire.portal.invoices', [
            'currentTab' => $tab,
            'tabs' => ['unpaid' => __('Unpaid'), 'paid' => __('Paid'), 'all' => __('All')],
            'invoices' => Invoice::query()->where('customer_id', $this->customerId())
                ->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote)
                ->when($tab === 'unpaid', fn ($q) => $q->where('balance', '>', 0))
                ->when($tab === 'paid', fn ($q) => $q->where('balance', '<=', 0))
                ->orderByDesc('due_date')->orderByDesc('id')->get(),
        ])->title(__('Invoices'));
    }
}
```

Create `resources/views/livewire/portal/invoices.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Invoices') }}</flux:heading>

    <div role="tablist" aria-label="{{ __('Invoices') }}" class="flex gap-1 overflow-x-auto">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $currentTab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                @class([
                    'shrink-0 rounded-md px-3 py-1.5 text-sm font-medium',
                    'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $currentTab === $key,
                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700' => $currentTab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($invoices as $i)
            <li wire:key="inv-{{ $i->id }}" class="space-y-2 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $i->number }}</span>
                    <x-status-badge :status="$i->displayLabel()" />
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Issued :date', ['date' => $i->issue_date->format('d/m/Y')]) }} · {{ __('Due :date', ['date' => $i->due_date->format('d/m/Y')]) }}
                </div>
                <dl class="grid grid-cols-3 gap-2 text-sm tabular-nums">
                    <div><dt class="text-zinc-500">{{ __('Total') }}</dt><dd>{{ App\Support\Fils::grouped(App\Support\Fils::fromDecimal($i->total)) }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Paid') }}</dt><dd>{{ App\Support\Fils::grouped(App\Support\Fils::fromDecimal($i->total) - App\Support\Fils::fromDecimal($i->balance)) }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Balance') }}</dt><dd class="font-semibold">{{ App\Support\Fils::grouped(App\Support\Fils::fromDecimal($i->balance)) }}</dd></div>
                </dl>
                <flux:link :href="route('portal.invoices.pdf', $i)" class="text-sm">{{ __('Invoice PDF') }}</flux:link>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No invoices here.') }}</flux:text></li>
        @endforelse
    </ul>
    <flux:text class="text-sm">{{ __('Amounts are in BHD.') }}</flux:text>
</section>
```

Create `app/Livewire/Portal/Payments.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the tenant's payments; a receipt for each confirmed one (plan ruling 10). */
#[Layout('layouts::portal')]
class Payments extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.payments', [
            'payments' => Payment::query()->where('customer_id', $this->customerId())->orderByDesc('received_on')->orderByDesc('id')->get(),
        ])->title(__('Payments'));
    }
}
```

Create `resources/views/livewire/portal/payments.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Payments') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($payments as $p)
            <li wire:key="pay-{{ $p->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $p->number }}</span>
                    <x-status-badge :status="$p->status" />
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $p->received_on->format('d/m/Y') }} · {{ __($p->method->label()) }}</span>
                    <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($p->amount))]) }}</span>
                </div>
                @if ($p->status === App\Enums\PaymentStatus::Confirmed)
                    <flux:link :href="route('portal.payments.receipt', $p)" class="text-sm">{{ __('Receipt PDF') }}</flux:link>
                @endif
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No payments yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
```

Create `app/Livewire/Portal/Cheques.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Enums\ChequeDirection;
use App\Livewire\Concerns\InPortal;
use App\Models\Cheque;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the cheques the company received from the tenant (plan ruling 11). */
#[Layout('layouts::portal')]
class Cheques extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.cheques', [
            'cheques' => Cheque::query()->where('customer_id', $this->customerId())->where('direction', ChequeDirection::Received)
                ->orderByDesc('cheque_date')->orderByDesc('id')->get(),
        ])->title(__('Cheques'));
    }
}
```

Create `resources/views/livewire/portal/cheques.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Cheques') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($cheques as $c)
            <li wire:key="chq-{{ $c->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $c->cheque_no }}</span>
                    <x-status-badge :status="$c->status" />
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $c->bank_name }} · {{ $c->cheque_date->format('d/m/Y') }}</span>
                    <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($c->amount))]) }}</span>
                </div>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No cheques yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Portal tests/Feature/Customers tests/Feature/Pdf`
Expected: PASS.

- [ ] **Step 10: Gates and commit.** Run `php vendor/bin/pint --dirty`, then `php vendor/bin/phpstan analyse --memory-limit=2G`, then `npm run build`.

```bash
git add app/Livewire/Portal/Home.php app/Livewire/Portal/Agreements.php app/Livewire/Portal/Invoices.php app/Livewire/Portal/Payments.php \
  app/Livewire/Portal/Cheques.php resources/views/livewire/portal/home.blade.php resources/views/livewire/portal/agreements.blade.php \
  resources/views/livewire/portal/invoices.blade.php resources/views/livewire/portal/payments.blade.php resources/views/livewire/portal/cheques.blade.php \
  app/Http/Controllers/Portal/DownloadController.php routes/portal.php resources/views/layouts/portal.blade.php \
  app/Models/Customer.php app/Livewire/Customers/Form.php resources/views/components/status-badge.blade.php tests/Pest.php tests/Feature/Portal/PagesTest.php
git commit -m "$(cat <<'EOF'
Portal pages: home figures, agreements, invoices, payments and cheques, with scoped and audited PDF downloads

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---
### Task 6: Statement page and PDF; shared documents in the portal and the staff "Share with tenant" toggle

**Files:**
- Create: `app/Livewire/Portal/Statement.php`, `app/Livewire/Portal/Documents.php`
- Create: `resources/views/livewire/portal/statement.blade.php`, `resources/views/livewire/portal/documents.blade.php`
- Create: `app/Actions/Documents/ShareDocumentWithTenant.php`
- Modify: `app/Billing/CustomerStatement.php`, `app/Http/Controllers/CustomerStatementPdfController.php`
- Modify: `app/Http/Controllers/Portal/DownloadController.php`, `routes/portal.php`, `resources/views/layouts/portal.blade.php`
- Modify: `app/Models/Document.php`, `app/Livewire/Documents/Panel.php`, `resources/views/livewire/documents/panel.blade.php`
- Test: `tests/Feature/Portal/StatementAndDocumentsTest.php`

**Interfaces:**
- Consumes: `CustomerStatement::receivables()/deposits()`, `PdfRenderer::render()`, `StoreDocument::handle()`, `Documents\Panel`, `InPortal`, `DownloadController::account()`, `issuedInvoice()`, `RecordPayment`, `portalAccount()`.
- Produces:
  - `CustomerStatement::pdf(Customer $customer, string $from, string $to): string` (PDF bytes). The staff controller now calls it.
  - `Document::SHAREABLE` (list of class names) and the scope `Document::sharedWithTenant(int $customerId)`.
  - `ShareDocumentWithTenant::handle(User $actor, Document $document, bool $shared): Document`. Throws `AuthorizationException`, or `ValidationException` (key `document`) for a record type that cannot be shared.
  - `Documents\Panel::toggleShare(mixed $documentId, ShareDocumentWithTenant $share): void`. Errors go under `share`.
  - `DownloadController::statement(Request $request)` and `DownloadController::document(string $document)`.
  - Routes `portal.statement` (`?from&to`, Y-m-d), `portal.statement.pdf`, `portal.documents`, `portal.documents.download` (`{document}`).

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/StatementAndDocumentsTest.php`:

```php
<?php

use App\Actions\Documents\ShareDocumentWithTenant;
use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\DocumentCategory;
use App\Enums\RoleName as R;
use App\Livewire\Documents\Panel;
use App\Livewire\Portal\Statement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    $this->agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
        [Unit::factory()->for($this->building)->create(['code' => '101'])]);
    $this->account = portalAccount($this->tenant);
    $au = $this->agreement->agreementUnits->first();
    issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $au]], '2026-09-01', $this->agreement); // INV-2026-000001
    issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $au]], '2026-10-01', $this->agreement); // INV-2026-000002
    $finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance);
    app(RecordPayment::class)->handle($finance, $this->tenant, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '500.000']); // RCP-2026-000001
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->store = fn (mixed $on, string $name, DocumentCategory $category = DocumentCategory::Other) => app(StoreDocument::class)
        ->handle($this->pm, $on, UploadedFile::fake()->create($name, 10, 'application/pdf'), $category);
});

test('the statement shows the account between two dates; bad dates are a message', function () {
    $page = Livewire::actingAs($this->account, 'tenant')->test(Statement::class)
        ->assertSet('from', '2026-01-01')->assertSet('to', '2026-10-18')
        ->assertSeeInOrder(['Opening balance', '0.000', 'INV-2026-000001', '400.000', 'INV-2026-000002', '800.000', 'RCP-2026-000001', '500.000', '300.000']);

    $page->set('from', '2026-10-02')->assertSeeInOrder(['Opening balance', '800.000', 'RCP-2026-000001', '300.000'])->assertDontSee('INV-2026-000001');
    $page->set('to', '2026-01-01')->assertSee('Choose a valid date range.');
    $page->set('from', ['x'])->assertOk()->assertSee('Choose a valid date range.');
    $page->set('from', '2026-02-30')->assertOk()->assertSee('Choose a valid date range.');
});

test('the statement PDF is the staff one, for the signed-in tenant only, audited', function () {
    $this->actingAs($this->account, 'tenant')
        ->get(route('portal.statement.pdf', ['from' => '2026-01-01', 'to' => '2026-10-18']))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $audit = Activity::query()->where('event', 'customer.statement_exported')->sole();
    expect($audit->causer?->is($this->account))->toBeTrue()
        ->and($audit->subject?->is($this->tenant))->toBeTrue();

    $this->get(route('portal.statement.pdf', ['from' => '2026-10-18', 'to' => '2026-01-01']))->assertNotFound();
    $this->get(route('portal.statement.pdf', ['from' => ['x'], 'to' => '2026-01-01']))->assertNotFound();
    $this->get(route('portal.statement.pdf', ['customer' => Customer::factory()->create()->id, 'from' => '2026-01-01', 'to' => '2026-10-18']))->assertOk(); // no customer parameter is read
    expect(Activity::query()->where('event', 'customer.statement_exported')->pluck('subject_id')->unique()->all())->toBe([$this->tenant->id]);
});

test('staff share a document from the tenant page; the toggle is an audit entry', function () {
    $rules = ($this->store)($this->tenant, 'house-rules.pdf');

    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $this->tenant])
        ->assertSee('Share with tenant')
        ->call('toggleShare', $rules->id)->assertHasNoErrors()->assertSee('Shared with tenant')->assertSee('Stop sharing');

    expect($rules->fresh()->shared_with_tenant)->toBeTrue();
    $audit = Activity::query()->where('subject_type', $rules->getMorphClass())->where('subject_id', $rules->id)->where('event', 'updated')->sole();
    expect($audit->causer?->is($this->pm))->toBeTrue()
        ->and($audit->attribute_changes['attributes']['shared_with_tenant'])->toBeTrue()
        ->and($audit->attribute_changes['old']['shared_with_tenant'])->toBeFalse();
});

test('the tenant sees and downloads only shared documents on themselves, their agreements or their invoices', function () {
    $share = fn ($document) => app(ShareDocumentWithTenant::class)->handle($this->pm, $document, true);
    $rules = $share(($this->store)($this->tenant, 'house-rules.pdf'));
    $annex = $share(($this->store)($this->agreement, 'agreement-annex.pdf'));
    $private = ($this->store)($this->tenant, 'id-copy.pdf', DocumentCategory::IdCopy);
    $other = Customer::factory()->create();
    $otherShared = $share(($this->store)($other, 'other-rules.pdf'));

    $this->actingAs($this->account, 'tenant')->get(route('portal.documents'))->assertOk()
        ->assertSee('house-rules.pdf')->assertSee('agreement-annex.pdf')->assertDontSee('id-copy.pdf')->assertDontSee('other-rules.pdf');

    $this->get(route('portal.documents.download', $rules))->assertOk()->assertDownload('house-rules.pdf');
    $this->get(route('portal.documents.download', $annex))->assertOk();
    expect(Activity::query()->where('event', 'document.downloaded')->where('causer_type', $this->account->getMorphClass())->count())->toBe(2);

    $this->get(route('portal.documents.download', $private))->assertNotFound();
    $this->get(route('portal.documents.download', $otherShared))->assertNotFound();

    app(ShareDocumentWithTenant::class)->handle($this->pm, $rules->fresh(), false);
    $this->get(route('portal.documents.download', $rules))->assertNotFound();
});

test('sharing is offered only on tenant, agreement and invoice documents, to staff who can change the record', function () {
    $photo = ($this->store)($this->building, 'lobby.pdf', DocumentCategory::Photo);
    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $this->building])->assertDontSee('Share with tenant');
    expect(fn () => app(ShareDocumentWithTenant::class)->handle($this->pm, $photo, true))->toThrow(ValidationException::class);

    $rules = ($this->store)($this->tenant, 'house-rules.pdf');
    $finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance); // views tenants, cannot change them
    Livewire::actingAs($finance)->test(Panel::class, ['documentable' => $this->tenant])->assertSee('house-rules.pdf')->assertDontSee('Share with tenant');
    expect(fn () => app(ShareDocumentWithTenant::class)->handle($finance, $rules, true))->toThrow(AuthorizationException::class)
        ->and($rules->fresh()->shared_with_tenant)->toBeFalse();
});

test('a tampered or foreign document id is a message, never a 500', function (Closure $id) {
    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $this->tenant])
        ->call('toggleShare', $id->call($this))->assertOk()->assertHasErrors('share');
})->with([
    'text' => [fn () => 'abc'],
    'an array' => [fn () => ['1']],
    'another record\'s document' => [fn () => ($this->store)($this->agreement, 'annex.pdf')->id],
    'no such document' => [fn () => 999999],
]);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/StatementAndDocumentsTest.php`
Expected: FAIL with `Class "App\Livewire\Portal\Statement" not found` and `Class "App\Actions\Documents\ShareDocumentWithTenant" not found`.

- [ ] **Step 3: One statement PDF for staff and the portal.** In `app/Billing/CustomerStatement.php` add `use App\Models\CompanySetting;` and `use App\Pdf\PdfRenderer;`, and after `deposits()`:

```php
    /** The statement PDF, as staff and the tenant portal download it (spec 2026-10-18 tenant portal §4). */
    public static function pdf(Customer $customer, string $from, string $to): string
    {
        return app(PdfRenderer::class)->render('pdf.statement', [
            'company' => CompanySetting::current(),
            'customer' => $customer,
            'from' => $from,
            'to' => $to,
            'receivables' => self::receivables($customer, $from, $to),
            'deposits' => self::deposits($customer, $from, $to),
        ], ['footer' => '<div style="text-align:center; font-size:8pt; color:#555">{PAGENO} / {nbpg}</div>']);
    }
```

Replace `app/Http/Controllers/CustomerStatementPdfController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Billing\CustomerStatement;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CustomerStatementPdfController
{
    public function __invoke(Request $request, Customer $customer): Response
    {
        $user = $request->user();
        abort_unless($user !== null && Customer::visibleTo($user)->whereKey($customer->id)->exists(), 403);
        $range = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        Audit::log('customer.statement_exported', $customer, properties: $range, causer: $user);

        return response(CustomerStatement::pdf($customer, $range['from'], $range['to']), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="statement-'.$customer->id.'.pdf"',
        ]);
    }
}
```

- [ ] **Step 4: Shared documents.** In `app/Models/Document.php` add `use Illuminate\Database\Eloquent\Attributes\Scope;` and `use Illuminate\Database\Eloquent\Builder;`, and inside the class:

```php
    /** Spec 2026-10-18 tenant portal §4: records whose documents staff may share with the tenant (plan ruling 13). */
    public const array SHAREABLE = [Customer::class, Agreement::class, Invoice::class];

    /**
     * Shared documents on the tenant, their agreements or their invoices; deleted ones are left out by SoftDeletes.
     *
     * @param  Builder<Document>  $query
     */
    #[Scope]
    protected function sharedWithTenant(Builder $query, int $customerId): void
    {
        $query->where('shared_with_tenant', true)->where(fn (Builder $q) => $q
            ->where(fn (Builder $c) => $c->where('documentable_type', (new Customer)->getMorphClass())->where('documentable_id', $customerId))
            ->orWhere(fn (Builder $a) => $a->where('documentable_type', (new Agreement)->getMorphClass())
                ->whereIn('documentable_id', Agreement::query()->select('id')->where('customer_id', $customerId)))
            ->orWhere(fn (Builder $i) => $i->where('documentable_type', (new Invoice)->getMorphClass())
                ->whereIn('documentable_id', Invoice::query()->select('id')->where('customer_id', $customerId))));
    }
```

Create `app/Actions/Documents/ShareDocumentWithTenant.php`:

```php
<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §4: the "Share with tenant" flag. Only on tenant, agreement and invoice documents, by
 * someone who can change that record; Document's LogsActivity records each change (plan ruling 13).
 */
final class ShareDocumentWithTenant
{
    public function handle(User $actor, Document $document, bool $shared): Document
    {
        $record = $document->documentable;
        if ($record === null || ! in_array($record::class, Document::SHAREABLE, true)) {
            throw ValidationException::withMessages(['document' => __('Only documents on a tenant, an agreement or an invoice can be shared with the tenant.')]);
        }
        if (! $actor->can('update', $record)) {
            throw new AuthorizationException;
        }

        $document->forceFill(['shared_with_tenant' => $shared])->save();

        return $document;
    }
}
```

In `app/Livewire/Documents/Panel.php` add `use App\Actions\Documents\ShareDocumentWithTenant;`, and after `delete()`:

```php
    /** Spec 2026-10-18 tenant portal §4: flip "Share with tenant". The id is looked up inside this record (never a 500). */
    public function toggleShare(mixed $documentId, ShareDocumentWithTenant $share): void
    {
        $record = $this->documentable();
        $document = is_int($documentId) || (is_string($documentId) && ctype_digit($documentId))
            ? Document::query()->where('documentable_type', $record->getMorphClass())->where('documentable_id', $this->recordId)->find((int) $documentId)
            : null;
        if ($document === null) {
            $this->addError('share', __('Choose a document from the list.'));

            return;
        }

        try {
            $share->handle($this->actor(), $document, ! $document->shared_with_tenant);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            $this->addError('share', (string) collect($e->errors())->flatten()->first());
        }
    }
```

In `render()` add to the view data:

```php
            'shareable' => in_array($this->type, Document::SHAREABLE, true),
```

In `resources/views/livewire/documents/panel.blade.php`, inside `<div class="flex items-center gap-2">` before `<flux:badge size="sm">{{ $document->category->label() }}</flux:badge>`, add:

```blade
                    @if ($shareable && $document->shared_with_tenant)
                        <flux:badge size="sm" color="green">{{ __('Shared with tenant') }}</flux:badge>
                    @endif
                    @if ($shareable && $canUpload)
                        <flux:button size="sm" variant="ghost" wire:click="toggleShare({{ $document->id }})">
                            {{ $document->shared_with_tenant ? __('Stop sharing') : __('Share with tenant') }}
                        </flux:button>
                    @endif
```

Also add `<flux:error name="share" />` directly under the closing `</ul>`, so a toggle error shows next to the list.

- [ ] **Step 5: The portal pages and downloads.** In `app/Http/Controllers/Portal/DownloadController.php` add `use App\Billing\CustomerStatement;`, `use Illuminate\Http\Request;` and `use Illuminate\Support\Facades\Validator;`, and the two methods:

```php
    /** Spec §4: the tenant statement PDF; no customer parameter exists, so it is always the signed-in tenant's. */
    public function statement(Request $request): Response
    {
        $account = $this->account();
        $v = Validator::make($request->query(), [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        abort_if($v->fails(), 404); // a plain link, not a form (plan ruling 10)
        $range = $v->validated();
        $customer = $account->customer;
        Audit::log('customer.statement_exported', $customer, properties: $range, causer: $account);

        return response(CustomerStatement::pdf($customer, $range['from'], $range['to']), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="statement.pdf"',
        ]);
    }

    /** Spec §4: a shared document on the tenant, their agreements or their invoices; anything else is a 404. */
    public function document(string $document): Response
    {
        $account = $this->account();
        $model = Document::query()->sharedWithTenant($account->customer_id)->findOrFail($document);
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }
```

In `routes/portal.php`, inside the `auth:tenant` group after the `cheques` route:

```php
        Route::livewire('statement', Portal\Statement::class)->name('statement');
        Route::get('statement/pdf', [DownloadController::class, 'statement'])->name('statement.pdf');
        Route::livewire('documents', Portal\Documents::class)->name('documents');
        Route::get('documents/{document}', [DownloadController::class, 'document'])->whereNumber('document')->name('documents.download');
```

In `resources/views/layouts/portal.blade.php` add to `$nav` after the Cheques line:

```php
        ['portal.statement', __('Statement')],
        ['portal.documents', __('Documents')],
```

Create `app/Livewire/Portal/Statement.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Billing\CustomerStatement;
use App\Livewire\Concerns\InPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the existing tenant statement for a date range, on screen and as PDF. */
#[Layout('layouts::portal')]
class Statement extends Component
{
    use InPortal;

    // Untyped: a tampered value shows "Choose a valid date range." instead of a TypeError (a 500).
    #[Url]
    public mixed $from = '';

    #[Url]
    public mixed $to = '';

    public function mount(): void
    {
        $this->from = self::isDate($this->from) ? $this->from : now('Asia/Bahrain')->startOfYear()->toDateString();
        $this->to = self::isDate($this->to) ? $this->to : now('Asia/Bahrain')->toDateString();
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public function render(): View
    {
        $customer = $this->account()->customer;
        $valid = self::isDate($this->from) && self::isDate($this->to) && $this->from <= $this->to;

        return view('livewire.portal.statement', [
            'valid' => $valid,
            'receivables' => $valid ? CustomerStatement::receivables($customer, $this->from, $this->to) : null,
            'deposits' => $valid ? CustomerStatement::deposits($customer, $this->from, $this->to) : [],
        ])->title(__('Statement'));
    }
}
```

Create `resources/views/livewire/portal/statement.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Statement') }}</flux:heading>

    <div class="flex flex-wrap items-end gap-2">
        <x-date-input wire:model.live="from" :label="__('From')" />
        <x-date-input wire:model.live="to" :label="__('To')" />
        @if ($valid)
            <flux:button icon="document-arrow-down" :href="route('portal.statement.pdf', ['from' => $from, 'to' => $to])" target="_blank">{{ __('PDF') }}</flux:button>
        @endif
    </div>

    @if (! $valid)
        <flux:text>{{ __('Choose a valid date range.') }}</flux:text>
    @else
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white text-sm dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
            <li class="flex justify-between gap-2 p-3">
                <span>{{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }} · {{ __('Opening balance') }}</span>
                <span class="tabular-nums">{{ App\Support\Fils::grouped($receivables['opening']) }}</span>
            </li>
            @foreach ($receivables['rows'] as $i => $row)
                <li wire:key="st-{{ $i }}" class="space-y-1 p-3">
                    <div class="flex flex-wrap justify-between gap-2">
                        <span>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }} · {{ __($row['kind']) }} {{ $row['reference'] }}</span>
                        <span class="tabular-nums">{{ $row['debit'] ? App\Support\Fils::grouped($row['debit']) : '−'.App\Support\Fils::grouped($row['credit']) }}</span>
                    </div>
                    <div class="text-end text-zinc-500 tabular-nums">{{ __('Balance :amount', ['amount' => App\Support\Fils::grouped($row['balance'])]) }}</div>
                </li>
            @endforeach
        </ul>
        <flux:text>{{ __('Closing balance :amount BHD. A negative balance is credit in your favour.', ['amount' => App\Support\Fils::grouped($receivables['closing'])]) }}</flux:text>

        @foreach ($deposits as $d => $deposit)
            <div wire:key="dep-{{ $d }}" class="space-y-2">
                <flux:heading size="lg" level="2">{{ __('Deposit — :unit', ['unit' => $deposit['unit']]) }}</flux:heading>
                <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white text-sm dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                    @foreach ($deposit['rows'] as $i => $row)
                        <li wire:key="dep-{{ $d }}-{{ $i }}" class="flex flex-wrap justify-between gap-2 p-3">
                            <span>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }} · {{ __($row['kind']) }}</span>
                            <span class="tabular-nums">{{ App\Support\Fils::grouped($row['amount']) }} · {{ __('Held :amount', ['amount' => App\Support\Fils::grouped($row['held'])]) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif
</section>
```

The statement test expects the running balances 400.000, 800.000 and 300.000, and the amounts 400.000 and 500.000. Each row shows its amount, then "Balance :amount". The opening row shows its balance.

Create `app/Livewire/Portal/Documents.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: documents staff shared with the tenant (plan ruling 13). */
#[Layout('layouts::portal')]
class Documents extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.documents', [
            'documents' => Document::query()->sharedWithTenant($this->customerId())->latest('id')->get(),
        ])->title(__('Documents'));
    }
}
```

Create `resources/views/livewire/portal/documents.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Documents') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($documents as $document)
            <li wire:key="doc-{{ $document->id }}" class="flex flex-wrap items-center justify-between gap-2 p-4">
                <flux:link :href="route('portal.documents.download', $document)" class="min-w-0 truncate">{{ $document->original_name }}</flux:link>
                <span class="flex items-center gap-2 text-sm text-zinc-500">
                    <flux:badge size="sm">{{ $document->category->label() }}</flux:badge>
                    {{ $document->created_at->format('d/m/Y') }}
                </span>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No documents have been shared with you yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Portal tests/Feature/Documents tests/Feature/Customers/CustomerStatementTest.php tests/Feature/Agreements/ContractPdfTest.php`
Expected: PASS.

- [ ] **Step 7: Gates and commit.** Run `php vendor/bin/pint --dirty`, then `php vendor/bin/phpstan analyse --memory-limit=2G`, then `npm run build`.

```bash
git add app/Livewire/Portal/Statement.php app/Livewire/Portal/Documents.php resources/views/livewire/portal/statement.blade.php \
  resources/views/livewire/portal/documents.blade.php app/Actions/Documents/ShareDocumentWithTenant.php app/Billing/CustomerStatement.php \
  app/Http/Controllers/CustomerStatementPdfController.php app/Http/Controllers/Portal/DownloadController.php routes/portal.php \
  resources/views/layouts/portal.blade.php app/Models/Document.php app/Livewire/Documents/Panel.php resources/views/livewire/documents/panel.blade.php \
  tests/Feature/Portal/StatementAndDocumentsTest.php
git commit -m "$(cat <<'EOF'
Portal statement and documents: the tenant statement on screen and as PDF, shared documents only, and the staff "Share with tenant" toggle

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---
### Task 7: Maintenance from the portal: one write path, photos, the daily limit, the tenant timeline, the staff "Portal" badge

**Files:**
- Modify: `app/Actions/Maintenance/LogMaintenanceRequest.php`, `app/Actions/Documents/StoreDocument.php`
- Modify: `app/Models/MaintenanceRequest.php`, `app/Mail/MaintenanceUpdate.php`
- Modify: `resources/views/livewire/maintenance/requests.blade.php`, `resources/views/livewire/maintenance/request-show.blade.php`
- Test: `tests/Feature/Portal/PortalRequestsTest.php`

**Interfaces:**
- Consumes: `PortalAccount`, `MaintenanceEvent::record(…, User|PortalAccount $by, …)`, `MaintenanceChannel::Portal`, `NextDocumentNumber`, `MaintenanceUpdate::sendFor()`, `Audit::log()`, `workOrderFor()`, `maintenanceVendor()`, `AddMaintenanceNote`, `CompleteWorkOrder`, `portalAccount()`.
- Produces:
  - `LogMaintenanceRequest::forPortal(PortalAccount $account, array $data, mixed $photos = []): MaintenanceRequest`. Keys: `place` (`unit:{id}` | `building:{id}`), `category`, `description`, `entry_permitted`, plus `photos` (list of uploaded files). Throws `AuthorizationException` for an inactive account. Throws `ValidationException` with keys `request` (the daily limit), `place`, `category`, `description`, `entry_permitted`, `photos` and `photos.N`.
  - `LogMaintenanceRequest::portalPlaces(int $customerId): array<string, string>` and `const PORTAL_DAILY_LIMIT = 10`.
  - `handle()` is unchanged for callers. Both entries end in the private `create(array $attributes, User|PortalAccount $by, list<UploadedFile> $photos = [])`.
  - `StoreDocument::forPortal(PortalAccount $account, MaintenanceRequest $request, UploadedFile $file): Document` and `const PHOTO_MIMES`. `handle()` is unchanged for callers. Both entries end in the private `store()`.
  - `MaintenanceRequest::tenantTimeline(): list<array{at: CarbonImmutable, label: string}>`.
  - Audit event `portal.maintenance_requested`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/PortalRequestsTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\AddMaintenanceNote;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Livewire\Maintenance\Requests;
use App\Mail\MaintenanceUpdate;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    // Sara's ended agreement elsewhere, and another tenant's unit next door: neither may be chosen.
    $this->oldUnit = Unit::factory()->for(Building::factory()->create(['name' => 'Juffair Heights']))->create(['code' => '7']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31'], [$this->oldUnit]);
    $this->otherUnit = Unit::factory()->for($this->building)->create(['code' => '102']);
    activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->otherUnit]);
    $this->account = portalAccount($this->tenant);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->log = fn (array $data = [], mixed $photos = []) => app(LogMaintenanceRequest::class)->forPortal($this->account, [
        'place' => 'unit:'.$this->unit->id, 'category' => 'plumbing', 'description' => 'Kitchen sink leaking', 'entry_permitted' => true, ...$data,
    ], $photos);
});

test('the tenant may choose their units on a current agreement, or the common area of those buildings', function () {
    expect(LogMaintenanceRequest::portalPlaces($this->tenant->id))->toBe([
        'unit:'.$this->unit->id => 'Seef Tower 101',
        'building:'.$this->building->id => 'Building common area — Seef Tower',
    ]);
});

test('a portal request goes through the one write path: portal channel, the tenant, normal priority, photos, timeline, email, audit', function () {
    $request = ($this->log)([], [UploadedFile::fake()->image('leak.jpg'), UploadedFile::fake()->image('pipe.png')]);

    expect($request->number)->toBe('MR-2026-000001')
        ->and($request->channel)->toBe(MaintenanceChannel::Portal)
        ->and($request->customer_id)->toBe($this->tenant->id)
        ->and($request->reported_by_name)->toBe('Sara Tenant')
        ->and($request->priority)->toBe(MaintenancePriority::Normal)
        ->and($request->status)->toBe(MaintenanceStatus::Open)
        ->and($request->unit_id)->toBe($this->unit->id)
        ->and($request->building_id)->toBe($this->building->id)
        ->and($request->entry_permitted)->toBeTrue()
        ->and($request->created_by)->toBeNull()
        ->and($request->portal_account_id)->toBe($this->account->id);

    $event = $request->events()->sole();
    expect($event->to_status)->toBe('open')->and($event->user_id)->toBeNull()->and($event->portal_account_id)->toBe($this->account->id);

    $photos = $request->documents()->orderBy('id')->get();
    expect($photos->pluck('original_name')->all())->toBe(['leak.jpg', 'pipe.png'])
        ->and($photos->every(fn ($d) => $d->category === DocumentCategory::Maintenance && $d->portal_account_id === $this->account->id
            && $d->uploaded_by === null && $d->shared_with_tenant === false))->toBeTrue();

    Mail::assertQueued(MaintenanceUpdate::class, fn (MaintenanceUpdate $m) => $m->request->is($request) && $m->kind === MaintenanceUpdate::RECEIVED
        && $m->hasTo('sara@tenant.test') && $m->locale === 'en');
    expect(Activity::query()->where('event', 'portal.maintenance_requested')->sole()->causer?->is($this->account))->toBeTrue();
});

test('a common-area request has the building and no unit', function () {
    $request = ($this->log)(['place' => 'building:'.$this->building->id]);

    expect($request->unit_id)->toBeNull()->and($request->building_id)->toBe($this->building->id)->and($request->place())->toBe('Seef Tower');
});

test('anything outside the tenant\'s current places, and bad input, is a validation message', function (Closure $data, string $key) {
    try {
        ($this->log)($data->call($this));
        $this->fail('The request was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
    expect(MaintenanceRequest::count())->toBe(0);
})->with([
    'another tenant\'s unit' => [fn () => ['place' => 'unit:'.$this->otherUnit->id], 'place'],
    'a unit of an ended agreement' => [fn () => ['place' => 'unit:'.$this->oldUnit->id], 'place'],
    'the ended agreement\'s building' => [fn () => ['place' => 'building:'.$this->oldUnit->building_id], 'place'],
    'a made-up place' => [fn () => ['place' => 'unit:999999'], 'place'],
    'a bare unit id' => [fn () => ['place' => (string) $this->unit->id], 'place'],
    'place as an array' => [fn () => ['place' => ['unit:'.$this->unit->id]], 'place'],
    'no place' => [fn () => ['place' => ''], 'place'],
    'unknown category' => [fn () => ['category' => 'roofing'], 'category'],
    'no description' => [fn () => ['description' => ''], 'description'],
    'description too long' => [fn () => ['description' => str_repeat('x', 2001)], 'description'],
    'entry permission not a boolean' => [fn () => ['entry_permitted' => 'maybe'], 'entry_permitted'],
]);

test('photos: up to 5 pictures, jpg, png or webp, 10 MB each', function (Closure $photos, string $key) {
    try {
        ($this->log)([], $photos());
        $this->fail('The request was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
    expect(MaintenanceRequest::count())->toBe(0);
})->with([
    'six photos' => [fn () => array_map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 6)), 'photos'],
    'a PDF' => [fn () => [UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf')], 'photos.0'],
    'over 10 MB' => [fn () => [UploadedFile::fake()->image('big.jpg')->size(10241)], 'photos.0'],
    'not a file' => [fn () => ['x'], 'photos.0'],
    'photos as text' => [fn () => 'x', 'photos'],
]);

test('10 portal requests per account per day', function () {
    foreach (range(1, 10) as $i) {
        ($this->log)();
    }
    expect(fn () => ($this->log)())->toThrow(ValidationException::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 00:01', 'Asia/Bahrain'));
    ($this->log)();
    expect(MaintenanceRequest::count())->toBe(11);
});

test('a disabled account or a deleted tenant cannot send a request', function (Closure $end) {
    $end->call($this);

    expect(fn () => ($this->log)())->toThrow(AuthorizationException::class)
        ->and(MaintenanceRequest::count())->toBe(0);
})->with([
    'disabled' => [fn () => $this->account->forceFill(['disabled_at' => now()])->save()],
    'tenant deleted' => [fn () => $this->tenant->delete()],
]);

test('the tenant timeline shows received, work assigned and completed; never vendors, notes or amounts', function () {
    $request = ($this->log)();
    $workOrder = workOrderFor($this->pm, $request, maintenanceVendor(['name' => 'Gulf Pipes W.L.L.']), ['estimate' => '75.000', 'instructions' => 'Key with the guard']);
    app(AddMaintenanceNote::class)->handle($this->pm, $request, 'Internal: tenant was difficult');
    app(CompleteWorkOrder::class)->handle($this->pm, $workOrder, ['completion_note' => 'Replaced the trap', 'completed_on' => '2026-10-18', 'actual_net' => '']);

    $timeline = $request->fresh()->tenantTimeline();
    expect(array_column($timeline, 'label'))->toBe(['Request received', 'Work assigned', 'Completed'])
        ->and(json_encode($timeline))->not->toContain('Gulf Pipes')->not->toContain('75')->not->toContain('Internal')->not->toContain('WO-');
});

test('a cancelled request ends with "Cancelled" on the tenant timeline', function () {
    $request = ($this->log)();
    app(CancelMaintenanceRequest::class)->handle($this->pm, $request, 'Logged twice');

    expect(array_column($request->fresh()->tenantTimeline(), 'label'))->toBe(['Request received', 'Cancelled']);
});

test('staff see a Portal badge on the list and the request page, and the portal as who logged it', function () {
    $request = ($this->log)();

    Livewire::actingAs($this->pm)->test(Requests::class)->assertSee('MR-2026-000001')->assertSee('Portal');
    $this->actingAs($this->pm)->get(route('maintenance.requests.show', $request))->assertOk()
        ->assertSee('Portal')->assertSeeInOrder(['Logged', '18/10/2026', 'Tenant portal']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/PortalRequestsTest.php`
Expected: FAIL with `Call to undefined method App\Actions\Maintenance\LogMaintenanceRequest::forPortal()` (and `::portalPlaces()`).

- [ ] **Step 3: Photos through the one document write path.** Replace `app/Actions/Documents/StoreDocument.php`:

```php
<?php

namespace App\Actions\Documents;

use App\Audit\Audit;
use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\MaintenanceRequest;
use App\Models\PortalAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StoreDocument
{
    /** Spec §8.6. */
    public const string MIMES = 'pdf,jpg,jpeg,png,webp,docx,xlsx';

    /** Spec 2026-10-18 tenant portal §4: what a tenant may send with a portal request. */
    public const string PHOTO_MIMES = 'jpg,jpeg,png,webp';

    public const int MAX_KB = 10240;

    public function handle(User $actor, Model $documentable, UploadedFile $file, DocumentCategory $category, ?CarbonInterface $expiresOn = null): Document
    {
        if (! $actor->can('update', $documentable)) {
            throw new AuthorizationException;
        }

        // Spec §5.6: the generated contract is written only by StoreApprovedContract.
        if ($category === DocumentCategory::GeneratedPdf) {
            throw ValidationException::withMessages(['category' => 'Generated contracts cannot be uploaded.']);
        }

        return $this->store($documentable, $file, self::MIMES, $category, $expiresOn, ['uploaded_by' => $actor->id], $actor);
    }

    /** Spec 2026-10-18 tenant portal §4: a photo the tenant sends with their own portal request (from LogMaintenanceRequest::forPortal). */
    public function forPortal(PortalAccount $account, MaintenanceRequest $request, UploadedFile $file): Document
    {
        if ($request->portal_account_id !== $account->id) {
            throw new AuthorizationException;
        }

        return $this->store($request, $file, self::PHOTO_MIMES, DocumentCategory::Maintenance, null, ['portal_account_id' => $account->id], $account);
    }

    /** @param  array{uploaded_by?: int, portal_account_id?: int}  $uploader  exactly one (the documents_creator_chk CHECK) */
    private function store(Model $documentable, UploadedFile $file, string $mimes, DocumentCategory $category, ?CarbonInterface $expiresOn, array $uploader, Model $causer): Document
    {
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:'.$mimes, 'max:'.self::MAX_KB]])->validate();

        // Random name, no personal data: archive entry names are not encrypted (spec §13.3).
        $path = sprintf('documents/%s/%s.%s', now()->format('Y/m'), Str::uuid(), strtolower($file->extension() ?? $file->getClientOriginalExtension()));
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return DB::transaction(function () use ($documentable, $file, $category, $expiresOn, $path, $uploader, $causer) {
            $document = new Document([
                'category' => $category,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'expires_on' => $expiresOn,
                ...$uploader,
            ]);
            $document->documentable()->associate($documentable);
            $document->save();

            Audit::log('document.uploaded', $document, properties: ['category' => $category->value], causer: $causer);

            return $document;
        });
    }
}
```

- [ ] **Step 4: The portal entry to logging a request.** Replace `app/Actions/Maintenance/LogMaintenanceRequest.php`:

```php
<?php

namespace App\Actions\Maintenance;

use App\Actions\Documents\StoreDocument;
use App\Actions\NextDocumentNumber;
use App\Audit\Audit;
use App\Enums\AgreementStatus;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\Trade;
use App\Mail\MaintenanceUpdate;
use App\Models\Building;
use App\Models\Customer;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\PortalAccount;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-17 maintenance §4 step 1: staff log a request (maintenance.manage, in their buildings). Spec 2026-10-18
 * tenant portal §4: forPortal() is the tenant's entry, scoped to their current agreements. Both end in create(), the one
 * write path (plan ruling 14).
 */
final class LogMaintenanceRequest
{
    /** Spec 2026-10-18 tenant portal §4: requests per portal account per day (Asia/Bahrain). */
    public const int PORTAL_DAILY_LIMIT = 10;

    public function __construct(private NextDocumentNumber $next, private StoreDocument $documents) {}

    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, array $data): MaintenanceRequest
    {
        if (! $actor->can('create', MaintenanceRequest::class)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        $buildingId = is_numeric($data['building_id'] ?? null) ? (int) $data['building_id'] : 0;

        $v = Validator::make($data, [
            'building_id' => ['bail', 'required', 'integer', Rule::in(Building::visibleTo($actor)->pluck('id')->all())],
            'unit_id' => ['bail', 'nullable', 'integer', Rule::exists('units', 'id')->where('building_id', $buildingId)->whereNull('deleted_at')],
            'customer_id' => ['bail', 'nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'reported_by_name' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::enum(MaintenanceChannel::class)->except([MaintenanceChannel::Portal])],
            'category' => ['required', Rule::enum(Trade::class)],
            'priority' => ['required', Rule::enum(MaintenancePriority::class)],
            'description' => ['required', 'string', 'max:2000'],
            'entry_permitted' => ['required', 'boolean'],
        ])->validate();

        if (isset($v['customer_id']) && ! Customer::visibleTo($actor)->whereKey($v['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => __('Choose a tenant from the list.')]);
        }

        return $this->create([
            ...Arr::only($v, ['building_id', 'unit_id', 'customer_id', 'reported_by_name', 'channel', 'category', 'priority', 'description']),
            'entry_permitted' => (bool) $v['entry_permitted'],
        ], $actor);
    }

    /**
     * Spec 2026-10-18 tenant portal §4: no staff permission or building scope; the place must be one of the tenant's own.
     * Customer = the tenant, reported by the tenant's name, priority normal, channel portal.
     *
     * @param  array<array-key, mixed>  $data  place ("unit:ID" | "building:ID"), category, description, entry_permitted
     */
    public function forPortal(PortalAccount $account, array $data, mixed $photos = []): MaintenanceRequest
    {
        if (! $account->isActive()) {
            throw new AuthorizationException;
        }
        $sentToday = MaintenanceRequest::query()->where('portal_account_id', $account->id)->where('created_at', '>=', now('Asia/Bahrain')->startOfDay())->count();
        if ($sentToday >= self::PORTAL_DAILY_LIMIT) {
            throw ValidationException::withMessages(['request' => __('You have sent :count requests today. Please call the office for anything else.', ['count' => self::PORTAL_DAILY_LIMIT])]);
        }

        $data = array_map(fn (mixed $v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        $places = self::portalPlaces($account->customer_id);
        $v = Validator::make([...$data, 'photos' => $photos], [
            'place' => ['bail', 'required', 'string', Rule::in(array_keys($places))],
            'category' => ['required', Rule::enum(Trade::class)],
            'description' => ['required', 'string', 'max:2000'],
            'entry_permitted' => ['required', 'boolean'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['file', 'mimes:'.StoreDocument::PHOTO_MIMES, 'max:'.StoreDocument::MAX_KB],
        ], [
            // In the tenant's language; a "*" stands for one segment, so "photos.*" is the list and "photos.*.*" one photo.
            'place.*' => __('Choose one of your units or a building common area.'),
            'category.*' => __('Choose a category.'),
            'description.*' => __('Describe the problem in up to 2000 characters.'),
            'entry_permitted.*' => __('Say whether the vendor may enter.'),
            'photos.*' => __('Send up to 5 photos.'),
            'photos.*.*' => __('Each photo must be a JPG, PNG or WEBP picture of up to 10 MB.'),
        ])->validate();

        [$kind, $id] = explode(':', (string) $v['place']);
        $unit = $kind === 'unit' ? Unit::query()->findOrFail((int) $id) : null;
        $tenant = $account->customer;

        return $this->create([
            'building_id' => $unit !== null ? $unit->building_id : (int) $id,
            'unit_id' => $unit?->id,
            'customer_id' => $tenant->id,
            'reported_by_name' => Str::limit($tenant->name_en, 150, ''),
            'channel' => MaintenanceChannel::Portal->value,
            'category' => $v['category'],
            'priority' => MaintenancePriority::Normal->value,
            'description' => $v['description'],
            'entry_permitted' => (bool) $v['entry_permitted'],
        ], $account, array_values($v['photos'] ?? []));
    }

    /**
     * Spec 2026-10-18 tenant portal §4: the tenant's units on a current agreement (MaintenanceRequest::currentTenantId's
     * rule), then each of those buildings' common area. "unit:ID" / "building:ID" => label.
     *
     * @return array<string, string>
     */
    public static function portalPlaces(int $customerId): array
    {
        $today = now('Asia/Bahrain')->toDateString();
        $units = Unit::query()->with('building:id,name')
            ->whereHas('agreementUnits', fn (Builder $au) => $au->where('start_date', '<=', $today)
                ->whereRaw('COALESCE(move_out_date, end_date) >= ?', [$today])
                ->whereHas('agreement', fn (Builder $a) => $a->where('customer_id', $customerId)->where('status', AgreementStatus::Active)))
            ->orderBy('building_id')->orderBy('code')->get(['id', 'code', 'building_id']);

        $places = [];
        foreach ($units as $unit) {
            $places['unit:'.$unit->id] = $unit->building->name.' '.$unit->code;
        }
        foreach ($units->unique('building_id') as $unit) {
            $places['building:'.$unit->building_id] = __('Building common area — :building', ['building' => $unit->building->name]);
        }

        return $places;
    }

    /**
     * The one write path: number, request, first timeline row, photos and the "received" email, in one transaction.
     * StoreDocument writes each file before its insert, so a rollback can leave an unreferenced file (plan ruling 14).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<UploadedFile>  $photos
     */
    private function create(array $attributes, User|PortalAccount $by, array $photos = []): MaintenanceRequest
    {
        return DB::transaction(function () use ($attributes, $by, $photos) {
            $request = new MaintenanceRequest($attributes);
            $request->forceFill([
                'number' => ($this->next)(NumberSequenceKey::MaintenanceRequest), 'status' => MaintenanceStatus::Open,
                'created_by' => $by instanceof User ? $by->id : null, 'portal_account_id' => $by instanceof PortalAccount ? $by->id : null,
            ])->save();
            MaintenanceEvent::record($request, $by, to: MaintenanceStatus::Open->value);

            if ($by instanceof PortalAccount) {
                foreach ($photos as $photo) {
                    $this->documents->forPortal($by, $request, $photo);
                }
                Audit::log('portal.maintenance_requested', $request, properties: ['number' => $request->number, 'photos' => count($photos)], causer: $by);
            }

            MaintenanceUpdate::sendFor($request, MaintenanceUpdate::RECEIVED); // spec §4 step 1; after commit

            return $request;
        });
    }
}
```

In `app/Mail/MaintenanceUpdate.php::sendFor()` change the queue line to:

```php
            Mail::to(new Address((string) $tenant->email, $tenant->name_en))->queue((new self($request, $kind))->locale('en')->afterCommit()); // plan ruling 6
```

- [ ] **Step 5: The tenant timeline.** In `app/Models/MaintenanceRequest.php` add `use App\Enums\WorkOrderStatus;`, and after `events()`:

```php
    /**
     * Spec 2026-10-18 tenant portal §4: what the tenant sees of the timeline (plan ruling 15). Status rows only; never
     * notes, reasons, work order numbers, vendors or amounts.
     *
     * @return list<array{at: CarbonImmutable, label: string}>
     */
    public function tenantTimeline(): array
    {
        $steps = [];
        foreach ($this->events()->where('kind', 'status')->get(['work_order_id', 'to_status', 'created_at']) as $event) {
            $label = match (true) {
                $event->work_order_id === null && $event->to_status === MaintenanceStatus::Open->value => __('Request received'),
                $event->work_order_id !== null && $event->to_status === WorkOrderStatus::Assigned->value => __('Work assigned'),
                $event->work_order_id === null && $event->to_status === MaintenanceStatus::Resolved->value => __('Completed'),
                $event->work_order_id === null && $event->to_status === MaintenanceStatus::Cancelled->value => __('Cancelled'),
                default => null,
            };
            if ($label !== null) {
                $steps[] = ['at' => $event->created_at, 'label' => $label];
            }
        }

        return $steps;
    }
```

- [ ] **Step 6: The staff "Portal" badge.** In `resources/views/livewire/maintenance/requests.blade.php` replace `<flux:link :href="route('maintenance.requests.show', $r)" wire:navigate>{{ $r->number }}</flux:link>` with:

```blade
                            <flux:link :href="route('maintenance.requests.show', $r)" wire:navigate>{{ $r->number }}</flux:link>
                            @if ($r->channel === \App\Enums\MaintenanceChannel::Portal)
                                <flux:badge size="sm" color="sky" class="ms-1">{{ __('Portal') }}</flux:badge>
                            @endif
```

In `resources/views/livewire/maintenance/request-show.blade.php`, after `<flux:badge size="sm" :color="$request->priority->color()">{{ $request->priority->label() }}</flux:badge>`, add:

```blade
                @if ($request->channel === \App\Enums\MaintenanceChannel::Portal)
                    <flux:badge size="sm" color="sky">{{ __('Portal') }}</flux:badge>
                @endif
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Portal tests/Feature/Maintenance tests/Feature/Documents tests/Feature/Agreements/ContractPdfTest.php`
Expected: PASS.

- [ ] **Step 8: Gates and commit.** Run `php vendor/bin/pint --dirty`, then `php vendor/bin/phpstan analyse --memory-limit=2G`, then `npm run build`.

```bash
git add app/Actions/Maintenance/LogMaintenanceRequest.php app/Actions/Documents/StoreDocument.php app/Models/MaintenanceRequest.php \
  app/Mail/MaintenanceUpdate.php resources/views/livewire/maintenance/requests.blade.php resources/views/livewire/maintenance/request-show.blade.php \
  tests/Feature/Portal/PortalRequestsTest.php
git commit -m "$(cat <<'EOF'
Portal maintenance write path: forPortal through the one request and document write paths, current places only, photos, 10 a day, tenant timeline, staff Portal badge

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---
### Task 8: The portal's maintenance pages: the list, a request with the tenant timeline, the new request form with photos

**Files:**
- Create: `app/Livewire/Portal/Maintenance.php`, `app/Livewire/Portal/MaintenanceShow.php`, `app/Livewire/Portal/NewMaintenanceRequest.php`
- Create: `resources/views/livewire/portal/maintenance.blade.php`, `maintenance-show.blade.php`, `new-maintenance-request.blade.php`
- Modify: `routes/portal.php`, `resources/views/layouts/portal.blade.php`, `resources/views/livewire/portal/home.blade.php`
- Test: `tests/Feature/Portal/MaintenancePagesTest.php`

**Interfaces:**
- Consumes: `LogMaintenanceRequest::forPortal()` / `::portalPlaces()` / `PORTAL_DAILY_LIMIT`, `MaintenanceRequest::tenantTimeline()` / `place()`, `InPortal`, `Trade`, `maintenanceRequest()`, `workOrderFor()`, `maintenanceVendor()`, `AddMaintenanceNote`, `portalAccount()`.
- Produces:
  - Routes `portal.maintenance`, `portal.maintenance.create` (`maintenance/new`), `portal.maintenance.show` (`maintenance/{maintenance}`, numeric).
  - `Portal\NewMaintenanceRequest`: public `mixed $form`, `mixed $photos`; method `save()`. Errors go under `form.place`, `form.category`, `form.description`, `form.entry_permitted`, `photos`, `photos.N` and `request`.
  - `Portal\MaintenanceShow::mount(string $maintenance)`, with `#[Locked] int $requestId`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/MaintenancePagesTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\AddMaintenanceNote;
use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Enums\Trade;
use App\Livewire\Portal\MaintenanceShow;
use App\Livewire\Portal\NewMaintenanceRequest;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->otherUnit = Unit::factory()->for($this->building)->create(['code' => '102']);
    activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->otherUnit]);
    $this->account = portalAccount($this->tenant);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->staffLogged = maintenanceRequest($this->pm, $this->unit); // MR-2026-000001, Sara's, logged by the office
    $this->otherRequest = maintenanceRequest($this->pm, $this->otherUnit); // MR-2026-000002, the other tenant's
    $this->form = fn () => Livewire::actingAs($this->account, 'tenant')->test(NewMaintenanceRequest::class)
        ->set('form.place', 'unit:'.$this->unit->id)->set('form.category', 'ac')->set('form.description', 'The AC is not cooling')->set('form.entry_permitted', true);
});

test('the tenant sends a request with a photo from the portal and lands on its page', function () {
    $page = Livewire::actingAs($this->account, 'tenant')->test(NewMaintenanceRequest::class)
        ->assertSee('Seef Tower 101')->assertSee('Building common area — Seef Tower')->assertDontSee('Seef Tower 102');
    $page->set('form.place', 'unit:'.$this->unit->id)->set('form.category', 'ac')->set('form.description', 'The AC is not cooling')
        ->set('form.entry_permitted', true)->set('photos', [UploadedFile::fake()->image('ac.jpg')])
        ->call('save')->assertHasNoErrors();

    $request = MaintenanceRequest::query()->where('channel', 'portal')->sole();
    $page->assertRedirect(route('portal.maintenance.show', $request));
    expect($request->number)->toBe('MR-2026-000003')
        ->and($request->category)->toBe(Trade::Ac)
        ->and($request->documents()->sole()->original_name)->toBe('ac.jpg');

    $this->actingAs($this->account, 'tenant')->get(route('portal.maintenance.show', $request))->assertOk()
        ->assertSeeInOrder(['MR-2026-000003', 'Open', 'Seef Tower 101', 'Air conditioning', 'The AC is not cooling', 'Request received']);
});

test('tampered form input is a message, never a 500', function (string $property, mixed $value, string $error) {
    ($this->form)()->set($property, $value)->call('save')->assertOk()->assertHasErrors($error);

    expect(MaintenanceRequest::query()->where('channel', 'portal')->count())->toBe(0);
})->with([
    'a place that is not theirs' => ['form.place', 'unit:999999', 'form.place'],
    'place as an array' => ['form.place', ['x'], 'form.place'],
    'the whole form as text' => ['form', 'x', 'form.place'],
    'unknown category' => ['form.category', 'roofing', 'form.category'],
    'description too long' => ['form.description', str_repeat('x', 2001), 'form.description'],
    'entry permission as text' => ['form.entry_permitted', 'maybe', 'form.entry_permitted'],
    'photos as text' => ['photos', 'x', 'photos'],
]);

test('the daily limit is a message on the form', function () {
    foreach (range(1, LogMaintenanceRequest::PORTAL_DAILY_LIMIT) as $i) {
        app(LogMaintenanceRequest::class)->forPortal($this->account, ['place' => 'unit:'.$this->unit->id, 'category' => 'general', 'description' => 'Leak '.$i, 'entry_permitted' => false]);
    }

    ($this->form)()->call('save')->assertOk()->assertHasErrors('request')->assertSee('You have sent 10 requests today.');
});

test('a tenant with no current agreement is asked to call the office', function () {
    $former = Customer::factory()->create(['email' => 'former@tenant.test']);

    Livewire::actingAs(portalAccount($former), 'tenant')->test(NewMaintenanceRequest::class)
        ->assertSee('You have no current agreement, so requests cannot be sent here. Please call the office.')->assertDontSee('Send request');
});

test('the list and the request page show the tenant view: status and dates, never vendors, notes, amounts or work order numbers', function () {
    $workOrder = workOrderFor($this->pm, $this->staffLogged, maintenanceVendor(['name' => 'Gulf Pipes W.L.L.']), ['estimate' => '75.000', 'instructions' => 'Key with the guard']);
    app(AddMaintenanceNote::class)->handle($this->pm, $this->staffLogged, 'Internal: tenant was difficult');
    $this->actingAs($this->account, 'tenant');

    $this->get(route('portal.maintenance'))->assertOk()
        ->assertSeeInOrder(['MR-2026-000001', 'In progress', 'Seef Tower 101', 'Plumbing', '18/10/2026'])
        ->assertDontSee('MR-2026-000002');

    $this->get(route('portal.maintenance.show', $this->staffLogged))->assertOk()
        ->assertSeeInOrder(['MR-2026-000001', 'In progress', 'Kitchen sink leaking', 'Request received', 'Work assigned'])
        ->assertDontSee('Gulf Pipes')->assertDontSee('75.000')->assertDontSee($workOrder->number)
        ->assertDontSee('Internal: tenant was difficult')->assertDontSee('Key with the guard');

    $this->get(route('portal.home'))->assertSee(route('portal.maintenance.show', $this->staffLogged));
});

test('another tenant\'s request is a 404, by URL and by Livewire', function () {
    $this->actingAs($this->account, 'tenant')->get(route('portal.maintenance.show', $this->otherRequest))->assertNotFound();
    $this->get('/portal/maintenance/abc')->assertNotFound();

    Livewire::actingAs($this->account, 'tenant')->test(MaintenanceShow::class, ['maintenance' => (string) $this->otherRequest->id])->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/MaintenancePagesTest.php`
Expected: FAIL with `Class "App\Livewire\Portal\NewMaintenanceRequest" not found`.

- [ ] **Step 3: Routes, menu and the home link.** In `routes/portal.php`, inside the `auth:tenant` group after the `documents.download` route:

```php
        Route::livewire('maintenance', Portal\Maintenance::class)->name('maintenance');
        Route::livewire('maintenance/new', Portal\NewMaintenanceRequest::class)->name('maintenance.create');
        Route::livewire('maintenance/{maintenance}', Portal\MaintenanceShow::class)->whereNumber('maintenance')->name('maintenance.show');
```

In `resources/views/layouts/portal.blade.php` add to `$nav` after the Documents line:

```php
        ['portal.maintenance', __('Maintenance')],
```

In `resources/views/livewire/portal/home.blade.php` replace `<span>{{ $r->number }} · {{ $r->category->label() }}</span>` with:

```blade
                <span><flux:link :href="route('portal.maintenance.show', $r)">{{ $r->number }}</flux:link> · {{ $r->category->label() }}</span>
```

- [ ] **Step 4: The components.** Create `app/Livewire/Portal/Maintenance.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use App\Models\MaintenanceRequest;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the tenant's requests, whoever logged them, latest first. */
#[Layout('layouts::portal')]
class Maintenance extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.maintenance', [
            'requests' => MaintenanceRequest::query()->where('customer_id', $this->customerId())->with(['building', 'unit'])->latest('id')->get(),
        ])->title(__('Maintenance'));
    }
}
```

Create `app/Livewire/Portal/MaintenanceShow.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use App\Models\MaintenanceRequest;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: one of the tenant's requests and its tenant timeline (plan ruling 15). Another tenant's id is a 404. */
#[Layout('layouts::portal')]
class MaintenanceShow extends Component
{
    use InPortal;

    #[Locked]
    public int $requestId;

    public function mount(string $maintenance): void
    {
        $this->requestId = MaintenanceRequest::query()->where('customer_id', $this->customerId())->findOrFail($maintenance)->id;
    }

    public function render(): View
    {
        $request = MaintenanceRequest::query()->where('customer_id', $this->customerId())->with(['building', 'unit'])->findOrFail($this->requestId);

        return view('livewire.portal.maintenance-show', [
            'request' => $request,
            'timeline' => $request->tenantTimeline(),
        ])->title($request->number);
    }
}
```

Create `app/Livewire/Portal/NewMaintenanceRequest.php`:

```php
<?php

namespace App\Livewire\Portal;

use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\Trade;
use App\Livewire\Concerns\InPortal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Spec 2026-10-18 tenant portal §4: the tenant's new request, saved through LogMaintenanceRequest::forPortal (plan ruling 14). */
#[Layout('layouts::portal')]
class NewMaintenanceRequest extends Component
{
    use InPortal, WithFileUploads;

    private const array NEW_FORM = ['place' => '', 'category' => 'general', 'description' => '', 'entry_permitted' => false];

    private const array FORM_KEYS = ['place', 'category', 'description', 'entry_permitted'];

    // Untyped on purpose: a tampered payload reaches forPortal's validation instead of a TypeError (a 500).
    public mixed $form = self::NEW_FORM;

    public mixed $photos = [];

    public function save(LogMaintenanceRequest $log): void
    {
        try {
            $request = $log->forPortal($this->account(), is_array($this->form) ? $this->form : [], $this->photos);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn ($m, $k) => [in_array($k, self::FORM_KEYS, true) ? "form.{$k}" : $k => $m])->all());
        }

        $this->redirectRoute('portal.maintenance.show', ['maintenance' => $request->id]);
    }

    public function render(): View
    {
        return view('livewire.portal.new-maintenance-request', [
            'places' => LogMaintenanceRequest::portalPlaces($this->customerId()),
            'categories' => Trade::cases(),
        ])->title(__('New maintenance request'));
    }
}
```

- [ ] **Step 5: The views.** Create `resources/views/livewire/portal/maintenance.blade.php`:

```blade
<section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">{{ __('Maintenance') }}</flux:heading>
        <flux:button variant="primary" :href="route('portal.maintenance.create')">{{ __('New request') }}</flux:button>
    </div>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($requests as $r)
            <li wire:key="mr-{{ $r->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:link :href="route('portal.maintenance.show', $r)" class="font-medium">{{ $r->number }}</flux:link>
                    <x-status-badge :status="$r->status" />
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $r->place() }} · {{ $r->category->label() }}</div>
                <div class="text-sm text-zinc-500">
                    {{ __('Sent :date', ['date' => $r->created_at->format('d/m/Y')]) }}
                    @if ($r->resolved_at)
                        · {{ __('Completed :date', ['date' => $r->resolved_at->format('d/m/Y')]) }}
                    @endif
                </div>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No requests yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
```

Create `resources/views/livewire/portal/maintenance-show.blade.php`:

```blade
<section class="space-y-6">
    <div class="space-y-1">
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="xl" level="1">{{ $request->number }}</flux:heading>
            <x-status-badge :status="$request->status" />
        </div>
        <flux:text>{{ $request->place() }} · {{ $request->category->label() }}</flux:text>
    </div>

    <dl class="grid gap-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Sent') }}</dt><dd>{{ $request->created_at->format('d/m/Y') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Entry permitted') }}</dt><dd>{{ $request->entry_permitted ? __('Yes') : __('No') }}</dd></div>
        <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Problem') }}</dt><dd class="whitespace-pre-line">{{ $request->description }}</dd></div>
    </dl>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Progress') }}</flux:heading>
        <ol class="space-y-2 border-s-2 border-zinc-200 ps-4 dark:border-zinc-700">
            @foreach ($timeline as $i => $step)
                <li wire:key="step-{{ $i }}" class="text-sm">
                    <time class="text-zinc-500" datetime="{{ $step['at']->toIso8601String() }}">{{ $step['at']->format('d/m/Y H:i') }}</time>
                    · {{ $step['label'] }}
                </li>
            @endforeach
        </ol>
    </div>

    <flux:link :href="route('portal.maintenance')">{{ __('All requests') }}</flux:link>
</section>
```

Create `resources/views/livewire/portal/new-maintenance-request.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('New maintenance request') }}</flux:heading>

    @if ($places === [])
        <flux:text>{{ __('You have no current agreement, so requests cannot be sent here. Please call the office.') }}</flux:text>
    @else
        <form wire:submit="save" class="space-y-4">
            <flux:select wire:model="form.place" :label="__('Where is the problem?')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($places as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="form.category" :label="__('Category')">
                @foreach ($categories as $c)
                    <option value="{{ $c->value }}">{{ $c->label() }}</option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="form.description" :label="__('What is the problem?')" rows="4" maxlength="2000" />
            <flux:switch wire:model="form.entry_permitted" :label="__('The vendor may enter when I am out')" />
            <flux:field>
                <flux:label>{{ __('Photos (up to 5; JPG, PNG or WEBP; 10 MB each)') }}</flux:label>
                <input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" class="block w-full text-sm" />
                <span wire:loading wire:target="photos" class="text-sm text-zinc-500">{{ __('Uploading…') }}</span>
                <flux:error name="photos" />
                @foreach (range(0, 4) as $i)
                    <flux:error name="photos.{{ $i }}" />
                @endforeach
            </flux:field>
            <flux:error name="request" />
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto">{{ __('Send request') }}</flux:button>
        </form>
    @endif
</section>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Portal`
Expected: PASS (including `ArabicTest` over the new views).

- [ ] **Step 7: Gates and commit.** Run `php vendor/bin/pint --dirty`, then `php vendor/bin/phpstan analyse --memory-limit=2G`, then `npm run build`.

```bash
git add app/Livewire/Portal/Maintenance.php app/Livewire/Portal/MaintenanceShow.php app/Livewire/Portal/NewMaintenanceRequest.php \
  resources/views/livewire/portal/maintenance.blade.php resources/views/livewire/portal/maintenance-show.blade.php \
  resources/views/livewire/portal/new-maintenance-request.blade.php routes/portal.php resources/views/layouts/portal.blade.php \
  resources/views/livewire/portal/home.blade.php tests/Feature/Portal/MaintenancePagesTest.php
git commit -m "$(cat <<'EOF'
Portal maintenance pages: the tenant's requests, a tenant timeline without vendors or amounts, and the new request form with photos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---
### Task 9: Isolation sweep (tenant A against tenant B on every route; staff against tenant guards on both route sets) and the full suite

**Files:**
- Test: `tests/Feature/Portal/IsolationTest.php`

**Interfaces:**
- Consumes: every portal route and component from Tasks 3–8, the staff routes, `portalAccount()`, `portalStoredContract()`, `issuedInvoice()`, `activeAgreement()`, `maintenanceRequest()`, `matrixUser()`, `RecordPayment`, `StoreDocument`, `ShareDocumentWithTenant`.
- Produces: no production code. A failure here is a bug in the task that owns the route. Fix it there and re-run this file.

- [ ] **Step 1: Write the test** `tests/Feature/Portal/IsolationTest.php`:

```php
<?php

use App\Actions\Documents\ShareDocumentWithTenant;
use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\DocumentCategory;
use App\Enums\RoleName as R;
use App\Livewire\Portal;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** A tenant with one of everything the portal shows (isolation tests). */
function isolationTenant(string $name, string $code, User $finance, User $pm, Building $building): array
{
    $tenant = Customer::factory()->create(['name_en' => $name, 'email' => strtolower(strtok($name, ' ')).'@tenant.test']);
    $unit = Unit::factory()->for($building)->create(['code' => $code]);
    $agreement = activeAgreement(['customer_id' => $tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    portalStoredContract($agreement);
    $invoice = issuedInvoice($tenant, [['net' => '400.000', 'au' => $agreement->agreementUnits->first()]], '2026-10-01', $agreement);
    $payment = app(RecordPayment::class)->handle($finance, $tenant, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '100.000']);
    (new Cheque)->forceFill([
        'direction' => 'received', 'customer_id' => $tenant->id, 'cheque_no' => 'CHQ-'.$code, 'bank_name' => 'NBB', 'cheque_date' => '2026-11-01',
        'amount' => '400.000', 'status' => 'held', 'created_by' => $finance->id,
    ])->save();
    $document = app(StoreDocument::class)->handle($pm, $tenant, UploadedFile::fake()->create("rules-{$code}.pdf", 10, 'application/pdf'), DocumentCategory::Other);
    app(ShareDocumentWithTenant::class)->handle($pm, $document, true);
    $request = maintenanceRequest($pm, $unit);

    return [
        'tenant' => $tenant, 'account' => portalAccount($tenant), 'unit' => $unit, 'agreement' => $agreement, 'invoice' => $invoice,
        'payment' => $payment, 'document' => $document, 'request' => $request,
        'markers' => [$name, $agreement->number, (string) $invoice->number, $payment->number, 'CHQ-'.$code, "rules-{$code}.pdf", $request->number, $building->name.' '.$code],
    ];
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->admin = matrixUser(R::Admin, $building);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance);
    $pm = matrixUser(R::PropertyManager, $building);
    $this->a = isolationTenant('Sara Tenant', '101', $finance, $pm, $building);
    $this->b = isolationTenant('Omar Other', '202', $finance, $pm, $building);
});

test('tenant A sees none of tenant B\'s records on any portal page', function () {
    $this->actingAs($this->a['account'], 'tenant');
    $pages = [
        route('portal.home'), route('portal.agreements'), route('portal.invoices', ['tab' => 'all']), route('portal.payments'),
        route('portal.cheques'), route('portal.statement'), route('portal.documents'), route('portal.maintenance'), route('portal.maintenance.create'),
    ];

    foreach ($pages as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach ($this->b['markers'] as $marker) {
            expect($html)->not->toContain($marker);
        }
    }
    // A sees their own, so the pages above were not simply empty.
    $this->get(route('portal.documents'))->assertSee('rules-101.pdf');
    $this->get(route('portal.maintenance'))->assertSee($this->a['request']->number);
});

test('every one of tenant B\'s ids is a 404 for tenant A, and so are made-up ids', function () {
    $this->actingAs($this->a['account'], 'tenant');

    foreach ([
        route('portal.agreements.pdf', $this->b['agreement']),
        route('portal.invoices.pdf', $this->b['invoice']),
        route('portal.payments.receipt', $this->b['payment']),
        route('portal.documents.download', $this->b['document']),
        route('portal.maintenance.show', $this->b['request']),
        '/portal/agreements/999999999/contract', '/portal/invoices/abc/pdf', '/portal/payments/1e3/receipt',
        '/portal/documents/99999999999999999999999', '/portal/maintenance/-1',
    ] as $url) {
        $this->get($url)->assertNotFound();
    }

    Livewire::actingAs($this->a['account'], 'tenant')->test(Portal\MaintenanceShow::class, ['maintenance' => (string) $this->b['request']->id])->assertNotFound();
});

test('tenant A cannot send a request for tenant B\'s unit', function () {
    Livewire::actingAs($this->a['account'], 'tenant')->test(Portal\NewMaintenanceRequest::class)
        ->set('form.place', 'unit:'.$this->b['unit']->id)->set('form.description', 'Leak')->call('save')->assertHasErrors('form.place');

    expect(MaintenanceRequest::query()->where('channel', 'portal')->count())->toBe(0);
});

test('a tenant session opens no staff page', function () {
    $a = $this->a;
    $this->actingAs($a['account'], 'tenant');

    foreach ([
        route('dashboard'), route('customers.index'), route('customers.edit', $a['tenant']), route('customers.statement', $a['tenant']),
        route('customers.statement.pdf', ['customer' => $a['tenant'], 'from' => '2026-01-01', 'to' => '2026-10-18']),
        route('agreements.pdf', $a['agreement']), route('invoices.index'), route('invoices.pdf', $a['invoice']), route('payments.receipt', $a['payment']),
        route('documents.download', $a['document']), route('maintenance.requests.show', $a['request']), route('admin.audit'), route('profile.edit'),
    ] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

test('a staff session opens no portal page, and portal components refuse a staff user', function () {
    $a = $this->a;
    $this->actingAs($this->admin);

    foreach ([
        route('portal.home'), route('portal.agreements'), route('portal.agreements.pdf', $a['agreement']), route('portal.invoices'),
        route('portal.invoices.pdf', $a['invoice']), route('portal.payments'), route('portal.payments.receipt', $a['payment']), route('portal.cheques'),
        route('portal.statement'), route('portal.statement.pdf', ['from' => '2026-01-01', 'to' => '2026-10-18']), route('portal.documents'),
        route('portal.documents.download', $a['document']), route('portal.maintenance'), route('portal.maintenance.create'),
        route('portal.maintenance.show', $a['request']),
    ] as $url) {
        $this->get($url)->assertRedirect(route('portal.login'));
    }
    $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));

    foreach ([Portal\Home::class, Portal\Agreements::class, Portal\Invoices::class, Portal\Payments::class, Portal\Cheques::class,
        Portal\Statement::class, Portal\Documents::class, Portal\Maintenance::class, Portal\NewMaintenanceRequest::class] as $component) {
        Livewire::actingAs($this->admin)->test($component)->assertForbidden();
    }
});

test('portal components expose no public method that takes a customer id', function () {
    $allowed = ['render', 'mount', 'sendCode', 'signIn', 'startAgain', 'save'];

    foreach (glob(app_path('Livewire/Portal/*.php')) as $file) {
        $class = 'App\\Livewire\\Portal\\'.basename($file, '.php');
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || realpath((string) $method->getFileName()) !== realpath($file)) {
                continue; // inherited, or from a trait such as WithFileUploads
            }
            expect($method->getName())->toBeIn($allowed);
            foreach ($method->getParameters() as $parameter) {
                expect(strtolower($parameter->getName()))->not->toContain('customer');
            }
        }
    }
});
```

- [ ] **Step 2: Run the test**

Run: `php artisan test tests/Feature/Portal/IsolationTest.php`
Expected: PASS. If a case fails, the bug is in the task that owns that route or component. Fix it there, re-run that task's test file, then this one.

- [ ] **Step 3: Full suite and gates.** Run these serially:
  - `php artisan test`. Everything passes. That includes `tests/Feature/Integrity` (still 61 triggers), `tests/Concurrency/VendorsMigrationTest.php` (now rolling back the portal migration first), `tests/Feature/Auth` (staff on `auth:web`), `tests/Feature/Audit` (`auth.login` unchanged for staff) and `tests/Feature/Maintenance` (staff channels, creator names).
  - `php vendor/bin/pint --dirty`
  - `php vendor/bin/phpstan analyse --memory-limit=2G`
  - `npm run build`

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Portal/IsolationTest.php
git commit -m "$(cat <<'EOF'
Portal isolation sweep: tenant against tenant on every portal route, staff and tenant guards on both route sets, no customer ids on public Livewire methods

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (done while writing)

- **Spec coverage:**
  - §1 purpose: Tasks 5, 6 and 8 cover every listed view, and Task 8 covers raising and following requests. Out of scope stays out: no payment, no self-service contact changes, no SMS, no "view as tenant", no owner portal, no push.
  - §2 approach: a separate guard, model, session and layout (Tasks 1 and 3). The PDFs are reused (Tasks 5 and 6), and so are the document store and `LogMaintenanceRequest` (Task 7). Every query starts from the account's `customer_id` (`InPortal`, `DownloadController::account()`).
  - §3 accounts: both tables (Task 1, plus `remember_token`, ruling 4). Invite, re-send, disable and enable, with permission, email and mail-ready checks and audits (Task 4). The email sync (Task 4). The code flow (Task 2) and the session (Task 3). Remember 30 days on by default, and sign out (Task 3). The every-request check (Task 3 `PortalSession` and `InPortal`). "Mail not set up" (Tasks 2, 3 and 4). `portal.login` / `portal.logout` (Task 3).
  - §4 pages:
    - Home figures (Task 5, ruling 12).
    - Agreements, Invoices with tabs, Payments and Cheques (Task 5).
    - Statement with a dd/mm/yyyy range (`<x-date-input>`) on screen and as PDF (Task 6).
    - Documents shared-only, with the staff toggle and an ownership-checked download (Task 6).
    - Maintenance list and tenant timeline (Tasks 7 and 8).
    - The new request with places, category, description ≤2000, entry permitted and up to 5 photos; channel portal, the tenant, their name, normal priority, the received email, the "Portal" badge and 10 a day (Tasks 7 and 8).
    - Bilingual with RTL and mobile first (Task 3 layout, `lang/ar.json`, `ArabicTest`).
  - §5 tables: nullable creators with `portal_account_id` and exactly-one CHECKs on requests and documents (and timeline rows, ruling 4); `shared_with_tenant`; the channel CHECK (Task 1). No money table is touched and no trigger is added.
  - §6 safety:
    - Scoped queries and 404s (Tasks 5–8, swept in Task 9).
    - No customer id on public Livewire methods (Task 9 reflection test).
    - Guards on both route sets (Tasks 1, 3 and 9).
    - Audits with the account as causer (Tasks 3, 5, 6 and 7).
    - Tampered input datasets in Tasks 2, 3, 5, 6, 7 and 8.
    - Headers as staff (ruling 17), CSRF (`web` group, `@csrf` on the layout forms), and constant-time compare (`hash_equals`).
  - §7 testing: each bullet maps to a file. `InviteTest`; `CodesTest` and `SignInTest`; `PagesTest`, `StatementAndDocumentsTest`, `MaintenancePagesTest` and `IsolationTest`; `StatementAndDocumentsTest`; `PortalRequestsTest` and `MaintenancePagesTest`; `SignInTest` and `ArabicTest` (Arabic and RTL), with dd/mm/yyyy and 3-dp amounts asserted throughout.
- **Spec conflicts and additions found:**
  - Ruling 4: the spec lists nullable creators only for requests and documents. The portal request's first timeline row needs the same on `maintenance_events`. "Remember this device" needs `remember_token`.
  - Ruling 3: one browser shares one session cookie. Staff sign-out also ends a tenant session in that browser. Neither guard ever opens the other's routes.
  - Ruling 13: invoices have no documents panel today, so "Share with tenant" is reachable on tenant and agreement documents only. The portal query already includes invoice documents.
  - Framework validation messages (Laravel's own `validation.*`) have no Arabic file. The portal's validators pass their own `__()` messages, so tenant-facing errors are Arabic. A stray framework message would fall back to English.
  - Everything else is a clarification (rulings 1, 2, 5–12, 14–18).
- **Placeholder scan:** none. Every step has its code or its exact command.
- **Type consistency:**
  - `portalAccount(Customer, array)`, `portalCodeFor(string)` and `portalStoredContract(Agreement)` are the same in every test.
  - `SendLoginCode::handle(mixed, string)` and `VerifyLoginCode::handle(mixed $email, mixed $code, string $ip)` are the same in Tasks 2 and 3.
  - `InviteToPortal::handle(User, Customer)` and `SetPortalAccess::handle(User, Customer, bool)` are the same in Task 4.
  - `MaintenanceEvent::record(MaintenanceRequest, User|PortalAccount, …)` is the same in Tasks 1 and 7.
  - `LogMaintenanceRequest::forPortal(PortalAccount, array, mixed)`, `::portalPlaces(int)` and `PORTAL_DAILY_LIMIT` are the same in Tasks 7, 8 and 9.
  - `StoreDocument::forPortal(PortalAccount, MaintenanceRequest, UploadedFile)` is the same in Task 7.
  - `ShareDocumentWithTenant::handle(User, Document, bool)` is the same in Tasks 6 and 9.
  - `CustomerStatement::pdf(Customer, string, string)` is the same in Task 6.
  - `Document::sharedWithTenant(int)` and `Document::SHAREABLE` are the same in Task 6.
  - `MaintenanceRequest::tenantTimeline()` returns `list<array{at, label}>` in Tasks 7 and 8.
  - `Customer::outstandingFils()` is the same in Task 5.
  - Routes: `portal.{login, locale, logout, home, agreements, agreements.pdf, invoices, invoices.pdf, payments, payments.receipt, cheques, statement, statement.pdf, documents, documents.download, maintenance, maintenance.create, maintenance.show}`.
- **Fixture arithmetic:**
  - `PagesTest`: INV-1 and INV-2 are 400.000 each, and the payment is 500.000 with 400.000 on INV-1. INV-2 owes 400.000 and the credit is 100.000. The next due is INV-2 (01/10/2026). The agreement ends 31/12/2026, 74 days after 18/10/2026.
  - `StatementAndDocumentsTest`: the same invoices, with 500.000 paid oldest first. The running balance is 0 → 400 → 800 → 300. From 02/10/2026 the opening balance is 800.000.
  - `MaintenancePagesTest`: two office requests first (MR-…01 Sara's, MR-…02 the other tenant's). The portal request is MR-2026-000003.
