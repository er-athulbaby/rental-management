# Owner portal — design

**Status:** approved in conversation, 21 October 2026. Rank 7 of the Buildium comparison. Builds on the tenant portal
(`2026-10-18-tenant-portal-design.md`); everything said there about sign-in, sessions, rate limits, audit and safety
applies unless changed here.

## 1. Purpose

Let owners see their buildings, statements, payouts, expenses, maintenance and shared documents without calling the
office. **View only.**

**Out of scope:** owners approving spend, raising requests or messaging the office; owners changing their own details;
emailing statements automatically; an owner contract PDF (none exists today); the visual design pass (later, for both
portals).

## 2. Approach

One portal, two kinds of access. A `portal_accounts` row belongs to a tenant, an owner, or both (one person, one email,
one sign-in). Sign-in, codes, rate limits, remember-me, the session stamp, locale and the staff/portal separation are
reused unchanged. Owner pages are a new route group behind an `owner` access check; tenant pages get a matching
`tenant` access check. Every owner query starts from the signed-in account's `owner_id` and goes through that owner's
contracts.

Rejected: a separate `/owner` portal with its own guard and accounts (duplicates the security-sensitive sign-in code).

## 3. Accounts and access

- **`portal_accounts`:** `customer_id` becomes nullable; add `owner_id` (nullable, unique, FK to owners, no cascade —
  owners are soft-deleted). CHECK: at least one of `customer_id`, `owner_id` is set.
- **Invite (owner page):** staff with `owners.manage` see the same box tenants have: **Invite to portal** (only when the
  owner has an email and mail is set up), **Re-send invite**, **Disable** / **Enable**.
- **Linking:** if the email being invited already belongs to a portal account for the other kind (a tenant account when
  inviting an owner, or the reverse), the invite links the record to that account instead of creating one. That
  person sees both menus.
- **Email changes:** changing a tenant's or owner's email updates the account email when the account is linked to that
  record only. When the account is shared and the new email differs from the other record's email, the save is refused
  with "This email is also the portal sign-in for :name; change both together."
- **Disable / Enable:** acts on the whole account (both menus), from either page; ends remembered devices and live
  sessions (as today). The box says "Also used by owner :name" / "Also used by tenant :name" when shared.
- **Deleted records:** a soft-deleted owner loses owner access; a soft-deleted tenant loses tenant access. The account
  stays signed in while at least one linked record is active; otherwise the session ends (as today).
- **After sign-in:** tenant-only accounts land on the tenant home, owner-only accounts on the owner home. Shared
  accounts get a **Tenant / Owner** switch in the header; the choice is kept in the session.
- **Access checks:** owner pages give 404 to accounts without an active owner; tenant pages give 404 to accounts without
  an active tenant.
- **Audit:** `portal.invited`, `portal.disabled`, `portal.enabled` record which record (tenant or owner) the action was
  taken on; `portal.login` / `portal.logout` unchanged.

## 4. Owner pages (EN / AR with RTL, mobile first, view only)

"Their contracts" = the owner's owner contracts with status active, ended or terminated (never draft or pending
approval). "Their buildings" = the buildings of those contracts.

- **Home:** balance owed to the owner (closing balance of the latest finalised statement, per managed contract); last
  payout and next lease payment due; occupancy per building; contracts ending within 90 days.
- **Buildings:** one card per building.
  - **Managed contract:** occupancy and rent roll — unit, tenant name, agreement dates, monthly rent, balance owed, and
    vacant units. No tenant phone, email or ID number.
  - **Leased contract:** building and units only, no tenant or rent information.
- **Statements:** finalised owner statements only; period, opening balance, income, fees, charges, closing balance; the
  same PDF staff download.
- **Payments:** paid disbursements to the owner — date, amount, method, reference, bank with IBAN masked (last 4).
  For leased contracts, the rent schedule (owner payables): due date, amount, paid or not.
- **Expenses:** expenses charged to the owner (`charge_to` owner, on their contracts) — date, building, unit,
  category, description, amount; reversed ones marked reversed. No internal notes.
- **Maintenance:** requests in their buildings — number, unit, category, status, dates, the tenant-portal timeline, and
  any cost charged to the owner. No staff notes.
- **Contracts:** number, building, type (managed / leased), dates, status.
- **Documents:** new **Share with owner** flag (`documents.shared_with_owner`, default false) on documents attached to
  the owner, their contracts, their statements or their buildings. Staff toggle it in the documents panel (same rules
  as Share with tenant). Downloads go through a portal route that checks ownership and the flag.

## 5. Changes to existing tables

- `portal_accounts.customer_id` nullable; `portal_accounts.owner_id` (nullable, unique FK); CHECK at least one set.
- `documents.shared_with_owner` (bool, default false).
- No money tables touched; `EXPECTED_TRIGGERS` unchanged.

## 6. Safety

- Every owner query is scoped from `auth('tenant')->user()->owner_id` through the owner's contracts. A record id that
  is not theirs gives 404. Public Livewire methods never accept an owner or customer id.
- Owner pages refuse tenant-only accounts and the reverse; staff routes still refuse portal sessions.
- Statement PDF and document downloads audited with the account as causer.
- Tampered input gives a validation message, never a 500. All strings through `__()`.

## 7. Testing

- Owner invite / re-send / disable / enable: permissions, audits, no invite without email or mail.
- Linking: same email links owner and tenant to one account; shared-account email change refused; disable ends both.
- Sign-in landing for tenant-only, owner-only and shared accounts; the Tenant / Owner switch.
- Soft-deleted owner loses owner access; account with no active record is signed out.
- Isolation: owner A never sees or downloads owner B's statement, payout, payable, expense, request, contract,
  document or rent roll (tampered ids → 404); owner pages refuse tenant-only accounts and the reverse; the existing
  isolation sweep is extended to owner routes.
- Leased buildings show no rent roll; the rent roll shows no tenant contact details; only finalised statements; IBAN
  masked; draft and pending contracts hidden.
- Arabic / RTL; dd/mm/yyyy; amounts BHD 3 dp.
