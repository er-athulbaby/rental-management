# Report builder — design

**Status:** approved in conversation, 15 October 2026. Implements rank 1 of the Buildium comparison.
**Builds on:** the v1 design (`2026-09-28-rental-management-v1-design.md`), §8 (permissions, building scope, audit) and §10 (reports).

## 1. Purpose

Let staff answer their own questions from the data without a developer: pick a subject, choose columns and
filters, group and total, chart, compare periods, save, share, export to Excel and email on a schedule. The 13
ready-made reports stay as they are.

**Out of scope:** owner or tenant recipients (portals come later), outside email addresses, pivot tables,
calculated columns typed by users, custom fields, a general ledger, report builder access for Vendor Support
beyond what its permissions already give.

## 2. Approach

Each subject is a small PHP class that declares where its rows come from (always scoped to the user) and the fields
it offers. One engine turns a saved **report definition** (a validated description, never SQL) into database
queries. Grouping, totals and comparison are computed in the database on exact DECIMAL values.

Rejected: exposing database columns automatically (leaks internal fields, breaks on schema change, bypasses
permissions); grouping in PHP (slow at scale, no safer).

## 3. Screens

### 3.1 Reports page
A **My reports** area above the existing catalogue, with two tabs: **My reports** (private to me) and **Shared**.
Each card: name, subject, creator, last run. **New report** opens the builder.

### 3.2 Builder (`/reports/builder/{report?}`)
- **Left panel:** Subject · Columns (tick, drag to order; grouped by section) · Filters (rules) · Period (date
  field + range) · Group by (up to 2) with totals · Sort · Compare with · Chart.
- **Main area:** chart (when set) above the results table; group subtotals and grand total; live as settings
  change.
- **Top bar:** name, Save, Save as copy, Share (Only me / Everyone with report access), Export to Excel,
  Schedule email, Delete.
- Changing the subject resets columns, filters, grouping and sort (after a confirm if anything was set).

### 3.3 Running a saved report
Opens with results. Anyone who can see it may change the period or filters **for that run** (not saved). Only
the creator or an Admin saves changes; others can **Save as copy** (a new private report they own).

## 4. Subjects and fields

### 4.1 Field model
A field declares: `key`, `label`, `section` (for the column picker), `type`, the SQL expression or join it reads,
and flags `filterable`, `groupable`, `sortable`, `aggregates` (allowed totals), `permission` (extra permission
needed, e.g. `reports.financial` for money on a leasing subject).

| Type | Filter operators | Group | Totals |
|---|---|---|---|
| text | is, is not, contains, is empty, is not empty | yes | count |
| enum (status, method, type…) | is any of, is none of | yes | count |
| money (BHD, 3 dp) | =, ≠, >, ≥, <, ≤, between | no | sum, average, min, max |
| number | as money | no | sum, average, min, max |
| date | on, before, after, between, relative range | by day, month, quarter, year | min, max |
| boolean | is yes, is no | yes | count |
| reference (tenant, building, unit, owner…) | is any of (picker) | yes | count |

Every subject offers **Count of rows**. Money sums use SQL `SUM` on DECIMAL columns; averages are rounded
half-up to 3 dp for display. Internal columns (ids, tokens, `posted_at`, hashes) are never fields.

### 4.2 Subjects
Each subject has a **base query** that applies the same `visibleTo($user)` scope as its screens, a **required
permission**, a **default column set**, and either **period date fields** or **as-at** behaviour.

