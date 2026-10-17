# Maintenance requests and work orders — design

**Status:** approved in conversation, 17 October 2026. Rank 5 of the Buildium comparison.

## 1. Purpose

Log maintenance problems reported by tenants or staff, send the work to an outside vendor or to the company's own
staff, follow it to completion, and record its cost as an expense through the existing expense rules.

**Out of scope:** tenant self-service requests (comes with the tenant portal); paying vendors through Payments out
(later — vendors are not yet a payee type); preventive / recurring maintenance schedules; vendor logins; quotes and
bidding; SLA timers.

## 2. Approach

Requests and work orders are their own records (not money records — no triggers). Completing a work order with a cost
calls the existing `App\Actions\Expenses\RecordExpense`, so every expense rule — owner approval note and contract,
tenant charge invoice, VAT, posting lock — applies unchanged. The link is stored on the work order
(`work_orders.expense_id`); the locked `expenses` table is not changed.

Rejected: keeping the cost on the work order and teaching owner statements, tenant charges and reports a second source.

## 3. Records

### 3.1 Vendors (`vendors`)
name (≤150), type `company` | `individual` | `own_staff`, trades (JSON list of trade keys, ≥1), mobile, email
(nullable), CR number (nullable), VAT number (nullable), notes, active (bool). Own staff are listed here, so no app
login is needed. Inactive vendors cannot get new work orders. Soft deletes.

**Trades** (fixed list, also the request category): `plumbing`, `electrical`, `ac`, `carpentry`, `painting`,
`cleaning`, `pest_control`, `general`.

### 3.2 Maintenance requests (`maintenance_requests`)
number `MR-YYYY-NNNNNN` (new `NumberSequenceKey::MaintenanceRequest`), building, unit (nullable — common areas),
customer (nullable; prefilled from the unit's current agreement, editable), reported_by_name (≤150), channel `phone` |
`whatsapp` | `walk_in` | `inspection` | `email`, category (trade key), priority `urgent` | `high` | `normal` | `low`,
description (≤2000), entry_permitted (bool), status `open` | `in_progress` | `resolved` | `cancelled`, resolved_at,
cancel_reason, created_by. Photos use the existing documents (`documentable`), category "maintenance".

### 3.3 Work orders (`work_orders`)
number `WO-YYYY-NNNNNN` (`NumberSequenceKey::WorkOrder`), maintenance_request, vendor, scheduled_on (nullable date),
instructions (≤2000), estimate (DECIMAL 12,3, nullable), status `assigned` | `in_progress` | `completed` |
`cancelled`, completed_at, completion_note (≤2000), actual_net and actual_tax (DECIMAL 12,3, nullable), expense_id
(nullable, unique), cancel_reason, created_by. After-photos as documents.

### 3.4 Timeline (`maintenance_events`)
One row per status change or note on a request or its work orders: request, work_order (nullable), kind
(`status` | `note`), from / to status, note (≤1000), user, created_at. Also audited.

## 4. Flow

1. **Log a request** (maintenance.manage). Urgent requests appear on the dashboard (count + list). If the request has
   a tenant with an email and the tenant has not opted out of reminders, a bilingual "request received" email is sent
   (same layout and safety as tenant reminders; nothing sent when mail is not set up).
2. **Work orders.** Add one or more work orders, each to an active vendor whose trades include the request's category
   or `general`. Adding the first moves the request to `in_progress`. Each work order has a printable sheet (address,
   unit, tenant name and mobile, entry permission, instructions, scheduled date — no amounts) and a "Copy for WhatsApp"
   text with the same content.
3. **Progress.** Work order status changes are recorded in the timeline. Cancelling needs a reason.
4. **Complete.** Completing asks for the completion note and the actual cost (net + VAT; VAT defaults from the company
   rate when VAT-registered). With a cost > 0, the **Record expense** form opens prefilled — building, unit, category
   (trade → expense category: cleaning → `cleaning`, all others → `maintenance`), description "WO-… · vendor · request
   summary", expense date = completion date, net, VAT — and the user chooses who pays (company / owner / tenant) as on
   the Expenses screen. Saving runs `RecordExpense` (needs `expenses.manage`) and stores `expense_id`. A work order may
   be completed with no cost (warranty, own staff) without an expense. Completing and recording the expense happen in
   one transaction: if `RecordExpense` refuses, the work order stays not completed.
5. **Resolve.** When every work order is completed or cancelled (and at least one completed), the request resolves
   automatically; staff may also resolve or cancel it by hand (cancel needs a reason; cancelling a request cancels its
   open work orders). On resolve, the tenant gets a bilingual "request completed" email under the same conditions as
   step 1.

## 5. Screens

- **Sidebar group "Maintenance"** (maintenance.view): Requests, Work orders, Vendors.
- **Requests list:** tabs Open / In progress / Resolved / All with counts; filters building (`<x-building-filter>`),
  priority, category, vendor; search MR number, unit code, tenant name or mobile; urgent first, then oldest.
- **Request page:** details, photos, work orders (status, vendor, scheduled, estimate, actual), timeline with add-note,
  linked expenses with total, actions per permission.
- **Work orders list:** same filters plus status; "Overdue" badge when scheduled_on < today and not completed or
  cancelled.
- **Vendors list and form** (maintenance.manage to edit): filter by trade, type, active; each vendor shows its work
  orders and total cost.
- **Unit and tenant pages:** a "Maintenance" section listing their requests.
- **Reports (Reports page, Operational):** "Open maintenance" (number, building, unit, category, priority, age in
  days, vendor) and "Maintenance cost" (by building / unit / category, from the linked expenses, net or total per the
  VAT rule used by Building profitability), both with Excel export audited as `report.exported`.

## 6. Permissions

New permissions `maintenance.view` (Admin, Management, Property manager, Leasing) and `maintenance.manage` (Admin,
Property manager). Recording the cost also needs `expenses.manage`. Building scope applies to requests, work orders
and reports; vendors are company-wide. Vendor Support (the software vendor's support role) keeps its current rule of
everything except decisions and finance management.

## 7. Safety

Requests and work orders read and link money records but only create them through `RecordExpense`. Tampered Livewire
input gives a validation message, never a 500; public Livewire methods check permission and building scope. All
strings through `__()`; UI says "tenant"; dates dd/mm/yyyy; money BHD 3 dp, never floats. `EXPECTED_TRIGGERS`
unchanged.

## 8. Testing

- Vendors CRUD and validation; inactive vendors refused for new work orders; trade match.
- Request lifecycle: open → in progress on first work order → auto-resolve; manual resolve / cancel cascades; reasons
  required; timeline rows and audits.
- Completion with cost: prefilled expense, `RecordExpense` called with the chosen payer (company, owner with contract
  and note, tenant → tenant invoice); refusal rolls back completion; no-cost completion; `expense_id` unique.
- Building scope and permissions on every list, page, action and report; Leasing can view, not manage.
- Emails: received / completed sent once, bilingual, skipped for no email, opted out, mail not set up.
- Work order sheet and WhatsApp text contain no amounts; overdue badge.
- Reports: open maintenance ages; cost report equals the sum of linked expenses and matches Building profitability's
  expense rule; Excel audited.
- Tampered input on every screen.
