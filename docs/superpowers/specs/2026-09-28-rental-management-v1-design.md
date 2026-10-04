# Rental Management System — v1 Design Spec

- **Date:** 2026-09-28 (revision 2, after independent review)
- **Status:** Draft for review
- **Input:** client SRS "Rental Management System — Detailed Software Requirements & Development Scope" (70 sections), reduced to v1 through a section-by-section design review, then corrected by a four-way review (stack facts, finance logic, consistency, security).
- **Team assumed:** 2 developers (1 senior Laravel backend, 1 mid full-stack).

---

## 0. Summary

A Laravel + Livewire rental management **product** for Bahrain property management companies. Each company gets **its own installation** (own server, own database). All companies run the same codebase; differences between companies live only in settings and templates.

v1 lets a company run its whole rental business:

- buildings that are **owned**, **leased from an owner**, or **managed for an owner**, in any mix;
- **multi-unit agreements**, with bilingual English/Arabic contract PDFs;
- rent invoicing, post-dated cheques, payments, deposits;
- payments to owners, owner statements, building profitability;
- reports, a management dashboard, approvals, and an append-only audit trail enforced by database triggers (§8.5);
- import of each company's existing tenants and balances at go-live.

**Estimate:** v1 ≈ 17.5 weeks. v2 ≈ 10 weeks. v3 ≈ 8 weeks (§15).

---

## 1. Decisions

| # | Decision | Source |
|---|---|---|
| D1 | Product installed separately for each company. Not SaaS; no multi-tenancy anywhere in the code. | Client |
| D2 | Blade + Livewire UI. No SPA, no public REST API in v1. | Client |
| D3 | VAT registration is optional per install. | Client |
| D4 | Online payment gateway deferred to v2; provider not chosen. | Client |
| D5 | Application UI in English only. Contract PDFs bilingual English + Arabic. | Client |
| D6 | Overdue = due date + grace days. No late fees. | Client |
| D7 | Management approves agreement activation. Single approval step. | Client |
| D8 | Buildings can be owned, leased from an owner, or managed for an owner — all three in v1. | Client |
| D9 | One agreement can cover several units, including units in different buildings with different owners. | Client |
| D10 | Partial payments are split pro-rata across the invoice's lines. | Client |
| D11 | The nine actions in §8.3 require Management approval. | Client |
| D12 | The vendor hosts and manages every installation. | **Assumption — confirm (C1)** |
| D13 | One codebase. No code specific to one company; differences only via settings, templates and feature switches. | Design |
| D14 | No Redis. Queue, cache and sessions use the database. | Design |
| D15 | No general ledger. Customer, deposit and owner ledgers are queries over append-only tables; journal export to accounting software is v3. | Design |
| D16 | Always the latest stable versions of Laravel, MySQL, PHP and packages. Pinned at M0 (§2); a new major released during the build is adopted at the next milestone boundary. | Client |

---

## 2. Architecture

- **Runtime (verified 2026-09-28):** Laravel 13.x (13.33), PHP 8.5, MySQL 26.7 (Innovation track), Nginx, PHP-FPM, Supervisor, Ubuntu 26.04 LTS.
  - MySQL Innovation releases are supported only until the next one ships, so each install follows every Innovation release (roughly quarterly) through the normal release process (§13.2).
  - Local development: Laravel Herd (PHP 8.5) + MySQL 26.7.
