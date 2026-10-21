# Owner Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let owners sign in to the existing portal with the same emailed code and see, view only, their buildings (with a rent roll where the company manages them), finalised statements, payouts, lease payments, expenses, maintenance, contracts and shared documents, in English or Arabic, with one sign-in for a person who is both a tenant and an owner.

**Architecture:** One portal, two kinds of access. A `portal_accounts` row holds a tenant, an owner or both (`customer_id` becomes nullable, `owner_id` is new). Sign-in, codes, rate limits, remember-me, the session stamp, locale and the staff/portal separation are reused unchanged on the `tenant` guard. Tenant pages and owner pages (`/portal/owner/...`, names `portal.owner.*`) sit behind one `PortalAccess` route middleware with a `tenant` or `owner` parameter, and every portal component guards itself through the `InPortal` trait's `customerId()` / `ownerId()`. Every owner query lives in one class, `App\Portal\OwnerPortal`, and starts from the account's `owner_id` through `OwnerContract::portalFor()`. The rent roll reuses the report's query (`Queries::rentRollUnits`), the statement PDF reuses the staff one (`OwnerStatementPdf::response`), and document sharing generalises the tenant action into `ShareDocument`.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, spatie/laravel-activitylog, `App\Audit\Audit`, `App\Support\Fils`, `App\Billing\OwnerStatementCalculator`, `App\Pdf\OwnerStatementPdf`, `App\Jobs\StoreOwnerStatement`, `App\Reports\Queries`, `App\Actions\Documents\StoreDocument`, `App\Reminders\EmailChannel`, `<x-mail.bilingual>`, `<x-status-badge>`.

**Spec:** `docs/superpowers/specs/2026-10-21-owner-portal-design.md`. Read it first; this plan argues from it. It builds on `docs/superpowers/specs/2026-10-18-tenant-portal-design.md` and the executed plan `docs/superpowers/plans/2026-10-18-tenant-portal.md`, whose rulings still hold unless a ruling below changes them.

## Global Constraints

- "One portal, two kinds of access. A `portal_accounts` row belongs to a tenant, an owner, or both (one person, one email, one sign-in). Sign-in, codes, rate limits, remember-me, the session stamp, locale and the staff/portal separation are reused unchanged" (spec §2). **View only** (spec §1).
- "Every owner query is scoped from `auth('tenant')->user()->owner_id` through the owner's contracts. A record id that is not theirs gives 404. Public Livewire methods never accept an owner or customer id" (spec §6).
- Every portal query starts from the signed-in account: `customer_id` for tenant pages, `owner_id` through the owner's contracts for owner pages. An id that is not theirs gives 404, via `whereNumber` plus a scoped lookup.
- "Owner pages refuse tenant-only accounts and the reverse; staff routes still refuse portal sessions" (spec §6).
- "Statement PDF and document downloads audited with the account as causer" (spec §6). `portal.invited`, `portal.disabled`, `portal.enabled` "record which record (tenant or owner) the action was taken on" (spec §3).
- Actions are the only write path.
- Money is in BHD with 3 decimals, as strings or fils, never floats. Use the `Fils` helpers (`fromDecimal`, `toDecimal`, `grouped`).
- Timezone is Asia/Bahrain. Dates show as dd/mm/yyyy (`format('d/m/Y')`). "Today" is `now('Asia/Bahrain')`.
- Every string goes through `__()` and needs an `ar.json` entry. Say "tenant", never "customer", in UI copy. Owner pages are EN / AR with RTL, mobile first (spec §4).
- Tampered Livewire input gives a validation message or 404, never a 500.
- Public non-static Livewire methods are browser-callable, so internals must be private or static. Use `#[Locked]` for ids.
- Money tables are immutable through triggers. `EXPECTED_TRIGGERS` = 61 stays unchanged ("No money tables touched", spec §5).
- Migrations must roll back in order.
- The dev DB is migrated with `php artisan migrate --database=migrator --force`. Never `migrate:fresh` on the dev DB `rms`.
- Tests run serially on `rms_test`, never with `--parallel`.
- Gates: Pint `--dirty`, phpstan `--memory-limit=2G`, `npm run build`.
- PHP binary: `/c/Users/ababy/.config/herd/bin/php85/php.exe` (written `php` below).
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit anything under `.superpowers/`.
- No visual polish. Keep pages functional and consistent with the existing portal pages, because a design pass comes later.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file. Portal views never use `@can` (the policies take staff `User`s; `SignInTest` checks this).

## Rulings made while planning

1. **Nullable `customer_id`: every tenant path is guarded.** Without a guard, an owner-only account's `customer_id` is `null`, and Eloquent's `where('customer_id', null)` becomes `IS NULL`. That would show an owner every common-area maintenance request (their `customer_id` is null) and give a `TypeError` (a 500) where an `int` is expected. So:
   - Route middleware `App\Http\Middleware\PortalAccess` with a parameter: `PortalAccess::class.':tenant'` on the tenant route group and `PortalAccess::class.':owner'` on the owner group. A missing kind is a 404.
   - The `InPortal` trait keeps `account()` (403 unless an active `PortalAccount`, tenant plan ruling 2). It gains `customerId()` / `customer()` (404 unless `hasTenant()`) and `ownerId()` / `owner()` (404 unless `hasOwner()`). Livewire tests run no middleware, so these are the component-side guards. Tenant `Home` and `Statement` stop reading `account()->customer` and use `customer()`. `NewMaintenanceRequest::save()` calls `customerId()` first.
   - `Portal\DownloadController::account()` adds a 404 unless `hasTenant()`. `LogMaintenanceRequest::forPortal()` refuses an account without an active tenant.
   - `SendLoginCode` and `VerifyLoginCode` keep calling `isActive()`, which now means either record (ruling 2). The code email names the person through `PortalAccount::personName()`, not `->customer`.
   - `InviteToPortal`, `SetPortalAccess` and the tenant/owner saves' email sync take `Customer|Owner` (rulings 5 and 6).
2. **"Active".** `PortalAccount::isActive()` is: not disabled, and `hasTenant() || hasOwner()`. Each of those reads the row fresh and ignores soft-deleted records. `PortalSession` is unchanged: an account with no active record left is signed out with the existing message. A soft-deleted owner on a shared account only loses the owner side (404 there, the switch hidden).
3. **Owner routes live at `/portal/owner/...`, names `portal.owner.*`.** Inside the existing `portal` group they inherit, unchanged:
   - `PortalSession` (locale, stamp, the active check);
   - the `auth:tenant` guard;
   - `bootstrap/app.php`'s `portal/*` guest redirect;
   - Livewire's `PortalSession::isPortalPage()` (it keys on `portal.*` names), so owner pages update under the tenant guard;
   - `StorageTest`'s "every `portal.*` route takes the tenant guard" check.

   A separate prefix such as `/owner` would need each of those copied. `GET /portal` stays the tenant home (`portal.home`). For an owner-only account, `PortalAccess:tenant` redirects that one route to `portal.owner.home` instead of a 404, because `/portal` is the portal's front door.
4. **The Tenant / Owner switch.**
   - The session key is `portal_view` (`PortalAccess::VIEW`). `PortalAccess` writes the kind on every page it lets through, so the last side used is kept.
   - The switch is two header links, Tenant → `portal.home` and Owner → `portal.owner.home`. It shows only when the account `hasTenant() && hasOwner()`. No POST route is needed.
   - `PortalAccess::homeRoute($account)` returns the owner home when `portal_view` is `owner` and the account still has an owner. Otherwise it returns the tenant home when the account has a tenant, else the owner home.
   - Sign-in, the `guest:tenant` redirect (`redirectUsersTo`) and the portal brand link use `homeRoute()`.
   - Sign-in and sign-out forget `portal_view`, so a shared account lands on the tenant home after each sign-in. Tenant-only accounts land on the tenant home and owner-only accounts on the owner home (spec §3).
   - The sign-in heading becomes "Sign in to the portal", because owners use it too.
5. **Invite and linking** (`InviteToPortal::handle(User, Customer|Owner)`, one action for both pages). The checks are as before: `can('update', $record)` (`customers.manage` with building scope, or `owners.manage`), mail ready, an email present, and the record not deleted. Inside one transaction, with both rows locked:
   - Look up the record's own account (by `customer_id` / `owner_id`) and the account that has the record's email.
   - If an account has the email and it is not the record's own: refuse when the record already has an account, or when that account already holds a record of this kind ("Another tenant's / owner's portal account uses this email."). Refuse when it is disabled ("The portal account for this email is disabled. Enable it on :name's page first."). Otherwise **link**: set this kind's column on it.
   - Otherwise create the account or re-enable the record's own (as today).
   - Race: two invites for the two kinds of one new email can both insert. `email` is unique, so the loser gets `UniqueConstraintViolationException`. The whole transaction is retried once through `retry(2, …, when: unique violation)`, and the second try finds the winner's row and links to it. Should the retry fail too, that is the "uses this email" message. `owner_id` and `customer_id` are each unique, so one record never has two accounts.
   - The audit `portal.invited` has `record` (`tenant`|`owner`), `customer_id` or `owner_id`, `email`, `resent` and `linked`. Re-sending to the record's own enabled account is `resent: true`. The invite mail is `PortalInvitation($account, $kind)` with that kind's subject, greeting and wording.
6. **Disable / Enable** (`SetPortalAccess::handle(User, Customer|Owner, bool)`) act on the whole account (spec §3), from either page. Disable nulls `remember_token` and deletes unused codes, as today. Enable takes the email of the page's record and needs one. The audit carries `record` and that record's id. The "Tenant portal" and "Owner portal" cards say "Also used by owner :name" / "Also used by tenant :name" when the account is shared and the other record is not deleted.
7. **Email changes on a shared account** (spec §3). The rule lives in `App\Actions\Portal\SyncPortalEmail`: `problem(?record, ?email)` runs in the save's validator, and `apply(record)` runs inside the save's transaction. The real owner save action is `App\Actions\Owners\SaveOwner` (called by `Owners\Form`). Both it and `SaveCustomer` use the class. For a record with an account:
   - A new email that another account uses is refused ("Another tenant's / owner's portal account uses this email.").
   - On a shared account whose other record has an email, a new email that differs from it is refused with "This email is also the portal sign-in for :name; change both together."
   - Clearing the email while the account is enabled is refused only when no other linked record keeps an email. On a shared account the other record still signs in with the account's email.
   - So staff move a shared account to a new email in three saves: clear it on one record, change the other (the account follows), then set the first again. A test walks this path.
   - `apply()` copies a changed, non-empty email in lower case and nulls `remember_token`, as the tenant sync does today. A racing unique violation is the "uses this email" message.
8. **"Their contracts" and "their buildings".** `OwnerContract::portalFor(int $ownerId)` is the owner's contracts with status active, ended or terminated (`OwnerContract::ATTRIBUTED`), never draft or pending approval. "Their buildings" are those contracts' `building_id`s. Managed or leased decides what a building card shows (ruling 9).
9. **Every scoped query** (all in `App\Portal\OwnerPortal`, all starting from `owner_id`):
   - **statements**: `owner_statements` whose `owner_contract_id` is in `portalFor` and whose status is `finalised`.
   - **payouts**: `disbursements` with `payee_type = owner`, `payee_id = owner_id` and `status = paid`. Pending, approved, rejected and reversed ones are left out.
   - **payables** (the rent schedule): `owner_payables` on `portalFor` contracts of type leased. Cancelled (replaced) rows are left out.
   - **expenses**: `expenses` with `charge_to = owner` and `owner_contract_id` in `portalFor`, both recorded and reversed.
   - **requests**: `maintenance_requests` in a building of a `portalFor` contract, logged on a day that contract covered (`start_date ≤ DATE(created_at) ≤ COALESCE(terminated_on, end_date)`). Without the date window, owner A would see requests from owner B's years in a building that changed hands.
   - **cost of a request**: the sum of `total` of its work orders' expenses that are in **expenses**, with status recorded.
   - **buildings**: one card per building of a `portalFor` contract. The **rent roll** is shown only while one of their managed contracts on that building is active and covers today. Its units are that contract's units, and its rows come from `Queries::rentRollUnits()`, the report's own query, refactored out of `RentRollReport` with no change to the report. It shows unit, tenant name, agreement dates, monthly rent, balance owed (once per agreement, as the report) and vacant units. It never shows tenant phone, email or ID number. A leased building, or one whose managed contract has ended, shows its unit codes only. Occupancy is "n of m units let" over the rent roll's rows.
   - **documents**: `Document::sharedWithOwner(int)`, ruling 13.
   - **contracts**: `portalFor` itself.
10. **Ids in owner URLs.** Owner URLs carry only three ids: the statement PDF, the document download and the maintenance request. Each has `whereNumber` and is looked up inside the scoped query with `findOrFail`. Controller and `mount` parameters are `string`, so a 30-digit id is a 404, not a `TypeError`. `Owner\MaintenanceShow` keeps the id in `#[Locked] public int $requestId`. No public Livewire method on an owner component takes an argument. The isolation sweep's reflection test checks this, and checks that no parameter name contains "customer" or "owner".
11. **Statements and the PDF.**
    - Each row's figures come from `OwnerStatementCalculator::stored()`: opening, closing, and fees = `fee_amount + fee_tax`. Its window entries are split by kind: "Charges" are `expense` and `expense_reversal`, "Paid to you" is `remittance` and `remittance_reversal`, and "Income" is everything else (collections, deposits when the owner holds them, opening balances). Each is net of its reversals, so opening + income − charges − fees − paid to you = closing.
    - "Paid to you" is shown besides the spec's five figures so the row adds up.
    - The PDF is the staff one: `OwnerStatementPdf::response()` (stored copy if present, else rendered), which `OwnerStatementPdfController` now calls too. A portal download is audited `owner_statement.exported` with the account as causer.
12. **Payments.** Payouts list date, number, amount, method, reference. `disbursements` keep no bank snapshot. So the bank is said once above the list, from the owner's record: "Bank transfers are paid to :bank, IBAN :iban.", with `Owner::maskedIban()` (`••••` + last 4). A per-row bank would show today's bank against old transfers. The full IBAN never reaches the page. The rent schedule lists due date, building, amount, and "Paid" or "Not paid yet".
13. **Documents.**
    - `Document::SHAREABLE` becomes per kind: `['tenant' => [Customer, Agreement, Invoice], 'owner' => [Owner, OwnerContract, Building]]`.
    - Owner statements are not in the owner list. They have no documents panel and no `update` policy, so no one could share their documents. Their PDF is on the Statements page.
    - **The existing action is generalised** into `ShareDocument::handle(User, Document, string $audience, bool)`, with `ShareDocumentWithTenant` removed. Both flags need the same two checks: the record type is in `SHAREABLE[$audience]`, and the user can update the record. A second class would copy both checks. The four test call sites change with it.
    - The panel shows "Share with owner" only on owner-shareable records, to users who can update the record, through `toggleOwnerShare(mixed $documentId)`. The browser never sends the audience.
    - `Document::sharedWithOwner(int)` returns shared documents on the owner, on a `portalFor` contract, or on a building of one. A building document shared with the owner is therefore seen by every owner with a contract on that building. That is what "their buildings" means (spec §4).
    - The `LogsActivity` options gain `shared_with_owner`, so each toggle is audited with the staff causer.
14. **Maintenance.** The list shows number, place (building and unit), category, status, sent date and completed date. A request page adds the tenant-portal timeline (`MaintenanceRequest::tenantTimeline()`, tenant plan ruling 15) and "Cost charged to you" when above zero. Description, reporter, vendor, work order numbers, notes and amounts other than the owner's cost are never shown.
15. **Home** (spec §4):
    - balance owed to the owner: per managed contract of theirs, the closing balance of its latest finalised statement;
    - last payout: the latest of **payouts**;
    - next lease payment due: the earliest scheduled row of **payables**;
    - occupancy: the managed building cards' "n of m units let";
    - contracts ending soon: their active contracts with `end_date` from today to today + 90.
16. **Mail and copy.**
    - `PortalInvitation` takes `$kind`. The owner subject is "Your owner portal · بوابة المالك · company".
    - The sign-in code email's Arabic greeting drops "المستأجر", because owners receive it too.
    - Every new string, staff and portal, gets an `ar.json` entry in the task that adds it. Each task inserts its lines after the line `"Credit refund reversal": "عكس استرداد رصيد",`, which ends in a comma, so the JSON stays valid.
    - `ArabicTest` scans the owner views and components, and its shared-label list gains the owner enums (Task 9).
17. **Migration and rollback.** `2026_12_24_000100_add_owner_portal.php` changes `portal_accounts.customer_id` to nullable. It adds `owner_id` (nullable, unique, FK to owners, no cascade), the CHECK `portal_accounts_holder_chk` (at least one set), and `documents.shared_with_owner`. Its `down()` reverses that and fails, safely, once an owner-only row exists (as the tenant migration's `down()` does). `tests/Concurrency/VendorsMigrationTest.php` rolls it back before the tenant portal migration and re-runs it after, so the real rollback order is exercised.

## File Structure

```
database/migrations/2026_12_24_000100_add_owner_portal.php          NEW  (Task 1)
app/Models/PortalAccount.php                                       MOD  owner(), hasTenant/hasOwner, isActive, column/of/otherThan, personName, name (Task 1)
app/Models/Owner.php                                               MOD  portalAccount(), displayName() (Task 1)
tests/Pest.php                                                     MOD  portalAccount(Customer|Owner) (Task 1); portalOwnerFixture() (Task 5)
tests/Concurrency/VendorsMigrationTest.php                         MOD  rollback order (Task 1)
app/Http/Middleware/PortalAccess.php                               NEW  (Task 2)
app/Livewire/Concerns/InPortal.php                                 MOD  customerId/customer/ownerId/owner (Task 2)
app/Livewire/Portal/Home.php, Statement.php, NewMaintenanceRequest.php, Login.php  MOD (Task 2)
app/Http/Controllers/Portal/DownloadController.php, LogoutController.php          MOD (Task 2)
app/Actions/Maintenance/LogMaintenanceRequest.php                  MOD  forPortal guard (Task 2)
app/Actions/Portal/SendLoginCode.php, resources/views/mail/portal-sign-in-code.blade.php  MOD (Task 2)
routes/portal.php                                                  MOD  access groups (Task 2); owner routes (Tasks 5–8)
bootstrap/app.php                                                  MOD  redirectUsersTo (Task 2)
resources/views/layouts/portal.blade.php                           MOD  switch, owner nav (Task 2; nav lines Tasks 5–8)
resources/views/livewire/portal/login.blade.php                    MOD  heading (Task 2)
app/Livewire/Portal/Owner/Home.php + view                          NEW  (Task 2); figures (Task 7)
lang/ar.json                                                       MOD  (Tasks 2–8)
app/Actions/Portal/InviteToPortal.php, SetPortalAccess.php         MOD  Customer|Owner, linking (Task 3)
app/Mail/PortalInvitation.php, resources/views/mail/portal-invitation.blade.php  MOD  kind (Task 3)
app/Livewire/Owners/Form.php, resources/views/livewire/owners/form.blade.php      MOD  Owner portal card (Task 3)
app/Livewire/Customers/Form.php, resources/views/livewire/customers/form.blade.php MOD "Also used by owner" (Task 3)
app/Actions/Portal/SyncPortalEmail.php                             NEW  (Task 4)
app/Actions/Customers/SaveCustomer.php, app/Actions/Owners/SaveOwner.php          MOD  (Task 4)
app/Models/OwnerContract.php                                       MOD  portalFor scope (Task 5)
app/Reports/Queries.php, app/Livewire/Reports/RentRollReport.php   MOD  rentRollUnits (Task 5)
app/Portal/OwnerPortal.php                                         NEW  every owner query (Task 5)
app/Livewire/Portal/Owner/Buildings.php, Contracts.php + views     NEW  (Task 5)
app/Pdf/OwnerStatementPdf.php, app/Http/Controllers/OwnerStatementPdfController.php  MOD  response() (Task 6)
app/Http/Controllers/Portal/OwnerDownloadController.php            NEW  statement (Task 6); document (Task 8)
app/Livewire/Portal/Owner/Statements.php, Payments.php, Expenses.php + views      NEW  (Task 6)
app/Livewire/Portal/Owner/Maintenance.php, MaintenanceShow.php + views            NEW  (Task 7)
app/Models/Document.php                                            MOD  SHAREABLE per kind, sharedWithOwner, cast, log (Task 8)
app/Actions/Documents/ShareDocument.php                            NEW  (Task 8); ShareDocumentWithTenant.php DELETED
app/Livewire/Documents/Panel.php, resources/views/livewire/documents/panel.blade.php  MOD (Task 8)
app/Livewire/Portal/Owner/Documents.php + view                     NEW  (Task 8)
tests/Feature/Portal/OwnerAccountsTest.php                         NEW  (Task 1)
tests/Feature/Portal/OwnerAccessTest.php                           NEW  (Task 2); SignInTest.php, ArabicTest.php MOD (Task 2)
tests/Feature/Portal/OwnerInviteTest.php                           NEW  (Task 3)
tests/Feature/Portal/PortalEmailTest.php                           NEW  (Task 4)
tests/Feature/Portal/OwnerScopeTest.php                            NEW  (Task 5)
tests/Feature/Portal/OwnerMoneyTest.php                            NEW  (Task 6)
tests/Feature/Portal/OwnerHomeAndMaintenanceTest.php               NEW  (Task 7)
tests/Feature/Portal/OwnerDocumentsTest.php                        NEW  (Task 8); StatementAndDocumentsTest.php, IsolationTest.php MOD (Task 8)
tests/Feature/Portal/IsolationTest.php, ArabicTest.php             MOD  (Task 9)
```

---

### Task 1: Storage: an account holds a tenant, an owner or both

**Files:**
- Create: `database/migrations/2026_12_24_000100_add_owner_portal.php`
- Modify: `app/Models/PortalAccount.php`, `app/Models/Owner.php`
- Modify: `tests/Pest.php` (the `portalAccount()` helper), `tests/Concurrency/VendorsMigrationTest.php`
- Test: `tests/Feature/Portal/OwnerAccountsTest.php`

**Interfaces:**
- Consumes: `PortalAccount`, `Customer::displayName(string)`, `Owner`, the tenant portal migration.
- Produces:
  - `portal_accounts.customer_id` nullable, `portal_accounts.owner_id` (nullable, unique, FK owners), CHECK `portal_accounts_holder_chk`, `documents.shared_with_owner` (bool, default false).
  - `PortalAccount::owner(): BelongsTo` (with trashed), `hasTenant(): bool`, `hasOwner(): bool`, `isActive(): bool` (ruling 2), `static column(Customer|Owner): string`, `static of(Customer|Owner): ?PortalAccount`, `otherThan(Customer|Owner): Customer|Owner|null`, `personName(string $lang): string`, `name` attribute ("Tenant portal · …" or "Owner portal · …").
  - `Owner::portalAccount(): HasOne`, `Owner::displayName(string $lang): string`.
  - Pest `portalAccount(Customer|Owner $holder, array $attributes = []): PortalAccount`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/OwnerAccountsTest.php`:

```php
<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $this->owner = Owner::factory()->create(['name_en' => 'Ali Owner', 'name_ar' => 'علي', 'email' => 'Ali@Owner.test']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'sara@tenant.test']);
});