| Subject | Row | Permission | Period fields | Main fields |
|---|---|---|---|---|
| Invoices | invoice (credit notes included, type field) | reports.financial | issue date, due date | number, type, tenant, agreement, building, units, issue date, due date, display status (incl. Overdue, Paid, Partially paid), net, VAT, total, paid, credited, balance, days overdue |
| Payments | receipt | reports.financial | received on | number, tenant, building, received on, method (split → "Split"), amount, applied, left as credit, status, recorded by |
| Cheques | received cheque | reports.financial | cheque date, cleared on | number, bank, tenant, building, cheque date, amount, status, deposited on, cleared on, bounced on, bounce reason |
| Agreements | agreement | reports.operational | start date, end date | number, tenant, building, units, start, end, status, monthly rent, deposit, frequency, days to expiry, notice date, planned exit |
| Units | unit | reports.operational | as at a date | building, code, type, use, furnishing, occupancy (occupied / vacant / blocked, as at the date, using `Queries::occupiedUnitIds`), list rent, current tenant, current rent, agreement end |
| Tenants | tenant | reports.operational | — | name, type, ID type, nationality, mobile, buildings, active agreements; outstanding and credit need reports.financial |
| Owners | owner | reports.financial | — | name, type, ID type, active contracts, buildings |
| Owner contracts | owner contract | reports.financial | start date, end date | number, owner, building, type, status, start, end, rent, fee type, fee value, units |
| Owner statements | statement | reports.financial | period end | number, owner, building, period start, period end, opening, fee base, fee, fee VAT, closing, status (stored figures only; collections, expenses and remittances need the owner ledger and are not fields in this version) |
| Expenses | expense | reports.financial | expense date | building, unit, category, date, net, VAT, total, charged to, status |
| Payments out | disbursement | reports.financial | paid on | number, payee type, payee, purpose, building, amount, method, paid on, status |
| Deposits held | agreement unit | reports.financial | as at a date | tenant, agreement, building, unit, deposit agreed, held now, settled |

Field lists are the minimum; a field the owning screen already shows may be added during the build if it costs one
line.

### 4.3 Building on records that span buildings
An invoice or payment whose units sit in more than one building has Building = **"Multiple"**. Grouping by
building puts it under "Multiple" (no double counting). The Building **filter** matches it under each of its
buildings (using the existing `inBuilding` scopes). A payment held wholly as credit takes its tenant's
building(s) per the existing `Payment::inBuilding` rule; if that is several, "Multiple".

### 4.4 Periods
Relative ranges: Today, Yesterday, This week, Last week, Last 7 days, This month, Last month, Month to date, This
quarter, Last quarter, This year, Last year, Year to date. Weeks start on Sunday. Custom: from–to dates. All
resolved in Asia/Bahrain. As-at subjects take an "as at" date (default today).

## 5. Grouping, totals, charts

- Up to **2 group levels** on groupable fields; dates group by day, month, quarter or year.
- Each money/number column chooses a total (sum, average, min, max); Count of rows is always available.
- Group header rows carry subtotals; groups can be collapsed; a grand total row closes the table.
- **Summary only** hides detail rows.
- **Sort:** by any sortable column; within grouped reports, groups can sort by a total.
- **Charts** (grouped reports only): Bars (categories) or Columns (when level 1 is a date bucket); plots one
  chosen total of level 1; drawn with `<x-report-chart>` (validated palette, light/dark, hover figures, table stays
  the exact view). With comparison: two shades side by side + legend.
- **Limits:** 500 detail rows on screen ("showing 500 of N — export for all"); up to 1,000 groups; Excel exports
  everything with subtotal rows.

## 6. Period comparison

- Offered only for subjects with a period field. Options: previous period (same length, immediately before) or
  same period last year.
- Applies to totals: per group (grouped) or the grand total (ungrouped). For each total column: This period,
  Comparison period, Change, Change %.
- A group present in only one period shows 0 for the other. Change % is blank when the comparison value is 0.
- Direction shown with ▲/▼ and sign, no red/green.
- Both periods use identical filters, permissions and building scope; period labels show real dates.

## 7. Scheduled email

- Creator or Admin schedules a **saved** report: daily, weekly (day), or monthly (day 1–28 or last day), at a
  chosen hour, Asia/Bahrain. Several schedules per report allowed.
- **Recipients:** active users only (picker), never Vendor Support, never outside addresses.
- Fixed date ranges warn "will send the same dates every time"; relative periods are the norm.
- **Email:** report name, period (real dates), row count, grand totals (+ comparison), Excel attachment, link to
  open in the app. Over 10 MB → no attachment, link only.
- **Per recipient:** the report runs as each recipient (their permissions and buildings); a recipient without
  access to the subject is skipped and noted on the delivery.
