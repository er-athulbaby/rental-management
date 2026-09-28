# Rental Management System — v1 Design Spec

- **Date:** 2026-09-28
- **Status:** Draft for review
- **Input:** client SRS "Rental Management System — Detailed Software Requirements & Development Scope" (70 sections), reduced to v1 through a section-by-section design review.
- **Team assumed:** 2 developers (1 senior Laravel backend, 1 mid full-stack).

---

## 0. Summary

A Laravel + Livewire rental management **product** for Bahrain property management companies. Each company gets **its own installation** (own server, own database). All companies run the same codebase; differences between companies live only in settings and templates.

v1 lets a company run its whole rental business:

- buildings that are **owned**, **leased from an owner**, or **managed for an owner**, in any mix;
- **multi-unit agreements**, with bilingual English/Arabic contract PDFs;
- rent invoicing, post-dated cheques, payments, deposits;
- payments to owners, owner statements, building profitability;
- reports, a management dashboard, approvals and a tamper-proof audit trail;
- import of each company's existing tenants and balances at go-live.

**Estimate:** v1 ≈ 16.5 weeks. v2 ≈ 10 weeks. v3 ≈ 8 weeks (§15).

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

---

## 2. Architecture

- **Runtime:** Laravel (latest stable major at the start of M0), PHP 8.3+, MySQL 8.0+, Nginx, PHP-FPM, Supervisor, Ubuntu LTS.
- **UI:** Livewire + Tailwind CSS + Flux (free components). Every staff screen must be usable at 375 px width — leasing staff work from phones.
- **Layering:**
  - Livewire components and controllers are thin: validate input, authorize, call one Action.
  - `app/Actions/*` — one class per business operation (`ActivateAgreement`, `RecordPayment`, `ClearCheque`, …). Each runs inside **one database transaction** and is the **only write path** for the records it changes.
  - Eloquent models hold relationships, casts and query scopes only.
  - `app/Services/*` only for external concerns: PDF rendering, mail.
  - No repository layer.
- **Background work:** `database` queue driver, `php artisan queue:work` under Supervisor; scheduler via cron `schedule:run` every minute.
- **Files:** private disk `storage/app/private/{buildings,units,owners,customers,agreements,payments,expenses,statements}/…`. Nothing under `public/`. Downloads only through `DocumentController@download` (policy check + audit entry).
- **Money:** every monetary column is `DECIMAL(12,3)`. Rounding is half-up to 3 decimals. Money arithmetic in PHP uses integer fils (or `brick/math`), never floats.
- **Packages:** `spatie/laravel-permission`, `spatie/laravel-activitylog`, `spatie/laravel-backup`, `spatie/simple-excel`, `mpdf/mpdf` (+ `mpdf/qrcode`), Pest, Larastan, Pint.

---

## 3. Company settings

A single-row table `company_settings`, edited by Admin:

| Group | Fields |
|---|---|
| Profile | `name_en`, `name_ar`, `cr_number`, `address_en`, `address_ar`, `phone`, `email`, `website`, `logo` |
| Tax | `vat_registered` (bool), `trn`, `vat_rate` (default 10.00), default tax category for residential units (default `exempt`) and for commercial units (default `standard`) |
| Locale | `currency_code` (default BHD), `display_decimals` (default 3), `date_format` (default `d/m/Y`); timezone fixed to `Asia/Bahrain` |
| Billing | `default_grace_days` (default 5), `invoice_lead_days` (default 7), `proration_basis` (`actual_365` default, or `days_30`) |
| Control | `require_different_approver` (default true), `vendor_support_enabled` (default true) |

Document number formats live in `number_sequences` (§6.8).

---

## 4. Property and owners

### 4.1 Buildings
`buildings`: `name`, `code` (unique), `location`, `address`, `type` (residential | commercial | mixed), `floors_count`, `parking`, `facilities`, `property_manager_user_id`, `notes`, soft deletes. Photos and documents via `documents` (§9.1).

There is no "property" or "compound" level above buildings in v1 (add it when a company needs to group buildings).

### 4.2 Units
`units`: `building_id`, `code` (unique per building), `floor` (string: "G", "1", "M"…), `use` (residential | commercial), `type` (flat | villa | studio | shop | office | showroom | warehouse | other), `bedrooms`, `bathrooms`, `area_sqm`, `furnishing` (unfurnished | semi | furnished), `list_rent` (monthly), `list_deposit`, `list_service_charge` (monthly), `default_tax_category` (nullable → falls back to the setting for its `use`), `ewa_account_no`, `blocked` (bool), `blocked_reason`, `notes`, soft deletes.

### 4.3 Unit status (computed, never stored or typed)
Evaluated for "today" (T) from agreement units (§5.3), using `effective_end` from §5.5:

| Status | Rule |
|---|---|
| **Occupied** | An agreement unit with `start_date ≤ T ≤ effective_end` whose agreement is active or expired |
| **Notice given** | Occupied, and that agreement unit or its agreement has a `planned_exit_date` |
| **Reserved** | Not occupied, and has an agreement unit in a `pending_approval` agreement, or in an active agreement with `start_date > T` |
| **Blocked** | `blocked = true` and none of the above |
| **Available** | None of the above |

An occupied unit with a future agreement shows "Occupied · next tenant from {date}".

### 4.4 Owners
`owners`: `type` (person | company), `name_en`, `name_ar`, `id_type` (cpr | passport | cr), `id_number` (unique together with `id_type`), `nationality`, `phone`, `email`, `address`, `bank_name`, `iban`, `account_name`, `notes`, soft deletes.