test('an account holds a tenant, an owner or both, never neither, and one account per owner', function () {
    $ownerOnly = portalAccount($this->owner);
    expect($ownerOnly->customer_id)->toBeNull()
        ->and($ownerOnly->owner_id)->toBe($this->owner->id)
        ->and($ownerOnly->email)->toBe('ali@owner.test');

    $shared = portalAccount($this->tenant, ['owner_id' => Owner::factory()->create()->id]);
    expect($shared->customer_id)->toBe($this->tenant->id)->and($shared->owner_id)->not->toBeNull();

    expect(fn () => (new PortalAccount)->forceFill(['email' => 'nobody@portal.test'])->save())->toThrow(QueryException::class)
        ->and(fn () => portalAccount(Owner::factory()->create(['email' => 'x@owner.test']), ['owner_id' => $this->owner->id]))->toThrow(QueryException::class)
        ->and(fn () => portalAccount(Owner::factory()->create(['email' => 'y@owner.test']), ['owner_id' => 999999]))->toThrow(QueryException::class)
        ->and(Schema::hasColumn('documents', 'shared_with_owner'))->toBeTrue();
});

test('an account is active while it is enabled and at least one of its records is not deleted', function () {
    $secondOwner = Owner::factory()->create();
    $shared = portalAccount($this->tenant, ['owner_id' => $secondOwner->id]);
    $ownerOnly = portalAccount($this->owner);

    expect($ownerOnly->isActive())->toBeTrue()->and($ownerOnly->hasOwner())->toBeTrue()->and($ownerOnly->hasTenant())->toBeFalse()
        ->and($shared->hasTenant())->toBeTrue()->and($shared->hasOwner())->toBeTrue();

    $secondOwner->delete();
    expect($shared->isActive())->toBeTrue()->and($shared->hasOwner())->toBeFalse()->and($shared->hasTenant())->toBeTrue();
    $this->tenant->delete();
    expect($shared->isActive())->toBeFalse();

    $this->owner->delete();
    expect($ownerOnly->isActive())->toBeFalse();
    $this->owner->restore();
    expect($ownerOnly->isActive())->toBeTrue();
    $ownerOnly->forceFill(['disabled_at' => now()])->save();
    expect($ownerOnly->isActive())->toBeFalse();
});

test('the account names its person for audits and emails, the tenant first', function () {
    $ownerOnly = portalAccount($this->owner);
    $shared = portalAccount($this->tenant, ['owner_id' => Owner::factory()->create(['name_en' => 'Omar Owner'])->id]);

    expect($ownerOnly->name)->toBe('Owner portal · Ali Owner')
        ->and($ownerOnly->personName('en'))->toBe('Ali Owner')
        ->and($ownerOnly->personName('ar'))->toBe('علي')
        ->and($shared->name)->toBe('Tenant portal · Sara Tenant')
        ->and($shared->personName('ar'))->toBe('سارة');
});

test('an account finds its record and the other record it shares, while that one is not deleted', function () {
    $account = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);

    expect(PortalAccount::column($this->owner))->toBe('owner_id')
        ->and(PortalAccount::column($this->tenant))->toBe('customer_id')
        ->and(PortalAccount::of($this->owner)?->is($account))->toBeTrue()
        ->and($this->owner->portalAccount?->is($account))->toBeTrue()
        ->and($account->otherThan($this->owner)?->is($this->tenant))->toBeTrue()
        ->and($account->otherThan($this->tenant)?->is($this->owner))->toBeTrue();

    $this->tenant->delete();
    expect($account->otherThan($this->owner))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/OwnerAccountsTest.php`
Expected: FAIL. `portalAccount()` rejects an `Owner` (TypeError), and `owner_id` does not exist.

- [ ] **Step 3: Write the migration** `database/migrations/2026_12_24_000100_add_owner_portal.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-21 owner portal §3 and §5: a portal account holds a tenant, an owner or both; documents get "Share with
 * owner". Not money tables: no triggers (EXPECTED_TRIGGERS unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->foreignId('owner_id')->nullable()->unique()->after('customer_id')->constrained(); // no cascade: owners are soft-deleted
        });
        DB::statement('ALTER TABLE portal_accounts ADD CONSTRAINT portal_accounts_holder_chk CHECK (customer_id IS NOT NULL OR owner_id IS NOT NULL)');

        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('shared_with_owner')->default(false)->after('shared_with_tenant');
        });
    }

    /** ponytail: reversible only while every account still has a tenant; an owner-only row makes the NOT NULL change fail, which is the safe outcome. */
    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('shared_with_owner'));
        DB::statement('ALTER TABLE portal_accounts DROP CHECK portal_accounts_holder_chk');
        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Replace `app/Models/PortalAccount.php`** with:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: one person's sign-in to the portal, the `tenant`
 * guard's user, holding a tenant, an owner or both (one email, one sign-in). Never a staff User. Written only by
 * App\Actions\Portal (and the tenant and owner saves' email sync); no password, sign-in is by emailed code.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $owner_id
 * @property string $email lower case (tenant plan ruling 5)
 * @property string $locale 'en' | 'ar'
 * @property CarbonImmutable|null $invited_at
 * @property int|null $invited_by
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $disabled_at
 * @property string|null $remember_token nulled on disable and on an email change; PortalSession also stamps live sessions with it
 * @property-read Customer|null $customer
 * @property-read Owner|null $owner
 * @property-read string $name
 */
class PortalAccount extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public const array LOCALES = ['en', 'ar'];

    protected $fillable = ['customer_id', 'owner_id', 'email', 'locale'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return ['invited_at' => 'immutable_datetime', 'last_login_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime'];
    }

    /**
     * Stored in lower case for every writer (tenant plan ruling 5).
     *
     * @return Attribute<never, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => Str::lower($value));
    }

    /** @return BelongsTo<Customer, $this> with trashed: a deleted tenant's account still names them in the audit log */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<Owner, $this> with trashed: a deleted owner's account still names them in the audit log */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class)->withTrashed();
    }

    /** Owner portal plan ruling 2: a linked tenant that is not soft-deleted, read fresh (not from a loaded relation). */
    public function hasTenant(): bool
    {
        return $this->customer_id !== null && Customer::query()->whereKey($this->customer_id)->exists();
    }

    /** Owner portal plan ruling 2: a linked owner that is not soft-deleted, read fresh. */
    public function hasOwner(): bool
    {
        return $this->owner_id !== null && Owner::query()->whereKey($this->owner_id)->exists();
    }

    /** Checked on every request (owner portal plan ruling 2): not disabled, and at least one linked record not soft-deleted. */
    public function isActive(): bool
    {
        return $this->disabled_at === null && ($this->hasTenant() || $this->hasOwner());
    }

    /** The account column that links $record: customer_id for a tenant, owner_id for an owner. */
    public static function column(Customer|Owner $record): string
    {
        return $record instanceof Customer ? 'customer_id' : 'owner_id';
    }

    /** $record's own account, if it has one. */
    public static function of(Customer|Owner $record): ?self
    {
        return self::query()->where(self::column($record), $record->getKey())->first();
    }

    /** The other record on a shared account, while it is not soft-deleted ("Also used by …", owner portal plan ruling 6). */
    public function otherThan(Customer|Owner $record): Customer|Owner|null
    {
        return $record instanceof Customer
            ? ($this->owner_id !== null ? Owner::query()->find($this->owner_id) : null)
            : ($this->customer_id !== null ? Customer::query()->find($this->customer_id) : null);
    }

    /** The person the emails greet: the tenant when there is one, else the owner (owner portal plan ruling 16). */
    public function personName(string $lang): string
    {
        return $this->customer !== null ? $this->customer->displayName($lang) : (string) $this->owner?->displayName($lang);
    }

    /**
     * How the audit log viewer names this causer (tenant plan ruling 9).
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->customer !== null
            ? __('Tenant portal').' · '.$this->customer->name_en
            : __('Owner portal').' · '.$this->owner?->name_en);
    }

    /** No password: sign-in is by emailed code (spec §3). */
    public function getAuthPassword(): string
    {
        return '';
    }
}
```

- [ ] **Step 5: Add the relation and the display name to `app/Models/Owner.php`.**
  - Add `use Illuminate\Database\Eloquent\Relations\HasOne;` to the imports.
  - Add these lines to the class docblock under `@property string $name_en`:

```php
 * @property string|null $name_ar
 * @property string|null $email
 * @property string|null $bank_name
```

  - Add these methods after `maskedIban()`:

```php
    /** @return HasOne<PortalAccount, $this> spec 2026-10-21 owner portal §3 */
    public function portalAccount(): HasOne
    {
        return $this->hasOne(PortalAccount::class);
    }

    /** The name in $lang ('ar' falls back to English), as Customer::displayName. */
    public function displayName(string $lang): string
    {
        return $lang === 'ar' && filled($this->name_ar) ? (string) $this->name_ar : $this->name_en;
    }
```

- [ ] **Step 6: Generalise the Pest helper.** In `tests/Pest.php`, replace the whole `portalAccount()` function (docblock included) with:

```php
/** A portal account for $holder, a tenant or an owner (portal tests): enabled, English, with the holder's email in lower case, unless $attributes say otherwise. */
function portalAccount(Customer|Owner $holder, array $attributes = []): PortalAccount
{
    $account = (new PortalAccount)->forceFill([
        PortalAccount::column($holder) => $holder->id, 'email' => Str::lower((string) $holder->email), 'locale' => 'en', 'invited_at' => now(), ...$attributes,
    ]);
    $account->save();

    return $account;
}
```

- [ ] **Step 7: Keep the real rollback order in `tests/Concurrency/VendorsMigrationTest.php`.**
  - In `beforeEach`, after the `$this->portal = require …` line, add:

```php
    $this->ownerPortal = require database_path('migrations/2026_12_24_000100_add_owner_portal.php');
```

  - In `afterEach`, after the `if (! Schema::hasTable('portal_accounts')) { … }` block, add:

```php
    if (! Schema::hasColumn('portal_accounts', 'owner_id')) { // the owner portal migration after that, in up order
        $this->ownerPortal->up();
    }
```

  - Replace both occurrences (use `replace_all`) of

```php
    if (Schema::hasTable('portal_accounts')) {
        $this->portal->down(); // real rollback order: the portal migration alters maintenance_requests, so it goes back first
    }
```

    with

```php
    if (Schema::hasColumn('portal_accounts', 'owner_id')) {
        $this->ownerPortal->down(); // real rollback order: the owner portal migration alters portal_accounts, so it goes back first
    }
    if (Schema::hasTable('portal_accounts')) {
        $this->portal->down(); // real rollback order: the portal migration alters maintenance_requests, so it goes back first
    }
```

  - Replace the last line of the file's last test, `    expect(Schema::hasTable('portal_accounts'))->toBeTrue();`, with:

```php
    expect(Schema::hasTable('portal_accounts'))->toBeTrue();
    $this->ownerPortal->up();
    expect(Schema::hasColumn('portal_accounts', 'owner_id'))->toBeTrue();
```

- [ ] **Step 8: Migrate the dev DB.**

Run: `php artisan migrate --database=migrator --force`
Expected: `2026_12_24_000100_add_owner_portal ... DONE`.

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/Portal/OwnerAccountsTest.php tests/Concurrency/VendorsMigrationTest.php tests/Feature/Portal`
Expected: PASS. `StorageTest`'s "one account per tenant and per email" still passes, because `portalAccount($other, ['email' => …])` still fills `customer_id`.

- [ ] **Step 10: Gates and commit.**

Run: `php vendor/bin/pint --dirty` and `php vendor/bin/phpstan analyse --memory-limit=2G`.

```bash
git add database/migrations/2026_12_24_000100_add_owner_portal.php app/Models/PortalAccount.php app/Models/Owner.php tests/Pest.php tests/Concurrency/VendorsMigrationTest.php tests/Feature/Portal/OwnerAccountsTest.php
git commit -m "$(cat <<'EOF'
Owner portal storage: a portal account holds a tenant, an owner or both; active while one record is

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Access: tenant and owner sides, the landing, the switch, and every tenant path guarded

**Files:**
- Create: `app/Http/Middleware/PortalAccess.php`, `app/Livewire/Portal/Owner/Home.php`, `resources/views/livewire/portal/owner/home.blade.php`
- Modify: `app/Livewire/Concerns/InPortal.php`, `app/Livewire/Portal/Home.php`, `app/Livewire/Portal/Statement.php`, `app/Livewire/Portal/NewMaintenanceRequest.php`, `app/Livewire/Portal/Login.php`
- Modify: `app/Http/Controllers/Portal/DownloadController.php`, `app/Http/Controllers/Portal/LogoutController.php`, `app/Actions/Maintenance/LogMaintenanceRequest.php`, `app/Actions/Portal/SendLoginCode.php`
- Modify: `routes/portal.php`, `bootstrap/app.php`, `resources/views/layouts/portal.blade.php`, `resources/views/livewire/portal/login.blade.php`, `resources/views/mail/portal-sign-in-code.blade.php`, `lang/ar.json`
- Modify: `tests/Feature/Portal/SignInTest.php`, `tests/Feature/Portal/ArabicTest.php`
- Test: `tests/Feature/Portal/OwnerAccessTest.php`

**Interfaces:**
- Consumes: `PortalAccount::hasTenant()`, `hasOwner()`, `isActive()`, `personName()`, `Owner::displayName()` (Task 1).
- Produces:
  - Middleware `PortalAccess` (`handle(Request, Closure, string $kind)`; `const VIEW = 'portal_view'`; `static homeRoute(mixed $account): string`).
  - `InPortal`: `protected customerId(): int`, `protected customer(): Customer`, `protected ownerId(): int`, `protected owner(): Owner`.
  - Route group `portal.owner.*` at `/portal/owner` with `portal.owner.home`. Later tasks add routes after the line `Route::livewire('/', Portal\Owner\Home::class)->name('home');`.
  - Layout `$nav` for the owner side, a list later tasks extend.
  - `App\Livewire\Portal\Owner\Home` (Livewire name `portal.owner.home`).

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/OwnerAccessTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Livewire\Portal;
use App\Mail\PortalSignInCode;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->owner = Owner::factory()->create(['name_en' => 'Ali Owner', 'name_ar' => 'علي', 'email' => 'ali@owner.test']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    $this->signIn = fn (string $email) => Livewire::test(Portal\Login::class)->set('email', $email)->call('sendCode')
        ->set('code', portalCodeFor($email))->call('signIn');
});

test('sign-in lands a tenant-only account on the tenant home', function () {
    portalAccount($this->tenant);

    ($this->signIn)('sara@tenant.test')->assertRedirect(route('portal.home'));
});

test('sign-in lands an owner-only account on the owner home, and the code email greets the owner', function () {
    portalAccount($this->owner);

    ($this->signIn)('ali@owner.test')->assertRedirect(route('portal.owner.home'));

    $mail = Mail::queued(PortalSignInCode::class)->sole();
    expect($mail->nameEn)->toBe('Ali Owner')->and($mail->nameAr)->toBe('علي');
});

test('a shared account lands on the tenant home, switches with the header links, and the last side is kept in the session', function () {
    $account = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);
    ($this->signIn)('sara@tenant.test')->assertRedirect(route('portal.home'));

    $this->actingAs($account, 'tenant');
    $this->get(route('portal.home'))->assertOk()->assertSee('Welcome, Sara Tenant')
        ->assertSee(route('portal.owner.home'))->assertSee(route('portal.agreements'));
    $this->get(route('portal.owner.home'))->assertOk()->assertSessionHas('portal_view', 'owner')
        ->assertSee('Welcome, Ali Owner')->assertDontSee(route('portal.agreements'));
    $this->get(route('portal.login'))->assertRedirect(route('portal.owner.home'));

    $this->get(route('portal.home'))->assertOk()->assertSessionHas('portal_view', 'tenant');
    $this->get(route('portal.login'))->assertRedirect(route('portal.home'));
});

test('a tenant-only account sees no switch and gets a 404 on the owner pages', function () {
    $account = portalAccount($this->tenant);

    $this->actingAs($account, 'tenant')->get(route('portal.home'))->assertOk()->assertDontSee(route('portal.owner.home'));
    $this->get(route('portal.owner.home'))->assertNotFound();
    Livewire::actingAs($account, 'tenant')->test(Portal\Owner\Home::class)->assertNotFound();
});

test('an owner-only account opening /portal goes to the owner home; tenant pages, downloads and requests are a 404', function () {
    $account = portalAccount($this->owner);
    $building = Building::factory()->create();
    $pm = matrixUser(R::PropertyManager, $building);
    // A common-area request has no tenant: a null customer_id must never match an owner-only account.
    $commonArea = maintenanceRequest($pm, Unit::factory()->for($building)->create(), ['unit_id' => null, 'customer_id' => null]);

    $this->actingAs($account, 'tenant');
    $this->get(route('portal.home'))->assertRedirect(route('portal.owner.home'));
    foreach ([
        route('portal.agreements'), route('portal.invoices'), route('portal.payments'), route('portal.cheques'), route('portal.statement'),
        route('portal.documents'), route('portal.maintenance'), route('portal.maintenance.create'), route('portal.maintenance.show', $commonArea),
        route('portal.statement.pdf', ['from' => '2026-01-01', 'to' => '2026-10-21']),
    ] as $url) {
        $this->get($url)->assertNotFound();
    }

    foreach ([Portal\Home::class, Portal\Agreements::class, Portal\Statement::class, Portal\Maintenance::class, Portal\NewMaintenanceRequest::class] as $component) {
        Livewire::actingAs($account, 'tenant')->test($component)->assertNotFound();
    }
    Livewire::actingAs($account, 'tenant')->test(Portal\MaintenanceShow::class, ['maintenance' => (string) $commonArea->id])->assertNotFound();
    expect(fn () => app(LogMaintenanceRequest::class)->forPortal($account, ['place' => 'building:'.$building->id, 'category' => 'general', 'description' => 'Leak', 'entry_permitted' => false]))
        ->toThrow(AuthorizationException::class);
});

test('a deleted owner loses the owner side; with no active record left the session ends', function () {
    $shared = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);
    $this->actingAs($shared, 'tenant')->get(route('portal.owner.home'))->assertOk();
    $this->owner->delete();

    $this->get(route('portal.owner.home'))->assertNotFound();
    $this->get(route('portal.home'))->assertOk()->assertDontSee(route('portal.owner.home'));
    $this->get(route('portal.login'))->assertRedirect(route('portal.home'));

    $this->tenant->delete();
    $this->get(route('portal.home'))->assertRedirect(route('portal.login'))->assertSessionHas('status');
    expect(Auth::guard('tenant')->check())->toBeFalse();
});

test('an owner-only account whose owner is deleted is signed out on the next request', function () {
    $this->actingAs(portalAccount($this->owner), 'tenant')->get(route('portal.owner.home'))->assertOk();
    $this->owner->delete();

    $this->get(route('portal.owner.home'))->assertRedirect(route('portal.login'));
    expect(Auth::guard('tenant')->check())->toBeFalse();
});

test('the owner side speaks Arabic, right to left, for an Arabic account', function () {
    $this->actingAs(portalAccount($this->owner, ['locale' => 'ar']), 'tenant')->get(route('portal.owner.home'))
        ->assertOk()->assertSee('dir="rtl"', false)->assertSee('أهلاً، علي')->assertSee('بوابة المالك');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/OwnerAccessTest.php`
Expected: FAIL. Route `portal.owner.home` is not defined.

- [ ] **Step 3: Create `app/Http/Middleware/PortalAccess.php`:**

```php
<?php

namespace App\Http\Middleware;

use App\Models\PortalAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 2026-10-21 owner portal §3: tenant pages need an active tenant on the account, owner pages an active owner;
 * anything else is a 404. The side a page belongs to is kept in the session for the landing (owner portal plan
 * rulings 1, 3 and 4). Runs after auth:tenant, so the guard's user is a portal account.
 */
final class PortalAccess
{
    public const string VIEW = 'portal_view';

    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount, 404);

        if (! ($kind === 'owner' ? $account->hasOwner() : $account->hasTenant())) {
            // /portal is the portal's front door: an owner-only account goes to its own home rather than a 404.
            if ($kind === 'tenant' && $request->routeIs('portal.home') && $account->hasOwner()) {
                return redirect()->route('portal.owner.home');
            }
            abort(404);
        }

        session([self::VIEW => $kind]);

        return $next($request);
    }

    /** Where a signed-in account lands: the side it last used if it still has it, else its tenant side, else its owner side. */
    public static function homeRoute(mixed $account): string
    {
        if (! $account instanceof PortalAccount) {
            return 'portal.home';
        }
        if (session(self::VIEW) === 'owner' && $account->hasOwner()) {
            return 'portal.owner.home';
        }

        return $account->hasTenant() ? 'portal.home' : 'portal.owner.home';
    }
}
```

- [ ] **Step 4: Replace `app/Livewire/Concerns/InPortal.php`** with:

```php
<?php

namespace App\Livewire\Concerns;

use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use Illuminate\Support\Facades\Auth;

/**
 * Spec 2026-10-18 tenant portal §6 and 2026-10-21 owner portal §6: every portal query starts from the signed-in
 * account. Protected, so the browser cannot call these; they check the guard themselves because Livewire tests run no
 * middleware (tenant plan ruling 2). A side the account does not have is a 404 (owner portal plan ruling 1).
 */
trait InPortal
{
    protected function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);

        return $account;
    }

    /** The account's tenant: never null here, so no query ever matches `customer_id IS NULL`. */
    protected function customerId(): int
    {
        $account = $this->account();
        abort_unless($account->hasTenant(), 404);

        return (int) $account->customer_id;
    }

    protected function customer(): Customer
    {
        return Customer::query()->findOrFail($this->customerId());
    }

    protected function ownerId(): int
    {
        $account = $this->account();
        abort_unless($account->hasOwner(), 404);

        return (int) $account->owner_id;
    }

    protected function owner(): Owner
    {
        return Owner::query()->findOrFail($this->ownerId());
    }
}
```

- [ ] **Step 5: Guard the tenant components.**
  - In `app/Livewire/Portal/Home.php` replace `$customer = $this->account()->customer;` with `$customer = $this->customer();`.
  - In `app/Livewire/Portal/Statement.php` replace `$customer = $this->account()->customer;` with `$customer = $this->customer();`.
  - In `app/Livewire/Portal/NewMaintenanceRequest.php`, make the first statement of `save()` this line:

```php
        $this->customerId(); // an owner-only account has no places: a 404, never a 500 (owner portal plan ruling 1)
```

- [ ] **Step 6: Guard the tenant downloads.** In `app/Http/Controllers/Portal/DownloadController.php`:
  - add `use App\Models\Customer;`;
  - in `statement()`, replace `$customer = $account->customer;` with `$customer = Customer::query()->findOrFail((int) $account->customer_id);`;
  - replace the private `account()` with:

```php
    private function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);
        abort_unless($account->hasTenant(), 404); // owner portal plan ruling 1: never `customer_id IS NULL`

        return $account;
    }
```

- [ ] **Step 7: Guard the portal request.** In `app/Actions/Maintenance/LogMaintenanceRequest.php`, `forPortal()`:
  - replace `if (! $account->isActive()) {` with `if (! $account->isActive() || ! $account->hasTenant()) {`;
  - replace `$places = self::portalPlaces($account->customer_id);` with `$places = self::portalPlaces((int) $account->customer_id);`;
  - replace `$tenant = $account->customer;` with `$tenant = Customer::query()->findOrFail((int) $account->customer_id);`;
  - add `use App\Models\Customer;` to the imports if it is not there.