- **Runner:** `rms:reports:send-scheduled`, scheduled every 15 minutes with a heartbeat
  (`HEARTBEAT_REPORT_SCHEDULES`). It claims due schedules and queues one job per recipient.
- **Idempotency:** `report_deliveries` unique on (schedule, due_at); a retry never re-sends.
- **Failures:** the creator is emailed; 3 consecutive failures pause the schedule. Creator deactivated → pause.
  Report deleted → schedules removed.
- Every send is audited (report, recipients, period).

## 8. Permissions and safety

- Builder access: `reports.operational` or `reports.financial`. Subjects and fields filtered by §4 permissions.
  No new permissions.
- Shared reports run as the viewer; unavailable subjects hide the report from that viewer; unavailable fields are
  dropped with a visible note.
- Edit/delete/share/schedule: the creator, or a user with `settings.manage` (Admin).
- Definitions are validated on save and on run against the subject registry; unknown subject, field, operator or
  bad value → refused. Values are bound parameters; no SQL is stored or accepted.
- Read-only; runs as `rms_app`. Limits: 30 columns, 20 filters, 2 group levels, 30-second query time limit
  (`MAX_EXECUTION_TIME` hint), row caps above.
- Audit events: `report.saved`, `report.shared`, `report.deleted`, `report.exported` (existing), `report.scheduled`,
  `report.sent`.

## 9. Storage

- `saved_reports`: id, name (≤120), subject, definition (JSON, with `version`), visibility (`private` | `shared`),
  created_by, updated_by, last_run_at, timestamps, soft deletes.
- `report_schedules`: id, saved_report_id, frequency (`daily` | `weekly` | `monthly`), day (nullable), hour
  (0–23), next_run_at, consecutive_failures, paused_at, created_by, timestamps; recipients in
  `report_schedule_user`.
- `report_deliveries`: id, report_schedule_id, due_at (unique with schedule), status (`sent` | `failed` |
  `skipped`), recipients_count, error, sent_at.
- These tables are not under the immutability triggers (they are configuration, not money); `EXPECTED_TRIGGERS`
  is unchanged.

## 10. Code layout

- `app/Reports/Builder/Subject.php` (abstract) and `Subjects/*` (12 classes); `Field.php`; `Registry.php`.
- `ReportDefinition.php` (validated value object, versioned), `Engine.php` (compile + run: rows, groups, totals,
  comparison), `Period.php` (relative ranges), `ExcelWriter.php`.
- Livewire: `Reports/Builder.php` + view; `Reports/Index` gains My reports.
- Scheduling: `Console/Commands/SendScheduledReports.php`, `Jobs/SendScheduledReport.php`,
  `Notifications/ScheduledReport.php`.
- Models: `SavedReport`, `ReportSchedule`, `ReportDelivery`; policy `SavedReportPolicy`.

## 11. Testing

- Per subject: default columns render; each filter type; grouped sums equal fixture totals to the fil.
- Scope: a Leasing user with one building never sees another building's rows on any subject; financial fields
  hidden without `reports.financial`.
- "Multiple" building: no double counting in grouped totals; filter finds the record under each building.
- Periods: each relative range resolves to the right dates in Asia/Bahrain; comparison periods correct
  (month, quarter, custom, last year, leap years).
- Definition validation: unknown field/operator/subject and bad values refused; old `version` upgraded.
- Sharing: private hidden from others; shared runs as viewer; edit rights; Save as copy.
- Scheduling: due-time calculation (day 31 → last day), per-recipient scoping, idempotent retry, pause after 3
  failures and on creator deactivation, Vendor Support never a recipient, 10 MB rule.
- Excel matches the screen (including subtotal rows); export audited.
- Performance: 10,000 invoices, grouped by building and month, under 2 seconds on the dev machine.

## 12. Build order

1. Engine, registry, definition, periods; subjects Invoices, Payments, Agreements, Units; builder with columns,
   filters, sort, save, share, Excel; My reports on the Reports page.
2. The other 8 subjects.
3. Grouping, totals, charts.
4. Period comparison.
5. Scheduled email.

Each stage is usable on its own and ends with the full test suite green.