### 4.5 Owner contracts
`owner_contracts`:
- Common: `number` (OC-YYYY-NNNNNN), `owner_id`, `building_id`, `type` (leased | managed), `start_date`, `end_date`, `status` (draft | pending_approval | active | ended | terminated), `previous_contract_id`, `notes`.
- **Leased** (the company rents from the owner): `rent_amount` (per payment period), `payment_frequency` (monthly | quarterly | half_yearly | yearly).
- **Managed** (the company manages for the owner): `fee_type` (percent_collected | percent_billed | fixed), `fee_value` (percent, or BHD per month when fixed), `expense_approval_limit`, `deposits_held_by` (company | owner).

`owner_contract_units`: (`owner_contract_id`, `unit_id`). A whole-building contract links every unit of the building; a single-flat owner's contract links one unit.

**Owned** = a unit with no active owner contract on a given date. There is no "owned" contract row.

Rules:
- A unit can be covered by **at most one** owner contract in status pending_approval or active on any date. Checked on submit and on approval: the Action locks each unit row (`SELECT … FOR UPDATE`), then tests for date overlap.
- Activation and early termination require Management approval (§8.3, item 7).
- Rent or fee changes, and renewals, create a **new** contract linked by `previous_contract_id`. An active contract's terms are never edited.
- A nightly job moves active contracts past `end_date` to `ended`.
- Activating a leased contract generates its payment schedule to the owner (§7.8).

### 4.6 Owner attribution (the rule that keeps statements correct)
Every invoice line gets `owner_contract_id` **stamped when the invoice is issued**: the active owner contract covering that line's unit on the line's `period_start` (NULL = company-owned). Allocations copy the value from their line. Owner statements, owner ledgers and building profitability read only these stored values; they never recompute attribution from a unit's current arrangement.

*Known limit:* a line is attributed to the contract covering its period start. An owner contract that starts or ends mid-period does not split an already-billed period.