- [ ] **Step 8: The code email greets the person.**
  - In `app/Actions/Portal/SendLoginCode.php` replace the two lines

```php
                $tenant = $account->customer;
                Mail::to(new Address($account->email, $tenant->name_en))->queue(new PortalSignInCode($code, $tenant->name_en, $tenant->displayName('ar')));
```

    with

```php
                $name = $account->personName('en'); // the tenant, else the owner (owner portal plan ruling 16)
                Mail::to(new Address($account->email, $name))->queue(new PortalSignInCode($code, $name, $account->personName('ar')));
```

  - In `resources/views/mail/portal-sign-in-code.blade.php` replace `<p>عزيزي المستأجر {{ $nameAr }}،</p>` with `<p>عزيزي {{ $nameAr }}،</p>`.

- [ ] **Step 9: Landing after sign-in and sign-out.**
  - In `app/Livewire/Portal/Login.php` add `use App\Http\Middleware\PortalAccess;`.
  - In `signIn()`, replace `session()->forget('portal_stamp'); // a fresh sign-in is stamped anew by PortalSession` with:

```php
        session()->forget(['portal_stamp', PortalAccess::VIEW]); // stamped anew by PortalSession; a shared account starts on its tenant side
```

    and replace `$this->redirectRoute('portal.home');` (the one at the end of `signIn()`) with `$this->redirectRoute(PortalAccess::homeRoute($account));`.
  - In `signedIn()`, replace `$this->redirectRoute('portal.home');` with `$this->redirectRoute(PortalAccess::homeRoute(Auth::guard('tenant')->user()));`.
  - In `app/Http/Controllers/Portal/LogoutController.php` add `use App\Http\Middleware\PortalAccess;` and replace `$request->session()->forget('portal_stamp');` with `$request->session()->forget(['portal_stamp', PortalAccess::VIEW]);`.
  - In `bootstrap/app.php`, add `use App\Http\Middleware\PortalAccess;` and `use Illuminate\Support\Facades\Auth;` to the imports. Replace the `redirectUsersTo` line with:

```php
        $middleware->redirectUsersTo(fn (Request $request) => $portal($request) ? route(PortalAccess::homeRoute(Auth::guard('tenant')->user())) : route('dashboard'));
```

- [ ] **Step 10: Routes.** Replace `routes/portal.php` with:

```php
<?php

use App\Http\Controllers\Portal\DownloadController;
use App\Http\Controllers\Portal\LocaleController;
use App\Http\Controllers\Portal\LogoutController;
use App\Http\Middleware\PortalAccess;
use App\Http\Middleware\PortalSession;
use App\Livewire\Portal;
use Illuminate\Support\Facades\Route;

/*
 * Spec 2026-10-18 tenant portal and 2026-10-21 owner portal: the `tenant` guard only (tenant plan ruling 1). Tenant
 * pages need an active tenant on the account, owner pages an active owner (owner portal plan rulings 1 and 3). Every
 * record id is looked up inside the signed-in account's records (tenant plan ruling 18, owner portal plan ruling 10).
 */
Route::prefix('portal')->name('portal.')->middleware(PortalSession::class)->group(function () {
    Route::livewire('login', Portal\Login::class)->middleware('guest:tenant')->name('login');
    Route::post('locale', LocaleController::class)->middleware('throttle:30,1')->name('locale');

    Route::middleware('auth:tenant')->group(function () {
        Route::middleware(PortalAccess::class.':tenant')->group(function () {
            Route::livewire('/', Portal\Home::class)->name('home');
            Route::livewire('agreements', Portal\Agreements::class)->name('agreements');
            Route::get('agreements/{agreement}/contract', [DownloadController::class, 'agreement'])->whereNumber('agreement')->name('agreements.pdf');
            Route::livewire('invoices', Portal\Invoices::class)->name('invoices');
            Route::get('invoices/{invoice}/pdf', [DownloadController::class, 'invoice'])->whereNumber('invoice')->name('invoices.pdf');
            Route::livewire('payments', Portal\Payments::class)->name('payments');
            Route::get('payments/{payment}/receipt', [DownloadController::class, 'receipt'])->whereNumber('payment')->name('payments.receipt');
            Route::livewire('cheques', Portal\Cheques::class)->name('cheques');
            Route::livewire('statement', Portal\Statement::class)->name('statement');
            Route::get('statement/pdf', [DownloadController::class, 'statement'])->name('statement.pdf');
            Route::livewire('documents', Portal\Documents::class)->name('documents');
            Route::get('documents/{document}', [DownloadController::class, 'document'])->whereNumber('document')->name('documents.download');
            Route::livewire('maintenance', Portal\Maintenance::class)->name('maintenance');
            Route::livewire('maintenance/new', Portal\NewMaintenanceRequest::class)->name('maintenance.create');
            Route::livewire('maintenance/{maintenance}', Portal\MaintenanceShow::class)->whereNumber('maintenance')->name('maintenance.show');
        });

        Route::prefix('owner')->name('owner.')->middleware(PortalAccess::class.':owner')->group(function () {
            Route::livewire('/', Portal\Owner\Home::class)->name('home');
        });

        Route::post('logout', LogoutController::class)->name('logout');
    });
});
```

- [ ] **Step 11: The owner home.** Create `app/Livewire/Portal/Owner/Home.php`:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: the owner's home. */
#[Layout('layouts::portal')]
class Home extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.home', [
            'name' => $this->owner()->displayName(app()->getLocale()),
        ])->title(__('Home'));
    }
}
```

  Create `resources/views/livewire/portal/owner/home.blade.php`:

```blade
<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>
</section>
```

- [ ] **Step 12: The layout.** Replace the `@php … @endphp` block at the top of `resources/views/layouts/portal.blade.php` with:

```blade
@php
    $account = auth('tenant')->user();
    $rtl = app()->getLocale() === 'ar';
    $company = \App\Models\CompanySetting::query()->find(1);
    $companyName = $company ? ($rtl && filled($company->name_ar) ? $company->name_ar : $company->name_en) : config('app.name');
    // Owner portal plan ruling 4: the side is the route's; a shared account gets the Tenant / Owner switch.
    $ownerSide = request()->routeIs('portal.owner.*');
    $both = $account instanceof \App\Models\PortalAccount && $account->hasTenant() && $account->hasOwner();
    // [route, label]; each page's task adds its line (plan File Structure).
    $nav = $ownerSide ? [
        ['portal.owner.home', __('Home')],
    ] : [
        ['portal.home', __('Home')],
        ['portal.agreements', __('Agreements')],
        ['portal.invoices', __('Invoices')],
        ['portal.payments', __('Payments')],
        ['portal.cheques', __('Cheques')],
        ['portal.statement', __('Statement')],
        ['portal.documents', __('Documents')],
        ['portal.maintenance', __('Maintenance')],
    ];
@endphp
```

  Then, in the same file:
  - replace `<a href="{{ route($account ? 'portal.home' : 'portal.login') }}" class="font-semibold">{{ $companyName }}</a>` with `<a href="{{ route($account ? ($ownerSide ? 'portal.owner.home' : 'portal.home') : 'portal.login') }}" class="font-semibold">{{ $companyName }}</a>`;
  - replace `<nav aria-label="{{ __('Tenant portal') }}" class="mx-auto max-w-3xl overflow-x-auto px-4">` with `<nav aria-label="{{ $ownerSide ? __('Owner portal') : __('Tenant portal') }}" class="mx-auto max-w-3xl overflow-x-auto px-4">`;
  - directly after `<div class="flex items-center gap-1">` (the header's button row), insert:

```blade
                    @if ($both)
                        <nav aria-label="{{ __('Portal') }}" class="flex gap-1">
                            @foreach ([['portal.home', __('Tenant'), ! $ownerSide], ['portal.owner.home', __('Owner'), $ownerSide]] as [$route, $label, $current])
                                <a href="{{ route($route) }}" @if ($current) aria-current="page" @endif
                                    @class([
                                        'block rounded-md px-3 py-1.5 text-sm font-medium',
                                        'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $current,
                                        'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700' => ! $current,
                                    ])>{{ $label }}</a>
                            @endforeach
                        </nav>
                    @endif
```

- [ ] **Step 13: Sign-in heading.**
  - In `resources/views/livewire/portal/login.blade.php` replace `{{ __('Sign in to the tenant portal') }}` with `{{ __('Sign in to the portal') }}`.
  - In `tests/Feature/Portal/SignInTest.php`, replace `->assertSee('Sign in to the tenant portal');` with `->assertSee('Sign in to the portal');`, and `->assertSee('تسجيل الدخول إلى بوابة المستأجر');` with `->assertSee('تسجيل الدخول إلى البوابة');`.
  - In the same file's "portal views never use @can" test, replace `$views = [resource_path('views/layouts/portal.blade.php'), ...glob(resource_path('views/livewire/portal/*.blade.php'))];` with:

```php
    $views = [resource_path('views/layouts/portal.blade.php'), ...glob(resource_path('views/livewire/portal/*.blade.php')), ...glob(resource_path('views/livewire/portal/owner/*.blade.php'))];
```

- [ ] **Step 14: Arabic.**
  - In `tests/Feature/Portal/ArabicTest.php`'s first test, replace `...glob(resource_path('views/livewire/portal/*.blade.php')),` with:

```php
        ...glob(resource_path('views/livewire/portal/*.blade.php')),
        ...glob(resource_path('views/livewire/portal/owner/*.blade.php')),
        ...glob(app_path('Livewire/Portal/Owner/*.php')),
```

  - In `lang/ar.json`, after the line `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Sign in to the portal": "تسجيل الدخول إلى البوابة",
    "Owner portal": "بوابة المالك",
    "Tenant": "المستأجر",
    "Owner": "المالك",
```

- [ ] **Step 15: Run the tests.**

Run: `php artisan test tests/Feature/Portal`
Expected: PASS. That includes the new file and the existing `SignInTest` (the tenant-only landing is still `portal.home`), `FinalFixesTest`, `MaintenancePagesTest`, `IsolationTest` and `StorageTest` (owner routes sit under `auth:tenant`).

- [ ] **Step 16: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.

```bash
git add app/Http/Middleware/PortalAccess.php app/Livewire/Concerns/InPortal.php app/Livewire/Portal app/Http/Controllers/Portal app/Actions/Maintenance/LogMaintenanceRequest.php app/Actions/Portal/SendLoginCode.php routes/portal.php bootstrap/app.php resources/views/layouts/portal.blade.php resources/views/livewire/portal resources/views/mail/portal-sign-in-code.blade.php lang/ar.json tests/Feature/Portal/OwnerAccessTest.php tests/Feature/Portal/SignInTest.php tests/Feature/Portal/ArabicTest.php
git commit -m "$(cat <<'EOF'
Owner portal access: tenant and owner sides behind PortalAccess, landing and Tenant / Owner switch, every tenant path guarded against an owner-only account

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Invite, link, re-send, disable and enable from the owner page

**Files:**
- Modify: `app/Actions/Portal/InviteToPortal.php`, `app/Actions/Portal/SetPortalAccess.php`
- Modify: `app/Mail/PortalInvitation.php`, `resources/views/mail/portal-invitation.blade.php`
- Modify: `app/Livewire/Owners/Form.php`, `resources/views/livewire/owners/form.blade.php`
- Modify: `app/Livewire/Customers/Form.php`, `resources/views/livewire/customers/form.blade.php`
- Modify: `lang/ar.json`
- Test: `tests/Feature/Portal/OwnerInviteTest.php`

**Interfaces:**
- Consumes: `PortalAccount::column()`, `of()`, `otherThan()`, `personName()` (Task 1), `OwnerPolicy::update` (`owners.manage`), `EmailChannel::ready()`.
- Produces:
  - `InviteToPortal::handle(User $actor, Customer|Owner $record): PortalAccount` and `InviteToPortal::takenMessage(Customer|Owner $record): string`.
  - `SetPortalAccess::handle(User $actor, Customer|Owner $record, bool $enabled): PortalAccount`.
  - `PortalInvitation::__construct(PortalAccount $account, string $kind = 'tenant')`, with a public `$kind`.
  - `Owners\Form::invitePortal()`, `disablePortal()`, `enablePortal()`.
  - View data `portal.other` on both forms.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/OwnerInviteTest.php`:

```php
<?php

use App\Actions\Portal\InviteToPortal;
use App\Actions\Portal\SetPortalAccess;
use App\Enums\RoleName as R;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Owners\Form as OwnerForm;
use App\Mail\PortalInvitation;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create();
    $this->admin = matrixUser(R::Admin, $this->building);
    $this->owner = Owner::factory()->create(['name_en' => 'Ali Owner', 'name_ar' => 'علي', 'email' => 'Ali@Owner.test']);
    // The same person as a tenant, under the same email in another case.
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'ALI@owner.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($this->building)->create()]);
    $this->page = fn () => Livewire::actingAs($this->admin)->test(OwnerForm::class, ['owner' => $this->owner->fresh()]);
});

test('staff invite an owner from the owner page: the account takes the owner email and an owner invite is queued', function () {
    ($this->page)()->assertSee('Owner portal')->assertSee('Not invited yet.')->assertSee('Invite to portal')
        ->call('invitePortal')->assertHasNoErrors()->assertSee('Re-send invite')->assertSee('Disable');

    $account = PortalAccount::query()->sole();
    expect($account->owner_id)->toBe($this->owner->id)
        ->and($account->customer_id)->toBeNull()
        ->and($account->email)->toBe('ali@owner.test')
        ->and($account->invited_by)->toBe($this->admin->id);

    $mail = Mail::queued(PortalInvitation::class)->sole();
    expect($mail->kind)->toBe('owner')->and($mail->hasTo('ali@owner.test', 'Ali Owner'))->toBeTrue();
    $mail->assertHasSubject('Your owner portal · بوابة المالك · Demo Properties W.L.L.')
        ->assertSeeInOrderInHtml(['Dear Ali Owner,', 'owner portal', route('portal.login'), 'عزيزي المالك علي', route('portal.login')], false);

    $audit = Activity::query()->where('event', 'portal.invited')->sole();
    expect($audit->causer?->is($this->admin))->toBeTrue()
        ->and($audit->subject?->is($account))->toBeTrue()
        ->and($audit->getProperty('record'))->toBe('owner')
        ->and($audit->getProperty('owner_id'))->toBe($this->owner->id)
        ->and($audit->getProperty('linked'))->toBeFalse()
        ->and($audit->getProperty('resent'))->toBeFalse();

    ($this->page)()->call('invitePortal');
    expect(Activity::query()->where('event', 'portal.invited')->latest('id')->first()->getProperty('resent'))->toBeTrue();
});

test('inviting an owner whose email has a tenant account links the owner to it: one sign-in, both sides', function () {
    $tenantAccount = portalAccount($this->tenant);

    app(InviteToPortal::class)->handle($this->admin, $this->owner);

    $account = PortalAccount::query()->sole();
    expect($account->is($tenantAccount))->toBeTrue()
        ->and($account->customer_id)->toBe($this->tenant->id)
        ->and($account->owner_id)->toBe($this->owner->id)
        ->and(Activity::query()->where('event', 'portal.invited')->sole()->getProperty('linked'))->toBeTrue();
    ($this->page)()->assertSee('Also used by tenant Sara Tenant');
    Livewire::actingAs($this->admin)->test(CustomerForm::class, ['customer' => $this->tenant])->assertSee('Also used by owner Ali Owner');

    $this->actingAs($account, 'tenant');
    $this->get(route('portal.home'))->assertOk();
    $this->get(route('portal.owner.home'))->assertOk();
});

test('inviting a tenant whose email has an owner account links the other way', function () {
    $ownerAccount = portalAccount($this->owner);

    app(InviteToPortal::class)->handle($this->admin, $this->tenant);

    expect(PortalAccount::query()->sole()->is($ownerAccount))->toBeTrue()
        ->and($ownerAccount->fresh()->customer_id)->toBe($this->tenant->id);
    Mail::assertQueued(PortalInvitation::class, fn (PortalInvitation $m) => $m->kind === 'tenant');
});

test('an email whose account already has another owner is refused', function () {
    portalAccount(Owner::factory()->create(['email' => 'ali@owner.test']));

    expect(fn () => app(InviteToPortal::class)->handle($this->admin, $this->owner))
        ->toThrow(ValidationException::class, 'Another owner\'s portal account uses this email.')
        ->and(PortalAccount::query()->where('owner_id', $this->owner->id)->exists())->toBeFalse();
});

test('linking to a disabled account is refused', function () {
    portalAccount($this->tenant, ['disabled_at' => now()]);

    expect(fn () => app(InviteToPortal::class)->handle($this->admin, $this->owner))
        ->toThrow(ValidationException::class, 'The portal account for this email is disabled. Enable it on Sara Tenant\'s page first.')
        ->and(PortalAccount::query()->sole()->owner_id)->toBeNull();
});

test('only staff who can manage owners invite, disable or enable', function () {
    $pm = matrixUser(R::PropertyManager, $this->building); // views owners, cannot manage them

    expect(fn () => app(InviteToPortal::class)->handle($pm, $this->owner))->toThrow(AuthorizationException::class);
    Livewire::actingAs($pm)->test(OwnerForm::class, ['owner' => $this->owner])->assertDontSee('Invite to portal')->call('invitePortal')->assertForbidden();

    app(InviteToPortal::class)->handle($this->admin, $this->owner);
    expect(fn () => app(SetPortalAccess::class)->handle($pm, $this->owner, false))->toThrow(AuthorizationException::class)
        ->and(PortalAccount::query()->sole()->disabled_at)->toBeNull();
});

test('no invite for an owner without an email, while mail is not set up, or once deleted', function () {
    $this->owner->update(['email' => null]);
    ($this->page)()->assertDontSee('Invite to portal')->call('invitePortal')->assertHasErrors('portal');

    $this->owner->update(['email' => 'ali@owner.test']);
    config(['mail.default' => 'log']);
    ($this->page)()->assertSee('Portal email is not set up.')->assertDontSee('Invite to portal')->call('invitePortal')->assertHasErrors('portal');
    config(['mail.default' => 'smtp']);

    $this->owner->delete();
    expect(fn () => app(InviteToPortal::class)->handle($this->admin, $this->owner))->toThrow(ValidationException::class)
        ->and(PortalAccount::count())->toBe(0);
    Mail::assertNothingQueued();
});

test('disable from the owner page ends both sides and the live session; enable from the tenant page brings both back; each audit names its record', function () {
    $account = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);
    $this->actingAs($account, 'tenant')->get(route('portal.home'))->assertOk();

    ($this->page)()->call('disablePortal')->assertHasNoErrors()->assertSee('Portal access is disabled.');
    $account->refresh(); // the test keeps one instance signed in; a real request loads the row afresh
    $this->get(route('portal.home'))->assertRedirect(route('portal.login'));

    app(SetPortalAccess::class)->handle($this->admin, $this->tenant, true);
    expect($account->fresh()->disabled_at)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['portal.disabled', 'portal.enabled'])->orderBy('id')->get()
            ->map(fn (Activity $a) => [$a->event, $a->getProperty('record')])->all())
        ->toBe([['portal.disabled', 'owner'], ['portal.enabled', 'tenant']]);
});

