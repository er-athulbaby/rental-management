# Tenant portal — design

**Status:** approved in conversation, 18 October 2026. Rank 6 of the Buildium comparison.

## 1. Purpose

Let tenants see their own agreements, invoices, receipts, cheques, statement and shared documents, and raise and
follow maintenance requests, without calling the office.

**Out of scope:** online payment (rank 8, needs a gateway); tenants changing their own contact details; SMS or WhatsApp
sign-in codes (later); staff "view as tenant"; owner portal (rank 7); push notifications.

## 2. Approach

A separate `tenant` auth guard at `/portal` with its own `PortalAccount` model, session and layout. Tenants are never
staff `User`s, so staff permissions, building scope and staff routes stay untouched. Portal pages reuse the existing PDF
generators (invoice, receipt, agreement, tenant statement), the document store and `LogMaintenanceRequest`; every portal
query starts from the signed-in account's customer.

Rejected: tenants as `User`s with a role (shares staff routes and permission checks; one slip exposes staff screens).

## 3. Accounts and sign-in

- **`portal_accounts`:** id, customer_id (unique, FK, no cascade — customers are soft-deleted), email (≤255, unique),
  locale `en` | `ar` (default `en`), invited_at, invited_by (user), last_login_at, disabled_at, timestamps.
- **`portal_login_codes`:** id, portal_account_id, code_hash, expires_at, used_at, attempts (int), created_at. A new code
  replaces any unused one for that account.
- **Invite:** on the tenant page, staff with `customers.manage` see **Invite to portal** (only when the tenant has an email
  and mail is set up), **Re-send invite** and **Disable** / **Enable**. Invite creates or re-enables the account with the
  tenant's current email and sends a bilingual invite email linking to `/portal/login`. Changing the tenant's email on the
  tenant form updates the account's email. Audited: `portal.invited`, `portal.disabled`, `portal.enabled`.
- **Sign-in:** the tenant enters an email → if an active account exists for an active (not soft-deleted) tenant, a
  6-digit code is emailed; the screen always says "If this email has an account, we've sent a code". The code is valid
  10 minutes, once; a code allows 5 attempts, then it is spent. Rate limits: 5 code requests per email per hour and 20
  per IP per hour; 10 code checks per IP per minute. Codes are stored hashed.
- **Session:** `tenant` guard, separate session key; "remember this device" for 30 days (default on); **Sign out**. Each
  request re-checks the account is not disabled and the tenant not soft-deleted — otherwise the session ends.
- **Mail not set up** (same check as reminders): invite and sign-in show "Portal email is not set up".
- Audited: `portal.login` (account as causer, IP), `portal.logout`.

## 4. Pages (bilingual EN / AR with RTL, mobile first)

- **Home:** balance owed and credit (the existing customer figures), next due invoice, upcoming held cheques, open
  maintenance requests, agreements ending within 90 days.
- **Agreements:** list (number, units, dates, monthly rent, status) and agreement PDF download.
- **Invoices:** tabs Unpaid / Paid / All; number, issue date, due date, total, paid, balance; invoice PDF.
- **Payments:** receipts with receipt PDF.
- **Cheques:** received cheques: number, bank, date, amount, status.
- **Statement:** date range (dd/mm/yyyy), the existing tenant statement on screen and as PDF.
- **Documents:** only documents with the new **Share with tenant** flag (`documents.shared_with_tenant`, default false)
  attached to the tenant, their agreements or their invoices. Staff toggle it in the documents panel. Download through a
  portal route that checks ownership and the flag.
- **Maintenance:** their requests (number, unit, category, status, dates) and a tenant timeline — received, work
  assigned (no vendor names), completed / cancelled; **no** amounts, estimates, vendor details or staff notes.
- **New maintenance request:** unit = one of the tenant's units on a current agreement, or "Building common area" (a
  building of a current agreement); category; description (≤2000); entry permitted; up to 5 photos (jpg, png, webp,
  ≤10 MB each). Saved through `LogMaintenanceRequest` with channel `portal` (added to the channel CHECK), customer =
  the tenant, reported_by_name = the tenant's name, priority `normal`. The "received" email is sent as for staff
  requests. Staff see a "Portal" badge. Rate limit: 10 requests per account per day.

## 5. Changes to existing tables

- `maintenance_requests.created_by` becomes nullable; add `portal_account_id` (nullable FK). A CHECK requires exactly
  one of them.
- `documents.uploaded_by` becomes nullable; add `portal_account_id` (nullable FK); CHECK exactly one.
- `documents.shared_with_tenant` (bool, default false).
- No money tables touched; `EXPECTED_TRIGGERS` unchanged.

## 6. Safety

- Every portal query is scoped from `auth('tenant')->user()->customer_id`. A record id in a URL that does not belong to
  the tenant returns 404. Public Livewire methods on portal components never accept a customer id.
- Portal routes use only the `tenant` guard; staff routes reject the `tenant` guard; staff and tenant sessions are
  independent.
- Portal sign-in, PDF downloads, document downloads and maintenance requests are audited with the portal account as
  causer.
- Tampered input gives a validation message, never a 500. All strings through `__()`; Arabic where the tenant chose it.
- Security headers as the staff app; CSRF on all forms; codes compared in constant time.

## 7. Testing

- Invite / re-send / disable / enable permissions and audits; no invite without email or with mail not set up.
- Sign-in: code flow, expiry, single use, attempt limit, rate limits, no account enumeration, disabled and soft-deleted
  tenants refused and logged out, hashed codes.
- Isolation: tenant A can never see or download tenant B's agreement, invoice, receipt, cheque, statement, document or
  request (tampered ids → 404); staff routes refuse tenant sessions and vice versa.
- Documents: only shared ones visible; sharing toggled by staff.
- Maintenance from portal: allowed units only; photos validated; channel portal; email sent; badge; rate limit; no
  vendor names or amounts in the tenant view.
- Arabic / RTL rendering; dd/mm/yyyy; amounts BHD 3 dp.
