# Report packages — design

**Status:** approved in conversation, 17 October 2026. Completes rank 3 of the Buildium comparison.
**Builds on:** the report builder design (`2026-10-15-report-builder-design.md`, §7 scheduled email) and the ready-made
reports on `App\Livewire\Reports\Concerns\ReportPage` / `StatementPage`.

## 1. Purpose

Bundle several reports — saved custom reports and ready-made reports — into one named **package**, download it as one
Excel workbook, and email it to staff on a schedule (for example a monthly management pack).

**Out of scope:** owner or outside recipients; PDF output; Building profitability, Head-lease due and Owner statements
(separate screens, not on the shared report building blocks); per-item recipients.

## 2. Approach

Each ready-made report already computes its columns and rows inside its Livewire class. A package runs that same code
**headless**: the runner creates the report class, sets its settings, runs it as the recipient, and takes its columns and
rows — the same data its own Excel export writes. Saved custom reports run through `Engine::run($user, $def, null)` and
`ExcelWriter`. One workbook, one sheet per item.

Rejected: moving every ready-made report's figures into separate query classes (rewrites 14 reports for no user-visible
gain).

## 3. What a package is

- **Package:** name (≤120), visibility `private` | `shared` (as saved reports), creator. The creator or a user with
  `settings.manage` edits, shares, schedules and deletes it. Anyone who can see it may download it.
- **Items:** an ordered list, 1–15. Each item is one of:
  - a **saved report** the editor can see (private-own or shared), or
  - a **ready-made report** — one of the 14 reports on `ReportPage` / `StatementPage` — with its settings:
    - building: all or one (only for reports that have a building filter);
    - period: a relative preset from `App\Reports\Builder\Period::presets()` (Last month, This month, Last quarter, Year
      to date, …), resolved in Asia/Bahrain on each run. For single-date reports the date is the period's last day,
      or "today" when the preset is "Today". Reports without dates ignore it. Statement reports use the months the
      period covers (capped at 12, most recent kept);
    - the report's own options (e.g. collected / billed, owner for the owner income statement, days for expiring).
  Settings are validated against that report's own rules on save and again on every run; an item whose settings no
  longer validate (e.g. a deleted owner) is skipped and noted, never a 500.
- **Where:** a **Packages** area on the Reports page under My reports (Mine / Shared cards, **New package**). The
  package screen (`/reports/packages/{package?}`) has the item list (add, remove, move up / down, edit settings),
  Save, Share, Delete, **Download now** and **Schedule email**.
- **Permissions:** no new permissions. Package screens need `reports.operational` or `reports.financial`. Each item
  runs only if the person it runs as has that report's permission; otherwise it is skipped with a note.

## 4. Running a package

`App\Reports\Packages\PackageRunner::run(ReportPackage, User $as, CarbonImmutable $now): PackageResult` — for each item
in order, run it as `$as` and collect `{title, period label, columns, rows, row count, headline total, skipped reason?}`.

- Ready-made reports: a public method on both shared building blocks returns the export's columns and rows for given
  settings, acting as a given user (the auth user is swapped for the run and always restored).
- Saved reports: the definition is validated for `$as` with `ReportDefinition::forViewer`; dropped fields are noted on
  the sheet.
- `PackageWorkbook::write(PackageResult, string $path)`: one sheet per item in package order, named after the report
  (cut to 31 characters, `(2)`, `(3)` on clashes, characters Excel forbids removed). Each sheet: a title row, a period
  row, a blank row, then the same header and rows as that report's own export. A skipped item gets a sheet with the
  reason only.

## 5. Scheduled email

- **Schedules reuse `report_schedules`.** Add nullable `report_package_id`; `saved_report_id` becomes nullable; a CHECK
  requires exactly one. Frequency, day, hour, recipients, `next_run_at`, deliveries, idempotency, failure counting,
  pausing (3 failures, creator deactivated) and audit (`report.scheduled`, `report.sent`) behave exactly as for saved
  reports. Deleting a package deletes its schedules (cascade).
- **Runner:** `rms:reports:send-scheduled` also claims due package schedules. The job builds the package per recipient
  (independently: one recipient's failure never blocks another), as now.
- **Recipients:** active staff only, never Vendor Support, never outside addresses (existing rule).
- **Every item skipped** for a recipient → nothing sent to them; noted on the delivery.
- **Email:** subject "`<package name>` — `<period>`". Body: one line per item — name, real dates, row count, headline
  total (the report's first money total, if any) — and a list of skipped items with reasons. One `.xlsx` attachment;
  over 10 MB → no attachment, link only. "Open in the app" links to the package screen.
- **Limits:** 15 items; one email job per delivery with the existing 300-second timeout; a timeout is one failure and
  the creator is told.

## 6. Download now

Builds the same workbook for the person clicking and streams it; audited as `report.exported` with
`{report: 'package', package: <id>}`. Temp files are deleted after sending.

## 7. Storage

- `report_packages`: id, name, visibility, created_by, updated_by, timestamps, soft deletes.
- `report_package_items`: id, report_package_id (cascade), position, kind (`saved` | `ready_made`), saved_report_id
  (nullable), report (ready-made key, nullable), settings (JSON), timestamps. CHECK: kind matches which reference is
  set. A deleted saved report leaves its item, which is then skipped with the note "Report deleted".
- No triggers; `EXPECTED_TRIGGERS` unchanged.

## 8. Safety

Read-only runs as `rms_app`. Item settings are whitelisted per report; ids are checked against what the runner's user
can see on every run. Tampered Livewire input gives a validation message, never a 500. All strings through `__()`; UI
says "tenant".

## 9. Testing

- Package CRUD and sharing rules (creator, Admin, viewers download only).
- Each of the 14 ready-made reports runs headless with settings and matches its own Excel export for the same settings.
- Saved report items; a deleted saved report; an item the user lacks permission for; an item whose settings no longer
  validate.
- Relative periods resolve per run; statement month capping.
- Workbook: sheet order, names (truncation, clashes, forbidden characters), title and period rows.
- Schedules: package schedule claimed and sent; per-recipient scope (a building-scoped recipient gets only their
  buildings); all-skipped recipient gets nothing; exactly-one CHECK; existing saved-report schedule tests unchanged.
- Email body lines and the 10 MB rule; Download now audited.