test('the owner page portal buttons take no arguments from the browser', function () {
    foreach (['invitePortal', 'disablePortal', 'enablePortal'] as $name) {
        $parameters = (new ReflectionMethod(OwnerForm::class, $name))->getParameters();
        expect($parameters)->toHaveCount(1)
            ->and($parameters[0]->getType()?->getName())->toBeIn([InviteToPortal::class, SetPortalAccess::class]);
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/OwnerInviteTest.php`
Expected: FAIL. `InviteToPortal::handle()` takes only a `Customer`, and `Owners\Form` has no `invitePortal`.

- [ ] **Step 3: Replace `app/Actions/Portal/InviteToPortal.php`** with:

```php
<?php

namespace App\Actions\Portal;

use App\Audit\Audit;
use App\Mail\PortalInvitation;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use App\Models\User;
use App\Reminders\EmailChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: "Invite to portal" and "Re-send invite" on the
 * tenant and owner pages. Creates or re-enables the record's account, or links the record to the account of the other
 * kind that has its email, then queues the invite for that kind (owner portal plan ruling 5).
 */
final class InviteToPortal
{
    public function handle(User $actor, Customer|Owner $record): PortalAccount
    {
        if (! $actor->can('update', $record)) {
            throw new AuthorizationException;
        }
        if (! (new EmailChannel)->ready()) {
            throw ValidationException::withMessages(['portal' => __('Portal email is not set up.')]);
        }
        $email = Str::lower(trim((string) $record->email));
        if ($email === '' || $record->trashed()) {
            throw ValidationException::withMessages(['portal' => $record instanceof Customer
                ? __('Add the tenant\'s email before inviting them.')
                : __('Add the owner\'s email before inviting them.')]);
        }

        try {
            // Two invites for the two kinds of one new email can both insert; the loser's second try links to the winner's row.
            $account = retry(2, fn () => DB::transaction(fn () => $this->write($actor, $record, $email)), 0,
                fn (Throwable $e) => $e instanceof UniqueConstraintViolationException);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['portal' => self::takenMessage($record)]);
        }

        try {
            Mail::to(new Address($email, $record->name_en))->queue(new PortalInvitation($account, $record instanceof Customer ? 'tenant' : 'owner'));
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['portal' => __('The invite could not be sent. Please try again.')]);
        }

        return $account;
    }

    /** "Another tenant's / owner's portal account uses this email." */
    public static function takenMessage(Customer|Owner $record): string
    {
        return $record instanceof Customer
            ? __('Another tenant\'s portal account uses this email.')
            : __('Another owner\'s portal account uses this email.');
    }

    private function write(User $actor, Customer|Owner $record, string $email): PortalAccount
    {
        $column = PortalAccount::column($record);
        $account = PortalAccount::query()->where($column, $record->getKey())->lockForUpdate()->first();
        $byEmail = PortalAccount::query()->where('email', $email)->lockForUpdate()->first();
        $linked = false;

        if ($byEmail !== null && ! $byEmail->is($account)) {
            if ($account !== null || $byEmail->getAttribute($column) !== null) {
                throw ValidationException::withMessages(['portal' => self::takenMessage($record)]);
            }
            if ($byEmail->disabled_at !== null) {
                throw ValidationException::withMessages(['portal' => __('The portal account for this email is disabled. Enable it on :name\'s page first.', ['name' => $byEmail->personName('en')])]);
            }
            $account = $byEmail;
            $linked = true;
        }

        $account ??= new PortalAccount;
        $resent = $account->exists && ! $linked && $account->disabled_at === null;
        $account->forceFill([
            $column => $record->getKey(), 'email' => $email, 'disabled_at' => null, 'invited_at' => now(), 'invited_by' => $actor->id,
        ])->save();
        Audit::log('portal.invited', $account, properties: [
            'record' => $record instanceof Customer ? 'tenant' : 'owner', $column => $record->getKey(), 'email' => $email, 'resent' => $resent, 'linked' => $linked,
        ], causer: $actor);

        return $account;
    }
}
```

- [ ] **Step 4: Replace `app/Actions/Portal/SetPortalAccess.php`** with:

```php
<?php

namespace App\Actions\Portal;

use App\Audit\Audit;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: "Disable" / "Enable" on the tenant or owner page,
 * acting on the whole account (both sides). A disabled account's session ends on its next request (PortalSession);
 * enabling takes the page's record's current email. A no-op writes and audits nothing (owner portal plan ruling 6).
 */
final class SetPortalAccess
{
    public function handle(User $actor, Customer|Owner $record, bool $enabled): PortalAccount
    {
        if (! $actor->can('update', $record)) {
            throw new AuthorizationException;
        }
        $column = PortalAccount::column($record);
        $tenant = $record instanceof Customer;

        return DB::transaction(function () use ($actor, $record, $enabled, $column, $tenant) {
            $account = PortalAccount::query()->where($column, $record->getKey())->lockForUpdate()->first();
            if ($account === null) {
                throw ValidationException::withMessages(['portal' => $tenant
                    ? __('This tenant has not been invited to the portal.')
                    : __('This owner has not been invited to the portal.')]);
            }
            if ($enabled === ($account->disabled_at === null)) {
                return $account;
            }

            if ($enabled) {
                $email = Str::lower(trim((string) $record->email));
                if ($email === '' || $record->trashed()) {
                    throw ValidationException::withMessages(['portal' => $tenant
                        ? __('Add the tenant\'s email before enabling the portal.')
                        : __('Add the owner\'s email before enabling the portal.')]);
                }
                if (PortalAccount::query()->where('email', $email)->whereKeyNot($account->id)->exists()) {
                    throw ValidationException::withMessages(['portal' => InviteToPortal::takenMessage($record)]);
                }
                $account->forceFill(['email' => $email, 'disabled_at' => null])->save();
            } else {
                $account->forceFill(['disabled_at' => now(), 'remember_token' => null])->save();
                PortalLoginCode::query()->where('portal_account_id', $account->id)->whereNull('used_at')->delete();
            }
            Audit::log($enabled ? 'portal.enabled' : 'portal.disabled', $account,
                properties: ['record' => $tenant ? 'tenant' : 'owner', $column => $record->getKey()], causer: $actor);

            return $account;
        });
    }
}
```

- [ ] **Step 5: The invite mail by kind.** Replace `app/Mail/PortalInvitation.php` with:

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

/** Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: the invite, English then Arabic, for a tenant or an owner. */
class PortalInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    /** @param  'tenant'|'owner'  $kind  the side the invite was sent from */
    public function __construct(public PortalAccount $account, public string $kind = 'tenant')
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
        $parts = $this->kind === 'owner' ? [__('Your owner portal'), 'بوابة المالك'] : [__('Your tenant portal'), 'بوابة المستأجر'];

        return str_replace(["\r", "\n"], ' ', implode(' · ', [...$parts, CompanySetting::current()->name_en]));
    }

    public function content(): Content
    {
        $person = $this->kind === 'owner' ? $this->account->owner : $this->account->customer;

        return new Content(view: 'mail.portal-invitation', with: [
            'subjectLine' => $this->subjectLine(),
            'kind' => $this->kind,
            'nameEn' => (string) $person?->name_en,
            'nameAr' => (string) $person?->displayName('ar'),
            'url' => route('portal.login'),
        ]);
    }
}
```

  Replace `resources/views/mail/portal-invitation.blade.php` with:

```blade
<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        @if ($kind === 'owner')
            <p>{{ __('You can now see your buildings, statements, payments, expenses, maintenance and documents in our owner portal.') }}</p>
        @else
            <p>{{ __('You can now see your agreements, invoices, receipts, cheques and statement, and send maintenance requests, in our tenant portal.') }}</p>
        @endif
        <p>{{ __('Sign in with this email address. We email you a code each time; there is no password.') }}</p>
        <p><a href="{{ $url }}">{{ $url }}</a></p>
        <p>{{ __('Thank you.') }}</p>
    </x-slot:en>
    <x-slot:ar>
        @if ($kind === 'owner')
            <p>عزيزي المالك {{ $nameAr }}،</p>
            <p>يمكنكم الآن الاطلاع على عقاراتكم وكشوف حساباتكم ومدفوعاتكم ومصروفاتكم وطلبات الصيانة والمستندات عبر بوابة المالك.</p>
        @else
            <p>عزيزي المستأجر {{ $nameAr }}،</p>
            <p>يمكنكم الآن الاطلاع على عقودكم وفواتيركم وإيصالاتكم وشيكاتكم وكشف حسابكم، وإرسال طلبات الصيانة، عبر بوابة المستأجر.</p>
        @endif
        <p>سجّلوا الدخول باستخدام هذا البريد الإلكتروني. نرسل إليكم رمزاً في كل مرة، ولا حاجة إلى كلمة مرور.</p>
        <p><a href="{{ $url }}">{{ $url }}</a></p>
        <p>شكراً لكم.</p>
    </x-slot:ar>
</x-mail.bilingual>
```

- [ ] **Step 6: The owner page card.** In `app/Livewire/Owners/Form.php`:
  - add to the imports:

```php
use App\Actions\Portal\InviteToPortal;
use App\Actions\Portal\SetPortalAccess;
use App\Models\PortalAccount;
use App\Reminders\EmailChannel;
use Closure;
```

  - add these methods after `save()`:

```php
    /** Spec 2026-10-21 owner portal §3: Invite / Re-send. No arguments: always this page's owner. */
    public function invitePortal(InviteToPortal $invite): void
    {
        $this->portal(fn (Owner $o) => $invite->handle($this->actor(), $o), __('Portal invite sent.'));
    }

    public function disablePortal(SetPortalAccess $access): void
    {
        $this->portal(fn (Owner $o) => $access->handle($this->actor(), $o, false), __('Portal access disabled.'));
    }

    public function enablePortal(SetPortalAccess $access): void
    {
        $this->portal(fn (Owner $o) => $access->handle($this->actor(), $o, true), __('Portal access enabled.'));
    }

    /** @param  Closure(Owner): PortalAccount  $run */
    private function portal(Closure $run, string $done): void
    {
        abort_if($this->ownerId === null, 404);
        try {
            $run(Owner::findOrFail($this->ownerId));
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: $done);
    }
```

  - replace `render()` with:

```php
    public function render(): View
    {
        $owner = $this->ownerId ? Owner::with('bankChanger:id,name')->findOrFail($this->ownerId) : null;
        $account = $owner ? PortalAccount::of($owner) : null;

        return view('livewire.owners.form', [
            'owner' => $owner,
            'types' => PartyType::cases(),
            'idTypes' => IdType::cases(),
            'canEdit' => $owner ? $this->actor()->can('update', $owner) : true,
            'canViewBank' => $owner ? $this->actor()->can('viewBank', $owner) : $this->actor()->can('owners.bank.manage'),
            'canEditBank' => $owner ? $this->actor()->can('updateBank', $owner) : $this->actor()->can('owners.bank.manage'),
            'portal' => $owner && $this->actor()->can('update', $owner) ? [
                'account' => $account, 'other' => $account?->otherThan($owner), 'mailReady' => (new EmailChannel)->ready(),
            ] : null,
        ])->title($owner ? $owner->name_en : __('New owner'));
    }
```

  In `resources/views/livewire/owners/form.blade.php`, insert directly before

```blade
    @if ($owner)
        <livewire:documents.panel :documentable="$owner" :key="'docs-owner-'.$owner->id" />
    @endif
```

  this card:

```blade
    @if ($portal)
        <flux:card class="space-y-3">
            <flux:heading size="lg">{{ __('Owner portal') }}</flux:heading>
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
            @if ($portal['other'])
                <flux:text>{{ __('Also used by tenant :name', ['name' => $portal['other']->name_en]) }}</flux:text>
            @endif
            @if (! $portal['mailReady'])
                <flux:text>{{ __('Portal email is not set up.') }}</flux:text>
            @endif
            <div class="flex flex-wrap gap-2">
                @if ($portal['mailReady'] && filled($owner?->email))
                    <flux:button variant="primary" wire:click="invitePortal">
                        {{ $portal['account'] && ! $portal['account']->disabled_at ? __('Re-send invite') : __('Invite to portal') }}
                    </flux:button>
                @endif
                @if ($portal['account'] && ! $portal['account']->disabled_at)
                    <flux:button variant="ghost" wire:click="disablePortal" wire:confirm="{{ __('Disable this owner\'s portal access?') }}">{{ __('Disable') }}</flux:button>
                @elseif ($portal['account'])
                    <flux:button variant="ghost" wire:click="enablePortal">{{ __('Enable') }}</flux:button>
                @endif
            </div>
            <flux:error name="portal" />
        </flux:card>
    @endif
```

- [ ] **Step 7: The tenant page names the owner.**
  - In `app/Livewire/Customers/Form.php::render()`, add `$account = $customer ? PortalAccount::of($customer) : null;` after the `$customer = …` line, and replace

```php
            'portal' => $customer && $this->actor()->can('update', $customer) ? [
                'account' => PortalAccount::query()->where('customer_id', $customer->id)->first(),
                'mailReady' => (new EmailChannel)->ready(),
            ] : null,
```

    with

```php
            'portal' => $customer && $this->actor()->can('update', $customer) ? [
                'account' => $account, 'other' => $account?->otherThan($customer), 'mailReady' => (new EmailChannel)->ready(),
            ] : null,
```

  - In `resources/views/livewire/customers/form.blade.php`, insert directly before

```blade
            @if (! $portal['mailReady'])
                <flux:text>{{ __('Portal email is not set up.') }}</flux:text>
            @endif
```

    the lines

```blade
            @if ($portal['other'])
                <flux:text>{{ __('Also used by owner :name', ['name' => $portal['other']->name_en]) }}</flux:text>
            @endif
```

- [ ] **Step 8: Arabic.** In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Your owner portal": "بوابة المالك",
    "You can now see your buildings, statements, payments, expenses, maintenance and documents in our owner portal.": "يمكنكم الآن الاطلاع على عقاراتكم وكشوف حساباتكم ومدفوعاتكم ومصروفاتكم وطلبات الصيانة والمستندات عبر بوابة المالك.",
    "Add the owner's email before inviting them.": "أضف البريد الإلكتروني للمالك قبل دعوته.",
    "Another owner's portal account uses this email.": "يستخدم حساب مالك آخر في البوابة هذا البريد الإلكتروني.",
    "The portal account for this email is disabled. Enable it on :name's page first.": "حساب البوابة لهذا البريد الإلكتروني معطّل. فعّله أولاً من صفحة :name.",
    "This owner has not been invited to the portal.": "لم تتم دعوة هذا المالك إلى البوابة.",
    "Add the owner's email before enabling the portal.": "أضف البريد الإلكتروني للمالك قبل تفعيل البوابة.",
    "Also used by owner :name": "يستخدمه أيضاً المالك :name",
    "Also used by tenant :name": "يستخدمه أيضاً المستأجر :name",
    "Disable this owner's portal access?": "تعطيل وصول هذا المالك إلى البوابة؟",
```

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/Portal`
Expected: PASS. That includes the existing `InviteTest`: tenant messages, re-send audits and the tenant subject are unchanged.

- [ ] **Step 10: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.

```bash
git add app/Actions/Portal/InviteToPortal.php app/Actions/Portal/SetPortalAccess.php app/Mail/PortalInvitation.php resources/views/mail/portal-invitation.blade.php app/Livewire/Owners/Form.php resources/views/livewire/owners/form.blade.php app/Livewire/Customers/Form.php resources/views/livewire/customers/form.blade.php lang/ar.json tests/Feature/Portal/OwnerInviteTest.php
git commit -m "$(cat <<'EOF'
Owner portal invites: invite, re-send, disable and enable on the owner page; linking to the other kind's account by email; audits name the record

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Email changes keep a shared account consistent (tenant and owner saves)

**Files:**
- Create: `app/Actions/Portal/SyncPortalEmail.php`
- Modify: `app/Actions/Customers/SaveCustomer.php`, `app/Actions/Owners/SaveOwner.php`, `lang/ar.json`
- Test: `tests/Feature/Portal/PortalEmailTest.php`

**Interfaces:**
- Consumes: `PortalAccount::of()`, `otherThan()`, `column()` (Task 1), `InviteToPortal::takenMessage()` (Task 3).
- Produces: `SyncPortalEmail::problem(Customer|Owner|null $record, ?string $email): ?string`, `SyncPortalEmail::apply(Customer|Owner $record): void`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/PortalEmailTest.php`:

```php
<?php

use App\Actions\Customers\SaveCustomer;
use App\Actions\Owners\SaveOwner;
use App\Enums\RoleName as R;
use App\Livewire\Owners\Form as OwnerForm;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $building = Building::factory()->create();
    $this->admin = matrixUser(R::Admin, $building);
    $this->leasing = matrixUser(R::Leasing, $building);
    $this->owner = Owner::factory()->create(['name_en' => 'Ali Owner', 'email' => 'ali@owner.test']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'ali@owner.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($building)->create()]);
    $this->ownerData = fn (array $changes) => [
        ...$this->owner->fresh()->only(['name_en', 'name_ar', 'id_number', 'nationality', 'phone', 'email', 'address', 'notes', ...Owner::BANK_FIELDS]),
        'type' => 'person', 'id_type' => 'cpr', ...$changes,
    ];
    $this->tenantData = fn (array $changes) => [
        ...$this->tenant->fresh()->only(['name_en', 'name_ar', 'id_number', 'nationality', 'mobile', 'email', 'address', 'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes']),
        'type' => 'individual', 'id_type' => 'cpr', ...$changes,
    ];
});

test('an owner-only account follows the owner email from the owner page; a new address ends remembered devices', function () {
    $account = portalAccount($this->owner, ['remember_token' => 'old-token']);

    Livewire::actingAs($this->admin)->test(OwnerForm::class, ['owner' => $this->owner])
        ->set('form.email', 'Ali.New@Owner.test')->call('save')->assertHasNoErrors();

    expect($account->fresh()->email)->toBe('ali.new@owner.test')->and($account->fresh()->remember_token)->toBeNull();
});

test('an owner-only account: the email cannot be cleared while enabled, nor take another account\'s email', function () {
    portalAccount($this->owner);
    portalAccount(Owner::factory()->create(['email' => 'taken@owner.test']));

    expect(fn () => app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['email' => null])))
        ->toThrow(ValidationException::class, 'This owner uses the portal. Disable their portal access before removing the email.')
        ->and(fn () => app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['email' => 'TAKEN@owner.test'])))
        ->toThrow(ValidationException::class, 'Another owner\'s portal account uses this email.');
    Livewire::actingAs($this->admin)->test(OwnerForm::class, ['owner' => $this->owner])->set('form.email', '')->call('save')->assertHasErrors('form.email');
    expect(PortalAccount::query()->where('owner_id', $this->owner->id)->sole()->email)->toBe('ali@owner.test');
});

test('a shared account refuses an email on one record that the other record does not share', function () {
    portalAccount($this->tenant, ['owner_id' => $this->owner->id]);

    expect(fn () => app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['email' => 'new@owner.test'])))
        ->toThrow(ValidationException::class, 'This email is also the portal sign-in for Sara Tenant; change both together.')
        ->and(fn () => app(SaveCustomer::class)->handle($this->leasing, $this->tenant, ($this->tenantData)(['email' => 'new@owner.test'])))
        ->toThrow(ValidationException::class, 'This email is also the portal sign-in for Ali Owner; change both together.')
        ->and(PortalAccount::query()->sole()->email)->toBe('ali@owner.test');
});

test('to move a shared account to a new email: clear it on one record, change the other, then set the first again', function () {
    $account = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);

    app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['email' => null]));
    expect($account->fresh()->email)->toBe('ali@owner.test'); // the tenant still signs in with it

    app(SaveCustomer::class)->handle($this->leasing, $this->tenant->fresh(), ($this->tenantData)(['email' => 'Both.New@Owner.test']));
    expect($account->fresh()->email)->toBe('both.new@owner.test');

    app(SaveOwner::class)->handle($this->admin, $this->owner->fresh(), ($this->ownerData)(['email' => 'both.new@owner.test']));
    expect($account->fresh()->email)->toBe('both.new@owner.test')->and($account->fresh()->disabled_at)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/PortalEmailTest.php`
Expected: FAIL. The owner email change does not reach the account, and the shared-account saves are not refused.

- [ ] **Step 3: Create `app/Actions/Portal/SyncPortalEmail.php`:**

```php
<?php

namespace App\Actions\Portal;

use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use Illuminate\Support\Str;

/**
 * Spec 2026-10-21 owner portal §3 "Email changes": the portal account follows the email of its tenant and its owner.
 * problem() runs in the save's validator; apply() inside the save's transaction (owner portal plan ruling 7).
 */
final class SyncPortalEmail
{
    /** Why $email (trimmed; null clears it) cannot be saved on $record, or null when it can. */
    public static function problem(Customer|Owner|null $record, ?string $email): ?string
    {
        $account = $record?->exists ? PortalAccount::of($record) : null;
        if ($record === null || $account === null) {
            return null;
        }
        $other = $account->otherThan($record);
        $otherEmail = $other !== null && filled($other->email) ? Str::lower((string) $other->email) : null;

        if ($email === null) {
            // The account keeps its address while the other record still has it.
            return $account->disabled_at === null && $otherEmail === null
                ? ($record instanceof Customer
                    ? __('This tenant uses the portal. Disable their portal access before removing the email.')
                    : __('This owner uses the portal. Disable their portal access before removing the email.'))
                : null;
        }

        $email = Str::lower($email);
        if (PortalAccount::query()->where('email', $email)->whereKeyNot($account->id)->exists()) {
            return InviteToPortal::takenMessage($record);
        }
        if ($otherEmail !== null && $otherEmail !== $email) {
            return __('This email is also the portal sign-in for :name; change both together.', ['name' => $other?->name_en]);
        }

        return null;
    }

    /** The account takes the record's new email in lower case; a new address ends remembered devices. */
    public static function apply(Customer|Owner $record): void
    {
        if (filled($record->email)) {
            $new = Str::lower((string) $record->email);
            PortalAccount::query()->where(PortalAccount::column($record), $record->getKey())->where('email', '!=', $new)
                ->update(['email' => $new, 'remember_token' => null]);
        }
    }
}
```

- [ ] **Step 4: `SaveCustomer` uses it.** In `app/Actions/Customers/SaveCustomer.php`:
  - replace the imports `use App\Models\PortalAccount;` and `use Illuminate\Support\Str;` with `use App\Actions\Portal\SyncPortalEmail;`;
  - replace the block

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

    with

```php
            // Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: the portal account follows the email (owner portal plan ruling 7).
            $problem = SyncPortalEmail::problem($customer, is_string($data['email'] ?? null) ? $data['email'] : null);
            if ($problem !== null) {
                $validator->errors()->add('email', $problem);
            }
```

  - replace

```php
                if (filled($customer->email)) {
                    $new = Str::lower((string) $customer->email);
                    PortalAccount::query()->where('customer_id', $customer->id)->where('email', '!=', $new)->update(['email' => $new, 'remember_token' => null]); // a new address ends remembered devices
                }
```

    with

```php
                SyncPortalEmail::apply($customer);
```

- [ ] **Step 5: `SaveOwner` uses it.** Replace `app/Actions/Owners/SaveOwner.php` with:

```php
<?php

namespace App\Actions\Owners;

use App\Actions\Portal\InviteToPortal;
use App\Actions\Portal\SyncPortalEmail;
use App\Audit\Audit;
use App\Enums\IdType;
use App\Enums\PartyType;
use App\Enums\PermissionName;
use App\Models\Bank;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

final class SaveOwner
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Owner $owner, array $data, bool $viaImport = false): Owner
    {
        if (! ($owner ? $actor->can('update', $owner) : $actor->can('create', Owner::class))) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        if (isset($data['iban']) && is_string($data['iban'])) {
            $data['iban'] = strtoupper(str_replace(' ', '', $data['iban'])) ?: null;
        }

        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(PartyType::class)],
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['required', 'string', 'max:30',
                Rule::unique('owners', 'id_number')->where('id_type', $data['id_type'] ?? null)->ignore($owner?->id)],
            'nationality' => ['nullable', 'string', 'max:60', Rule::in(array_filter([...config('nationalities'), $owner?->nationality]))],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'bank_name' => ['nullable', 'string', 'max:100', Bank::rule($owner?->bank_name)],
            'iban' => ['nullable', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->after(function (ValidatorInstance $validator) use ($data, $owner) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            // Spec 2026-10-21 owner portal §3: the portal account follows the owner's email (plan ruling 7).
            $problem = SyncPortalEmail::problem($owner, is_string($data['email'] ?? null) ? trim($data['email']) : null);
            if ($problem !== null) {
                $validator->errors()->add('email', $problem);
            }
        })->validate();

        $bankNew = Arr::only($validated, Owner::BANK_FIELDS) + array_fill_keys(Owner::BANK_FIELDS, null);
        $bankOld = $owner ? $owner->only(Owner::BANK_FIELDS) : array_fill_keys(Owner::BANK_FIELDS, null);
        $pick = fn (array $src) => array_map(fn (string $f) => $src[$f] ?? null, Owner::BANK_FIELDS);
        $bankChanged = $pick($bankNew) !== $pick($bankOld);

        // The go-live import (import.run, new owners only) brings existing bank details across (spec §11).
        $importing = $viaImport && $owner === null && $actor->can(PermissionName::ImportRun);

        if ($bankChanged && ! $importing && ! $actor->can(PermissionName::OwnersBankManage)) {
            throw new AuthorizationException(__('Only holders of owners.bank.manage can change bank details.'));
        }

        try {
            return DB::transaction(function () use ($actor, $owner, $validated, $bankChanged, $bankOld, $bankNew, $importing) {
                $owner ??= new Owner;
                $owner->fill($validated);

                if ($bankChanged && ! $importing) {
                    $owner->forceFill(['bank_changed_at' => now(), 'bank_changed_by' => $actor->id]);
                }

                $owner->save();
                SyncPortalEmail::apply($owner);

                if ($bankChanged) {
                    $mask = fn (?string $iban) => $iban ? '••••'.substr($iban, -4) : null;
                    Audit::log('owner.bank.changed', $owner,
                        ['iban' => $mask($bankOld['iban']), 'bank_name' => $bankOld['bank_name'], 'account_name' => $bankOld['account_name']],
                        ['iban' => $mask($bankNew['iban']), 'bank_name' => $bankNew['bank_name'], 'account_name' => $bankNew['account_name']],
                        causer: $actor,
                    );
                }

                return $owner;
            });
        } catch (UniqueConstraintViolationException) { // a racing invite or save took this email
            throw ValidationException::withMessages(['email' => InviteToPortal::takenMessage($owner ?? new Owner)]);
        }
    }
}
```

- [ ] **Step 6: Arabic.** In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "This owner uses the portal. Disable their portal access before removing the email.": "يستخدم هذا المالك البوابة. عطّل وصوله إلى البوابة قبل حذف البريد الإلكتروني.",
    "This email is also the portal sign-in for :name; change both together.": "هذا البريد الإلكتروني هو أيضاً بريد الدخول إلى البوابة لـ :name؛ غيّرهما معاً.",
```

- [ ] **Step 7: Run the tests.**

Run: `php artisan test tests/Feature/Portal tests/Feature/Owners tests/Feature/Customers tests/Feature/Import`
Expected: PASS. The existing `InviteTest` email-sync tests keep their messages and behaviour, and the owner and import tests are unchanged for owners without an account.

- [ ] **Step 8: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`.

```bash
git add app/Actions/Portal/SyncPortalEmail.php app/Actions/Customers/SaveCustomer.php app/Actions/Owners/SaveOwner.php lang/ar.json tests/Feature/Portal/PortalEmailTest.php
git commit -m "$(cat <<'EOF'
Owner portal emails: tenant and owner saves keep the portal account's email in step and refuse splitting a shared account

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: The owner scope: every owner query, the shared rent roll, and the Buildings and Contracts pages

**Files:**
- Create: `app/Portal/OwnerPortal.php`
- Create: `app/Livewire/Portal/Owner/Buildings.php`, `app/Livewire/Portal/Owner/Contracts.php`, `resources/views/livewire/portal/owner/buildings.blade.php`, `resources/views/livewire/portal/owner/contracts.blade.php`
- Modify: `app/Models/OwnerContract.php`, `app/Reports/Queries.php`, `app/Livewire/Reports/RentRollReport.php`
- Modify: `routes/portal.php`, `resources/views/layouts/portal.blade.php`, `lang/ar.json`, `tests/Pest.php`
- Test: `tests/Feature/Portal/OwnerScopeTest.php`

**Interfaces:**
- Consumes: `InPortal::ownerId()` (Task 2), `OwnerStatementCalculator::stored()`, `OwnerContract::ATTRIBUTED`, `MaintenanceRequest`, `WorkOrder`, the test helpers `activeOwnerContract()`, `activeAgreement()`, `issuedInvoice()`, `maintenanceRequest()`, `workOrderFor()`, `maintenanceVendor()`, `portalAccount()`.
- Produces:
  - `OwnerContract::portalFor(int $ownerId)` scope.
  - `Queries::rentRollUnits(Builder $unitIds, string $on): Collection<int, stdClass>`.
  - `OwnerPortal::statements(int): Builder<OwnerStatement>`, `payouts(int): Builder<Disbursement>`, `payables(int): Builder<OwnerPayable>`, `expenses(int): Builder<Expense>`, `requests(int): Builder<MaintenanceRequest>`, `costFils(MaintenanceRequest, int): int`.
  - `OwnerPortal::summary(OwnerStatement): array{opening:int, income:int, charges:int, fees:int, paid:int, closing:int}`.
  - `OwnerPortal::buildings(int): Collection<int, array{building, managed, units, roll, occupied, total}>`.
  - Routes `portal.owner.buildings`, `portal.owner.contracts`.
  - Pest `portalOwnerFixture(string $name, string $code, User $by): array`.

- [ ] **Step 1: Add the fixture to `tests/Pest.php`.** Add to the imports:

```php
use App\Billing\OwnerStatementCalculator;
use App\Models\Disbursement;
use App\Models\Expense;
use App\Models\OwnerCharge;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use Illuminate\Support\Facades\DB;
```

  Add at the end of the file:

```php
/**
 * An owner with one of everything the owner portal shows (owner portal tests). Needs the roles seeded, a company setting,
 * this year's number sequences and today in October 2026 or later. $by is staff who may work in any building (Admin);
 * the owner's two buildings are added to their buildings.
 *  - ":name Tower", managed (fixed fee 0), active 01/01–31/12/2026: unit :code let to "Renter :code" at 400.000 a month
 *    with 400.000 owed, and unit "V:code" vacant.
 *  - ":name Lease", leased at 300.000 a month: unit "L:code" let to "Lessee :code"; October's payable paid, November's due.
 *  - a finalised September statement "OS-:code": opening 0.000, income 500.000, charges 50.000, closing 450.000.
 *  - payouts "DSB-:code-1" (remittance, 450.000, 05/10/2026, reference "TRF-:code") and "DSB-:code-2" (head lease, 300.000, 02/10/2026).
 *  - expenses "Leak repair :code" (50.000 on unit :code, linked to the request's work order) and "Paint touch-up :code" (20.000, reversed).
 *  - a maintenance request on unit :code with one work order.
 *
 * @return array{owner: Owner, account: PortalAccount, tower: Building, lease: Building, managed: OwnerContract, leased: OwnerContract, statement: OwnerStatement, payout: Disbursement, expense: Expense, request: MaintenanceRequest, markers: list<string>}
 */
function portalOwnerFixture(string $name, string $code, User $by): array
{
    $owner = Owner::factory()->create([
        'name_en' => $name, 'name_ar' => 'مالك '.$code, 'email' => Str::lower((string) strtok($name, ' ')).'@owner.test',
        'bank_name' => 'NBB', 'iban' => 'BH29NBOB0000000000'.str_pad($code, 4, '0', STR_PAD_LEFT),
    ]);
    $tower = Building::factory()->create(['name' => "{$name} Tower"]);
    $lease = Building::factory()->create(['name' => "{$name} Lease"]);
    $by->buildings()->syncWithoutDetaching([$tower->id, $lease->id]);

    $let = Unit::factory()->for($tower)->create(['code' => $code]);
    $vacant = Unit::factory()->for($tower)->create(['code' => 'V'.$code]);
    $managed = activeOwnerContract(['owner_id' => $owner->id, 'building_id' => $tower->id, 'type' => 'managed', 'start_date' => '2026-01-01',
        'end_date' => '2026-12-31', 'fee_type' => 'fixed', 'fee_value' => '0.000', 'deposits_held_by' => 'company', 'created_by' => $by->id], [$let, $vacant]);
    $renter = Customer::factory()->create(['name_en' => "Renter {$code}", 'email' => "renter{$code}@tenant.test"]);
    $agreement = activeAgreement(['customer_id' => $renter->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$let]);
    issuedInvoice($renter, [['net' => '400.000', 'au' => $agreement->agreementUnits->first()]], '2026-10-01', $agreement);

    $leasedUnit = Unit::factory()->for($lease)->create(['code' => 'L'.$code]);
    $leased = activeOwnerContract(['owner_id' => $owner->id, 'building_id' => $lease->id, 'type' => 'leased', 'rent_amount' => '300.000',
        'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'expense_approval_limit' => null, 'deposits_held_by' => null,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'created_by' => $by->id], [$leasedUnit]);
    activeAgreement(['customer_id' => Customer::factory()->create(['name_en' => "Lessee {$code}"])->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$leasedUnit]);

    $october = (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        'due_date' => '2026-10-01', 'amount' => '300.000', 'status' => 'scheduled']);
    $october->save();
    (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
        'due_date' => '2026-11-01', 'amount' => '300.000', 'status' => 'scheduled'])->save();
    $paid = fn (array $row) => DB::table('disbursements')->insertGetId([
        'payee_type' => 'owner', 'payee_id' => $owner->id, 'method' => 'bank_transfer', 'status' => 'paid', 'created_by' => $by->id,
        'recorded_by' => $by->id, 'created_at' => now(), 'updated_at' => now(), ...$row,
    ]);
    $headLease = $paid(['number' => "DSB-{$code}-2", 'purpose' => 'head_lease', 'amount' => '300.000', 'reference' => "TRF-{$code}-L",
        'paid_on' => '2026-10-02', 'posted_at' => '2026-10-02 10:00:00', 'source_type' => 'owner_payable', 'source_id' => $october->id]);
    DB::table('owner_payables')->where('id', $october->id)->update(['status' => 'paid', 'disbursement_id' => $headLease]);

    OwnerCharge::create(['owner_contract_id' => $managed->id, 'type' => 'opening_balance', 'net' => '500.000', 'tax_amount' => '0.000',
        'amount' => '500.000', 'posted_at' => '2026-09-05 10:00:00', 'created_by' => $by->id]);
    $expense = Expense::factory()->create(['building_id' => $tower->id, 'unit_id' => $let->id, 'category' => 'maintenance',
        'description' => "Leak repair {$code}", 'expense_date' => '2026-09-10', 'net' => '50.000', 'tax_amount' => '0.000', 'total' => '50.000',
        'charge_to' => 'owner', 'owner_contract_id' => $managed->id, 'owner_approval_note' => "Approved by phone {$code}",
        'posted_at' => '2026-09-10 10:00:00', 'recorded_by' => $by->id]);
    Expense::factory()->create(['building_id' => $tower->id, 'category' => 'cleaning', 'description' => "Paint touch-up {$code}",
        'expense_date' => '2026-09-12', 'net' => '20.000', 'tax_amount' => '0.000', 'total' => '20.000', 'charge_to' => 'owner',
        'owner_contract_id' => $managed->id, 'posted_at' => '2026-09-12 10:00:00', 'status' => 'reversed', 'reversed_at' => '2026-09-13 10:00:00',
        'reversed_by' => $by->id, 'reversal_reason' => "Wrong building {$code}", 'recorded_by' => $by->id]);

    $statement = (new OwnerStatement)->forceFill(['owner_contract_id' => $managed->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'cutoff_at' => '2026-09-30 23:59:59', 'status' => 'draft', 'created_by' => $by->id]);
    $statement->setRelation('contract', $managed);
    OwnerStatementCalculator::apply($statement);
    $statement->save();
    DB::table('owner_statements')->where('id', $statement->id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $statement->id)->update(['status' => 'finalised', 'number' => "OS-{$code}", 'finalised_at' => now(), 'finalised_by' => $by->id]);
    $payout = $paid(['number' => "DSB-{$code}-1", 'purpose' => 'owner_remittance', 'amount' => '450.000', 'reference' => "TRF-{$code}",
        'paid_on' => '2026-10-05', 'posted_at' => '2026-10-05 10:00:00', 'source_type' => 'owner_statement', 'source_id' => $statement->id]);

    $request = maintenanceRequest($by, $let);
    $workOrder = workOrderFor($by, $request, maintenanceVendor(['name' => "Fix-It {$code}"]));
    DB::table('work_orders')->where('id', $workOrder->id)->update(['status' => 'completed', 'completed_on' => '2026-09-10', 'expense_id' => $expense->id]);

    return [
        'owner' => $owner, 'account' => portalAccount($owner), 'tower' => $tower, 'lease' => $lease, 'managed' => $managed, 'leased' => $leased,
        'statement' => $statement->fresh(), 'payout' => Disbursement::query()->findOrFail($payout), 'expense' => $expense, 'request' => $request,
        'markers' => [$name, "OS-{$code}", "DSB-{$code}-1", "TRF-{$code}", "Leak repair {$code}", "Paint touch-up {$code}", $request->number,
            "Renter {$code}", (string) $managed->number, (string) $leased->number],
    ];
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Portal/OwnerScopeTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName as R;
use App\Livewire\Portal\Owner\Buildings;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Models\Unit;
use App\Portal\OwnerPortal;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->admin = matrixUser(R::Admin, Building::factory()->create());
    $this->a = portalOwnerFixture('Ali Owner', '101', $this->admin);
    $this->b = portalOwnerFixture('Badr Owner', '202', $this->admin);
});

