# Ledger money screens — design

**Status:** approved in conversation, 28 October 2026. Follows the general ledger
(`2026-10-24-general-ledger-design.md`); removes the most common manual journals.

## 1. Purpose

Give the three most common non-tenant money movements their own screens so they post to the ledger automatically:
paying vendors, cash and bank movements, and VAT returns. After this, manual journals are for corrections and
year-end only.

**Out of scope:** partial payment of an expense; bank reconciliation; VAT return forms or filing to NBR; approval for
cash and bank movements or VAT returns.

## 2. Paying a vendor (through Payments out)

- Payments out gains purpose **Vendor bill** and payee type **Vendor** (the vendor list).
- Choosing a vendor lists **unpaid expenses**: expenses from that vendor's work orders first, plus any other unpaid
  expense, which can be added by hand. Tick the expenses; the payment total is the sum of their full amounts.
- The link payment ↔ expenses is a new table (`disbursement_expenses`), because expenses are immutable once recorded.
  An expense can belong to only one non-rejected, non-reversed payment at a time.
- The existing approval, cheque, mark-paid and reversal flow applies unchanged.
- An expense is **Paid** (with a link to the payment) when its payment is paid; a reversal makes it unpaid again; a
  rejected payment frees its expenses.
- **Ledger:** marking paid posts Vendor payable → the payment method's account; reversal posts the opposite.
- **Ledger health:** the vendor row compares Vendor payable with the sum of unpaid expenses (charged to company,
  owner or tenant) from the start date, minus reversals — real unpaid bills.

## 3. Cash and bank movements (new page)

One form, Finance, each with date, amount, reference and an optional note:

| Kind | Debit | Credit |
|---|---|---|
| Transfer between money accounts (asset accounts flagged as money: Bank, Cash on hand, Card clearing, and any Admin adds) | to-account | from-account |
| Bank charge | Bank charges (new expense account) | the chosen money account |
| Bank interest received | the chosen money account | Bank interest income (new income account) |

- Transfer needs two different money accounts. Each movement can be reversed once (exact opposite). Listed with
  filters by date, kind and account.

## 4. VAT return (new page)

- Pick a period (from–to); the page shows VAT payable for the period from the ledger (credits minus debits on the
  VAT payable account dated in the period, excluding VAT return journals).
- Record the payment to NBR: date, method (bank transfer, cheque, cash), reference, amount (prefilled with the
  figure). Posts VAT payable → the method's account.
- If the period figure is a debit balance (more input than output), record a **refund** instead: method's account →
  VAT payable.
- A period may not overlap an existing non-reversed return. Each return can be reversed once.
- The list shows each period, the ledger figure, the amount paid or refunded, and the difference.

## 5. Shared rules

- Finance (`ledger.manage`) records and reverses; `ledger.view` (Finance, Admin) views.
- Audited; nothing dated on or before the ledger lock; dates within start date … today.
- BHD 3 dp through `Fils`; tampered input gives a message, never a 500; EN/AR.
- New accounts: **Bank charges** (expense) and **Bank interest income** (income), with rules `bank_charges` and
  `bank_interest`, inserted by migration; an account flag `is_money` marks money accounts (Bank, Cash on hand, Card
  clearing seeded true; Admin can set it on new asset accounts).
- New ledger source types: `vendor payments` via the existing disbursement source (purpose vendor_bill) and its
  reversal; `cash_movement` and its reversal; `vat_return` and its reversal — all through the existing Poster.

## 6. Testing

- Vendor bill: listing unpaid expenses per vendor; one active payment per expense; approval, paid, reversal and
  rejection update Paid status; ledger postings exact; health vendor row equals unpaid bills.
- Cash movements: each kind posts exactly; transfer refuses the same account and non-money accounts; reversal once.
- VAT return: period figure from the ledger; payment and refund post exactly; overlapping periods refused; reversal.
- Permissions, locked dates, tampered input, Arabic.