### 4.7 Expenses
`expenses`: `building_id`, `unit_id` (nullable), `category`, `description`, `expense_date`, `net`, `tax_amount`, `total`, `charge_to` (company | owner | tenant), `owner_contract_id` (resolved on entry), `agreement_unit_id` (tenant charges), `owner_approval_note` + document (required when charged to an owner above the contract's `expense_approval_limit`), `status` (recorded | reversed), `recorded_by`.

- `charge_to = owner` is only allowed when the unit/building is under an active **managed** contract on `expense_date`; it posts to that owner's ledger.
- `charge_to = tenant` requires a unit with an active agreement unit on `expense_date`; recording it creates and issues a **manual invoice** (§6.5) to that customer.
- Paying the supplier is outside v1 (it stays in the company's accounting system); vendors come in v2.

---

## 5. Customers and agreements

### 5.1 Customers
`customers`: `type` (individual | company), `name_en`, `name_ar` (optional, used in contracts), `id_type` (cpr | passport | cr), `id_number` (unique together with `id_type`), `nationality`, `mobile`, `email`, `address`, `contact_person` (companies), `emergency_contact_name`, `emergency_contact_phone`, `notes`, soft deletes. ID copies via `documents` with `expires_on`.

One master record per customer; every agreement, invoice and payment links to it.

### 5.2 Agreements (header)
`agreements`: `number` (AGR-YYYY-NNNNNN), `customer_id`, `start_date`, `end_date`, `frequency` (monthly | quarterly | half_yearly | yearly), `billing_day` (nullable; see §6.2), `grace_days` (default from settings), `notice_period_days`, `notice_date`, `planned_exit_date`, `status`, `previous_agreement_id` (renewals), `contract_template_id`, `created_by`, soft deletes (drafts only).

### 5.3 Agreement units and charges
`agreement_units`: `agreement_id`, `unit_id`, `list_rent` (copied from the unit when added), `deposit_amount`, `start_date`, `end_date` (default: the agreement's dates), `planned_exit_date`, `move_out_date`.

`agreement_unit_charges`: `agreement_unit_id`, `type` (rent | service_charge | parking | other), `description`, `monthly_amount`, `tax_category`. Exactly one `rent` charge per agreement unit.

- Units may belong to different buildings and different owner arrangements.
- Tax category is per charge, so residential and commercial units can share one agreement.
- **Discounts** have no separate workflow: the approval screen shows each unit's `list_rent` beside its agreed rent, and the total discount in BHD and %.

### 5.4 Agreement statuses

```
draft ──submit──▶ pending_approval ──approve──▶ active
  ▲                      │
  └──────reject──────────┘

active ──end_date passes, active renewal exists──▶ renewed
active ──end_date passes, no renewal──────────────▶ expired ──move-out recorded──▶ closed
active ──move-out recorded on/before end_date─────▶ closed
active ──early termination approved + move-out────▶ terminated
```

- While pending, an agreement and its units and charges are locked against edits.
- "Expiring soon" is a filter (end date within 30/60/90 days), not a status.
- **Unit occupancy ends only when a move-out is recorded**, whatever the end path. An expired agreement without a move-out is an overstay: the unit stays occupied and the agreement appears on the "expired, not closed" report. Overstay rent is billed by manual invoice or regularised by a renewal.

### 5.5 Double-booking prevention and pre-leasing
A new tenant can be signed for a unit that is still occupied, as long as the dates don't overlap (e.g. the current tenant leaves 31 Oct and the next starts 1 Nov).

For each agreement unit, `effective_end` is:

| Agreement status | `effective_end` |
|---|---|
| pending_approval, active | `move_out_date` if set, else `end_date` |
| expired | `move_out_date` if set, else **open-ended** |
| renewed | `end_date` |
| closed, terminated | `move_out_date` |
| draft | not considered |

**Rule:** when an agreement is **submitted**, and again when it is **approved**, the Action locks each unit row (`SELECT … FOR UPDATE`) and rejects the operation if any other agreement unit for that unit has `start_date ≤ new.end_date` and `effective_end ≥ new.start_date`. Drafts do not hold units: two staff can draft for the same unit, and the second to submit is rejected. (Reservations with expiry and deposit arrive with sales in v3.)

### 5.6 Contract templates and the frozen contract
- `contract_templates` (`name`, `is_default`, `active`) with ordered `contract_template_clauses` (`position`, `heading_en`, `heading_ar`, `body_en`, `body_ar`). Admin edits the clauses; there is no raw HTML editing.
- Merge fields: `{company_name}`, `{customer_name}`, `{customer_id_number}`, `{agreement_number}`, `{start_date}`, `{end_date}`, `{frequency}`, `{total_monthly_rent}`, `{total_deposit}`, `{notice_period_days}`, `{grace_days}`, `{units_table}` (bilingual schedule of units: building, unit, rent, service charge, deposit).
- On approval, the rendered clauses are copied to `agreement_clauses` (the snapshot). The approved contract PDF is generated from the snapshot, stored once as a document, and **never regenerated**. Later template edits never change an approved contract.
- Draft PDFs can be generated at any time and carry a "DRAFT" watermark.
- The signed paper copy is scanned and uploaded to the agreement (`documents`, category `signed_contract`).

### 5.7 Amendments (add or release a unit, terminate)
`agreement_amendments`: `agreement_id`, `type` (add_unit | release_unit | terminate), `effective_date`, `data` (json: unit, charges and deposit for add_unit), `reason`, `status` (draft | pending_approval | approved | rejected). Approval: §8.3 item 2 (item 3 for `terminate`).

On approval:

| Type | Effect |
|---|---|
| **add_unit** | Overlap check (§5.5). New agreement unit from `effective_date` to the agreement's end. Future scheduled invoices are cancelled and replaced (§6.3) to include the new unit's lines, with its first period prorated. If the period containing `effective_date` is already issued, a manual invoice bills the prorated amount. A deposit invoice is issued for the new unit's deposit. |
| **release_unit** | Sets that unit's `end_date` and `planned_exit_date` to `effective_date` (last day of occupancy). Future scheduled invoices are cancelled and replaced without the unit's lines. An already-issued invoice covering days after `effective_date` gets a prorated **credit note**, created and issued as part of this approval. Recording the unit's move-out creates a draft deposit settlement for that unit. |
| **terminate** | Same as release_unit for every unit, and sets the agreement's `end_date`. Recording the final move-out sets status `terminated`. |

Rent changes on an active agreement are not allowed; they happen only through renewal.

### 5.8 Renewal
- "Renew" creates a draft agreement from an active one: same customer, a subset of its units (adding units is a separate amendment after activation), charges copied, `start_date` = old `end_date` + 1 day, `previous_agreement_id` set. Rents and dates are editable.
- It goes through the normal submit → approval. The dates don't overlap, so the §5.5 rule needs no special case.
- **Deposits carry over:** on activation, `deposit_movements` record `transfer_out` on each old agreement unit and `transfer_in` on the new one. If the new deposit is higher, a deposit invoice bills the difference; if lower, a deposit settlement refunds the difference.
- When the old agreement's `end_date` passes, the nightly job sets it to `renewed`.

### 5.9 Move-out
Recorded per agreement unit (or for all units at once): `move_out_date` (≤ today), meter readings and condition notes (free text), photos (documents). It releases the unit and creates a draft deposit settlement (§7.7). A full move-in/move-out checklist is v2.

---

## 6. Billing

### 6.1 Invoice types and statuses
`invoices`: `number` (NULL until issued), `type` (rent | deposit | manual | opening | credit_note), `customer_id`, `agreement_id` (nullable), `related_invoice_id` (credit notes), `period_start`, `period_end`, `issue_date`, `due_date`, `grace_until`, `status` (draft | scheduled | issued | cancelled), `replaced_by_invoice_id`, cached `subtotal`, `tax_total`, `total`, `allocated`, `credited`, generated `balance = total − allocated − credited`, `issued_by`, `issued_at`.

`invoice_lines`: `invoice_id`, `agreement_unit_id` (nullable), `unit_id` (nullable), `charge_type` (rent | service_charge | parking | other | deposit | damage | cleaning | utilities | opening_balance), `description`, `period_start`, `period_end`, `net`, `tax_category`, `tax_rate`, `tax_amount`, `total`, `owner_contract_id` (stamped at issue, §4.6), `credited_line_id` (credit-note lines), cached `allocated`, `credited`.

- `draft` — manual invoices and credit notes not yet issued.
- `scheduled` — generated rent invoices not yet issued; no number; not part of any balance.
- `issued` — numbered, amounts immutable, part of the customer balance.
- `cancelled` — only scheduled or draft invoices can be cancelled. An issued invoice is corrected only by a credit note.
- The **displayed** labels "Partially paid", "Paid" and "Overdue" are derived from `balance` and `grace_until`; they are not stored statuses.

### 6.2 Rent schedule generation
On agreement activation, one `scheduled` rent invoice is created per billing period for the whole term, with one line per agreement unit per recurring charge.

- **Periods** start on `start_date` and advance by the agreement's frequency (1, 3, 6 or 12 months). If `billing_day` is set and differs from the start day, the first period is a stub from `start_date` to the day before the first `billing_day`. The last period ends on `end_date`.
- **Amounts:** a full period = `monthly_amount × months in period`. A partial period = whole months counted from the period start × `monthly_amount` + remaining days × daily rate, where daily rate = `monthly_amount × 12 / 365` (`actual_365`) or `monthly_amount / 30` (`days_30`). Rounded per line.
- **Rent is billed in advance:** `due_date` = period start.
- `issue_date` = max(activation date, `due_date − invoice_lead_days`).
- A **deposit invoice** (one line per agreement unit, `charge_type = deposit`, tax `out_of_scope`) is issued at activation, due on `start_date`.

### 6.3 Issuing, and "cancel and replace"
- The nightly job (and activation itself) issues every scheduled invoice whose `issue_date ≤ today`: it assigns the number, stamps `owner_contract_id` on each line, calculates tax, sets `grace_until = due_date + agreement.grace_days` and sets `issued_at`.
- Finance can **"Issue now"** any scheduled invoice early — needed when a customer pays several periods in advance (VAT tax point, see C2).
- Scheduled invoices are **never edited**. Any change (amendment, termination) cancels the affected scheduled invoices and creates replacements (`replaced_by_invoice_id`). Cheques matched to a cancelled invoice are re-pointed to its replacement automatically; if there is no replacement, the cheque is flagged "to return".

### 6.4 Tax
- If `vat_registered` is false: every line has tax 0 and the invoice is a plain invoice.
- If true: `tax_amount = round(net × tax_rate / 100, 3)` for `standard` lines, and 0 for `zero_rated`, `exempt` and `out_of_scope`. The rate is copied onto the line. Invoices with any taxed line print as "Tax Invoice" with the TRN and a VAT breakdown.
- Tax categories: `standard`, `zero_rated`, `exempt`, `out_of_scope`. Deposit and opening-balance lines are always `out_of_scope`.
- The tax point used for reporting is `issue_date`.

### 6.5 Manual invoices and credit notes
- **Manual invoice** (Finance): free lines, each with an optional unit for owner attribution. Finance issues it directly; no approval.
- **Credit note** (Finance requests, Management approves — §8.3 item 5): each line credits a specific issued line (`credited_line_id`), by at most that line's `total − credited`.
  - If the credited line still has an unpaid balance, the credit reduces it.
  - If the line was already paid, the paid part is first **de-allocated** (reversal allocation rows, §7.2) so the money returns to the payment as customer credit; then the credit is applied. The owner ledger sees the de-allocation as a reversal.
- Credit notes are numbered (CN-YYYY-NNNNNN) on issue.

### 6.6 Overdue
An issued invoice is overdue when `balance > 0` and today > `grace_until`. No job is needed; `grace_until` is fixed at issue, so later setting changes never rewrite history.

### 6.7 Opening balances
Import only (§11): `opening` invoices with `opening_balance` lines per agreement unit (so managed owners are attributed), issued on the cutover date.

### 6.8 Document numbers
`number_sequences`: `key` (agreement | owner_contract | invoice | credit_note | receipt | payment_out | owner_statement | deposit_settlement), `prefix`, `year`, `next_value`, `padding` (6). Format `{PREFIX}-{YYYY}-{NNNNNN}`, reset yearly. The number is taken with `SELECT … FOR UPDATE` inside the same transaction that issues the document, so numbers are **gapless** and never duplicated.

---

## 7. Money in, money out, deposits, owners

### 7.1 Payments in
`payments`: `number` (RCP-YYYY-NNNNNN — also the receipt number), `customer_id`, `received_on` (≤ today), `method` (cash | bank_transfer | cheque | card | deposit_applied), `amount`, `reference`, `cheque_id`, `notes`, `status` (confirmed | reversed), `recorded_by`, `posted_at`.

- Recorded by users with `payments.manage` (Finance). No approval needed to record.
- A receipt PDF is generated and stored on recording.
- `deposit_applied` payments are created only by deposit settlements (§7.7).

### 7.2 Allocation
`payment_allocations`: `payment_id`, `invoice_line_id`, `amount` (negative for reversals), `reverses_allocation_id`, `owner_contract_id` (copied from the line), `posted_at`, `created_by`. Write-once.

Algorithm:
1. **Which invoices:** Finance picks invoices and amounts, or accepts the default: issued invoices with `balance > 0`, oldest `due_date` first, then lowest id.
2. **Within an invoice (D10):** the amount is split across the lines that have a balance, in proportion to each line's balance, using the **largest-remainder method** in fils: each line gets its floored share, and the leftover fils go one at a time to the lines with the largest fractional parts (ties: lowest line id). An amount ≥ the invoice balance pays every line in full. No line ever receives more than its balance.
3. **Remainder:** anything not allocated stays on the payment as **customer credit**.

Customer credit = Σ confirmed payment amounts − Σ live allocations − Σ credit refunds. Whenever an invoice is issued for a customer who has credit, the credit is **auto-allocated** oldest-first.

### 7.3 Reversals
- **Payment reversal** (Finance requests with a reason; Management approves — §8.3 item 6): negative allocation rows for every live allocation, and payment status `reversed`. The stored receipt is not changed; the reversal appears in the ledger and on the payment.
- **Payment-out reversal** (same approval item): marks the disbursement `reversed`, writes the opposite owner-ledger entry or deposit movement, and cancels its issued cheque if there is one.

### 7.4 Cheques
`cheques`: `direction` (received | issued), `customer_id` or `owner_id`, `agreement_id`, `owner_contract_id`, `invoice_id` (target; received only), `cheque_no`, `bank_name`, `account_holder`, `cheque_date`, `amount`, `status`, `deposited_on`, `cleared_on`, `bounced_on`, `bounce_reason`, `returned_on`, `replaced_by_cheque_id`, `payment_id`, `disbursement_id`, `notes`. Cheque image via documents.

**Received cheques**

```
held ──deposit──▶ deposited ──clear──▶ cleared      (creates the payment)
  │                    └──bounce──▶ bounced ──▶ replaced (new cheque linked) | returned
  ├──return──▶ returned   (unused, e.g. after termination)
  └──cancel──▶ cancelled  (entered in error)
```

- At signing, all post-dated cheques are entered on one screen and each is matched to its scheduled invoice.
- **Clearing** creates a payment (`method = cheque`, `received_on = cleared_on`), allocated first to the target invoice, then oldest-first, with any remainder as credit. If the target invoice is still scheduled, it is issued first.
- **A bounce never needs a payment reversal**, because no payment exists until clearing. A cheque returned by the bank *after* clearing is handled as a payment reversal (§7.3).
- Deposits to the bank can be done in batches ("deposit selected").

**Issued cheques** (the company pays an owner or refunds a customer): `issued → cleared | cancelled`. Each is linked to a disbursement; cancelling one is a payment-out reversal.

### 7.5 Payments out
`disbursements`: `number` (PO-YYYY-NNNNNN), `payee_type` (owner | customer), `payee_id`, `purpose` (owner_remittance | head_lease | deposit_refund | credit_refund | other), `amount`, `method` (bank_transfer | cheque | cash), `cheque_id`, `reference`, `paid_on`, `source_type` + `source_id` (owner_statement | owner_payable | deposit_settlement), `status` (paid | reversed), `recorded_by`.

- With an approved source (finalised owner statement, scheduled head-lease payable, approved deposit settlement): no further approval.
- Without a source (e.g. refunding customer credit, an ad-hoc owner payment): Management approval (§8.3 item 9).

### 7.6 Deposits
`deposit_movements`: `agreement_unit_id`, `owner_contract_id` (nullable), `type` (received | applied | refunded | transfer_in | transfer_out | opening), `amount` (signed), `source_type` + `source_id`, `posted_at`. Write-once.

- A `received` movement is written automatically with every allocation to a deposit line (same sign as the allocation).
- **Deposit held** per agreement unit = Σ `amount`. Deposits never count as revenue.

### 7.7 Deposit settlement
`deposit_settlements`: `number` (DS-YYYY-NNNNNN), `agreement_id`, `status` (draft | pending_approval | approved | completed), with `deposit_settlement_units` (`agreement_unit_id`, `held_amount` snapshot) and `deposit_settlement_lines` (`type`: damage | cleaning | utilities | unpaid_rent | other, `description`, `amount`, `invoice_line_id` for unpaid_rent).

- Created as a draft by a move-out (or by a renewal with a lower deposit). Finance completes the deductions, each with a reason; Management approves (§8.3 item 4).
- On approval, in one transaction:
  1. Non-rent deductions (damage, cleaning, utilities, other) become one issued manual invoice.
  2. A `deposit_applied` payment for the total deductions is allocated to that invoice and to the selected unpaid invoices, and matching `applied` deposit movements are written.
  3. Refund = held − deductions. If deductions exceed the deposit, the rest remains owed by the customer.
- Finance then records the refund as a disbursement (source = the settlement), which writes the `refunded` movement. The status becomes `completed` when the refund is paid, or immediately if the refund is zero.

### 7.8 Head-lease payables (leased buildings)
`owner_payables`: `owner_contract_id`, `period_start`, `period_end`, `due_date` (= period start), `amount`, `status` (scheduled | paid | cancelled), `disbursement_id`. Generated on contract activation using the period and proration rules of §6.2. Paid by a disbursement (purpose `head_lease`). Early termination cancels future payables.

### 7.9 Owner ledger and statements (managed buildings)
**Owner ledger** entries for a managed contract, ordered by `posted_at`:

| Entry | Sign |
|---|---|
| Allocations (and their reversals) on lines stamped with the contract, all charge types except deposit | + |
| Deposit movements, only if `deposits_held_by = owner` | same sign as the movement |
| Management fee + VAT on the fee (`owner_charges`, written at statement finalisation) | − |
| Expenses charged to the owner | − |
| Payments to the owner (and their reversals) | − |
| Opening balance (import) | ± |

If `deposits_held_by = company`, deposits don't post to the owner ledger; the statement shows "deposits held by the company on your behalf" for information.

**Statements** — `owner_statements`: `number` (OS-YYYY-NNNNNN), `owner_contract_id`, `period_start`, `period_end` (calendar month), `cutoff_at` (end of `period_end`, Asia/Bahrain), `opening_balance`, `fee_base`, `fee_amount`, `fee_tax`, `closing_balance`, `status` (draft | pending_approval | finalised).

- A statement covers entries with `posted_at` after the previous statement's `cutoff_at` and up to its own. Entries posted later but dated inside the month appear on the next statement, showing their business date. A finalised statement therefore never changes.
- Drafts for every active managed contract are created on the 1st of each month; Finance reviews and submits; Management approves (§8.3 item 8). Finalisation writes the fee `owner_charges` row (`posted_at = cutoff_at`) and stores the PDF.
- **Fee base:** `percent_collected` = rent-line allocations in the window; `percent_billed` = net rent lines issued in the window minus rent credit notes issued in the window; `fixed` = `fee_value`. VAT on the fee applies when `vat_registered` (see C2 on the fee tax invoice). The fee base is **rent only** (see C4).
- Remittance: Finance records a disbursement to the owner (source = the statement) for up to the closing balance.

### 7.10 Ledgers are queries
- **Customer statement — receivables:** issued invoices (debit), issued credit notes (credit), payments (credit, by `received_on`), payment reversals (debit), credit refunds (debit), with a running balance.
- **Customer statement — deposits:** `deposit_movements` per agreement unit, with a running balance held.
- **Owner ledger:** §7.9.
- No ledger table exists, so nothing can drift out of sync. *Limit:* if statements become slow, add a monthly snapshot table.

### 7.11 Integrity check
Nightly: for every invoice line, cached `allocated` and `credited` must equal the sums of their source rows, and every invoice's cached totals must equal the sums of its lines. Any mismatch is logged and emailed to Vendor Support.

---

## 8. Roles, approvals, audit, security

### 8.1 Roles
| Capability | Admin | Management | Finance | Property Mgr | Leasing |
|---|---|---|---|---|---|
| Users, roles, settings, templates | ✔ | – | – | – | – |
| Buildings, units | ✔ | view | view | ✔ | view (assigned) |
| Owners, owner contracts | ✔ | view | ✔ | view | – |
| Customers | ✔ | view | view | ✔ | ✔ |
| Draft/submit agreements, amendments, move-outs | ✔ | view | view | ✔ | ✔ (assigned) |
| **Decide approvals** (§8.3) | – | ✔ | – | – | – |
| Invoices, manual invoices, credit-note requests | view | view | ✔ | – | – |
| Payments, allocations, cheques | view | view | ✔ | – | – |
| Payments out, owner statements, deposit settlements | view | view | ✔ | – | – |
| Expenses | view | view | ✔ | ✔ (enter) | – |
| Operational reports | ✔ | ✔ | ✔ | ✔ | assigned buildings |
| Financial reports, balances, ledgers | ✔ | ✔ | ✔ | – | – |
| Audit log | view | view | – | – | – |

- Roles are seeded defaults; Admin can change which permissions a role has (audited). A user can hold several roles.
- Permissions: `users.manage`, `roles.manage`, `settings.manage`, `templates.manage`, `audit.view`, `buildings.view`, `buildings.manage`, `buildings.view-all`, `owners.view`, `owners.manage`, `customers.view`, `customers.manage`, `agreements.view`, `agreements.manage`, `finance.view`, `invoices.manage`, `payments.manage`, `cheques.manage`, `disbursements.manage`, `expenses.manage`, `reports.operational`, `reports.financial`, `approvals.decide`.
- **Vendor Support:** the vendor's own account in every install, with all permissions. Admin cannot delete it but can disable it (`vendor_support_enabled`); re-enabling goes through the vendor. This keeps each company in control of access to its tenants' personal data (PDPL).
- **Admin has no finance-posting permissions by default.**

### 8.2 Building assignment
`building_user` (`building_id`, `user_id`). A user without `buildings.view-all` sees only their assigned buildings and everything under them: units, agreements (if any of the agreement's units is in an assigned building), owner contracts, expenses, reports, exports, search results and dashboard tiles. The scope is applied in one place (a query scope used by every list, report and export) and covered by the permission test (§14).

Customers are searchable by everyone with `customers.view` (name, ID number, mobile) to prevent duplicates. Balances and ledgers require `finance.view`.

### 8.3 Approvals
`approvals`: `approvable_type`, `approvable_id`, `action`, `status` (pending | approved | rejected), `requested_by`, `requested_at`, `decided_by`, `decided_at`, `comment`, `ip`.

Actions requiring Management approval (`approvals.decide`):

1. Agreement activation (including renewals)
2. Agreement amendment: add or release a unit
3. Early termination
4. Deposit settlement
5. Credit note
6. Reversal of a payment or of a payment out
7. Owner contract activation or early termination
8. Owner statement finalisation
9. Payment out with no approved source

Rules:
- With `require_different_approver` on (the default), the approver must differ from the requester **and** from the creator of the underlying record (agreement creator, payment recorder). Enforced in the Action.
- A pending item is locked against edits. Rejection returns it to draft with the comment.
- Management sees a "Pending my approval" list; each new request also sends an email.
- Single approval step in v1. A multi-step chain can be added later with only a `step` column.

### 8.4 Audit log
- `spatie/laravel-activitylog` on: buildings, units, owners, owner contracts, customers, agreements (with units, charges, amendments), invoices, credit notes, payments, allocations, cheques, disbursements, deposit settlements, owner statements, expenses, settings, users, roles and permissions.
- Explicit entries for: login (success and failure), logout, 2FA changes, document downloads, exports, approval decisions, and Vendor Support enable/disable.
- Each entry records user, IP, user agent, time, and old and new values.
- Visible read-only to Admin and Management. Retained indefinitely.

### 8.5 Immutability (MySQL triggers)
- **No UPDATE or DELETE:** `activity_log`, `payment_allocations`, `deposit_movements`, `owner_charges`; `approvals` once decided.
- **No DELETE:** `invoices`, `invoice_lines`, `payments`, `cheques`, `disbursements`, `owner_payables`, `owner_statements`, `deposit_settlements` (with units and lines), `expenses`, `agreements`, `agreement_units`, `owner_contracts`.
- **No amount changes:** `invoice_lines` amounts once the invoice is issued or cancelled; `payments.amount`; `disbursements.amount`.
- Triggers raise `SIGNAL SQLSTATE '45000'`. Corrections are always reversals, credit notes or cancel-and-replace.
- Other master data (buildings, units, owners, customers) is soft-deleted; foreign keys use `RESTRICT`, so anything still referenced can't be removed.
- *Limit:* a database user with DDL rights can drop the triggers. Production app credentials are the only routine access; DDL auditing is not in v1.

### 8.6 Authentication and security
- Laravel's official Livewire starter kit for login, password reset and **TOTP two-factor authentication** (via Laravel Fortify if the kit doesn't include it).
- 2FA is **mandatory** for Admin, Management, Finance and Vendor Support.
- No self-registration; Admin creates users. Users are deactivated, never deleted.
- Login throttling (5 attempts per minute per email + IP), 30-minute idle session timeout, passwords of at least 12 characters.
- CSRF protection, output escaping and validated Form Requests as standard; policies on every route and Livewire action.
- Uploads: allow-list of file types (pdf, jpg, png, webp, docx, xlsx), 10 MB limit, stored privately under a random name.
- `APP_DEBUG=false` in production; users never see stack traces, SQL or file paths.

---

## 9. Documents and PDFs

### 9.1 Documents
`documents`: `documentable_type`, `documentable_id`, `category`, `path`, `original_name`, `mime`, `size`, `expires_on`, `uploaded_by`. One table for every attachment and photo (units, buildings, owners, customers, agreements, payments, cheques, expenses, move-outs).

### 9.2 PDF engine
- **mPDF** renders Blade → HTML → PDF for every document, with an embedded Arabic font (Noto Naskh Arabic or Amiri). PDFs are generated in a queued job and stored privately.
- **M0 spike (1 day):** a real two-unit contract with English and Arabic clauses side by side, the schedule-of-units table, a QR code, header/footer and page numbers. **Pass:** Arabic letters join correctly; numbers and dates inside Arabic text display in the right order; under 3 seconds per page. **Fallback if it fails:** headless Chromium via `spatie/laravel-pdf` / Browsershot (adds Node and Chromium to every server).

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
The contract's QR code holds a signed URL to a public, rate-limited verification page that shows only: agreement number, status, start date and end date. No names or amounts.

---

## 10. Reports and dashboard

Every report filters by building and date range, respects building assignment, and exports to Excel (`spatie/simple-excel`). Statements also export to PDF.

**Operational:** unit availability and occupancy by building; agreements expiring in 30/60/90 days; expired agreements not closed (overstays); cheques to deposit (today / this week); bounced cheques awaiting action; pending approvals; customer documents expiring.

**Financial:** outstanding by customer; overdue ageing (0–30 / 31–60 / 61–90 / 90+ days past `grace_until`); collections by date and method; customer statement; owner statement; head-lease payments due; deposits held; VAT summary by period (output VAT by category, from issued invoices and credit notes); **building profitability**.

**Building profitability** (per building, for a date range, cash basis, with a billed column alongside):
- Income: allocations on non-deposit lines stamped NULL (owned) or with a leased contract, plus finalised management fees of the building's managed contracts.
- Costs: head-lease disbursements for the building's leased contracts, plus expenses charged to the company.
- Result = income − costs.

**Management dashboard:** 8 number tiles, no charts — occupancy %, rent due this month, collected this month, overdue total, cheques to deposit this week, open bounced cheques, agreements expiring in 60 days, pending approvals.

---

## 11. Data import (go-live)

Each company goes live with tenants already partway through their leases.

- **Excel templates:** buildings; units; owners; owner contracts (with units); customers; active agreements (with units, charges, deposits); opening balance per customer at cutover (per agreement unit where applicable); deposits held; post-dated cheques still held; opening balance per managed owner.
- **Dry run:** every file is validated first, and an error report lists each row's problems. Nothing is saved until all files are clean; then the import runs in a single transaction per file set.
- **Rules:**
  - Imported agreements are created `active`, with an approval row recorded as "Imported by {user}".
  - Scheduled invoices are generated from the cutover date forward only.
  - Leased contracts generate head-lease payables from the cutover date forward only.
  - Balances only: historical invoices stay in the old system.
  - Deposits held are imported as `opening` deposit movements.
- The templates go to the client in M1 so they can start filling them early.

---

## 12. Scheduled jobs

All times are Asia/Bahrain. Every job is idempotent (safe to run twice) and uses `withoutOverlapping()`.

| When | Job |
|---|---|
| Daily 01:00 | Issue scheduled invoices with `issue_date ≤ today`; auto-allocate customer credit |
| Daily 02:00 | Agreements past `end_date`: → `renewed` if an active renewal exists, else → `expired` |
| Daily 02:15 | Owner contracts past `end_date` → `ended` |
| Daily 02:30 | Integrity check (§7.11) |
| Daily 03:30 | Backup (§13.3) |
| 1st of month 04:00 | Draft owner statements for the previous month, for every active managed contract |
| Daily 07:00 | Email Finance: cheques to deposit today/this week, bounces awaiting action |
| Daily 07:00 | Email Management: pending approvals, agreements expiring in 30/60/90 days |
| Monday 07:00 | Email: customer and owner ID documents expiring in the next 30 days |

Customer-facing reminders (SMS/email) are v2/v3.

---

## 13. Operations

### 13.1 Hosting (assumes D12)
- One server per company: 2 vCPU / 4 GB RAM, Ubuntu LTS, Nginx, PHP-FPM, MySQL 8, Supervisor. No Redis.
- Hosted in Bahrain (e.g. the AWS Bahrain region or a local provider) to avoid PDPL cross-border transfer questions — confirm (C3).
- Servers are provisioned and deployed with **Laravel Forge** (or Ploi): SSL, deploy script, queue worker, scheduler, server monitoring.

### 13.2 Install and release
- **`php artisan rms:install`** creates the company settings and the first Admin user, and seeds roles, permissions, number sequences, the default contract template and the Vendor Support account. With Forge, a new company can be live in about an hour.
- **Environments:** local → **staging** (a demo install with fake data that doubles as the sales demo) → one production server per company. A company's UAT runs on its own production server with its imported data before go-live.
- **Releases:** tagged versions from `main`, deployed to staging first, then to each company. Each deploy runs `migrate --force`, caches config/routes/views and restarts the queue workers. Before any release that contains migrations, the migrations are run against a copy of the largest company's database. The Forge dashboard shows which version each company runs.
- **D13 applies to every release:** no company-specific code.

### 13.3 Backups
- `spatie/laravel-backup` nightly: database dump plus `storage/app/private`, as an encrypted archive sent to object storage at a **different provider**. Retention: 30 daily + 12 monthly.
- MySQL binary logs kept for 7 days on the server, for point-in-time recovery from mistakes. *Limit:* the binlogs are local, so losing the server loses up to 24 hours (back to the last nightly backup).
- **Restore test:** a weekly job on staging restores the latest backup of one company (rotating) into a scratch database, runs sanity counts, and alerts on failure. Plus a manual full restore drill every quarter.

### 13.4 Monitoring and errors
- Error tracking (Sentry or Flare), tagged by company.
- An uptime check per install; alerts for failed jobs, low disk space and backup health.
- Users see "Something went wrong. Please try again."; details go only to the logs and error tracking.

---

## 14. Testing

- **Pest, on real MySQL 8 in CI — never SQLite.** Triggers, generated columns and row locks are MySQL behaviour.
- **Feature tests (one per flow):**
  1. Multi-unit agreement → submit → approval by a different user → scheduled invoices with correct periods and proration → unit statuses.
  2. Two overlapping submissions for the same unit → the second is rejected; a non-overlapping pre-lease is accepted.
  3. Issue job run twice → no duplicate invoices; numbers gapless; owner attribution stamped.
  4. Partial payment → the largest-remainder split sums exactly to the fil and never exceeds a line's balance; customer credit auto-allocates on the next issue.
  5. Cheque cleared → payment created; cheque bounced → no payment; replacement linked.
  6. Managed owner statement = collections − fee − expenses − remittances; an entry posted late lands on the next statement; a finalised statement is unchanged.
  7. Head-lease payables generated with proration and paid via a disbursement.
  8. Unit released mid-period → scheduled invoices replaced; prorated credit note; deposit settlement → deductions invoice, deposit applied, refund.
  9. Credit note on a paid line → de-allocation → customer credit.
  10. Renewal → deposit transferred; old agreement → `renewed` after its end date.
  11. **Permission matrix:** one data-driven test of every role against every protected action, including building-assignment scoping of lists, reports and exports.
  12. Immutability: a DELETE on a financial table and an UPDATE on the audit log are rejected by the database.
  13. Approval rule: neither the requester nor the record's creator can approve.
- **Unit tests:** proration, largest-remainder split, fee calculation, tax rounding, period generation.
- **CI (GitHub Actions) on every pull request:** Pest, Pint, Larastan, `composer audit`.
- **Before the first go-live:** an OWASP Top 10 review, and an external penetration test if the client requires one.

---

## 15. Milestones

| # | Milestone | Weeks | Exit criteria |
|---|---|---|---|
| M0 | **Foundation:** starter kit + 2FA, roles and permissions, building assignment, audit log + triggers, settings, number sequences, approvals, documents, `rms:install`, Forge staging, CI, backups, mPDF spike | 2.5 | `rms:install` produces a working install on a fresh server; CI green; a backup restores; mPDF spike passes (or the fallback is chosen) |
| M1 | **Property and owners:** buildings, units, owners, owner contracts + units, expenses, import templates | 2 | The client's real buildings, units, owners and contracts imported into staging |
| M2 | **Customers and agreements:** customers, multi-unit agreements, approval, EN/AR clause templates + frozen PDF, schedule generation + proration, amendments, termination, renewal, move-out, deposit invoices | 3.5 | 10 real agreements (at least one multi-unit across two buildings) entered and approved; the client signs off the EN/AR contract PDF |
| M3 | **Tenant finance:** issuing, manual invoices, credit notes, payments + allocation, customer credit, cheques (both directions), payments out, customer statements, deposit settlements | 4 | One full month's rent cycle reconciles to the fil against the client's existing records |
| M4 | **Owner finance:** head-lease payables, owner ledger, fees, statements + finalisation, remittances, building profitability | 2 | One managed owner's statement and one head-lease schedule match the client's current figures |
| M5 | **Go-live:** reports, dashboard, exports, import dry run + final import, UAT, security review, training, cutover | 2.5 | UAT signed; live in production |
| | **v1 total** | **16.5** | |

**v2 (~10 weeks):** customer portal; owner portal; online payment gateway (`payment_transactions`, webhook-verified, idempotent) behind a `PaymentGateway` interface; maintenance tickets with costs charged to company/owner/tenant and owner approval of spend; vendors; move-in/move-out checklists; customer email/SMS notifications.

**v3 (~8 weeks):** leads, site visits, reservations (with expiry and deposit) and sales performance; charts; journal export to accounting software; multi-step approvals; automated customer reminders; a public REST API for a mobile app.

---

## 16. Out of scope for v1

Customer and owner portals; online payments; maintenance; vendors; leads, sales, reservations and quotations; SMS/WhatsApp; e-signature; a mobile app and public API; a general ledger / chart of accounts; late fees; utility billing; multi-currency; an Arabic application UI; multi-step approvals; smart-building integration; any AI features.

---

## 17. Confirmations needed (not blocking the build)

| # | Question | Who confirms | Default until confirmed |
|---|---|---|---|
| C1 | Does the vendor host every install (D12)? | Product owner | Yes |
| C2 | VAT: is residential rent exempt and commercial rent standard-rated; what is the tax point for payments received in advance; do tax invoices need Arabic; can the owner statement serve as the tax invoice for the management fee? | Tax accountant | Defaults in §3; English tax invoices; fee shown with VAT on the statement |
| C3 | PDPL: hosting location, a data processing agreement between the vendor and each company, retention of ID copies | Bahrain lawyer | Host in Bahrain; keep ID copies for the life of the customer record |
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