test('every owner-portal query returns only the owner\'s own records', function () {
    $id = $this->a['owner']->id;
    (new OwnerPayable)->forceFill(['owner_contract_id' => $this->a['leased']->id, 'period_start' => '2026-12-01', 'period_end' => '2026-12-31',
        'due_date' => '2026-12-01', 'amount' => '300.000', 'status' => 'cancelled'])->save();
    DB::table('disbursements')->insert([
        ['number' => 'DSB-101-9', 'payee_type' => 'owner', 'payee_id' => $id, 'purpose' => 'other', 'amount' => '5.000', 'method' => 'cash', 'status' => 'reversed',
            'paid_on' => '2026-10-06', 'posted_at' => '2026-10-06 10:00:00', 'reversed_at' => '2026-10-07 10:00:00', 'created_by' => $this->admin->id,
            'recorded_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(OwnerPortal::statements($id)->pluck('number')->all())->toBe(['OS-101'])
        ->and(OwnerPortal::payouts($id)->orderBy('number')->pluck('number')->all())->toBe(['DSB-101-1', 'DSB-101-2'])
        ->and(OwnerPortal::payables($id)->orderBy('due_date')->get()->map(fn (OwnerPayable $p) => $p->due_date->toDateString())->all())->toBe(['2026-10-01', '2026-11-01'])
        ->and(OwnerPortal::expenses($id)->orderBy('id')->pluck('description')->all())->toBe(['Leak repair 101', 'Paint touch-up 101'])
        ->and(OwnerPortal::requests($id)->pluck('number')->all())->toBe([$this->a['request']->number])
        ->and(OwnerPortal::costFils($this->a['request'], $id))->toBe(50_000)
        ->and(OwnerPortal::costFils($this->b['request'], $id))->toBe(0)
        ->and(OwnerPortal::summary($this->a['statement']))->toBe(['opening' => 0, 'income' => 500_000, 'charges' => 50_000, 'fees' => 0, 'paid' => 0, 'closing' => 450_000]);
});

test('a managed building shows its rent roll: units, tenant names, agreement dates, monthly rent, balance owed and vacant units', function () {
    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.buildings'))->assertOk()
        ->assertSeeInOrder(['Ali Owner Tower', '1 of 2 units let', 'Unit 101', 'Renter 101', '01/01/2026 – 31/12/2026', 'Monthly rent: 400.000 BHD',
            'Balance owed: 400.000 BHD', 'Unit V101', 'Vacant', 'Ali Owner Lease', 'Units: L101'])
        ->assertDontSee('Badr Owner');
});

test('the rent roll shows no tenant contact details, and a leased building no tenant or rent', function () {
    $renter = Customer::query()->where('name_en', 'Renter 101')->sole();

    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.buildings'))->assertOk()
        ->assertDontSee($renter->mobile)->assertDontSee((string) $renter->email)->assertDontSee($renter->id_number)->assertDontSee('Lessee 101');

    $cards = Livewire::actingAs($this->a['account'], 'tenant')->test(Buildings::class)->viewData('buildings');
    $lease = $cards->firstWhere(fn (array $c) => $c['building']->is($this->a['lease']));
    expect($lease['managed'])->toBeFalse()->and($lease['roll'])->toBeNull()->and($lease['units'])->toBe(['L101']);
});

test('draft and pending contracts and their buildings are hidden; an ended managed contract shows its units only', function () {
    $ownerId = $this->a['owner']->id;
    OwnerContract::factory()->create(['owner_id' => $ownerId, 'building_id' => Building::factory()->create(['name' => 'Draft Plaza'])->id]);
    $pendingBuilding = Building::factory()->create(['name' => 'Pending Plaza']);
    $pending = OwnerContract::factory()->create(['owner_id' => $ownerId, 'building_id' => $pendingBuilding->id]);
    $pending->units()->attach(Unit::factory()->for($pendingBuilding)->create()->id);
    $pending->forceFill(['status' => 'pending_approval'])->save();
    $oldCourt = Building::factory()->create(['name' => 'Old Court']);
    $oldUnit = Unit::factory()->for($oldCourt)->create(['code' => 'O1']);
    $ended = activeOwnerContract(['owner_id' => $ownerId, 'building_id' => $oldCourt->id, 'type' => 'managed', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31'], [$oldUnit]);
    $ended->forceFill(['status' => 'ended'])->save();
    activeAgreement(['customer_id' => Customer::factory()->create(['name_en' => 'Later Renter'])->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$oldUnit]);

    $this->actingAs($this->a['account'], 'tenant');
    $this->get(route('portal.owner.buildings'))->assertOk()
        ->assertDontSee('Draft Plaza')->assertDontSee('Pending Plaza')
        ->assertSeeInOrder(['Old Court', 'Units: O1'])->assertDontSee('Later Renter');
    $this->get(route('portal.owner.contracts'))->assertOk()
        ->assertDontSee('Draft Plaza')->assertDontSee('Pending Plaza')
        ->assertSeeInOrder([(string) $ended->number, 'Ended', 'Old Court · Managed', '01/01/2025 – 31/12/2025']);
});

test('contracts list number, status, building, type and dates', function () {
    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.contracts'))->assertOk()
        ->assertSeeInOrder([(string) $this->a['leased']->number, 'Active', 'Ali Owner Lease · Leased', '01/01/2026 – 31/12/2026',
            (string) $this->a['managed']->number, 'Active', 'Ali Owner Tower · Managed', '01/01/2026 – 31/12/2026'])
        ->assertDontSee((string) $this->b['managed']->number)->assertDontSee('Badr Owner');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/OwnerScopeTest.php`
Expected: FAIL. Class `App\Portal\OwnerPortal` is not found.

- [ ] **Step 4: The contracts scope.** In `app/Models/OwnerContract.php`, add after the `visibleTo` scope:

```php
    /**
     * Spec 2026-10-21 owner portal §4: "their contracts" — the owner's contracts with status active, ended or
     * terminated, never draft or pending approval. Every owner-portal query starts here (owner portal plan ruling 8).
     *
     * @param  Builder<OwnerContract>  $query
     */
    #[Scope]
    protected function portalFor(Builder $query, int $ownerId): void
    {
        $query->where($query->qualifyColumn('owner_id'), $ownerId)
            ->whereIn($query->qualifyColumn('status'), array_map(fn (OwnerContractStatus $s) => $s->value, self::ATTRIBUTED));
    }
```

- [ ] **Step 5: Share the rent roll query.** In `app/Reports/Queries.php`, replace `currentAgreementUnits()` with:

```php
    /** The agreement unit occupying each unit on $on (the latest when two overlap), for "current tenant / rent". */
    public static function currentAgreementUnits(User $user, string $on): QueryBuilder
    {
        return self::currentAgreementUnitsIn(Unit::query()->visibleTo($user)->select('id'), $on);
    }

    /**
     * The rent roll's unit rows as at $on for the units in $unitIds, building then unit order: RentRollReport and the
     * owner portal's building cards read the same query (owner portal plan ruling 9).
     *
     * @param  Builder<Unit>  $unitIds  a select('id') query
     * @return Collection<int, \stdClass>
     */
    public static function rentRollUnits(Builder $unitIds, string $on): Collection
    {
        $rent = DB::table('agreement_unit_charges')->where('type', 'rent')->groupBy('agreement_unit_id')
            ->select('agreement_unit_id', DB::raw('SUM(monthly_amount) as rent'));
        // Deposit held per unit = every agreement unit of the unit with a positive balance, as the Deposits held report does,
        // so building totals reconcile to it (a released unit can still hold money).
        $perAu = DB::table('deposit_movements as dm')
            ->join('agreement_units as au2', 'au2.id', '=', 'dm.agreement_unit_id')
            ->join('agreements as a2', 'a2.id', '=', 'au2.agreement_id')
            ->whereNull('a2.deleted_at')->where('dm.posted_at', '<=', $on.' 23:59:59')
            ->groupBy('dm.agreement_unit_id', 'au2.unit_id')->havingRaw('SUM(dm.amount) > 0')
            ->select('au2.unit_id', DB::raw('SUM(dm.amount) as held'));
        $held = DB::query()->fromSub($perAu, 'x')->groupBy('unit_id')->select('unit_id', DB::raw('SUM(held) as held'));
        $owed = DB::table('invoices')->where('status', 'issued')->where('type', '<>', 'credit_note')->whereNotNull('agreement_id')
            ->groupBy('agreement_id')->select('agreement_id', DB::raw('SUM(balance) as owed'));

        return DB::table('units as u')
            ->join('buildings as b', 'b.id', '=', 'u.building_id')
            ->leftJoin('unit_types as ut', 'ut.code', '=', 'u.type')
            ->leftJoinSub(self::currentAgreementUnitsIn($unitIds, $on), 'cur', 'cur.unit_id', '=', 'u.id')
            ->leftJoin('agreement_units as au', 'au.id', '=', 'cur.au_id')
            ->leftJoin('agreements as a', 'a.id', '=', 'au.agreement_id')
            ->leftJoin('customers as c', 'c.id', '=', 'a.customer_id')
            ->leftJoinSub($rent, 'r', 'r.agreement_unit_id', '=', 'au.id')
            ->leftJoinSub($held, 'd', 'd.unit_id', '=', 'u.id')
            ->leftJoinSub($owed, 'o', 'o.agreement_id', '=', 'a.id')
            ->whereIn('u.id', $unitIds)
            ->orderBy('b.code')->orderBy('u.code')
            ->get(['u.building_id', 'b.code as building', 'u.code as unit', 'u.blocked', 'u.list_rent', 'u.type', 'ut.name as type_name',
                'au.id as au_id', 'au.start_date', 'au.end_date', 'a.id as agreement_id', 'a.number', 'c.name_en as tenant', 'r.rent', 'd.held', 'o.owed']);
    }

    /** @param  Builder<Unit>  $unitIds  a select('id') query */
    private static function currentAgreementUnitsIn(Builder $unitIds, string $on): QueryBuilder
    {
        return DB::table('agreement_units as au')->join('agreements as a', 'a.id', '=', 'au.agreement_id')
            ->whereNull('a.deleted_at')
            ->whereNotIn('a.status', [AgreementStatus::Draft->value, AgreementStatus::PendingApproval->value])
            ->where('au.start_date', '<=', $on)
            ->whereRaw('('.AgreementUnit::effectiveEndSql('au').') >= ?', [$on, $on])
            ->whereIn('au.unit_id', $unitIds)
            ->groupBy('au.unit_id')
            ->select('au.unit_id', DB::raw('MAX(au.id) as au_id'));
    }
```

  In `app/Livewire/Reports/RentRollReport.php`, replace everything in `rows()` from the line `$rent = DB::table('agreement_unit_charges')…` through `'au.id as au_id', 'au.start_date', 'au.end_date', 'a.id as agreement_id', 'a.number', 'c.name_en as tenant', 'r.rent', 'd.held', 'o.owed']);` with:

```php
        $units = Queries::rentRollUnits(
            Unit::query()->visibleTo($user)->when($this->building, fn ($q, int $b) => $q->where('units.building_id', $b))->select('id'), $on);
```

  `DB` is no longer used in `RentRollReport.php` after this change: remove `use Illuminate\Support\Facades\DB;` from it.

- [ ] **Step 6: Create `app/Portal/OwnerPortal.php`:**

```php
<?php

namespace App\Portal;

use App\Billing\OwnerStatementCalculator;
use App\Enums\ChargeTo;
use App\Enums\DisbursementStatus;
use App\Enums\ExpenseStatus;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\OwnerPayableStatus;
use App\Enums\OwnerStatementStatus;
use App\Enums\PayeeType;
use App\Models\Building;
use App\Models\Disbursement;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\WorkOrder;
use App\Reports\Queries;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Spec 2026-10-21 owner portal §4 and §6: every owner-portal query, each starting from the signed-in account's owner_id
 * and going through that owner's contracts (OwnerContract::portalFor). Pages and downloads read only these, so a record
 * that is not the owner's is never listed and its id is a 404 (owner portal plan rulings 8–10).
 */
final class OwnerPortal
{
    /** Ledger kinds the statement summary splits out (owner portal plan ruling 11); everything else is income. */
    private const array CHARGE_KINDS = ['expense', 'expense_reversal'];

    private const array PAYOUT_KINDS = ['remittance', 'remittance_reversal'];

    /** @return Builder<OwnerStatement> finalised statements of their contracts */
    public static function statements(int $ownerId): Builder
    {
        return OwnerStatement::query()->whereIn('owner_contract_id', OwnerContract::query()->portalFor($ownerId)->select('id'))
            ->where('status', OwnerStatementStatus::Finalised);
    }

    /** @return Builder<Disbursement> paid payments out to the owner; waiting, rejected and reversed ones are not payouts */
    public static function payouts(int $ownerId): Builder
    {
        return Disbursement::query()->where('payee_type', PayeeType::Owner)->where('payee_id', $ownerId)->where('status', DisbursementStatus::Paid);
    }

    /** @return Builder<OwnerPayable> the rent schedule of their leased contracts; cancelled (replaced) rows left out */
    public static function payables(int $ownerId): Builder
    {
        return OwnerPayable::query()
            ->whereIn('owner_contract_id', OwnerContract::query()->portalFor($ownerId)->where('type', OwnerContractType::Leased)->select('id'))
            ->where('status', '!=', OwnerPayableStatus::Cancelled);
    }

    /** @return Builder<Expense> expenses charged to the owner on their contracts, reversed ones included */
    public static function expenses(int $ownerId): Builder
    {
        return Expense::query()->where('charge_to', ChargeTo::Owner)
            ->whereIn('owner_contract_id', OwnerContract::query()->portalFor($ownerId)->select('id'));
    }

    /** @return Builder<MaintenanceRequest> requests in their buildings, logged on a day one of their contracts on that building covered */
    public static function requests(int $ownerId): Builder
    {
        return MaintenanceRequest::query()->whereExists(fn (QueryBuilder $q) => $q->from('owner_contracts as oc')
            ->whereColumn('oc.building_id', 'maintenance_requests.building_id')
            ->where('oc.owner_id', $ownerId)
            ->whereIn('oc.status', array_map(fn (OwnerContractStatus $s) => $s->value, OwnerContract::ATTRIBUTED))
            ->whereRaw('oc.start_date <= DATE(maintenance_requests.created_at)')
            ->whereRaw('COALESCE(oc.terminated_on, oc.end_date) >= DATE(maintenance_requests.created_at)'));
    }

    /** What a request cost the owner: its work orders' recorded expenses charged to the owner on their contracts. */
    public static function costFils(MaintenanceRequest $request, int $ownerId): int
    {
        return Fils::fromDecimal((string) (self::expenses($ownerId)->where('status', ExpenseStatus::Recorded)
            ->whereIn('id', WorkOrder::query()->where('maintenance_request_id', $request->id)->whereNotNull('expense_id')->select('expense_id'))
            ->sum('total') ?: '0'));
    }

    /**
     * A finalised statement's figures from its stored columns and its window's ledger entries, each net of its reversals:
     * opening + income − charges − fees − paid = closing (owner portal plan ruling 11).
     *
     * @return array{opening: int, income: int, charges: int, fees: int, paid: int, closing: int}
     */
    public static function summary(OwnerStatement $statement): array
    {
        $figures = OwnerStatementCalculator::stored($statement);
        $sum = fn (array $kinds, bool $in): int => (int) $figures['entries']->filter(fn (array $e) => in_array($e['kind'], $kinds, true) === $in)->sum('amount');

        return [
            'opening' => $figures['opening'],
            'income' => $sum([...self::CHARGE_KINDS, ...self::PAYOUT_KINDS], false),
            'charges' => -$sum(self::CHARGE_KINDS, true),
            'fees' => $figures['fee'] + $figures['fee_tax'],
            'paid' => -$sum(self::PAYOUT_KINDS, true),
            'closing' => $figures['closing'],
        ];
    }

    /**
     * Spec §4 "Buildings": one card per building of their contracts. The rent roll only while one of their managed
     * contracts on it is active today; otherwise the unit codes alone (owner portal plan ruling 9).
     *
     * @return Collection<int, array{building: Building, managed: bool, units: list<string>, roll: list<array{unit: string, tenant: ?string, start: ?string, end: ?string, rent: ?int, owed: ?int}>|null, occupied: int, total: int}>
     */
    public static function buildings(int $ownerId): Collection
    {
        $today = now('Asia/Bahrain')->toDateString();
        $date = fn (mixed $d) => $d === null ? null : CarbonImmutable::parse((string) $d)->format('d/m/Y');

        return OwnerContract::query()->portalFor($ownerId)
            ->with(['building' => fn ($q) => $q->withTrashed(), 'units' => fn ($q) => $q->withTrashed()])
            ->orderBy('id')->get()
            ->groupBy('building_id')
            ->map(function (Collection $group) use ($today, $date) {
                $live = $group->first(fn (OwnerContract $c) => $c->type === OwnerContractType::Managed && $c->status === OwnerContractStatus::Active
                    && $c->start_date->toDateString() <= $today && $c->end_date->toDateString() >= $today);
                $roll = null;
                if ($live !== null) {
                    $seen = [];
                    $roll = Queries::rentRollUnits(Unit::query()->whereIn('id', $live->units->modelKeys())->select('id'), $today)
                        ->map(function (\stdClass $u) use (&$seen, $date) {
                            $occupied = $u->au_id !== null;
                            $first = $occupied && ! isset($seen[$u->agreement_id]); // balance owed once per agreement, as the report
                            if ($occupied) {
                                $seen[$u->agreement_id] = true;
                            }

                            return [
                                'unit' => (string) $u->unit,
                                'tenant' => $occupied ? (string) $u->tenant : null,
                                'start' => $occupied ? $date($u->start_date) : null,
                                'end' => $occupied ? $date($u->end_date) : null,
                                'rent' => $occupied ? Fils::fromDecimal((string) ($u->rent ?? '0')) : null,
                                'owed' => $first ? Fils::fromDecimal((string) ($u->owed ?? '0')) : null,
                            ];
                        })->values()->all();
                }
                /** @var OwnerContract $sample */
                $sample = $group->first();

                return [
                    'building' => $sample->building,
                    'managed' => $live !== null,
                    'units' => $group->flatMap(fn (OwnerContract $c) => $c->units->pluck('code'))->unique()->sort()->values()->all(),
                    'roll' => $roll,
                    'occupied' => $roll === null ? 0 : count(array_filter($roll, fn (array $r) => $r['tenant'] !== null)),
                    'total' => $roll === null ? 0 : count($roll),
                ];
            })->values();
    }
}
```

- [ ] **Step 7: The two pages.** Create `app/Livewire/Portal/Owner/Buildings.php`:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: one card per building of their contracts; the rent roll only while managed (plan ruling 9). */
#[Layout('layouts::portal')]
class Buildings extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.buildings', ['buildings' => OwnerPortal::buildings($this->ownerId())])->title(__('Buildings'));
    }
}
```

  Create `app/Livewire/Portal/Owner/Contracts.php`:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Models\OwnerContract;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: their contracts — number, building, type, dates, status (plan ruling 8). */
#[Layout('layouts::portal')]
class Contracts extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.contracts', [
            'contracts' => OwnerContract::query()->portalFor($this->ownerId())->with(['building' => fn ($q) => $q->withTrashed()])
                ->orderByDesc('start_date')->orderByDesc('id')->get(),
        ])->title(__('Contracts'));
    }
}
```

  Create `resources/views/livewire/portal/owner/buildings.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Buildings') }}</flux:heading>
    @forelse ($buildings as $card)
        <flux:card wire:key="b-{{ $card['building']->id }}" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">{{ $card['building']->name }}</flux:heading>
                @if ($card['roll'] !== null)
                    <flux:badge size="sm">{{ __(':occupied of :total units let', ['occupied' => $card['occupied'], 'total' => $card['total']]) }}</flux:badge>
                @endif
            </div>
            @if ($card['roll'] === null)
                <flux:text>{{ __('Units') }}: {{ implode(', ', $card['units']) }}</flux:text>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($card['roll'] as $row)
                        <li wire:key="u-{{ $card['building']->id }}-{{ $row['unit'] }}" class="space-y-1 py-2 text-sm">
                            <div class="flex flex-wrap justify-between gap-2">
                                <span class="font-medium">{{ __('Unit') }} {{ $row['unit'] }}</span>
                                <span>{{ $row['tenant'] ?? __('Vacant') }}</span>
                            </div>
                            @if ($row['tenant'] !== null)
                                <div class="flex flex-wrap justify-between gap-2 text-zinc-600 dark:text-zinc-300">
                                    <span>{{ $row['start'] }} – {{ $row['end'] }}</span>
                                    <span class="tabular-nums">{{ __('Monthly rent') }}: {{ __(':amount BHD', ['amount' => App\Support\Fils::grouped((int) $row['rent'])]) }}</span>
                                    @if ($row['owed'] !== null)
                                        <span class="tabular-nums">{{ __('Balance owed') }}: {{ __(':amount BHD', ['amount' => App\Support\Fils::grouped($row['owed'])]) }}</span>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </flux:card>
    @empty
        <flux:text>{{ __('No buildings yet.') }}</flux:text>
    @endforelse
</section>
```

  Create `resources/views/livewire/portal/owner/contracts.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Contracts') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($contracts as $c)
            <li wire:key="oc-{{ $c->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $c->label() }}</span>
                    <x-status-badge :status="$c->status" />
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $c->building->name }} · {{ $c->type === App\Enums\OwnerContractType::Managed ? __('Managed') : __('Leased') }}</span>
                    <span>{{ $c->start_date->format('d/m/Y') }} – {{ $c->end_date->format('d/m/Y') }}</span>
                </div>
                @if ($c->terminated_on)
                    <flux:text size="sm">{{ __('Terminated on :date', ['date' => $c->terminated_on->format('d/m/Y')]) }}</flux:text>
                @endif
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No contracts yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
```

- [ ] **Step 8: Routes, nav, Arabic.**
  - In `routes/portal.php` replace `            Route::livewire('/', Portal\Owner\Home::class)->name('home');` with:

```php
            Route::livewire('/', Portal\Owner\Home::class)->name('home');
            Route::livewire('buildings', Portal\Owner\Buildings::class)->name('buildings');
            Route::livewire('contracts', Portal\Owner\Contracts::class)->name('contracts');