- **UI:** Livewire 4 + Tailwind CSS + **free Flux only**. Date and file fields use native `input type=date` / `type=file`, selects are native, and customer lookup is a text input with a results list (Flux's date picker, searchable select, tabs and file upload are Pro-only). Every staff screen must be usable at 375 px width — leasing staff work from phones.
- **Layering:**
  - Livewire components and controllers are thin: validate input, authorize, call one Action. Livewire model-id properties are `#[Locked]`, and actions authorize against the loaded model.
  - `app/Actions/*` — one class per business operation (`ActivateAgreement`, `RecordPayment`, `ClearCheque`, …). Each runs inside **one database transaction** (`DB::transaction(…, attempts: 3)`) and is the **only write path** for the records it changes. Actions never mass-update audited models.
  - Eloquent models hold relationships, casts and query scopes only.
  - `app/Services/*` only for external concerns: PDF rendering, mail.
  - No repository layer.
- **Background work:** `database` queue driver, `php artisan queue:work` under Supervisor; scheduler via cron `schedule:run` every minute.
- **Files:** private disk `storage/app/private/{buildings,units,owners,customers,agreements,payments,expenses,statements}/…`. Nothing under `public/`. Downloads only through `DocumentController@download` (policy check + audit entry, §9.1).
- **Money:** every monetary column is `DECIMAL(12,3)`. Money arithmetic in PHP uses **integer fils**, never floats. Rounding is half-up to the fil.
- **Packages (latest stable majors, verified 2026-09-28):** `livewire/livewire` ^4, `livewire/flux` ^2 (free), `laravel/fortify` ^1, `spatie/laravel-permission` ^8, `spatie/laravel-activitylog` ^5, `spatie/laravel-backup` ^10, `wnx/laravel-backup-restore` ^1.9, `league/flysystem-aws-s3-v3` ^3, `spatie/simple-excel` ^3, `mpdf/mpdf` ^8.3, `mpdf/qrcode` ^1, `sentry/sentry-laravel` ^4, `pestphp/pest` ^5, `larastan/larastan` ^3 (level 8), `laravel/pint` ^1.
- **Database users:** two per install (§8.5): `rms_app` for the application, `rms_migrate` for migrations only.

---

## 3. Company settings

A single-row table `company_settings`, edited by Admin unless noted:

| Group | Fields |
|---|---|
| Profile | `name_en`, `name_ar`, `cr_number`, `address_en`, `address_ar`, `phone`, `email`, `website`, `logo` |
| Tax | `vat_registered` (bool), `trn`, `vat_rate` (default 10.00), `residential_tax_category` (default `exempt`), `commercial_tax_category` (default `standard`) |
| Locale | `currency_code` (default BHD; always 3 decimals), `date_format` (default `d/m/Y`); timezone fixed to `Asia/Bahrain` |
| Billing | `default_grace_days` (default 5), `invoice_lead_days` (default 7), `proration_basis` (`actual_365` default, or `days_30`) |
| Install-level (not on the settings screen) | `require_different_approver` (default true) and `go_live_at` — set at install or cutover, changed only by the audited `php artisan rms:setting` on the server |

Document number formats live in `number_sequences` (§6.8).

---

## 4. Property and owners

### 4.1 Buildings
`buildings`: `name`, `code` (unique), `location`, `address`, `type` (residential | commercial | mixed), `floors_count`, `parking`, `facilities`, `property_manager_user_id` (informational; grants no access), `notes`, soft deletes. Photos and documents via `documents` (§9.1).

There is no "property" or "compound" level above buildings in v1 (add it when a company needs to group buildings).

### 4.2 Units
`units`: `building_id`, `code` (unique per building), `floor` (string: "G", "1", "M"…), `use` (residential | commercial), `type` (code of an Admin-managed `unit_types` row: Flat / Apartment, Villa, Shop, … each with a usual use that pre-fills `use`), `bedrooms`, `bathrooms`, `area_sqm`, `furnishing` (unfurnished | semi | furnished), `list_rent` (monthly), `list_deposit`, `list_service_charge` (monthly), `default_tax_category` (nullable → falls back to the setting for its `use`), `ewa_account_no`, `blocked` (bool), `blocked_reason`, `notes`, soft deletes.

### 4.3 Unit status (computed, never stored or typed)
Evaluated for "today" (T) from agreement units (§5.3), using `effective_end` from §5.5:

| Status | Rule |
|---|---|
| **Occupied** | An agreement unit with `start_date ≤ T ≤ effective_end` whose agreement is not draft or pending_approval |
| **Notice given** | Occupied, and that agreement unit or its agreement has a `planned_exit_date` |
| **Reserved** | Not occupied, and has an agreement unit in a `pending_approval` agreement, or an agreement unit with `start_date > T` in an active agreement |
| **Blocked** | `blocked = true` and none of the above |
| **Available** | None of the above |

An occupied unit with a future agreement shows "Occupied · next tenant from {date}".

### 4.4 Owners
`owners`: `type` (person | company), `name_en`, `name_ar`, `id_type` (cpr | passport | cr), `id_number` (unique together with `id_type`), `nationality`, `phone`, `email`, `address`, `bank_name`, `iban`, `account_name`, `bank_changed_at`, `bank_changed_by`, `notes`, soft deletes.

Bank fields are edited only with `owners.bank.manage` (seeded to Admin). For 30 days after a change, the remittance screen shows "Bank details changed {date} by {user}".

### 4.5 Owner contracts
`owner_contracts`:
- Common: `number` (OC-YYYY-NNNNNN), `owner_id`, `building_id`, `type` (leased | managed), `start_date`, `end_date`, `status` (draft | pending_approval | active | ended | terminated), `terminated_on`, `termination_reason`, `previous_contract_id`, `created_by`, `notes`.
- **Leased** (the company rents from the owner): `rent_amount` (per payment period), `payment_frequency` (monthly | quarterly | half_yearly | yearly).
- **Managed** (the company manages for the owner): `fee_type` (percent_collected | percent_billed | fixed), `fee_value` (percent, or BHD per month when fixed), `expense_approval_limit`, `deposits_held_by` (company | owner).

`owner_contract_units`: (`owner_contract_id`, `unit_id`). A whole-building contract links every unit of the building; a single-flat owner's contract links one unit.

**Owned** = a unit with no owner contract in status active, ended or terminated covering it on that date. There is no "owned" contract row.

Rules:
- A unit can be covered by **at most one** owner contract in status pending_approval or active on any date. Checked on submit and on approval: the Action locks each unit row with `SELECT … FOR UPDATE` in ascending id order as the transaction's first statement, then runs the overlap query as a locking read (`lockForUpdate()`).
- A successor contract (`previous_contract_id` set) may start before its predecessor's `end_date`; the overlap check ignores the predecessor from the successor's `start_date`.
- Approving a successor sets the predecessor's `end_date` to the day before the successor starts. Approving an early termination (§8.3 item 7, with `terminated_on` and `termination_reason`) sets `end_date = terminated_on`. **These are the only permitted changes to an active contract.** Either way, payables starting after the new `end_date` are cancelled, and a payable that straddles it is cancelled and replaced by a prorated one (§7.8).
- Activation requires Management approval (§8.3 item 7).
- Rent or fee changes, and renewals, create a **new** contract linked by `previous_contract_id`.
- The 02:15 job moves active contracts past `end_date` to `terminated` if `terminated_on` is set, else to `ended`.
- Activating a leased contract generates its payment schedule to the owner (§7.8).

### 4.6 Owner attribution (the rule that keeps statements correct)
Every invoice line gets `owner_contract_id` **stamped when the invoice is issued**: the owner contract in status active, ended or terminated whose dates cover that line's unit on the line's `period_start` — for lines without a period, on the agreement unit's `start_date` (deposit lines) or the invoice's `due_date` (others). NULL = company-owned. Allocations copy the value from their line.

- The issue job and "Issue now" **hold back** any invoice with a line whose unit has a pending_approval owner contract covering that date, until the approval is decided.
- Lines of an invoice created from a tenant-charged expense (§4.7) are always stamped NULL — the company paid the supplier.
- Owner statements, owner ledgers and building profitability read only these stored values; they never recompute attribution from a unit's current arrangement.

*Known limit:* a line is attributed to the contract covering its period start. An owner contract that starts or ends mid-period does not split an already-billed period.

### 4.7 Expenses
`expenses`: `building_id`, `unit_id` (nullable), `category`, `description`, `expense_date`, `net`, `tax_amount`, `total`, `charge_to` (company | owner | tenant), `owner_contract_id` (resolved on entry), `agreement_unit_id` (tenant charges), `owner_approval_note` + document (required when charged to an owner above the contract's `expense_approval_limit`), `status` (recorded | reversed), `posted_at`, `reversed_at`, `recorded_by`.

- `charge_to = owner` is only allowed when the unit is under a **managed** contract in status active, ended or terminated covering it on `expense_date`; it posts to that owner's ledger. With `unit_id` NULL, it is allowed only when exactly one managed contract covers the building's units on `expense_date`; otherwise enter one expense per unit.
- `charge_to = tenant` requires a unit with an active agreement unit on `expense_date` and also needs `invoices.manage`; recording it creates and issues a **manual invoice** (§6.5) to that customer, with lines stamped NULL (§4.6).
- Reversing an expense (Finance) sets `status = reversed` and `reversed_at`; the owner ledger shows an opposite entry at `reversed_at` (§7.9). A tenant-charged expense's invoice is corrected only by a credit note.
- Paying the supplier is outside v1 (it stays in the company's accounting system); vendors come in v2.

---

## 5. Customers and agreements

### 5.1 Customers
`customers`: `type` (individual | company), `name_en`, `name_ar` (optional, used in contracts), `id_type` (cpr | passport | cr), `id_number` (unique together with `id_type`), `nationality`, `mobile`, `email`, `address`, `contact_person` (companies), `emergency_contact_name`, `emergency_contact_phone`, `notes`, soft deletes. ID copies via `documents` with `expires_on`.

One master record per customer; every agreement, invoice and payment links to it. Visibility follows the building scope (§8.2).

### 5.2 Agreements (header)
`agreements`: `number` (AGR-YYYY-NNNNNN), `customer_id`, `start_date`, `end_date`, `frequency` (monthly | quarterly | half_yearly | yearly), `billing_day` (nullable, 1–28; see §6.2), `grace_days` (default from settings), `notice_period_days`, `notice_date`, `planned_exit_date`, `status`, `previous_agreement_id` (renewals), `contract_template_id`, `verify_token` (§9.4), `created_by`, soft deletes (drafts only).

### 5.3 Agreement units and charges
`agreement_units`: `agreement_id`, `unit_id`, `list_rent` (copied from the unit when added), `deposit_amount`, `start_date`, `end_date` (default: the agreement's dates), `planned_exit_date`, `move_out_date`, `move_out_readings`, `move_out_notes`, `move_out_recorded_by`.

`agreement_unit_charges`: `agreement_unit_id`, `type` (rent | service_charge | parking | other), `description`, `monthly_amount`, `tax_category`. Exactly one `rent` charge per agreement unit.

- Units may belong to different buildings and different owner arrangements.
- Tax category is per charge, so residential and commercial units can share one agreement.
- **Discounts** have no separate workflow: the approval screen shows each unit's `list_rent` beside its agreed rent, and the total discount in BHD and %.

### 5.4 Agreement statuses

```
draft ──submit──▶ pending_approval ──approve──▶ active
  ▲                      │
  └──────reject──────────┘

active ──end_date passes, not renewed──▶ expired
active | expired ──end_date passed, renewal active, every unit carried or moved out──▶ renewed
active | expired ──end_date passed, every unit has a move_out_date──▶ closed
                    (terminated instead of closed if a terminate amendment was approved)
```

- While pending, an agreement and its units and charges are locked against edits.
- "Expiring soon" is a filter (end date within 30/60/90 days), not a status.
- **A move-out recorded before `end_date` neither ends billing nor frees the unit before `end_date`.** Ending early needs an approved `release_unit` or `terminate` amendment (§5.7, §8.3 items 2–3).
- **Occupancy ends only when a move-out is recorded.** An expired agreement without a move-out is an overstay: the unit stays occupied and the agreement appears on the "expired, not closed" report. Overstay rent is billed by manual invoice or regularised by a renewal (§5.8).
- The closing check (→ closed / terminated / renewed) runs on each move-out and in the 02:00 job.
- **Record notice** (`agreements.manage`, no approval) sets `notice_date` and `planned_exit_date` on an active or expired agreement, or on one of its agreement units. It changes neither `end_date` nor billing.

### 5.5 Double-booking prevention and pre-leasing
A new tenant can be signed for a unit that is still occupied, as long as the dates don't overlap (e.g. the current tenant leaves 31 Oct and the next starts 1 Nov).

For each agreement unit, `effective_end` is:

| Agreement status | `effective_end` |
|---|---|
| draft | not considered |
| every other status | if `move_out_date` is set: the later of `move_out_date` and `end_date`. Otherwise: `end_date` if `end_date ≥ today` or the unit is carried into a pending_approval or active renewal (§5.8); else **open-ended** (overstay) |

**Rule:** when an agreement is **submitted**, and again when it is **approved**, the Action locks each unit row with `SELECT … FOR UPDATE` in ascending id order as the transaction's first statement, then runs the overlap query as a locking read. It rejects the operation if the unit is `blocked`, or if any other agreement unit for that unit has `start_date ≤ new.end_date` and `effective_end ≥ new.start_date`.

Drafts do not hold units: two staff can draft for the same unit, and the second to submit is rejected. (Reservations with expiry and deposit arrive with sales in v3.)

### 5.6 Contract templates and the frozen contract
- `contract_templates` (`name`, `is_default`, `active`) with ordered `contract_template_clauses` (`position`, `heading_en`, `heading_ar`, `body_en`, `body_ar`). Admin edits the clauses; there is no raw HTML editing.
- Merge fields: `{company_name}`, `{customer_name}`, `{customer_id_number}`, `{agreement_number}`, `{start_date}`, `{end_date}`, `{frequency}`, `{total_monthly_rent}` (Σ rent charges only), `{total_deposit}`, `{notice_period_days}`, `{grace_days}`, `{units_table}` (bilingual schedule of units: building, unit, rent, service charge, deposit). In Arabic bodies, name fields render `name_ar` (falling back to `name_en`) and `{frequency}` its Arabic term.
- On **submit**, the clauses are rendered into `agreement_clauses` (`agreement_id`, `position`, `heading_en`, `heading_ar`, `body_en`, `body_ar`), with merge values escaped. Approval freezes them (§8.5). Template edits after submission never reach this agreement.
- The approved contract PDF is generated from `agreement_clauses`, stored once as a document, and **never regenerated**.
- Draft PDFs can be generated at any time; they carry a "DRAFT" watermark and no QR code.
- The signed paper copy is scanned and uploaded to the agreement (`documents`, category `signed_contract`).

### 5.7 Amendments (add or release a unit, terminate)
`agreement_amendments`: `agreement_id`, `type` (add_unit | release_unit | terminate), `effective_date`, `data` (json: unit, charges and deposit for add_unit), `reason`, `status` (draft | pending_approval | approved), `created_by`. Approval: §8.3 item 2 (item 3 for `terminate`). Every unit an amendment touches must be in the requester's building scope (§8.2).

On approval:

| Type | Effect on dates and deposits |
|---|---|
| **add_unit** | Overlap check (§5.5). New agreement unit from `effective_date` to the agreement's `end_date`. A deposit invoice is issued for the new unit's deposit. |
| **release_unit** | Sets that unit's `end_date` and `planned_exit_date` to `effective_date` (the last day of occupancy). |
| **terminate** | Same as release_unit for every unit, and sets the agreement's `end_date` to `effective_date`. |

**Billing effect (all types):** each agreement unit's lines cover only the part of each period that falls inside that unit's `start_date`–`end_date`, prorated by §6.2. On approval, in the same transaction:
1. every scheduled invoice whose period ends on or after `effective_date` is cancelled and replaced (§6.3) under this rule;
2. for every **issued** invoice whose period ends on or after `effective_date`, the difference between what was billed and what this rule gives is billed on one manual invoice (add_unit) or credited on one credit note per affected invoice (release_unit, terminate), created and issued as part of this approval. A credit line's net = the billed line net − the §6.2 amount for the kept days `period_start`..`effective_date` (the whole line if no days are kept); tax per §6.5.

The draft deposit settlement for a released unit is created when its occupancy ends (§5.9).

Rent changes on an active agreement are not allowed; they happen only through renewal.

### 5.8 Renewal
- "Renew" creates a draft agreement from an **active or expired** (not closed) one: same customer, a subset of its units (adding units is a separate amendment after activation), charges copied, `start_date` = old `end_date` + 1 day, `previous_agreement_id` set. Rents and dates are editable.
- It goes through the normal submit → approval. Carried units keep `effective_end = end_date` on the old agreement (§5.5), so the renewal passes the overlap check even when approved after the old agreement expired.
- A unit **not** carried needs its own move-out (§5.9), which creates its deposit settlement; no transfer is written for it.
- **Deposits carry over:** on activation, each carried unit gets a `transfer_out` of min(held, new `deposit_amount`) on the old agreement unit and an equal `transfer_in` on the new one. Any unpaid balance on the old deposit line is credited by a credit note in the same approval. Any amount still held on the old agreement unit after the transfer is refunded through a draft deposit settlement on the old agreement (§7.7). The renewal's deposit invoice bills only what is still missing (§6.2).
- The old agreement becomes `renewed` once its `end_date` has passed, its renewal is active, and every unit is either carried or has a `move_out_date`.

### 5.9 Move-out
Recorded per agreement unit (or for all units at once) with `agreements.manage`, for units in the user's building scope: `move_out_date` (≤ today), `move_out_readings` and `move_out_notes` (free text), photos (documents on the agreement unit). A full move-in/move-out checklist is v2.

The draft deposit settlement (§7.7) is created when the unit's occupancy has ended: at the move-out if `end_date ≤ move_out_date`, otherwise by the 02:00 job on the day after `end_date`.

---

## 6. Billing

### 6.1 Invoice types and statuses
`invoices`: `number` (NULL until issued), `type` (rent | deposit | manual | opening | credit_note), `customer_id`, `agreement_id` (nullable), `related_invoice_id` (credit notes), `period_start`, `period_end`, `issue_date`, `due_date`, `grace_until`, `status` (draft | pending_approval | scheduled | issued | cancelled), `replaced_by_invoice_id`, cached `subtotal`, `tax_total`, `total`, `allocated`, `credited`, generated `balance = IF(type = 'credit_note', 0, total − allocated − credited)`, `created_by`, `issued_by`, `issued_at`.

`invoice_lines`: `invoice_id`, `agreement_unit_id` (nullable), `unit_id` (nullable), `charge_type` (rent | service_charge | parking | other | deposit | damage | cleaning | utilities | opening_balance), `description`, `period_start`, `period_end`, `net`, `tax_category`, `tax_rate`, `tax_amount`, `total`, `owner_contract_id` (stamped at issue, §4.6), `credited_line_id` (credit-note lines), cached `allocated`, `credited`. Database CHECK: `allocated >= 0 AND allocated + credited <= total`.

- `draft` — manual invoices and credit notes not yet submitted or issued.
- `pending_approval` — credit notes only; approval issues the credit note.
- `scheduled` — generated rent invoices not yet issued; no number; not part of any balance.
- `issued` — numbered, amounts immutable, part of the customer balance.
- `cancelled` — only draft, pending or scheduled invoices can be cancelled. An issued invoice is corrected only by a credit note.
- **A credit note acts only through `credited` on the lines it credits.** It never receives allocations and is excluded from allocation defaults, auto-allocation, outstanding, overdue and ageing.
- The **displayed** labels "Partially paid", "Paid" and "Overdue" are derived from `balance` and `grace_until`; they are not stored statuses.

### 6.2 Rent schedule generation
On agreement activation, one `scheduled` rent invoice is created per billing period for the whole term, with one line per agreement unit per recurring charge.

- **Periods:** the anchor is `billing_day` (1–28) if set, else the day of `start_date`. Period k starts at anchor + k × frequency months, computed from the anchor with end-of-month clamping (31 Jan → 28/29 Feb → 31 Mar). A stub runs from `start_date` to the first anchor date if they differ; the last period ends on `end_date`.
- **Lines:** each line covers its period's overlap with its agreement unit's `start_date`–`end_date`, prorated when shorter.
- **Amounts:** a full period = `monthly_amount × months in period`. A partial period = whole months counted from the period start × `monthly_amount` + remaining days × daily rate, where daily rate = `monthly_amount × 12 / 365` (`actual_365`) or `monthly_amount / 30` (`days_30`). Rounded once per line.
- **Rent is billed in advance:** `due_date` = period start.
- `issue_date` = max(activation date, `due_date − invoice_lead_days`).
- A **deposit invoice** (one line per agreement unit, `charge_type = deposit`, tax `out_of_scope`) is issued at activation, due on `start_date`. For a renewal, each carried unit's line bills only its new `deposit_amount` minus the amount transferred in; no deposit invoice is issued if no line is positive. Imported agreements get no deposit invoice (§11).

### 6.3 Issuing, and "cancel and replace"
- The nightly job (and activation itself) issues every scheduled invoice whose `issue_date ≤ today`, except those held back by §4.6. The issue Action writes tax and stamps `owner_contract_id` on each line, then assigns the number, sets `grace_until` and `issued_at`, and only then flips the status to `issued`.
- `grace_until = due_date + grace days` — the agreement's `grace_days`, or `default_grace_days` for an invoice without an agreement.
- Finance can **"Issue now"** any scheduled invoice early — needed when a customer pays several periods in advance (VAT tax point, see C2).
- Scheduled invoices are **never edited**. Any change (amendment, termination) cancels the affected scheduled invoices and creates replacements (`replaced_by_invoice_id`). Cheques matched to a cancelled invoice are re-pointed to its replacement automatically. If there is no replacement, the cheque's `invoice_id` is cleared and it appears on the "Held cheques to return" report (§10).

### 6.4 Tax
- If `vat_registered` is false: every line has tax 0 and the invoice is a plain invoice.
- If true: `tax_amount = round(net × tax_rate / 100, 3)` for `standard` lines, and 0 for `zero_rated`, `exempt` and `out_of_scope`. The rate is copied onto the line. Invoices with any `standard` or `zero_rated` line print as "Tax Invoice" with the TRN and a VAT breakdown (confirm under C2).
- Tax categories: `standard`, `zero_rated`, `exempt`, `out_of_scope`. Deposit and opening-balance lines are always `out_of_scope`.
- The tax point used for reporting is `issue_date`.

### 6.5 Manual invoices and credit notes
- **Manual invoice** (Finance, `invoices.manage`): free lines, each with an optional unit for owner attribution. Finance sets `due_date` (default: the issue date) and issues it directly; no approval.
- **Credit note** (Finance requests, Management approves — §8.3 item 5): draft → pending_approval → issued on approval. Each line credits a specific issued line (`credited_line_id`), by at most that line's `total − credited`.
  - For a credit c on a line with balance b, allocations totalling max(0, c − b) are first reversed on that line, newest first (a reversal row may cover part of an allocation; Σ reversals ≤ the allocation), returning that money to its payment as customer credit. Then the credit is applied. The owner ledger sees the de-allocation as a reversal.
  - Credit-note lines copy `tax_category` and `tax_rate` from the credited line; a credit that uses up the line's remaining net takes exactly its remaining tax.
- Credit notes are numbered (CN-YYYY-NNNNNN) on issue.

### 6.6 Overdue
An issued invoice is overdue when `balance > 0` and today > `grace_until`. No job is needed; `grace_until` is fixed at issue, so later setting changes never rewrite history.

### 6.7 Opening balances
Import only (§11): `opening` invoices with `opening_balance` lines per agreement unit (so managed owners are attributed), issued and due on the cutover date.

### 6.8 Document numbers
`number_sequences`: `key`, `prefix`, `year`, `next_value`, `padding` (6). Format `{PREFIX}-{YYYY}-{NNNNNN}`, reset yearly; `year` = the Asia/Bahrain year when the number is assigned.

| Key | Prefix | Number assigned |
|---|---|---|
| agreement | AGR | on approval |
| owner_contract | OC | on approval |
| invoice | INV | on issue |
| credit_note | CN | on issue |
| receipt | RCP | on recording the payment |
| payment_out | PO | when the disbursement becomes `paid` |
| owner_statement | OS | on finalisation |
| deposit_settlement | DS | on approval |

The number is taken with `SELECT … FOR UPDATE` on its (key, year) row inside the same transaction that assigns it, so numbers are **gapless** and never duplicated. Rows for the current and next year are created by `rms:install` and by a job every 1 December — never inside the assigning transaction (a locking read of a missing row takes gap locks and deadlocks).

---

## 7. Money in, money out, deposits, owners

### 7.1 Payments in
`payments`: `number` (RCP-YYYY-NNNNNN — also the receipt number), `customer_id`, `received_on` (≤ today), `method` (cash | bank_transfer | cheque | card | deposit_applied), `amount`, `reference`, `cheque_id`, `notes`, `status` (confirmed | reversed), `recorded_by`, `posted_at`.

- Recorded by users with `payments.manage` (Finance). No approval needed to record.
- A receipt PDF is generated and stored on recording.
- `deposit_applied` payments are created only by deposit settlements (§7.7) and cannot be reversed on their own.

### 7.2 Allocation
`payment_allocations`: `payment_id`, `invoice_line_id`, `amount` (negative for reversals), `tax_amount` (the allocation's share of the line's tax = amount × line.tax_amount / line.total, split by largest remainder in fils), `reverses_allocation_id`, `owner_contract_id` (copied from the line), `posted_at`, `created_by`. Write-once.

**Locking:** every Action that writes allocations, credit refunds or payment reversals first locks the `customers` row with `SELECT … FOR UPDATE`, then the invoice lines it touches, and rejects any result that leaves customer credit below zero.

Algorithm:
1. **Which invoices:** Finance picks invoices and amounts, or accepts the default: issued non-credit-note invoices with `balance > 0`, oldest `due_date` first, then lowest id.
2. **Within an invoice (D10):** the amount is split across the lines that have a balance, in proportion to each line's balance, using the **largest-remainder method** in fils: each line gets its floored share, and the leftover fils go one at a time to the lines with the largest fractional parts (ties: lowest line id). An amount ≥ the invoice balance pays every line in full. No line ever receives more than its balance.
3. **Remainder:** anything not allocated stays on the payment as **customer credit**.

- Customer credit = Σ confirmed payment amounts − Σ live allocations − Σ non-reversed credit refunds.
- A **credit refund** is a disbursement with `source_type = payment`, naming the payment whose credit it refunds.
- Whenever an invoice is issued for a customer who has credit, the credit is **auto-allocated** oldest-first, drawing from payments in order of oldest `received_on`.
- Deposit settlements allocate directly to named lines (§7.7) — the one exception to the split above.

### 7.3 Reversals
- **Payment reversal** (Finance requests with a reason; Management approves — §8.3 item 6): negative allocation rows for every live allocation, and payment status `reversed`. The stored receipt is not changed; the reversal appears in the ledger and on the payment.
- **Payment-out reversal** (same approval item): marks the disbursement `reversed` and sets `reversed_at` (the owner ledger shows the opposite entry then, §7.9); for a deposit refund, writes an opposite `refunded` movement; cancels its issued cheque if there is one. It reopens its source: an owner payable returns to `scheduled` (its `disbursement_id` cleared); a deposit settlement returns to `approved`; a credit refund's amount returns to customer credit.

### 7.4 Cheques
`cheques`: `direction` (received | issued), `customer_id` or `owner_id`, `agreement_id`, `owner_contract_id`, `invoice_id` (target; received only), `cheque_no`, `bank_name`, `account_holder`, `cheque_date`, `amount`, `status`, `deposited_on`, `cleared_on`, `bounced_on`, `bounce_reason`, `returned_on`, `replaced_by_cheque_id`, `payment_id`, `disbursement_id`, `notes`. Cheque image via documents.

**Received cheques**

```
held ──deposit──▶ deposited ──clear──▶ cleared ──returned by bank──▶ bounced (payment reversed, §7.3)
  │                    └──bounce──▶ bounced ──▶ replaced (new cheque linked) | returned
  ├──return──▶ returned   (unused, e.g. after termination)
  └──cancel──▶ cancelled  (entered in error)
```

- After activation, the post-dated cheques collected at signing are entered on one screen and each is matched to its scheduled invoice.
- **Clearing** creates a payment (`method = cheque`, `received_on = cleared_on`), allocated first to the target invoice, then oldest-first, with any remainder as credit. If the target invoice is still scheduled, it is issued first.
- **A bounce before clearing never needs a payment reversal**, because no payment exists until clearing. A cheque returned by the bank *after* clearing is a payment reversal (§7.3).
- Deposits to the bank can be done in batches ("deposit selected").

**Issued cheques** (the company pays an owner or refunds a customer): `issued → cleared | cancelled`. Each is linked to a disbursement; cancelling one is a payment-out reversal.

### 7.5 Payments out
`disbursements`: `number` (PO-YYYY-NNNNNN, assigned when `paid`), `payee_type` (owner | customer), `payee_id`, `purpose` (owner_remittance | head_lease | deposit_refund | credit_refund | other), `amount`, `method` (bank_transfer | cheque | cash), `cheque_id`, `reference`, `paid_on`, `source_type` + `source_id` (owner_statement | owner_payable | deposit_settlement | payment), `status` (pending_approval | approved | paid | reversed), `posted_at`, `reversed_at`, `created_by`, `recorded_by`.

- **With an approved source** (finalised owner statement, scheduled head-lease payable, approved deposit settlement, or a payment holding customer credit): recorded directly as `paid`, with no further approval, **within these limits**, checked in the Action with the source row (and, for remittances, the owner_contracts row) locked `FOR UPDATE`:
  - the payee is copied from the source;
  - a head-lease payable is paid exactly once, for its amount;
  - Σ non-reversed deposit refunds ≤ the settlement's refund;
  - Σ non-reversed credit refunds ≤ that payment's unallocated credit;
  - Σ non-reversed owner remittances ≤ the contract's live owner-ledger balance.
- **Anything else** (no source, or outside these limits): `pending_approval` → Management approval (§8.3 item 9) → `approved` → Finance records it as `paid`.

### 7.6 Deposits
`deposit_movements`: `agreement_unit_id`, `owner_contract_id` (nullable), `type` (received | applied | refunded | transfer_in | transfer_out | opening), `amount` (signed), `source_type` + `source_id`, `posted_at`. Write-once.

- A `received` movement is written automatically with every allocation to a deposit line (same sign as the allocation).
- **`owner_contract_id` is stamped when the movement is written and never recomputed.** `received` copies it from its allocation's line. `opening` uses the §4.6 rule on the cutover date. `applied`, `refunded` and `transfer_out` copy it from the agreement unit's `received`, `opening` or `transfer_in` movements. `transfer_in` copies it from its matching `transfer_out`.
- **Deposit held** per agreement unit = Σ `amount`. Deposits never count as revenue.

### 7.7 Deposit settlement
`deposit_settlements`: `number` (DS-YYYY-NNNNNN), `agreement_id`, `status` (draft | pending_approval | approved | completed), `created_by`, with `deposit_settlement_units` (`agreement_unit_id`, `held_amount` shown at draft) and `deposit_settlement_lines` (`agreement_unit_id`, `type`: damage | cleaning | utilities | unpaid_rent | other, `description`, `amount`, `invoice_line_id` for unpaid_rent — its `agreement_unit_id` is the one on that line).

- Created as a draft when a unit's occupancy ends (§5.9), or by a renewal that leaves deposit on the old agreement unit (§5.8). Finance completes the deductions, each with a reason; Management approves (§8.3 item 4).
- On approval, in one transaction:
  1. Non-rent deductions (damage, cleaning, utilities, other) become one issued manual invoice; its lines are stamped by §4.6 on the move-out date.
  2. Under lock, re-read each unit's held amount and each unpaid_rent line's balance, and cap those deductions to them. Per unit, the `applied` movement = min(held, that unit's deductions). One `deposit_applied` payment for Σ applied is allocated directly to the named unpaid_rent lines, then to the deductions invoice. Credit auto-allocation is suppressed inside this transaction.
  3. Refund per unit = held − applied. Deductions beyond the deposit stay owed by the customer.
- Finance then records the refund as a disbursement (source = the settlement), which writes the `refunded` movement. The status becomes `completed` when the refund is paid, or immediately if the refund is zero.

### 7.8 Head-lease payables (leased buildings)
`owner_payables`: `owner_contract_id`, `period_start`, `period_end`, `due_date` (= period start), `amount`, `status` (scheduled | paid | cancelled), `disbursement_id`.

- Generated on contract activation with the period rules of §6.2 (anchor = the contract's `start_date`).
- A full period = `rent_amount`; a partial period prorates `rent_amount` by the §6.2 basis using exact arithmetic, rounded once per payable.
- Paid by a disbursement (purpose `head_lease`, §7.5). A contract end-date change (§4.5) cancels later payables and replaces a straddling one.

### 7.9 Owner ledger and statements (managed buildings)
**Owner ledger** entries for a managed contract, ordered by `posted_at`:

| Entry | Sign |
|---|---|
| Allocations (and their reversals) on lines stamped with the contract, all charge types except deposit, **net of VAT** (`amount − tax_amount`) | + |
| Deposit movements, only if `deposits_held_by = owner` (opening movements excluded — see §11) | same sign as the movement |
| Owner charges (`owner_charges`: management fee + VAT on the fee; opening balance) | ± |
| Expenses charged to the owner, at `posted_at`; a reversal as an opposite entry at `reversed_at` | − |
| Payments to the owner, at `posted_at`; a reversal as an opposite entry at `reversed_at` | − |

The original entry always stays on its finalised statement; a reversal appears on the statement covering `reversed_at`.

If `deposits_held_by = company`, deposits don't post to the owner ledger; the statement shows "deposits held by the company on your behalf" for information.

**`owner_charges`:** `owner_contract_id`, `owner_statement_id` (nullable), `type` (management_fee | opening_balance), `net`, `tax_amount`, `amount` (signed; − = the owner owes the company), `posted_at`, `created_by`. Write-once. Finalisation writes one `management_fee` row with `posted_at = cutoff_at`; the import writes one `opening_balance` row per managed contract at cutover.

**Statements** — `owner_statements`: `number` (OS-YYYY-NNNNNN), `owner_contract_id`, `period_start`, `period_end` (calendar month), `cutoff_at` (end of `period_end`, Asia/Bahrain), `opening_balance`, `fee_base`, `fee_amount`, `fee_tax`, `closing_balance`, `status` (draft | pending_approval | finalised), `created_by`.

- A statement covers entries with `posted_at` after the previous statement's `cutoff_at` and up to its own. Entries posted later but dated inside the month appear on the next statement, showing their business date. A finalised statement never changes.
- Drafts are created on the 1st of each month for every managed contract that was active on any day of the previous month, or has ledger entries posted after its last statement's `cutoff_at`. Finance reviews and submits; Management approves (§8.3 item 8). Finalisation writes the fee row and stores the PDF.
- A statement can be submitted only after the contract's previous statement is finalised. The opening balance of a successor contract's first statement is 0; the predecessor keeps its own statements until its balance is remitted.
- **Fee base (rent only, see C4):**
  - `percent_collected` = net-of-VAT rent-line allocations in the window;
  - `percent_billed` = net rent lines issued in the window minus rent credit notes issued in the window;
  - `fixed` = `fee_value`, prorated by the §6.2 daily rate for the days the contract is active in the month.
  - On managed contracts, `opening_balance` lines count as rent.
  - A negative base gives a fee of 0 and carries into next month's base.
- VAT on the fee applies when `vat_registered` (see C2 on the fee tax invoice).
- **Remittance:** Finance records a disbursement to the owner (source = the statement) for up to the live owner-ledger balance (§7.5).

### 7.10 Ledgers are queries
- **Customer statement — receivables:** issued invoices (debit), issued credit notes (credit), payments (credit, by `received_on`), payment reversals (debit), credit refunds (debit), with a running balance.
- **Customer statement — deposits:** `deposit_movements` per agreement unit, with a running balance held.
- **Owner ledger:** §7.9.
- No ledger table exists, so nothing can drift out of sync. *Limit:* if statements become slow, add a monthly snapshot table.

### 7.11 Integrity check
Nightly, emailed to Vendor Support on any failure:
- every invoice line's cached `allocated` and `credited` equal the sums of their source rows, and every invoice's cached totals equal the sums of its lines;
- customer credit ≥ 0 for every customer, and deposit held ≥ 0 for every agreement unit;
- the expected number of immutability triggers (§8.5) exists.

---

## 8. Roles, approvals, audit, security

### 8.1 Roles
| Capability | Admin | Management | Finance | Property Mgr | Leasing |
|---|---|---|---|---|---|
| Users, roles, settings, templates | ✔ | – | – | – | – |
| Buildings, units | ✔ | view | view | ✔ | view (assigned) |
| Owners, owner contracts | ✔ | view | ✔ | view | – |
| Owner bank details | ✔ | – | view | – | – |
| Customers | ✔ | view | view | ✔ | ✔ (assigned, §8.2) |
| Draft/submit agreements, amendments, notices, move-outs | ✔ | view | view | ✔ | ✔ (assigned) |
| **Decide approvals** (§8.3) | – | ✔ | – | – | – |
| Invoices, manual invoices, credit-note requests | view | view | ✔ | – | – |
| Payments, allocations, cheques | view | view | ✔ | – | – |
| Payments out, owner statements, deposit settlements | view | view | ✔ | – | – |
| Expenses | view | view | ✔ | ✔ (enter) | – |
| Operational reports | ✔ | ✔ | ✔ | ✔ | assigned buildings |
| Financial reports, balances, ledgers | ✔ | ✔ | ✔ | – | – |
| Audit log | view | view | – | – | – |

- **Permissions:** `users.manage`, `roles.manage`, `settings.manage`, `templates.manage`, `audit.view`, `buildings.view`, `buildings.manage`, `buildings.view-all`, `owners.view`, `owners.manage`, `owners.bank.manage`, `customers.view`, `customers.manage`, `agreements.view`, `agreements.manage`, `finance.view`, `invoices.manage`, `payments.manage`, `cheques.manage`, `disbursements.manage`, `expenses.manage`, `reports.operational`, `reports.financial`, `approvals.decide`, `import.run`.
- `disbursements.manage` also covers owner statements and deposit settlements. An expense with `charge_to = tenant` also needs `invoices.manage`.
- `buildings.view-all` is seeded to Admin, Management, Finance and Property Mgr; Leasing sees assigned buildings only. `building_user` is managed under `users.manage`.
- Roles are seeded defaults; Admin can change which permissions a role has (audited). A user can hold several roles.
- **Self-change limits:** a user cannot change their own roles or the permissions of a role they hold. Admin never sets another user's password (users get a reset link). Any grant of `approvals.decide` or a finance `*.manage` permission, and any change to a user's email, is emailed to every other active `approvals.decide` holder (and to the old address).
- **Admin has no finance-posting permissions by default.**
- **"Finance `*.manage`"** means `invoices.manage`, `payments.manage`, `cheques.manage`, `disbursements.manage` and `expenses.manage`.
- **Vendor Support:** the vendor's account in every install, with every permission **except** `approvals.decide`, `owners.bank.manage` and the finance `*.manage` permissions, and it receives no business emails. `import.run` is seeded to it only. `rms:install` generates a unique password and TOTP secret per install. Admin cannot edit it, only deactivate it; re-enabling is only `php artisan rms:vendor-support --enable` on the server (audited). The vendor also has server access as the host (C3/DPA).

### 8.2 Building assignment
`building_user` (`building_id`, `user_id`). A user without `buildings.view-all` sees only their assigned buildings and everything under them: units, agreements (if any of the agreement's units is in an assigned building), owner contracts, expenses, customers, reports, exports, search results, dashboard tiles and emails. The scope is applied in one place (a query scope used by every list, report and export) and covered by the permission test (§14).

- **Customers:** list, detail, edit, export and documents follow the scope — a customer is in scope if it has any agreement with a unit in an assigned building, or no agreement yet. To prevent duplicates, a scoped user's exact match on `id_type` + `id_number` or on mobile returns only "Already exists: {name}, ID ••••{last 4}".
- **Writes:** every write that targets a unit (adding it to a draft or renewal, add_unit, release_unit, notice, move-out) requires the unit's building in scope; terminate requires all of the agreement's units in scope.

### 8.3 Approvals
`approvals`: `approvable_type`, `approvable_id`, `action`, `status` (pending | approved | rejected), `reason`, `payload` (json), `requested_by`, `requested_at`, `decided_by`, `decided_at`, `comment`, `ip`.

Actions requiring Management approval (`approvals.decide`):

1. Agreement activation (including renewals)
2. Agreement amendment: add or release a unit
3. Early termination
4. Deposit settlement
5. Credit note
6. Reversal of a payment or of a payment out
7. Owner contract activation or early termination
8. Owner statement finalisation
9. Payment out with no approved source, or outside the source limits (§7.5)

Rules:
- With `require_different_approver` on (install-level, §3), the approver must differ from the requester **and** from the creator of the underlying record (`created_by` / `recorded_by`). Enforced in the Action.
- A pending item is locked against edits.
- Rejecting a record created for approval returns it to `draft` with the comment. Rejecting a request about an existing record (a reversal, an early termination) leaves that record unchanged.
- Management sees a "Pending my approval" list; each new request also sends an email.
- Single approval step in v1. A multi-step chain can be added later with only a `step` column.

### 8.4 Audit log
- `spatie/laravel-activitylog` on: buildings, units, owners, owner contracts, customers, agreements (with units, charges, amendments), invoices, credit notes, payments, allocations, cheques, disbursements, deposit settlements, owner statements, expenses, settings, users, contract templates and clauses, and `number_sequences`.
- `activity_log` gets `ip` and `user_agent` columns, filled by a custom log action.
- **Explicit entries** (written by the Actions that make the change, with old and new lists where relevant) for: role and permission changes, user role changes, `building_user` changes, login (success and failure), logout, failed 2FA challenges, 2FA enable/disable/reset, password reset and change, document upload, download and delete, exports, approval decisions, and Vendor Support enable/disable.
- **Secrets are never logged:** user logs exclude `password`, `remember_token`, `two_factor_secret` and `two_factor_recovery_codes`; password and 2FA changes are explicit entries without values. A test asserts no activity row contains those keys.
- Each entry records user, IP, user agent, time, and old and new values.
- Visible read-only to Admin and Management. Retained indefinitely.

### 8.5 Immutability (MySQL triggers)
Triggers raise `SIGNAL SQLSTATE '45000'`. Corrections are always reversals, credit notes or cancel-and-replace.

- **No UPDATE or DELETE:** `activity_log`, `payment_allocations`, `deposit_movements`, `owner_charges`, `agreement_clauses` once the agreement is not draft, `owner_statements` once finalised, `approvals` once decided.
- **No DELETE:** `invoices`, `payments`, `cheques`, `disbursements`, `owner_payables`, `owner_statements`, `deposit_settlements`, `expenses`, `agreements`, `owner_contracts`; and `invoice_lines`, `agreement_units`, `agreement_unit_charges`, `owner_contract_units`, `deposit_settlement_units`, `deposit_settlement_lines` except while their parent is draft.
- **One-way status:** issued and cancelled invoices, reversed payments and disbursements, finalised statements, and non-draft agreements and owner contracts never return to an earlier status.
- **Frozen once the parent is not draft:**
  - `invoice_lines`: no INSERT; no UPDATE except the cached `allocated` and `credited` (the issue Action writes tax and stamps before flipping the status, §6.3);
  - agreement units and charges, except `end_date`, `planned_exit_date` and the move-out fields;
  - owner-contract terms, except `end_date` and `terminated_on` (§4.5);
  - expense amounts, `charge_to` and `owner_contract_id`;
  - `payments.amount` and `disbursements.amount`.
- Other master data (buildings, units, owners, customers) is soft-deleted; foreign keys use `RESTRICT`, so anything still referenced can't be removed.
- **Two database users per install:** `rms_app` (SELECT, INSERT, UPDATE, DELETE, EXECUTE) for web, queue and scheduler; `rms_migrate` (all privileges on the schema, including DDL and TRIGGER) used by the deploy script (`migrate --database=migrator --force`) and by the nightly backup (mysqldump only includes triggers for a user holding TRIGGER). `rms_app` therefore cannot drop triggers or TRUNCATE (which would skip them). EXECUTE lets `rms_app` call the `SQL SECURITY DEFINER` function the integrity check uses to count triggers, since `rms_app` cannot see `information_schema.TRIGGERS`. The users are created by the provisioning script, not by `rms:install`.
- The `rms_migrate` account is the DEFINER of every trigger: rotate its password with `ALTER USER`, never drop or recreate it (every write to a table with triggers would then fail with error 1449).
- Every server and the CI MySQL set `log_bin_trust_function_creators=1` (needed to create triggers with binary logging on); CI migrates as a non-root user with binary logs on.
- *Limit:* whoever holds `rms_migrate` or server root can still drop triggers. DDL auditing is not in v1; the integrity check (§7.11) asserts the trigger count.

### 8.6 Authentication and security
- Laravel's Livewire starter kit (Livewire 4, Flux, Fortify), generated without Teams; registration and email verification removed. Admin creates users; users are deactivated, never deleted.
- **2FA:** a middleware on every authenticated route sends any user holding a sensitive permission (`users.manage`, `roles.manage`, `settings.manage`, `audit.view`, `finance.view`, any finance `*.manage`, `approvals.decide`) who has no `two_factor_confirmed_at` to 2FA setup. Those users cannot disable 2FA. This follows the permission, so a role granted later triggers it too.
- **Sessions:** no "Remember me". Inactive users are rejected on every request. Deactivation, password change or reset, and 2FA reset delete the user's sessions and rotate `remember_token`. 30-minute idle timeout; `SESSION_SECURE_COOKIE=true`.
- **Login throttling:** 5 attempts per minute per email + IP, plus 20 failures per hour per email from any IP.
- **Password reset:** reset URLs are built from `APP_URL` (trusted hosts only); resets are refused for inactive users. Passwords are at least 12 characters.
- CSRF protection, output escaping and validated Form Requests as standard; policies on every route and Livewire action.
- Uploads: allow-list of file types (pdf, jpg, png, webp, docx, xlsx), 10 MB limit, stored privately under a random name.
- `APP_DEBUG=false` in production; users never see stack traces, SQL or file paths.

---

## 9. Documents and PDFs

### 9.1 Documents
`documents`: `documentable_type`, `documentable_id`, `category`, `path`, `original_name`, `mime`, `size`, `expires_on`, `uploaded_by`. One table for every attachment and photo (buildings, units, owners, customers, agreements, agreement units, payments, cheques, expenses, statements).

- `category` ∈ `photo`, `id_copy`, `cr_copy`, `signed_contract`, `cheque_image`, `move_out_photo`, `owner_approval`, `generated_pdf`, `other`. ID-expiry alerts use `id_copy` and `cr_copy`.
- `DocumentPolicy::view` = the user can `view` the document's `documentable`. Previews use the same audited route.

### 9.2 PDF engine
- **mPDF** renders Blade → HTML → PDF for every document. The Arabic font is **IBM Plex Sans Arabic** (OFL), shipped in `resources/fonts` and registered via `fontdata` with `useOTL => 0xFF` (without OpenType layout, Arabic letters don't join). Noto Naskh Arabic and Amiri cannot be used: mPDF 8.3.1 fails on their OpenType tables ("GPOS Lookup Type 5, Format 3 not supported"; verified 2026-09-28). PDFs are generated in a queued job and stored privately.
- Layout: one table row per clause paragraph, so a long clause never forces mPDF to shrink the whole table. mPDF never splits a row across pages, so no paragraph may be longer than a page; the M2 template editor enforces a maximum paragraph length.
- Clause bodies and merge values are escaped (`{{ }}`), and mPDF loads images only from code-supplied local paths (logo, QR).
- **M0 spike (1 day):** a real two-unit contract with English and Arabic clauses side by side, including one clause longer than a page, the schedule-of-units table, a QR code, header/footer and page numbers. **Pass:** Arabic letters join correctly; numbers and dates inside Arabic text display in the right order; no table is scaled down; under 3 seconds per page. **Fallback if it fails:** `spatie/laravel-pdf` with the `chrome` or `weasyprint` driver (never the Cloudflare driver).

### 9.3 PDFs in v1
| Document | Language |
|---|---|
| Agreement contract (frozen at approval, §5.6) | English + Arabic |
| Invoice / tax invoice, credit note | English (see C2) |
| Receipt | English |
| Customer statement | English |
| Owner statement | English |
| Deposit settlement | English |

### 9.4 QR code
The approved contract's QR code encodes `APP_URL/v/{verify_token}` — 32 random characters stored on the agreement at approval, so it survives an `APP_KEY` rotation. The public verification page shows only: agreement number, status, start date and end date. Unknown tokens return 404; the page is rate-limited and `noindex`.

---

## 10. Reports and dashboard

Every report filters by building and date range, respects building assignment (§8.2), and exports to Excel (`spatie/simple-excel`). Statements also export to PDF.

**Operational** (`reports.operational`): unit availability and occupancy by building; agreements expiring in 30/60/90 days; expired agreements not closed (overstays); pending approvals (only items the viewer may `view`, plus their own requests); customer and owner ID documents expiring. Cheque reports — cheques to deposit (today / this week), bounced cheques awaiting action, **held cheques to return** — also need `cheques.manage` or `finance.view`.

**Financial** (`reports.financial`):
- outstanding by customer (Σ invoice balances − customer credit);
- overdue ageing by days past `grace_until`: 1–30 / 31–60 / 61–90 / 91+;
- collections by date and method — `deposit_applied` excluded and shown separately;
- customer statement; owner statement; head-lease payments due; deposits held;
- VAT summary by period: output VAT by category from issued invoices and credit notes, plus VAT on management fees from `owner_charges` by `posted_at`;
- **building profitability** (per building, for a date range, cash basis, with a billed column alongside):
  - income: net-of-VAT allocations on non-deposit lines stamped NULL (owned) or with a leased contract, plus finalised management fees (net) of the building's managed contracts;
  - costs: head-lease disbursements for the building's leased contracts, plus expenses charged to the company or to tenants;
  - result = income − costs.

**Management dashboard:** 8 number tiles, no charts; each tile needs its report's permission.

| Tile | Definition |
|---|---|
| Occupancy % | occupied units ÷ (all units − blocked units) |
| Rent due this month | rent invoices (issued or scheduled) due this month |
| Collected this month | payments received this month, excluding `deposit_applied` |
| Overdue total | Σ balance of overdue invoices |
| Cheques to deposit this week | held received cheques with `cheque_date` this week |
| Open bounced cheques | bounced, not yet replaced or returned |
| Expiring in 60 days | active agreements with `end_date` in the next 60 days |
| Pending approvals | pending items the viewer may decide |

---

## 11. Data import (go-live)

Each company goes live with tenants already partway through their leases.

- **Access:** running the import requires `import.run` (Vendor Support only). `company_settings.go_live_at` is set at cutover; after that, the import screens and Actions refuse to run.
- **Excel templates:** buildings; units; owners; owner contracts (with units); customers; active agreements (with units, charges, deposits); opening balance per customer at cutover (per agreement unit where applicable); deposits held; post-dated cheques still held; opening balance per managed owner. ID, phone, IBAN and money columns are formatted as Text in the templates.
- **Dry run:** every file is validated first, row by row with Laravel's Validator (money read as strings to 3 decimals), and an error report lists each row's problems. Nothing is saved until all files are clean; then the import runs in a single transaction per file set.
- **Rules:**
  - Imported agreements are created `active`, with an approval row recorded as "Imported by {user}". They get no deposit invoice.
  - Scheduled invoices and head-lease payables are generated only for periods starting on or after cutover, on the agreement's (or contract's) own anchor. The period containing cutover counts as billed in the old system; its unpaid part is in the opening balance.
  - Balances only: historical invoices stay in the old system.
  - Deposits held are imported as `opening` deposit movements. They do not post to the owner ledger; the imported owner opening balance (`owner_charges`, type `opening_balance`) is the only source for what is owed to the owner.
- The templates go to the client in M1 so they can start filling them early.

---

## 12. Scheduled jobs

All times are Asia/Bahrain. Every job is idempotent (safe to run twice), uses `withoutOverlapping(120)`, and pings a Forge heartbeat after each run.

| When | Job |
|---|---|
| Daily 01:00 | Issue scheduled invoices with `issue_date ≤ today` (except those held back by §4.6); auto-allocate customer credit |
| Daily 02:00 | Agreements past `end_date`: → `renewed` per §5.8, → `closed`/`terminated` per §5.4, other active ones → `expired`; create draft deposit settlements for units whose occupancy ended (§5.9) |
| Daily 02:15 | Owner contracts past `end_date` → `terminated` if `terminated_on` is set, else `ended` |
| Daily 02:30 | Integrity check (§7.11) |
| Daily 03:30 | Backup (§13.3) |
| 1st of month 04:00 | Draft owner statements for the previous month (§7.9) |
| 1 December 04:30 | Create next year's `number_sequences` rows |
| Daily 07:00 | Email Finance: cheques to deposit today/this week, bounces awaiting action |
| Daily 07:00 | Email Management: pending approvals, agreements expiring in 30/60/90 days |
| Monday 07:00 | Email: customer and owner ID documents expiring in the next 30 days |

Email recipients are active users holding the relevant permission (`cheques.manage`, `approvals.decide`, `customers.manage`), excluding Vendor Support. Content is filtered by the recipient's building scope and contains counts and links only, never ID numbers.

Customer-facing reminders (SMS/email) are v2/v3.

---

## 13. Operations

### 13.1 Hosting (assumes D12)
- One server per company: 2 vCPU / 4 GB RAM, Ubuntu 26.04 LTS, Nginx, PHP-FPM 8.5, MySQL 26.7, Supervisor. No Redis.
- Hosted on a **Bahrain-based provider** to avoid PDPL cross-border transfer questions — confirm (C3). **Not AWS me-south-1**, which has been unavailable since March 2026.
- Servers are provisioned and deployed with **Laravel Forge on the Business plan** (server monitoring and team roles are Business-only; Hobby allows only one custom VPS), as custom VPS servers: SSL, deploy script, queue worker, scheduler, heartbeats. Forge does not offer MySQL 26.x (verified 2026-09-28), so each server is provisioned without a database and MySQL 26.7 is installed from Oracle's APT repository by `deploy/provision-mysql.sh`, which also creates the two database users and sets `log_bin_trust_function_creators = 1` with `SET PERSIST`.
- Forge zero-downtime deploys, with `storage` as a shared path so uploads survive release pruning. Forge deploys a branch head, not a tag, so production sites deploy the `release` branch, which is moved to each release tag.
- Forge's deployment health checks run only after a deploy; continuous uptime checks of `/health` use a separate uptime service.

### 13.2 Install and release
- **`php artisan rms:install`** creates the company settings and the first Admin user, the current and next year's number sequences, roles, permissions and the Vendor Support account (unique password and TOTP secret); from M2 it also creates the default contract template. The database users already exist (§13.1). With Forge, a new company can be live in about an hour.
- **Environments:** local → **staging** (a demo install with fake data only; doubles as the sales demo) → one production server per company. **Staging never holds real company data.**
- **Company go-live:** the dry-run import and UAT run on the company's own production server. After UAT sign-off, its database and private storage are dropped, `rms:install` re-runs, then the final import, then `go_live_at` is set.
- **Releases:** tagged versions from `main`, deployed to staging first, then to each company. Each deploy runs migrations as `rms_migrate`, caches config/routes/views, restarts the queue workers, and writes `git describe --tags` to `APP_VERSION` (shown in the footer and on `/health`). Before any release that contains migrations, the migrations are run against a copy of the largest company's database (on the restore-check server, §13.3). MySQL Innovation upgrades (D16) ship through this same process.
- **D13 applies to every release:** no company-specific code.

### 13.3 Backups
- `spatie/laravel-backup` nightly: database dump (through the `migrator` connection, so triggers are included; `mysql_gtid_purged = OFF`, because a GTID_PURGED line makes the dump unrestorable on another GTID server) plus `storage_path('app/private')` (listed explicitly, with the restore package's temp folder excluded), as an AES-256 archive sent to object storage at a **different provider**, in a location C3 allows. Per-install archive passwords are kept in the vendor's password vault. Retention: 30 days of dailies plus 12 month-end backups, no size-based deletion. Archive entry names are not encrypted, so stored file names never contain personal data (documents use random names, §8.6).
- MySQL binary logs with `binlog_expire_logs_seconds = 604800` (7 days), kept on the server for point-in-time recovery from mistakes. *Limit:* the binlogs are local, so losing the server loses up to 24 hours (back to the last nightly backup).
- **Restore test:** weekly, on a dedicated restore-check server in the same location (wiped after each run), `wnx/laravel-backup-restore` restores one company's latest backup (rotating), runs sanity counts, and checks the archive's file entries; alerts on failure. A manual full restore drill every quarter.

### 13.4 Monitoring and errors
- Error tracking with **Sentry** (`sentry/sentry-laravel`), one project for all installs, each event tagged with the company code. `send_default_pii = false`, SQL bindings off and `max_request_body_size = 'never'` are hard-coded in `config/sentry.php`, never read from env. Only stack traces and SQL text leave the server (C3).
- An uptime check per install; alerts for failed jobs, missed heartbeats, low disk space and backup health.
- Users see "Something went wrong. Please try again."; details go only to the logs and error tracking.

---

## 14. Testing

- **Pest 5, on real MySQL 26.7 in CI — never SQLite.** Triggers, generated columns, CHECK constraints and row locks are MySQL behaviour. Tests run as `rms_app`, exactly like production; `migrate:fresh` runs through the `migrator` connection. CI migrates as a non-root user with binary logs on and `log_bin_trust_function_creators=1`. Tests that need real commits on two connections live in `tests/Concurrency` and use `DatabaseTruncation`.
- **Feature tests (one per flow):**
  1. Multi-unit agreement → submit → approval by a different user → scheduled invoices with correct periods, end-of-month anchors and proration → unit statuses.
  2. Two overlapping submissions for the same unit → the second is rejected, including when run concurrently on two connections; a non-overlapping pre-lease is accepted; a blocked unit is rejected.
  3. Issue job run twice → no duplicate invoices; numbers gapless; owner attribution stamped; an invoice is held back while an owner contract covering it is pending.
  4. Partial payment → the largest-remainder split sums exactly to the fil and never exceeds a line's balance; customer credit auto-allocates on the next issue; credit never goes negative.
  5. Cheque cleared → payment created; cheque bounced → no payment; replacement linked; returned after clearing → payment reversed.
  6. Managed owner statement = net-of-VAT collections − fee − expenses − remittances; an entry posted late lands on the next statement; a reversed expense shows on the later statement and the finalised one is unchanged.
  7. Head-lease payables generated with proration; paid exactly once via a disbursement; termination replaces a straddling payable.
  8. Unit released mid-period, including periods already issued with "Issue now" → scheduled invoices replaced, one credit note per issued invoice, kept days still billed; deposit settlement → deductions invoice, deposit applied (capped at held), refund.
  9. Credit note on a partly paid line → partial de-allocation → customer credit; credit notes never appear in balances or allocation defaults.
  10. Renewal after expiry → passes the overlap check; deposit transferred; uncarried unit needs its own move-out; old agreement → `renewed`.
  11. **Permission matrix:** one data-driven test of every role against every protected action, including building-assignment scoping of lists, customers, reports, exports and unit-targeted writes.
  12. **Immutability:** one case per §8.5 rule is rejected by the database.
  13. Approval rule: neither the requester nor the record's creator can approve; a user cannot change their own roles.
  14. Payment-out limits: a second remittance beyond the owner-ledger balance and a second payment of the same head-lease payable both require approval.
  15. No activity-log row contains a password, remember token or 2FA secret.
- **Unit tests:** period generation and anchors, proration, largest-remainder split, allocation tax split, fee calculation, tax rounding.
- **CI (GitHub Actions) on every pull request:** Pest, Pint, Larastan, `composer audit`.
- **Before the first go-live:** an OWASP Top 10 review, and an external penetration test if the client requires one.

---

## 15. Milestones

Implementation is planned **one milestone at a time**: one plan per milestone, written once the previous milestone's exit criteria are met, each naming the spec sections it covers.

| # | Milestone | Weeks | Exit criteria |
|---|---|---|---|
| M0 | **Foundation:** starter kit + 2FA enforcement, roles and permissions, users and roles administration, building assignment, audit log + its triggers, two DB users, settings, number sequences, documents, `rms:install`, Forge staging, CI, backups + restore check, error tracking and alerts, mPDF spike. (The approvals engine is built in M1 with its first user, owner-contract activation; each later table ships with its own immutability triggers.) | 3 | `rms:install` produces a working install on a fresh server; CI green; a restored backup contains the uploaded documents; mPDF spike passes (or the fallback is chosen) |
| M1 | **Property and owners:** approvals engine (§8.3), buildings, units, owners (bank-change controls), owner contracts + units + successors, expenses charged to company or owner, importer with dry run for buildings, units, owners and owner contracts | 2 | The client's real buildings, units, owners and contracts pass a dry-run import on the client's production server |
| M2 | **Customers and agreements:** customers, multi-unit agreements, approval, EN/AR clause templates + frozen PDF, QR verification page, schedule generation + proration, issuing and tax (§6.3–6.4), deposit invoices, notice | 3.5 | 10 real agreements (at least one multi-unit across two buildings) entered and approved; the client signs off the EN/AR contract PDF |
| M3 | **Tenant finance:** manual invoices, credit notes, payments + allocation, customer credit and credit refunds, cheques (both directions), payments out, payment and payment-out reversals, customer statements; tenant-charged expenses; the financial effects of amendments, terminations, renewals and move-outs; deposit settlements; integrity check | 4.5 | One full month's rent cycle reconciles to the fil against the client's existing records |
| M4 | **Owner finance:** head-lease payables, owner ledger, owner charges, fees, statements + finalisation, remittances with limits, building profitability | 2 | One managed owner's statement and one head-lease schedule match the client's current figures |
| M5 | **Go-live:** reports, dashboard, exports, scheduled emails, import of customers, agreements, balances, deposits and cheques, UAT, security review, training, cutover | 2.5 | UAT signed; live in production |
| | **v1 total** | **17.5** | |

**v2 (~10 weeks):** customer portal; owner portal; online payment gateway (`payment_transactions`, webhook-verified, idempotent) behind a `PaymentGateway` interface; maintenance tickets with costs charged to company/owner/tenant and owner approval of spend; vendors; move-in/move-out checklists; customer email/SMS notifications.

**v3 (~8 weeks):** leads, site visits, reservations (with expiry and deposit) and sales performance; charts; journal export to accounting software; multi-step approvals; automated customer reminders; a public REST API for a mobile app.

---

## 16. Out of scope for v1

Customer and owner portals; online payments; maintenance; vendors; leads, sales, reservations and quotations; SMS/WhatsApp; e-signature; a mobile app and public API; a general ledger / chart of accounts; late fees; utility billing; multi-currency; an Arabic application UI; multi-step approvals; money received from owners (e.g. recovering a negative owner balance — it carries forward in the ledger); smart-building integration; any AI features.

---

## 17. Confirmations needed (not blocking the build)

| # | Question | Who confirms | Default until confirmed |
|---|---|---|---|
| C1 | Does the vendor host every install (D12)? If any company self-hosts, mPDF (GPL-2.0) is distributed to it: accept the GPL for that install or switch to the §9.2 fallback. | Product owner | Vendor hosts all |
| C2 | VAT: is residential rent exempt and commercial rent standard-rated; what is the tax point for payments received in advance; do tax invoices need Arabic; can the owner statement serve as the tax invoice for the management fee; for managed units, is the company or the owner the supplier of the rent for VAT? | Tax accountant | Defaults in §3; English tax invoices; the company declares rent VAT and pays owners net of VAT |
| C3 | PDPL: hosting and backup-storage location, a data processing agreement between the vendor and each company, retention of ID copies, and error events (stack traces and SQL text, no personal data) sent to Sentry outside Bahrain | Bahrain lawyer | Bahrain-based hosting; ID copies kept for the life of the customer record |
| C4 | Management fee base: rent only, or rent + service charges? | First client | Rent only |
| C5 | Proration basis: actual/365 or 30-day month? | First client | `actual_365` (a setting) |
| C6 | Default grace days and invoice lead days | First client | 5 and 7 (settings) |

---

## Glossary

- **Head lease** — the company leases a building (or units) from an owner at a fixed rent and sublets to tenants.
- **Managed** — the owner keeps the building; the company collects rent on the owner's behalf for a fee.
- **Remittance** — payment of the net amount owed to an owner.
- **PDC** — post-dated cheque.
- **Agreement unit** — one unit within an agreement, with its own rent, charges and deposit.
- **Scheduled invoice** — a future rent invoice generated at activation; not yet numbered or owed.
- **Customer credit** — money received and not yet allocated to an invoice.
- **effective_end** — the date an agreement unit stops holding its unit (§5.5).
