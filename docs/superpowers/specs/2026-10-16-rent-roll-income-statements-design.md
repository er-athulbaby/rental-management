# Rent roll and income statements — design

**Status:** approved in conversation, 16 October 2026. Implements rank 2 of the Buildium comparison.
**Builds on:** the v1 design §10 (reports), `App\Reports\BuildingProfitability`, `App\Billing\OwnerLedger` and
`App\Billing\OwnerStatementCalculator`.

## 1. Purpose

Three ready-made reports managers and owners ask for first: a **rent roll**, the **company income statement**, and
an **owner income statement**. All are built from data already held; no new tables.

**Out of scope:** a general ledger, balance sheet or cash-flow statement; budgets; editing figures; owner logins.

## 2. Approach

One calculator, `App\Reports\IncomeStatement`, computes every income-statement line for a scope (all buildings, one
building, or one owner) over a period, month by month, on either basis. Both income-statement screens and
`BuildingProfitability` read from it, so their figures always agree. The rent roll is a query over units, using the
existing occupancy rule (`Queries::occupiedUnitIds`).

Rejected: adding these as report builder subjects. An income statement is lines × months, not a list of records.

## 3. Rent roll

- **Where:** Reports page, Leasing group. Permission `reports.operational`; money columns need `reports.financial`.
- **Inputs:** as-at date (default today, dd/mm/yyyy), building filter (`<x-building-filter>`), user's building scope.
- **One row per unit:** building, unit code, unit type, status (occupied / vacant / blocked as at the date), tenant,
  agreement number, start, end, monthly rent, deposit held, balance owed.
  - Monthly rent = the agreement's rent as at the date (amendments applied). Vacant units show their list rent in
    muted text; blocked units show none.
  - Deposit held = the same figure as the Deposits held report for that agreement unit.
  - Balance owed = the tenant's issued, unpaid invoice balance on that agreement (whole agreement, shown on its first
    unit row when an agreement spans several units, "(all units)" on the others).
- **Building subtotal row:** units, occupied, occupancy % (occupied ÷ (units − blocked), as the Occupancy report),
  total monthly rent, deposits held, balance owed. A grand total row closes the table.
- Sorted by building, then unit code. Excel export audited as `report.exported` like the other reports.

## 4. Company income statement

- **Where:** Reports page, Finance group. Permission `reports.financial`; user's building scope applies.
- **Inputs:** period (from/to months, at most 12; default the last 12 complete months) — one column per month plus
  Total; basis **Collected** (default) or **Billed**; scope: all buildings, one building, or one owner.
- **Lines** (every amount net of VAT, BHD 3 dp, sums in SQL on DECIMAL):

| Line | Collected | Billed |
|---|---|---|
| Rent, Service charges, Parking | allocations posted in the month | issued invoice lines (by issue date) less credit notes |
| Other tenant charges (other, damage, cleaning, utilities, opening balance) | as above | as above |
| Management fees | finalised management-fee owner charges posted in the month | same |
| **Total income** | | |
| Head lease | paid head-lease disbursements (by paid on) | owner payables due in the month (not replaced) |
| One line per expense category with any amount | recorded expenses by expense date, charged to company or tenant | same |
| **Total costs** | | |
| **Result** | income − costs | income − costs |

- **Company-earned lines only:** lines on owned units (no owner contract) or leased-contract units; never managed
  units (that rent is the owner's) and never deposits. This is `BuildingProfitability`'s rule.
- **Expense VAT:** a VAT-registered company counts expenses net; otherwise total (VAT is a cost).
- **Scope = owner:** that owner's contracts only — leased: income from those units less the head lease; managed:
  their management fees.
- **Lines without a unit** (some manual invoices) cannot be placed in a building: they appear only under "All
  buildings", on their own line "Not linked to a unit".
- Empty lines are hidden; a period with no figures shows "Nothing in this period."
- Excel: the same table, one row per line, months as columns.

## 5. Owner income statement

- **Where:** Reports page, Finance group. Permission `reports.financial`.
- **Inputs:** owner (searchable picker), period and basis as §4. An owner is listed only if the user can see at least
  one building of their contracts.

| Line | Managed contracts | Leased contracts |
|---|---|---|
| Rent and other charges, one line per charge type | collected or billed on their units (net of VAT, no deposits) | — |
| Head lease earned | — | paid (Collected) or due (Billed) in the month |
| Less management fee (+ fee VAT when the company is VAT-registered) | finalised fees, by posted date | — |
| Less expenses charged to the owner | recorded expenses, by expense date | same |
| **Net income to owner** | | |
| *Memo:* paid to owner | remittances by paid on (reversals subtract) | head-lease payments |

- Collected figures come from `OwnerLedger` entries (net of VAT), excluding deposit kinds. Month totals use the entry
  date in Asia/Bahrain; they agree with finalised owner statements when statement periods are calendar months.
- A month with no finalised statement shows the fee as "–" with the footnote "Fee not final until the owner
  statement for this month is finalised."
- Excel as §4.

## 6. Building profitability

Stays as the quick per-building summary; its figures come from `IncomeStatement` (Collected basis, one building).
Its existing tests must still pass unchanged.

## 7. Safety

Read-only; runs as `rms_app`. Building scope via the existing `visibleTo` / `inBuilding` scopes; owner scope never
widens a user's buildings. Inputs are whitelisted (basis, month range, ids that the user can see); tampered input
gives a validation message, never a 500. All strings through `__()`; UI says "tenant".

## 8. Testing

- Rent roll: occupied, vacant and blocked units as at a date; amended rent; multi-unit agreement balance shown once;
  subtotals and occupancy %; money hidden without `reports.financial`; a building-scoped user sees only their
  buildings.
- Company statement: each line on each basis matches a hand-built fixture to the fil; managed-unit rent excluded;
  deposits excluded; credit notes reduce Billed; replaced payables excluded; VAT-registered vs not for expenses;
  owner scope; "Not linked to a unit" only under all buildings; Collected total for one building equals
  `BuildingProfitability`.
- Owner statement: managed and leased owners; fee "–" before finalisation; memo remittances with a reversal;
  deposits excluded; agrees with a finalised calendar-month owner statement.
- Period limits (max 12 months), tampered basis and ids, Excel export audited.