```

  - In `resources/views/layouts/portal.blade.php` replace `        ['portal.owner.home', __('Home')],` with:

```php
        ['portal.owner.home', __('Home')],
        ['portal.owner.buildings', __('Buildings')],
        ['portal.owner.contracts', __('Contracts')],
```

  - In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Buildings": "العقارات",
    "Contracts": "العقود",
    "Managed": "عقد إدارة",
    "Leased": "عقد إيجار",
    "Unit": "الوحدة",
    "Units": "الوحدات",
    "Vacant": "شاغرة",
    "Monthly rent": "الإيجار الشهري",
    ":occupied of :total units let": ":occupied من :total وحدات مؤجرة",
    "No buildings yet.": "لا توجد عقارات بعد.",
    "No contracts yet.": "لا توجد عقود بعد.",
    "Ended": "منتهٍ",
    "Terminated on :date": "أُنهي في :date",
```

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/Portal tests/Feature/Reports/RentRollTest.php tests/Feature/ReportBuilder tests/Feature/ReportPackages`
Expected: PASS. The rent roll report and the units subject read `Queries` as before.

- [ ] **Step 10: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.

```bash
git add app/Portal/OwnerPortal.php app/Models/OwnerContract.php app/Reports/Queries.php app/Livewire/Reports/RentRollReport.php app/Livewire/Portal/Owner resources/views/livewire/portal/owner routes/portal.php resources/views/layouts/portal.blade.php lang/ar.json tests/Pest.php tests/Feature/Portal/OwnerScopeTest.php
git commit -m "$(cat <<'EOF'
Owner portal scope: every owner query from owner_id through their contracts; Buildings with the shared rent roll query, and Contracts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Statements with the staff PDF, Payments (payouts and the rent schedule), Expenses

**Files:**
- Create: `app/Http/Controllers/Portal/OwnerDownloadController.php`
- Create: `app/Livewire/Portal/Owner/Statements.php`, `Payments.php`, `Expenses.php` and `resources/views/livewire/portal/owner/statements.blade.php`, `payments.blade.php`, `expenses.blade.php`
- Modify: `app/Pdf/OwnerStatementPdf.php`, `app/Http/Controllers/OwnerStatementPdfController.php`
- Modify: `routes/portal.php`, `resources/views/layouts/portal.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Portal/OwnerMoneyTest.php`

**Interfaces:**
- Consumes: `OwnerPortal::statements()`, `summary()`, `payouts()`, `payables()`, `expenses()` (Task 5), `InPortal::ownerId()`, `owner()` (Task 2), `Owner::maskedIban()`, `portalOwnerFixture()`.
- Produces:
  - `OwnerStatementPdf::response(OwnerStatement): Response`.
  - `OwnerDownloadController::statement(string $statement, OwnerStatementPdf $pdf): Response` and its private `account(): PortalAccount`.
  - Routes `portal.owner.statements`, `portal.owner.statements.pdf` (`statements/{statement}/pdf`), `portal.owner.payments`, `portal.owner.expenses`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/OwnerMoneyTest.php`:

```php
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
    $this->actingAs($finance)->get(route('owner-statements.pdf', $this->a['statement']))->assertOk(); // the staff download still works
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/OwnerMoneyTest.php`
Expected: FAIL. Route `portal.owner.statements` is not defined.

- [ ] **Step 3: Share the statement PDF.** In `app/Pdf/OwnerStatementPdf.php`:
  - add `use App\Jobs\StoreOwnerStatement;` and `use Symfony\Component\HttpFoundation\Response;`;
  - add after `render()`:

```php
    /**
     * Spec §7.9: a finalised statement from its stored copy; otherwise rendered now (DRAFT unless finalised). Staff and
     * the owner portal both serve it this way (owner portal plan ruling 11).
     */
    public function response(OwnerStatement $s): Response
    {
        $stored = StoreOwnerStatement::stored($s);
        if ($stored !== null) {
            return Storage::disk($stored->disk)->response($stored->path, $stored->original_name, ['Content-Type' => 'application/pdf']);
        }

        return response($this->render($s), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.($s->number ?? 'statement-draft').'.pdf"']);
    }
```

  Replace `app/Http/Controllers/OwnerStatementPdfController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Models\OwnerStatement;
use App\Pdf\OwnerStatementPdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OwnerStatementPdfController
{
    public function __invoke(Request $request, OwnerStatement $statement, OwnerStatementPdf $pdf): Response
    {
        abort_unless($request->user('web')?->can('view', $statement) === true, 403);
        Audit::log('owner_statement.exported', $statement, properties: ['format' => 'pdf'], causer: $request->user('web'));

        return $pdf->response($statement);
    }
}
```

- [ ] **Step 4: The owner download controller.** Create `app/Http/Controllers/Portal/OwnerDownloadController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Audit\Audit;
use App\Models\PortalAccount;
use App\Pdf\OwnerStatementPdf;
use App\Portal\OwnerPortal;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 2026-10-21 owner portal §4 and §6: the owner's downloads, looked up inside their records, so any other id is a
 * 404 (owner portal plan ruling 10). Each download is audited with the portal account as causer.
 */
final class OwnerDownloadController
{
    /** Spec §4: a finalised statement of their contracts, the PDF staff download. */
    public function statement(string $statement, OwnerStatementPdf $pdf): Response
    {
        $account = $this->account();
        $model = OwnerPortal::statements((int) $account->owner_id)->findOrFail($statement);
        Audit::log('owner_statement.exported', $model, properties: ['format' => 'pdf'], causer: $account);

        return $pdf->response($model);
    }

    private function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);
        abort_unless($account->hasOwner(), 404);

        return $account;
    }
}
```

- [ ] **Step 5: The three components.** Create `app/Livewire/Portal/Owner/Statements.php`:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Models\OwnerStatement;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: finalised statements with their figures and the staff PDF (plan ruling 11). */
#[Layout('layouts::portal')]
class Statements extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.statements', [
            'statements' => OwnerPortal::statements($this->ownerId())
                ->with(['contract' => fn ($q) => $q->with(['building' => fn ($b) => $b->withTrashed()])])
                ->orderByDesc('period_start')->orderByDesc('id')->get()
                ->map(fn (OwnerStatement $s) => ['statement' => $s, ...OwnerPortal::summary($s)]),
        ])->title(__('Statements'));
    }
}
```

  Create `app/Livewire/Portal/Owner/Payments.php`:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: paid payouts, the bank with the IBAN masked, and the leased rent schedule (plan ruling 12). */
#[Layout('layouts::portal')]
class Payments extends Component
{
    use InPortal;

    public function render(): View
    {
        $owner = $this->owner();

        return view('livewire.portal.owner.payments', [
            'payouts' => OwnerPortal::payouts($owner->id)->orderByDesc('paid_on')->orderByDesc('id')->get(),
            'payables' => OwnerPortal::payables($owner->id)
                ->with(['contract' => fn ($q) => $q->with(['building' => fn ($b) => $b->withTrashed()])])
                ->orderBy('due_date')->orderBy('id')->get(),
            'bank' => filled($owner->iban)
                ? __('Bank transfers are paid to :bank, IBAN :iban.', ['bank' => (string) $owner->bank_name, 'iban' => (string) $owner->maskedIban()])
                : null,
        ])->title(__('Payments'));
    }
}
```

  Create `app/Livewire/Portal/Owner/Expenses.php`:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: expenses charged to the owner on their contracts; reversed ones marked; no internal notes. */
#[Layout('layouts::portal')]
class Expenses extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.expenses', [
            'expenses' => OwnerPortal::expenses($this->ownerId())
                ->with(['building' => fn ($q) => $q->withTrashed(), 'unit' => fn ($q) => $q->withTrashed()])
                ->orderByDesc('expense_date')->orderByDesc('id')->get(),
        ])->title(__('Expenses'));
    }
}
```

- [ ] **Step 6: The three views.** Create `resources/views/livewire/portal/owner/statements.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Statements') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($statements as $row)
            <li wire:key="os-{{ $row['statement']->id }}" class="space-y-2 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $row['statement']->number }} · {{ $row['statement']->contract->building->name }}</span>
                    <span class="text-sm">{{ $row['statement']->period_start->format('d/m/Y') }} – {{ $row['statement']->period_end->format('d/m/Y') }}</span>
                </div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                    @foreach (['opening' => __('Opening balance'), 'income' => __('Income'), 'charges' => __('Charges'), 'fees' => __('Fees'), 'paid' => __('Paid to you'), 'closing' => __('Closing balance')] as $key => $label)
                        <div>
                            <dt class="text-zinc-500">{{ $label }}</dt>
                            <dd class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped($row[$key])]) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <flux:link :href="route('portal.owner.statements.pdf', $row['statement'])" class="text-sm">{{ __('Statement PDF') }}</flux:link>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No statements yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
```

  Create `resources/views/livewire/portal/owner/payments.blade.php`:

```blade
<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Payments') }}</flux:heading>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Payouts') }}</flux:heading>
        @if ($bank)
            <flux:text>{{ $bank }}</flux:text>
        @endif
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
            @forelse ($payouts as $p)
                <li wire:key="po-{{ $p->id }}" class="space-y-1 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ $p->number }}</span>
                        <span class="text-sm">{{ $p->paid_on?->format('d/m/Y') }}</span>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2 text-sm">
                        <span>{{ implode(' · ', array_filter([__($p->method->label()), $p->reference ? __('Reference :ref', ['ref' => $p->reference]) : null])) }}</span>
                        <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($p->amount))]) }}</span>
                    </div>
                </li>
            @empty
                <li class="p-4"><flux:text>{{ __('No payouts yet.') }}</flux:text></li>
            @endforelse
        </ul>
    </div>

    @if ($payables->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg" level="2">{{ __('Rent schedule') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                @foreach ($payables as $r)
                    <li wire:key="op-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 p-4 text-sm">
                        <span>{{ __('Due :date', ['date' => $r->due_date->format('d/m/Y')]) }} · {{ $r->contract->building->name }}</span>
                        <span class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($r->amount))]) }}</span>
                        <span>{{ $r->status === App\Enums\OwnerPayableStatus::Paid ? __('Paid') : __('Not paid yet') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
```

  Create `resources/views/livewire/portal/owner/expenses.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Expenses') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($expenses as $e)
            <li wire:key="ex-{{ $e->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ implode(' · ', array_filter([$e->expense_date->format('d/m/Y'), $e->building->name, $e->unit?->code])) }}</span>
                    @if ($e->status === App\Enums\ExpenseStatus::Reversed)
                        <x-status-badge :status="$e->status" />
                    @endif
                </div>
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ __(str($e->category->value)->headline()->toString()) }} · {{ $e->description }}</span>
                    <span class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($e->total))]) }}</span>
                </div>
            </li>
        @empty
            <li class="p-4"><flux:text>{{ __('No expenses yet.') }}</flux:text></li>
        @endforelse
    </ul>
</section>
```

- [ ] **Step 7: Routes, nav, Arabic.**
  - In `routes/portal.php` add `use App\Http\Controllers\Portal\OwnerDownloadController;` and replace `            Route::livewire('/', Portal\Owner\Home::class)->name('home');` with:

```php
            Route::livewire('/', Portal\Owner\Home::class)->name('home');
            Route::livewire('statements', Portal\Owner\Statements::class)->name('statements');
            Route::get('statements/{statement}/pdf', [OwnerDownloadController::class, 'statement'])->whereNumber('statement')->name('statements.pdf');
            Route::livewire('payments', Portal\Owner\Payments::class)->name('payments');
            Route::livewire('expenses', Portal\Owner\Expenses::class)->name('expenses');
```

  - In `resources/views/layouts/portal.blade.php` replace `        ['portal.owner.buildings', __('Buildings')],` with:

```php
        ['portal.owner.buildings', __('Buildings')],
        ['portal.owner.statements', __('Statements')],
        ['portal.owner.payments', __('Payments')],
        ['portal.owner.expenses', __('Expenses')],
```

  - In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Statements": "كشوف الحساب",
    "Income": "الإيرادات",
    "Charges": "المصروفات المحمّلة",
    "Fees": "أتعاب الإدارة",
    "Paid to you": "المدفوع لك",
    "Closing balance": "الرصيد الختامي",
    "Statement PDF": "كشف الحساب بصيغة PDF",
    "No statements yet.": "لا توجد كشوف حساب بعد.",
    "Payouts": "المبالغ المحوّلة إليك",
    "Rent schedule": "جدول الإيجار",
    "Not paid yet": "لم يُدفع بعد",
    "Bank transfers are paid to :bank, IBAN :iban.": "تُحوَّل المبالغ إلى :bank، رقم الآيبان :iban.",
    "No payouts yet.": "لا توجد مبالغ محوّلة إليك بعد.",
    "Reference :ref": "المرجع :ref",
    "Expenses": "المصروفات",
    "No expenses yet.": "لا توجد مصروفات بعد.",
    "Utilities": "الخدمات",
    "Security": "الأمن",
    "Insurance": "التأمين على العقار",
    "Government": "رسوم حكومية",
    "Legal": "الشؤون القانونية",
    "Commission": "العمولة",
```

- [ ] **Step 8: Run the tests.**

Run: `php artisan test tests/Feature/Portal tests/Feature/OwnerStatements tests/Feature/Pdf`
Expected: PASS. The staff statement PDF tests are unchanged in behaviour.

- [ ] **Step 9: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.

```bash
git add app/Pdf/OwnerStatementPdf.php app/Http/Controllers/OwnerStatementPdfController.php app/Http/Controllers/Portal/OwnerDownloadController.php app/Livewire/Portal/Owner resources/views/livewire/portal/owner routes/portal.php resources/views/layouts/portal.blade.php lang/ar.json tests/Feature/Portal/OwnerMoneyTest.php
git commit -m "$(cat <<'EOF'
Owner portal money: finalised statements with the staff PDF, paid payouts with the IBAN masked, the lease rent schedule, and owner-charged expenses

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: The owner home's figures, and Maintenance with the tenant timeline and the cost charged

**Files:**
- Create: `app/Livewire/Portal/Owner/Maintenance.php`, `app/Livewire/Portal/Owner/MaintenanceShow.php`, `resources/views/livewire/portal/owner/maintenance.blade.php`, `resources/views/livewire/portal/owner/maintenance-show.blade.php`
- Modify: `app/Livewire/Portal/Owner/Home.php`, `resources/views/livewire/portal/owner/home.blade.php`
- Modify: `routes/portal.php`, `resources/views/layouts/portal.blade.php`, `lang/ar.json`
- Test: `tests/Feature/Portal/OwnerHomeAndMaintenanceTest.php`

**Interfaces:**
- Consumes: `OwnerPortal::payouts()`, `payables()`, `buildings()`, `requests()`, `costFils()` (Task 5), `OwnerContract::portalFor()`, `MaintenanceRequest::tenantTimeline()`, `place()`.
- Produces: routes `portal.owner.maintenance` and `portal.owner.maintenance.show` (`maintenance/{maintenance}`), and `Owner\MaintenanceShow` with `#[Locked] public int $requestId` and `mount(string $maintenance)`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/OwnerHomeAndMaintenanceTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName as R;
use App\Livewire\Portal\Owner;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->admin = matrixUser(R::Admin, Building::factory()->create());
    $this->a = portalOwnerFixture('Ali Owner', '101', $this->admin);
    $this->b = portalOwnerFixture('Badr Owner', '202', $this->admin);
    $this->actingAs($this->a['account'], 'tenant');
});

test('home shows the balance owed per managed contract, the last payout, the next lease payment, occupancy and contracts ending within 90 days', function () {
    $this->get(route('portal.owner.home'))->assertOk()
        ->assertSeeInOrder(['Welcome, Ali Owner', 'Balance owed to you', 'Ali Owner Tower · OS-101', '450.000 BHD',
            'Last payout', '450.000 BHD', '05/10/2026', 'Next lease payment', '300.000 BHD', 'Due 01/11/2026',
            'Occupancy', 'Ali Owner Tower', '1 of 2 units let',
            'Contracts ending soon', (string) $this->a['managed']->number, 'Ends 31/12/2026', (string) $this->a['leased']->number, 'Ends 31/12/2026'])
        ->assertDontSee('Badr Owner');
});

test('home in Arabic reads right to left with amounts to 3 decimals', function () {
    $this->a['account']->forceFill(['locale' => 'ar'])->save();

    $this->get(route('portal.owner.home'))->assertOk()->assertSee('dir="rtl"', false)
        ->assertSee('الرصيد المستحق لك')->assertSee('450.000 د.ب')->assertSee('31/12/2026');
});

test('maintenance lists requests in their buildings; a request shows the tenant timeline and the cost charged to them, never vendors or notes', function () {
    $request = $this->a['request'];

    $this->get(route('portal.owner.maintenance'))->assertOk()
        ->assertSeeInOrder([$request->number, $request->fresh()->status->label(), 'Ali Owner Tower 101', 'Plumbing', 'Sent 21/10/2026'])
        ->assertDontSee($this->b['request']->number);

    $this->get(route('portal.owner.maintenance.show', $request))->assertOk()
        ->assertSeeInOrder([$request->number, 'Ali Owner Tower 101', 'Cost charged to you', '50.000 BHD', 'Request received', 'Work assigned'])
        ->assertDontSee('Fix-It 101')->assertDontSee($request->workOrders()->first()->number)->assertDontSee('Kitchen sink leaking');
});

test('another owner\'s request and made-up ids are a 404, by URL and by Livewire', function () {
    foreach ([route('portal.owner.maintenance.show', $this->b['request']), '/portal/owner/maintenance/99999999999999999999',
        '/portal/owner/maintenance/abc', '/portal/owner/maintenance/-1'] as $url) {
        $this->get($url)->assertNotFound();
    }

    Livewire::actingAs($this->a['account'], 'tenant')->test(Owner\MaintenanceShow::class, ['maintenance' => (string) $this->b['request']->id])->assertNotFound();
});

test('a request logged before their contract started is not theirs', function () {
    $this->travelTo(CarbonImmutable::parse('2025-12-15 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2025);
    $old = maintenanceRequest($this->admin, Unit::query()->where('code', '101')->sole());
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));

    $this->get(route('portal.owner.maintenance'))->assertOk()->assertDontSee($old->number);
    $this->get(route('portal.owner.maintenance.show', $old))->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/OwnerHomeAndMaintenanceTest.php`
Expected: FAIL. "Balance owed to you" is not on the page, and route `portal.owner.maintenance` is not defined.

- [ ] **Step 3: The home's figures.** Replace `app/Livewire/Portal/Owner/Home.php` with:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\OwnerPayableStatus;
use App\Enums\OwnerStatementStatus;
use App\Livewire\Concerns\InPortal;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: what is owed to the owner, what was paid and is due, occupancy, and what ends soon (plan ruling 15). */
#[Layout('layouts::portal')]
class Home extends Component
{
    use InPortal;

    public function render(): View
    {
        $owner = $this->owner();
        $today = now('Asia/Bahrain')->toDateString();
        $until = now('Asia/Bahrain')->addDays(90)->toDateString();
        $contracts = OwnerContract::query()->portalFor($owner->id)->with(['building' => fn ($q) => $q->withTrashed()])->orderBy('id')->get();

        return view('livewire.portal.owner.home', [
            'name' => $owner->displayName(app()->getLocale()),
            'balances' => $contracts->filter(fn (OwnerContract $c) => $c->type === OwnerContractType::Managed)
                ->map(fn (OwnerContract $c) => ['contract' => $c, 'statement' => OwnerStatement::query()->where('owner_contract_id', $c->id)
                    ->where('status', OwnerStatementStatus::Finalised)->orderByDesc('period_start')->first()])
                ->filter(fn (array $b) => $b['statement'] !== null)->values(),
            'lastPayout' => OwnerPortal::payouts($owner->id)->orderByDesc('paid_on')->orderByDesc('id')->first(),
            'nextPayable' => OwnerPortal::payables($owner->id)->where('status', OwnerPayableStatus::Scheduled)->orderBy('due_date')->orderBy('id')->first(),
            'buildings' => OwnerPortal::buildings($owner->id)->filter(fn (array $card) => $card['managed'])->values(),
            'ending' => $contracts->filter(fn (OwnerContract $c) => $c->status === OwnerContractStatus::Active
                && $c->end_date->toDateString() >= $today && $c->end_date->toDateString() <= $until)->sortBy('end_date')->values(),
        ])->title(__('Home'));
    }
}
```

  Replace `resources/views/livewire/portal/owner/home.blade.php` with:

```blade
<section class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Balance owed to you') }}</flux:heading>
        @forelse ($balances as $b)
            <flux:card wire:key="bal-{{ $b['contract']->id }}" class="flex flex-wrap items-center justify-between gap-2">
                <span>{{ $b['contract']->building->name }} · {{ $b['statement']->number }}</span>
                <span class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($b['statement']->closing_balance))]) }}</span>
            </flux:card>
        @empty
            <flux:text>{{ __('No finalised statement yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Last payout') }}</flux:text>
            @if ($lastPayout)
                <div class="text-lg font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($lastPayout->amount))]) }}</div>
                <flux:text size="sm">{{ $lastPayout->paid_on?->format('d/m/Y') }}</flux:text>
            @else
                <flux:text>{{ __('No payouts yet.') }}</flux:text>
            @endif
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Next lease payment') }}</flux:text>
            @if ($nextPayable)
                <div class="text-lg font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped(App\Support\Fils::fromDecimal($nextPayable->amount))]) }}</div>
                <flux:text size="sm">{{ __('Due :date', ['date' => $nextPayable->due_date->format('d/m/Y')]) }}</flux:text>
            @else
                <flux:text>{{ __('Nothing is due.') }}</flux:text>
            @endif
        </flux:card>
    </div>

    @if ($buildings->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg" level="2">{{ __('Occupancy') }}</flux:heading>
            @foreach ($buildings as $card)
                <div wire:key="occ-{{ $card['building']->id }}" class="flex flex-wrap justify-between gap-2 text-sm">
                    <span>{{ $card['building']->name }}</span>
                    <span>{{ __(':occupied of :total units let', ['occupied' => $card['occupied'], 'total' => $card['total']]) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Contracts ending soon') }}</flux:heading>
        @forelse ($ending as $c)
            <div wire:key="end-{{ $c->id }}" class="flex flex-wrap justify-between gap-2 text-sm">
                <span>{{ $c->label() }} · {{ $c->building->name }}</span>
                <span>{{ __('Ends :date', ['date' => $c->end_date->format('d/m/Y')]) }}</span>
            </div>
        @empty
            <flux:text>{{ __('No contract ends in the next 90 days.') }}</flux:text>
        @endforelse
    </div>
</section>
```

- [ ] **Step 4: Maintenance components.** Create `app/Livewire/Portal/Owner/Maintenance.php`:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: requests in their buildings, latest first (plan rulings 9 and 14). */
#[Layout('layouts::portal')]
class Maintenance extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.maintenance', [
            'requests' => OwnerPortal::requests($this->ownerId())->with(['building', 'unit'])->latest('id')->get(),
        ])->title(__('Maintenance'));
    }
}
```

  Create `app/Livewire/Portal/Owner/MaintenanceShow.php`:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: one request in their buildings, the tenant timeline and the cost charged to them. Any other id is a 404. */
#[Layout('layouts::portal')]
class MaintenanceShow extends Component
{
    use InPortal;

    #[Locked]
    public int $requestId;

    public function mount(string $maintenance): void
    {
        $this->requestId = OwnerPortal::requests($this->ownerId())->findOrFail($maintenance)->id;
    }

    public function render(): View
    {
        $ownerId = $this->ownerId();
        $request = OwnerPortal::requests($ownerId)->with(['building', 'unit'])->findOrFail($this->requestId);

        return view('livewire.portal.owner.maintenance-show', [
            'request' => $request,
            'timeline' => $request->tenantTimeline(),
            'cost' => OwnerPortal::costFils($request, $ownerId),
        ])->title($request->number);
    }
}
```

  Create `resources/views/livewire/portal/owner/maintenance.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Maintenance') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($requests as $r)
            <li wire:key="mr-{{ $r->id }}" class="space-y-1 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:link :href="route('portal.owner.maintenance.show', $r)" class="font-medium">{{ $r->number }}</flux:link>
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

  Create `resources/views/livewire/portal/owner/maintenance-show.blade.php`:

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
        @if ($cost > 0)
            <div>
                <dt class="text-sm text-zinc-500">{{ __('Cost charged to you') }}</dt>
                <dd class="tabular-nums">{{ __(':amount BHD', ['amount' => App\Support\Fils::grouped($cost)]) }}</dd>
            </div>
        @endif
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

    <flux:link :href="route('portal.owner.maintenance')">{{ __('All requests') }}</flux:link>
</section>
```

- [ ] **Step 5: Routes, nav, Arabic.**
  - In `routes/portal.php` replace `            Route::livewire('/', Portal\Owner\Home::class)->name('home');` with:

```php
            Route::livewire('/', Portal\Owner\Home::class)->name('home');
            Route::livewire('maintenance', Portal\Owner\Maintenance::class)->name('maintenance');
            Route::livewire('maintenance/{maintenance}', Portal\Owner\MaintenanceShow::class)->whereNumber('maintenance')->name('maintenance.show');
```

  - In `resources/views/layouts/portal.blade.php` replace `        ['portal.owner.contracts', __('Contracts')],` with:

```php
        ['portal.owner.maintenance', __('Maintenance')],
        ['portal.owner.contracts', __('Contracts')],
```

  - In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Balance owed to you": "الرصيد المستحق لك",
    "Last payout": "آخر مبلغ محوّل إليك",
    "Next lease payment": "دفعة الإيجار التالية",
    "Occupancy": "الإشغال",
    "Contracts ending soon": "عقود تنتهي قريباً",
    "No contract ends in the next 90 days.": "لا ينتهي أي عقد خلال الـ 90 يوماً القادمة.",
    "No finalised statement yet.": "لا يوجد كشف حساب نهائي بعد.",
    "Cost charged to you": "التكلفة المحمّلة عليك",
```

- [ ] **Step 6: Run the tests.**

Run: `php artisan test tests/Feature/Portal`
Expected: PASS.

- [ ] **Step 7: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.

```bash
git add app/Livewire/Portal/Owner resources/views/livewire/portal/owner routes/portal.php resources/views/layouts/portal.blade.php lang/ar.json tests/Feature/Portal/OwnerHomeAndMaintenanceTest.php
git commit -m "$(cat <<'EOF'
Owner portal home and maintenance: balances, last payout, next lease payment, occupancy, contracts ending; requests with the tenant timeline and the cost charged

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Documents: "Share with owner", one share action for both audiences, and the owner's Documents page

**Files:**
- Create: `app/Actions/Documents/ShareDocument.php`, `app/Livewire/Portal/Owner/Documents.php`, `resources/views/livewire/portal/owner/documents.blade.php`
- Delete: `app/Actions/Documents/ShareDocumentWithTenant.php`
- Modify: `app/Models/Document.php`, `app/Livewire/Documents/Panel.php`, `resources/views/livewire/documents/panel.blade.php`
- Modify: `app/Http/Controllers/Portal/OwnerDownloadController.php`, `routes/portal.php`, `resources/views/layouts/portal.blade.php`, `lang/ar.json`
- Modify: `tests/Feature/Portal/StatementAndDocumentsTest.php`, `tests/Feature/Portal/IsolationTest.php`
- Test: `tests/Feature/Portal/OwnerDocumentsTest.php`

**Interfaces:**
- Consumes: `OwnerContract::portalFor()` (Task 5), `OwnerDownloadController::account()` (Task 6), `StoreDocument::handle()`, `portalOwnerFixture()`.
- Produces:
  - `Document::SHAREABLE` per kind (`['tenant' => […], 'owner' => […]]`), the `Document::sharedWithOwner(int $ownerId)` scope, and the `shared_with_owner` bool cast.
  - `ShareDocument::handle(User $actor, Document $document, string $audience, bool $shared): Document` with `ShareDocument::AUDIENCES`.
  - `Panel::toggleOwnerShare(mixed $documentId, ShareDocument $share)`.
  - `OwnerDownloadController::document(string $document)`.
  - Routes `portal.owner.documents`, `portal.owner.documents.download` (`documents/{document}`).

- [ ] **Step 1: Write the failing test** `tests/Feature/Portal/OwnerDocumentsTest.php`:

```php
<?php

use App\Actions\Documents\ShareDocument;
use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Enums\DocumentCategory;
use App\Enums\RoleName as R;
use App\Livewire\Documents\Panel;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerContract;
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
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->admin = matrixUser(R::Admin, Building::factory()->create());
    $this->a = portalOwnerFixture('Ali Owner', '101', $this->admin);
    $this->b = portalOwnerFixture('Badr Owner', '202', $this->admin);
    $this->store = fn (mixed $on, string $name) => app(StoreDocument::class)
        ->handle($this->admin, $on, UploadedFile::fake()->create($name, 10, 'application/pdf'), DocumentCategory::Other);
    $this->share = fn (mixed $document) => app(ShareDocument::class)->handle($this->admin, $document, 'owner', true);
});

test('staff share a document from the owner page; it is not shared until then, and the toggle is an audit entry', function () {
    $deed = ($this->store)($this->a['owner'], 'title-deed.pdf');
    expect($deed->fresh()->shared_with_owner)->toBeFalse();

    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $this->a['owner']])
        ->assertSee('Share with owner')->assertDontSee('Share with tenant')
        ->call('toggleOwnerShare', $deed->id)->assertHasNoErrors()->assertSee('Shared with owner');

    expect($deed->fresh()->shared_with_owner)->toBeTrue()->and($deed->fresh()->shared_with_tenant)->toBeFalse();
    $audit = Activity::query()->where('subject_type', $deed->getMorphClass())->where('subject_id', $deed->id)->where('event', 'updated')->sole();
    expect($audit->causer?->is($this->admin))->toBeTrue()
        ->and($audit->attribute_changes['attributes']['shared_with_owner'])->toBeTrue();
});

test('the owner sees and downloads only documents shared on them, their contracts or their buildings', function () {
    $deed = ($this->share)(($this->store)($this->a['owner'], 'title-deed.pdf'));
    $annex = ($this->share)(($this->store)($this->a['managed'], 'contract-annex.pdf'));
    $plan = ($this->share)(($this->store)($this->a['tower'], 'floor-plan.pdf'));
    $private = ($this->store)($this->a['owner'], 'id-copy.pdf');
    $draft = OwnerContract::factory()->create(['owner_id' => $this->a['owner']->id]);
    $draftTerms = ($this->share)(($this->store)($draft, 'draft-terms.pdf'));
    $other = ($this->share)(($this->store)($this->b['owner'], 'other-deed.pdf'));

    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.documents'))->assertOk()
        ->assertSee('title-deed.pdf')->assertSee('contract-annex.pdf')->assertSee('floor-plan.pdf')
        ->assertDontSee('id-copy.pdf')->assertDontSee('draft-terms.pdf')->assertDontSee('other-deed.pdf');

    $this->get(route('portal.owner.documents.download', $deed))->assertOk()->assertDownload('title-deed.pdf');
    $this->get(route('portal.owner.documents.download', $plan))->assertOk();
    expect(Activity::query()->where('event', 'document.downloaded')->where('causer_type', $this->a['account']->getMorphClass())->count())->toBe(2);

    foreach ([$private, $draftTerms, $other] as $document) {
        $this->get(route('portal.owner.documents.download', $document))->assertNotFound();
    }
    app(ShareDocument::class)->handle($this->admin, $deed->fresh(), 'owner', false);
    $this->get(route('portal.owner.documents.download', $deed))->assertNotFound();
    $this->get(route('portal.owner.documents.download', $annex))->assertOk();
});

test('a document shared with the owner is not shown to the tenant, and the reverse', function () {
    $renter = Customer::query()->where('name_en', 'Renter 101')->sole();
    $plan = ($this->share)(($this->store)($this->a['tower'], 'floor-plan.pdf'));
    $rules = app(ShareDocument::class)->handle($this->admin, ($this->store)($renter, 'house-rules.pdf'), 'tenant', true);

    $this->actingAs(portalAccount($renter), 'tenant')->get(route('portal.documents'))->assertOk()
        ->assertSee('house-rules.pdf')->assertDontSee('floor-plan.pdf');
    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.documents'))->assertOk()
        ->assertSee('floor-plan.pdf')->assertDontSee('house-rules.pdf');
    expect($plan->fresh()->shared_with_tenant)->toBeFalse()->and($rules->fresh()->shared_with_owner)->toBeFalse();
});

test('"Share with owner" is offered only on owner, owner contract and building documents, to staff who can change the record', function () {
    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $this->a['tower']])->assertSee('Share with owner')->assertDontSee('Share with tenant');
    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $this->a['managed']])->assertSee('Share with owner');
    $renter = Customer::query()->where('name_en', 'Renter 101')->sole();
    ($this->store)($renter, 'receipt-copy.pdf');
    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $renter])->assertSee('Share with tenant')->assertDontSee('Share with owner');
    expect(fn () => app(ShareDocument::class)->handle($this->admin, $renter->documents()->sole(), 'owner', true))->toThrow(ValidationException::class);

    $deed = ($this->store)($this->a['owner'], 'title-deed.pdf');
    $pm = User::factory()->withTwoFactor()->create()->assignRole(R::PropertyManager); // views owners, cannot change them
    Livewire::actingAs($pm)->test(Panel::class, ['documentable' => $this->a['owner']])->assertSee('title-deed.pdf')->assertDontSee('Share with owner');
    expect(fn () => app(ShareDocument::class)->handle($pm, $deed, 'owner', true))->toThrow(AuthorizationException::class)
        ->and($deed->fresh()->shared_with_owner)->toBeFalse();
});

test('a tampered or foreign document id is a message, never a 500', function (Closure $id) {
    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $this->a['owner']])
        ->call('toggleOwnerShare', $id->call($this))->assertOk()->assertHasErrors('share');
})->with([
    'text' => [fn () => 'abc'],
    'an array' => [fn () => ['1']],
    'another record\'s document' => [fn () => ($this->store)($this->a['managed'], 'annex.pdf')->id],
    'no such document' => [fn () => 999999],
]);
```

  (`Customer::documents()` is the existing `MorphMany`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Portal/OwnerDocumentsTest.php`
Expected: FAIL. Class `App\Actions\Documents\ShareDocument` is not found.

- [ ] **Step 3: The document model.** In `app/Models/Document.php`:
  - add the docblock line ` * @property bool $shared_with_owner` under `@property bool $shared_with_tenant` (`Owner`, `OwnerContract` and `Building` share the `App\Models` namespace, so no import is needed);
  - replace the `SHAREABLE` constant with:

```php
    /**
     * Spec 2026-10-18 tenant portal §4 and 2026-10-21 owner portal §4: records whose documents staff may share, per
     * audience. Owner statements are left out: no panel, no update policy; their PDF is on the Statements page (owner
     * portal plan ruling 13).
     */
    public const array SHAREABLE = [
        'tenant' => [Customer::class, Agreement::class, Invoice::class],
        'owner' => [Owner::class, OwnerContract::class, Building::class],
    ];
```

  - add after the `sharedWithTenant` scope:

```php
    /**
     * Spec 2026-10-21 owner portal §4: shared documents on the owner, their contracts or their buildings (owner portal
     * plan ruling 13); deleted ones are left out by SoftDeletes.
     *
     * @param  Builder<Document>  $query
     */
    #[Scope]
    protected function sharedWithOwner(Builder $query, int $ownerId): void
    {
        $query->where('shared_with_owner', true)->where(fn (Builder $q) => $q
            ->where(fn (Builder $o) => $o->where('documentable_type', (new Owner)->getMorphClass())->where('documentable_id', $ownerId))
            ->orWhere(fn (Builder $c) => $c->where('documentable_type', (new OwnerContract)->getMorphClass())
                ->whereIn('documentable_id', OwnerContract::query()->portalFor($ownerId)->select('id')))
            ->orWhere(fn (Builder $b) => $b->where('documentable_type', (new Building)->getMorphClass())
                ->whereIn('documentable_id', OwnerContract::query()->portalFor($ownerId)->select('building_id'))));
    }
```

  - in `casts()`, add `'shared_with_owner' => 'boolean'` beside `'shared_with_tenant' => 'boolean'`;
  - in `getActivitylogOptions()`, replace `->logOnly(['category', 'expires_on', 'shared_with_tenant'])` with `->logOnly(['category', 'expires_on', 'shared_with_tenant', 'shared_with_owner'])`.

- [ ] **Step 4: One share action.** Delete `app/Actions/Documents/ShareDocumentWithTenant.php` and create `app/Actions/Documents/ShareDocument.php`:

```php
<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Spec 2026-10-18 tenant portal §4 and 2026-10-21 owner portal §4: "Share with tenant" and "Share with owner". Only on
 * the audience's shareable records, by someone who can change that record; Document's LogsActivity records each change
 * (owner portal plan ruling 13).
 */
final class ShareDocument
{
    public const array AUDIENCES = ['tenant', 'owner'];

    public function handle(User $actor, Document $document, string $audience, bool $shared): Document
    {
        if (! in_array($audience, self::AUDIENCES, true)) {
            throw new InvalidArgumentException("Unknown audience {$audience}"); // a programming error: callers pass a literal
        }
        $record = $document->documentable;
        if ($record === null || ! in_array($record::class, Document::SHAREABLE[$audience], true)) {
            throw ValidationException::withMessages(['document' => $audience === 'tenant'
                ? __('Only documents on a tenant, an agreement or an invoice can be shared with the tenant.')
                : __('Only documents on an owner, an owner contract or a building can be shared with the owner.')]);
        }
        if (! $actor->can('update', $record)) {
            throw new AuthorizationException;
        }

        $document->forceFill([$audience === 'owner' ? 'shared_with_owner' : 'shared_with_tenant' => $shared])->save();

        return $document;
    }
}
```

- [ ] **Step 5: The panel.** In `app/Livewire/Documents/Panel.php`:
  - replace `use App\Actions\Documents\ShareDocumentWithTenant;` with `use App\Actions\Documents\ShareDocument;`;
  - replace the whole `toggleShare()` method (docblock included) with:

```php
    /** Spec 2026-10-18 tenant portal §4: flip "Share with tenant". The id is looked up inside this record (never a 500). */
    public function toggleShare(mixed $documentId, ShareDocument $share): void
    {
        $this->share($documentId, 'tenant', $share);
    }

    /** Spec 2026-10-21 owner portal §4: flip "Share with owner". The browser never names the audience. */
    public function toggleOwnerShare(mixed $documentId, ShareDocument $share): void
    {
        $this->share($documentId, 'owner', $share);
    }

    private function share(mixed $documentId, string $audience, ShareDocument $share): void
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
            $share->handle($this->actor(), $document, $audience, $audience === 'owner' ? ! $document->shared_with_owner : ! $document->shared_with_tenant);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            $this->addError('share', (string) collect($e->errors())->flatten()->first());
        }
    }
```

  - in `render()`, replace `'shareable' => in_array($this->type, Document::SHAREABLE, true),` with:

```php
            'shareable' => in_array($this->type, Document::SHAREABLE['tenant'], true),
            'ownerShareable' => in_array($this->type, Document::SHAREABLE['owner'], true),
```

  In `resources/views/livewire/documents/panel.blade.php`, insert directly after the closing `@endif` of the `@if ($shareable && $canUpload)` block (the block with `wire:click="toggleShare({{ $document->id }})"`):

```blade
                    @if ($ownerShareable && $document->shared_with_owner)
                        <flux:badge size="sm" color="green">{{ __('Shared with owner') }}</flux:badge>
                    @endif
                    @if ($ownerShareable && $canUpload)
                        <flux:button size="sm" variant="ghost" wire:click="toggleOwnerShare({{ $document->id }})">
                            {{ $document->shared_with_owner ? __('Stop sharing') : __('Share with owner') }}
                        </flux:button>
                    @endif
```

- [ ] **Step 6: The tenant tests use the new action.**
  - In `tests/Feature/Portal/StatementAndDocumentsTest.php`, replace `use App\Actions\Documents\ShareDocumentWithTenant;` with `use App\Actions\Documents\ShareDocument;`. Then make these replacements:
    - `app(ShareDocumentWithTenant::class)->handle($this->pm, $document, true)` → `app(ShareDocument::class)->handle($this->pm, $document, 'tenant', true)`
    - `app(ShareDocumentWithTenant::class)->handle($this->pm, $rules->fresh(), false)` → `app(ShareDocument::class)->handle($this->pm, $rules->fresh(), 'tenant', false)`
    - `app(ShareDocumentWithTenant::class)->handle($this->pm, $photo, true)` → `app(ShareDocument::class)->handle($this->pm, $photo, 'tenant', true)`
    - `app(ShareDocumentWithTenant::class)->handle($finance, $rules, true)` → `app(ShareDocument::class)->handle($finance, $rules, 'tenant', true)`
  - In `tests/Feature/Portal/IsolationTest.php`, replace `use App\Actions\Documents\ShareDocumentWithTenant;` with `use App\Actions\Documents\ShareDocument;`, and `app(ShareDocumentWithTenant::class)->handle($pm, $document, true);` with `app(ShareDocument::class)->handle($pm, $document, 'tenant', true);`.

- [ ] **Step 7: The owner's Documents page and download.** Create `app/Livewire/Portal/Owner/Documents.php`:

```php
<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: documents staff shared with the owner (plan ruling 13). */
#[Layout('layouts::portal')]
class Documents extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.documents', [
            'documents' => Document::query()->sharedWithOwner($this->ownerId())->latest('id')->get(),
        ])->title(__('Documents'));
    }
}
```

  Create `resources/views/livewire/portal/owner/documents.blade.php`:

```blade
<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Documents') }}</flux:heading>
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($documents as $document)
            <li wire:key="doc-{{ $document->id }}" class="flex flex-wrap items-center justify-between gap-2 p-4">
                <flux:link :href="route('portal.owner.documents.download', $document)" class="min-w-0 truncate">{{ $document->original_name }}</flux:link>
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

  In `app/Http/Controllers/Portal/OwnerDownloadController.php`, add `use App\Models\Document;` and `use Illuminate\Support\Facades\Storage;`, and add after `statement()`:

```php
    /** Spec §4: a document shared with the owner on them, their contracts or their buildings; anything else is a 404. */
    public function document(string $document): Response
    {
        $account = $this->account();
        $model = Document::query()->sharedWithOwner((int) $account->owner_id)->findOrFail($document);
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }
```

- [ ] **Step 8: Routes, nav, Arabic.**
  - In `routes/portal.php` replace `            Route::livewire('/', Portal\Owner\Home::class)->name('home');` with:

```php
            Route::livewire('/', Portal\Owner\Home::class)->name('home');
            Route::livewire('documents', Portal\Owner\Documents::class)->name('documents');
            Route::get('documents/{document}', [OwnerDownloadController::class, 'document'])->whereNumber('document')->name('documents.download');
```

  - In `resources/views/layouts/portal.blade.php` replace `        ['portal.owner.contracts', __('Contracts')],` with:

```php
        ['portal.owner.contracts', __('Contracts')],
        ['portal.owner.documents', __('Documents')],
```

  - In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Share with owner": "مشاركة مع المالك",
    "Shared with owner": "مشارك مع المالك",
    "Only documents on an owner, an owner contract or a building can be shared with the owner.": "يمكن مشاركة مستندات المالك أو عقده أو المبنى فقط مع المالك.",
```

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/Portal tests/Feature/Documents`
Expected: PASS. That includes `StatementAndDocumentsTest` on the new action. Its building panel still offers no "Share with tenant".

- [ ] **Step 10: Gates and commit.**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.

```bash
git add app/Actions/Documents app/Models/Document.php app/Livewire/Documents/Panel.php resources/views/livewire/documents/panel.blade.php app/Livewire/Portal/Owner/Documents.php resources/views/livewire/portal/owner/documents.blade.php app/Http/Controllers/Portal/OwnerDownloadController.php routes/portal.php resources/views/layouts/portal.blade.php lang/ar.json tests/Feature/Portal/OwnerDocumentsTest.php tests/Feature/Portal/StatementAndDocumentsTest.php tests/Feature/Portal/IsolationTest.php
git commit -m "$(cat <<'EOF'
Owner portal documents: Share with owner on owner, contract and building documents through one ShareDocument action; the owner's documents and scoped download

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Isolation sweep extended to owner routes (owner against owner, tenant-only against owner pages and the reverse, staff against both), Arabic labels, and the full suite

**Files:**
- Modify: `tests/Feature/Portal/IsolationTest.php`, `tests/Feature/Portal/ArabicTest.php`

**Interfaces:**
- Consumes: every owner route and component from Tasks 2 and 5–8, `portalOwnerFixture()`, `ShareDocument`, `StoreDocument`, the staff routes `owners.*`, `owner-contracts.show`, `owner-statements.*`, `disbursements.show`, `documents.download`.
- Produces: no production code. A failure here is a bug in the task that owns the route. Fix it there and re-run this file.

- [ ] **Step 1: Extend `tests/Feature/Portal/IsolationTest.php`.**
  - Add to the imports:

```php
use App\Models\OwnerContract;
```

  - Add after the `isolationTenant()` function:

```php
/** An owner with one of everything the owner portal shows, plus a document shared with them (isolation tests). */
function isolationOwner(string $name, string $code, User $admin): array
{
    $owner = portalOwnerFixture($name, $code, $admin);
    $document = app(StoreDocument::class)->handle($admin, $owner['owner'], UploadedFile::fake()->create("deed-{$code}.pdf", 10, 'application/pdf'), DocumentCategory::Other);
    app(ShareDocument::class)->handle($admin, $document, 'owner', true);

    return [...$owner, 'document' => $document, 'markers' => [...$owner['markers'], "deed-{$code}.pdf"]];
}

/** Every owner-portal page and download, with $o's own ids. */
function isolationOwnerUrls(array $o): array
{
    return [
        route('portal.owner.home'), route('portal.owner.buildings'), route('portal.owner.statements'), route('portal.owner.statements.pdf', $o['statement']),
        route('portal.owner.payments'), route('portal.owner.expenses'), route('portal.owner.maintenance'), route('portal.owner.maintenance.show', $o['request']),
        route('portal.owner.contracts'), route('portal.owner.documents'), route('portal.owner.documents.download', $o['document']),
    ];
}

/** @return list<class-string> the owner-portal page components that take no mount argument */
function isolationOwnerComponents(): array
{
    return [Portal\Owner\Home::class, Portal\Owner\Buildings::class, Portal\Owner\Statements::class, Portal\Owner\Payments::class,
        Portal\Owner\Expenses::class, Portal\Owner\Maintenance::class, Portal\Owner\Contracts::class, Portal\Owner\Documents::class];
}
```

  - At the end of `beforeEach`, add:

```php
    $this->oa = isolationOwner('Ali Owner', '303', $this->admin);
    $this->ob = isolationOwner('Badr Owner', '404', $this->admin);
```

  - Replace the last test (`portal components expose no public method that takes a customer id`) with:

```php
test('portal components expose no public method that takes a customer or owner id', function () {
    $allowed = ['render', 'mount', 'sendCode', 'signIn', 'startAgain', 'save'];

    foreach (['App\\Livewire\\Portal\\' => glob(app_path('Livewire/Portal/*.php')), 'App\\Livewire\\Portal\\Owner\\' => glob(app_path('Livewire/Portal/Owner/*.php'))] as $namespace => $files) {
        expect($files)->not->toBeEmpty();
        foreach ($files as $file) {
            $class = $namespace.basename($file, '.php');
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || realpath((string) $method->getFileName()) !== realpath($file)) {
                    continue; // inherited, or from a trait such as WithFileUploads
                }
                expect($method->getName())->toBeIn($allowed);
                foreach ($method->getParameters() as $parameter) {
                    expect(strtolower($parameter->getName()))->not->toContain('customer')->not->toContain('owner');
                }
            }
        }
    }
});
```

  - Add these tests at the end of the file:

```php
test('owner A sees none of owner B\'s records on any owner page', function () {
    $this->actingAs($this->oa['account'], 'tenant');

    foreach ([...array_filter(isolationOwnerUrls($this->oa), fn (string $url) => ! str_ends_with($url, '/pdf') && ! str_contains($url, '/documents/'))] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach ($this->ob['markers'] as $marker) {
            expect($html)->not->toContain($marker);
        }
    }
    // A sees their own, so the pages above were not simply empty.
    $this->get(route('portal.owner.statements'))->assertSee('OS-303');
    $this->get(route('portal.owner.buildings'))->assertSee('Renter 303');
    $this->get(route('portal.owner.documents'))->assertSee('deed-303.pdf');
});

test('every one of owner B\'s ids is a 404 for owner A, and so are made-up ids', function () {
    $this->actingAs($this->oa['account'], 'tenant');

    foreach ([
        route('portal.owner.statements.pdf', $this->ob['statement']), route('portal.owner.documents.download', $this->ob['document']),
        route('portal.owner.maintenance.show', $this->ob['request']),
        '/portal/owner/statements/999999999/pdf', '/portal/owner/statements/abc/pdf', '/portal/owner/documents/99999999999999999999999',
        '/portal/owner/maintenance/-1', '/portal/owner/maintenance/1e3',
    ] as $url) {
        $this->get($url)->assertNotFound();
    }

    Livewire::actingAs($this->oa['account'], 'tenant')->test(Portal\Owner\MaintenanceShow::class, ['maintenance' => (string) $this->ob['request']->id])->assertNotFound();
});

test('owner pages refuse a tenant-only account', function () {
    $this->actingAs($this->a['account'], 'tenant');

    foreach (isolationOwnerUrls($this->oa) as $url) {
        $this->get($url)->assertNotFound();
    }
    foreach (isolationOwnerComponents() as $component) {
        Livewire::actingAs($this->a['account'], 'tenant')->test($component)->assertNotFound();
    }
});

test('tenant pages refuse an owner-only account; /portal sends it to the owner home', function () {
    $a = $this->a;
    $this->actingAs($this->oa['account'], 'tenant');

    $this->get(route('portal.home'))->assertRedirect(route('portal.owner.home'));
    foreach ([
        route('portal.agreements'), route('portal.agreements.pdf', $a['agreement']), route('portal.invoices'), route('portal.invoices.pdf', $a['invoice']),
        route('portal.payments'), route('portal.payments.receipt', $a['payment']), route('portal.cheques'), route('portal.statement'),
        route('portal.statement.pdf', ['from' => '2026-01-01', 'to' => '2026-10-18']), route('portal.documents'), route('portal.documents.download', $a['document']),
        route('portal.maintenance'), route('portal.maintenance.create'), route('portal.maintenance.show', $a['request']),
    ] as $url) {
        $this->get($url)->assertNotFound();
    }
    foreach ([Portal\Home::class, Portal\Agreements::class, Portal\Invoices::class, Portal\Payments::class, Portal\Cheques::class,
        Portal\Statement::class, Portal\Documents::class, Portal\Maintenance::class, Portal\NewMaintenanceRequest::class] as $component) {
        Livewire::actingAs($this->oa['account'], 'tenant')->test($component)->assertNotFound();
    }
});

test('a staff session opens no owner page, and owner components refuse a staff user', function () {
    $this->actingAs($this->admin);

    foreach (isolationOwnerUrls($this->oa) as $url) {
        $this->get($url)->assertRedirect(route('portal.login'));
    }
    foreach (isolationOwnerComponents() as $component) {
        Livewire::actingAs($this->admin)->test($component)->assertForbidden();
    }
});

test('an owner session opens no staff page', function () {
    $o = $this->oa;
    $this->actingAs($o['account'], 'tenant');

    foreach ([
        route('dashboard'), route('owners.index'), route('owners.edit', $o['owner']), route('owner-contracts.show', $o['managed']),
        route('owner-statements.show', $o['statement']), route('owner-statements.pdf', $o['statement']), route('disbursements.show', $o['payout']),
        route('documents.download', $o['document']), route('admin.audit'),
    ] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
    expect(OwnerContract::query()->portalFor($o['owner']->id)->count())->toBe(2); // the fixture's two contracts, so the routes above had real ids
});
```

- [ ] **Step 2: Shared owner labels have Arabic.** In `tests/Feature/Portal/ArabicTest.php`:
  - add to the imports:

```php
use App\Enums\DisbursementMethod;
use App\Enums\ExpenseCategory;
use App\Enums\OwnerContractStatus;
use App\Models\OwnerContract;
```

  - in the second test's `$labels` list, after the line `'Building common area — :building', // LogMaintenanceRequest::portalPlaces()` add:

```php
        ...array_map(fn (OwnerContractStatus $s) => $s->label(), OwnerContract::ATTRIBUTED), // owner Contracts page
        ...array_map(fn (DisbursementMethod $m) => $m->label(), DisbursementMethod::cases()), // owner Payments page
        ...array_map(fn (ExpenseCategory $c) => str($c->value)->headline()->toString(), ExpenseCategory::cases()), // owner Expenses page
```

- [ ] **Step 3: Run the two files.**

Run: `php artisan test tests/Feature/Portal/IsolationTest.php tests/Feature/Portal/ArabicTest.php`
Expected: PASS. If a case fails, the bug is in the task that owns that route, component or string. Fix it there, re-run that task's test file, then these.

- [ ] **Step 4: Full suite and gates.** Run these serially:
  - `php artisan test`. Everything passes. That includes:
    - `tests/Feature/Integrity`: still 61 triggers (`EXPECTED_TRIGGERS` unchanged).
    - `tests/Concurrency/VendorsMigrationTest.php`: now rolls back the owner portal migration first.
    - `tests/Feature/Reports`: the rent roll on `Queries::rentRollUnits`.
    - `tests/Feature/OwnerStatements`: the staff PDF on `OwnerStatementPdf::response`.
    - `tests/Feature/Owners` and `tests/Feature/Customers`: the email sync.
    - `tests/Feature/Documents`: `ShareDocument`.
  - `php vendor/bin/pint --dirty`
  - `php vendor/bin/phpstan analyse --memory-limit=2G`
  - `npm run build`

- [ ] **Step 5: Commit.**

```bash
git add tests/Feature/Portal/IsolationTest.php tests/Feature/Portal/ArabicTest.php
git commit -m "$(cat <<'EOF'
Portal isolation sweep for owners: owner against owner on every owner route, tenant-only and owner-only against the other side, staff against owner routes, no owner ids on public Livewire methods

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (done while writing)

- **Spec coverage:**
  - §1 purpose: every listed view has a page. Buildings and Contracts are in Task 5, Statements, Payments and Expenses in Task 6, Home and Maintenance in Task 7, Documents in Task 8. View only: no owner write path exists. Out of scope stays out: no approvals, requests or messages from owners, no self-service details, no automatic statement email, no contract PDF, no design pass.
  - §2 approach: one account and guard (Task 1). Owner and tenant access checks (`PortalAccess`, Task 2). Every owner query through `owner_id` and their contracts (`OwnerPortal`, Task 5).
  - §3 accounts:
    - nullable `customer_id`, unique `owner_id` and the CHECK (Task 1);
    - invite, re-send, disable and enable on the owner page with `owners.manage`, an email and mail (Task 3);
    - linking (Task 3, ruling 5) and email changes (Task 4, ruling 7);
    - disable on the whole account from either page, with "Also used by" (Task 3);
    - deleted records (Tasks 1 and 2);
    - landing and the switch (Task 2, ruling 4);
    - 404 access checks (Task 2);
    - audits naming the record (Task 3).
  - §4 pages:
    - "their contracts" (ruling 8);
    - Home (Task 7, ruling 15);
    - Buildings, managed with the rent roll and without contact details, leased with units only (Task 5);
    - Statements, finalised only, with the figures and the staff PDF (Task 6);
    - Payments, paid payouts with the IBAN masked and the lease schedule (Task 6);
    - Expenses, charged to the owner on their contracts, reversed marked, no notes (Task 6);
    - Maintenance with the tenant timeline and the cost (Task 7);
    - Contracts (Task 5);
    - Documents with "Share with owner" and the checked download (Task 8);
    - EN/AR with RTL (Tasks 2 and 6–7, `ArabicTest`).
  - §5 tables: Task 1. No money table and no trigger is touched.
  - §6 safety: scoped queries and 404s (Tasks 5–8, swept in Task 9). No ids on public Livewire methods (Task 9 reflection). The sides refuse each other and staff refuse portal sessions (Tasks 2 and 9). PDF and document downloads are audited with the account (Tasks 6 and 8). Tampered input (Task 8 dataset, Task 7 and 9 ids).
  - §7 testing: each bullet maps to a file:
    - invites: `OwnerInviteTest`;
    - linking, the shared email change and disable-ends-both: `OwnerInviteTest` and `PortalEmailTest`;
    - landing and the switch: `OwnerAccessTest`;
    - a deleted owner and signing out: `OwnerAccessTest`;
    - isolation: `IsolationTest`, `OwnerScopeTest`, `OwnerMoneyTest`, `OwnerHomeAndMaintenanceTest`, `OwnerDocumentsTest`;
    - leased, contacts, finalised, IBAN and drafts: `OwnerScopeTest` and `OwnerMoneyTest`;
    - Arabic, dd/mm/yyyy and 3 dp: `OwnerAccessTest`, `OwnerMoneyTest`, `OwnerHomeAndMaintenanceTest`.
- **Spec ambiguities ruled on:**
  - Owner statements are left out of "Share with owner" (ruling 13). The statement PDF is on the Statements page.
  - Disbursements keep no bank snapshot. The owner's bank, masked, is shown once above the payouts (ruling 12).
  - "Requests in their buildings" is limited to the dates of their contracts on that building (ruling 9).
  - The statement row adds "Paid to you", so that opening, income, charges and fees add up to closing (ruling 11).
  - "Change both together": a shared account moves email in three saves, because clearing one side is allowed while the other keeps the email (ruling 7).
  - Linking to a disabled account is refused rather than silently re-enabling the other side (ruling 5).
  - An owner-only account at `/portal` is redirected, not given a 404 (ruling 3).
  - The rent roll shows only while a managed contract is active today. An ended managed contract shows units only (ruling 9).
  - The sign-in heading and the code email's Arabic greeting become neutral (rulings 4 and 16).
- **Placeholder scan:** none. Every step has its code or its exact command.
- **Type consistency:**
  - `portalAccount(Customer|Owner, array)` and `portalOwnerFixture(string, string, User)` are the same in every test.
  - `PortalAccess::VIEW`, `PortalAccess::homeRoute(mixed)` and `InPortal::customerId()/customer()/ownerId()/owner()` are the same in Tasks 2 and 5–8.
  - `InviteToPortal::handle(User, Customer|Owner)` and `::takenMessage(Customer|Owner)` are the same in Tasks 3 and 4.
  - `SetPortalAccess::handle(User, Customer|Owner, bool)` is the same in Task 3.
  - `SyncPortalEmail::problem(Customer|Owner|null, ?string)` and `::apply(Customer|Owner)` are the same in Task 4.
  - `OwnerContract::portalFor(int)` is the same in Tasks 5, 7, 8 and 9.
  - `Queries::rentRollUnits(Builder, string)` is the same in Task 5.
  - The `OwnerPortal::statements/payouts/payables/expenses/requests(int)`, `costFils(MaintenanceRequest, int)`, `summary(OwnerStatement)` and `buildings(int)` signatures are the same in Tasks 5–7.
  - `OwnerStatementPdf::response(OwnerStatement)` is the same in Task 6.
  - `ShareDocument::handle(User, Document, string, bool)` is the same in Tasks 8 and 9.
  - `Document::sharedWithOwner(int)` and `Document::SHAREABLE['tenant'|'owner']` are the same in Task 8.
  - Routes: `portal.owner.{home, buildings, contracts, statements, statements.pdf, payments, expenses, maintenance, maintenance.show, documents, documents.download}`.
- **Fixture arithmetic** (`portalOwnerFixture`, "today" 21/10/2026):
  - The September statement's window holds the opening charge +500.000 (05/09), the leak repair −50.000 (10/09), the touch-up −20.000 (12/09) and its reversal +20.000 (13/09). With a fixed fee of 0.000, the closing is 450.000, income 500.000 and charges 50.000.
  - The remittance DSB-…-1 posts on 05/10, after the cutoff, so "Paid to you" is 0.000.
  - The last payout is DSB-…-1 at 450.000 on 05/10/2026. The head lease DSB-…-2 is dated 02/10/2026.
  - The next lease payment is November, 300.000, due 01/11/2026.
  - Both contracts end 31/12/2026, 71 days after 21/10/2026, inside 90.
  - The Tower rent roll is unit :code (Renter :code, 400.000 a month, 400.000 owed) and unit V:code vacant, so "1 of 2 units let".
  - The request's cost is the 50.000 leak repair. The reversed touch-up is linked to no work order.
