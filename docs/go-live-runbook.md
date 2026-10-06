# Go-live runbook

One install per company (spec D2). Times are Asia/Bahrain. Vendor Support runs the import; Finance and Management sign off the figures.
The full checklist for onboarding a new company, from empty server to handover, is [`new-company-setup.md`](new-company-setup.md).

## Key dates

The cutover date agreed with the client is **Sunday 1 November 2026**. Everything is as at 00:00 that day.

| When | What |
|---|---|
| By Sun 18 Oct 2026 (T−14) | Templates filled; staging rehearsal starts |
| Before Sat 31 Oct 2026 | `php artisan rms:setting go_live_at 2026-11-01` on production |
| Sat 31 Oct 2026, evening (T−1) | Freeze, dry run, reconcile, import. **Must finish before 00:00.** This is a Bahrain weekend day: book Vendor Support, Finance and the client's sign-off in advance |
| Sun 1 Nov 2026, 00:00 | Imports close |
| Sun 1 Nov 2026, 01:00 | First nightly invoice run |
| Sun 1 Nov 2026, 02:30 | First nightly integrity check |
| Sun 1 Nov 2026 | Cutover-day spot checks with the client |
| Tue 1 Dec 2026, 04:00 | First owner statements drafted, for November 2026 |
| Tue 1 Dec 2026, 04:30 | 2027 number sequences created |

What the date means for billing:
- Rent and head-lease schedules start with the first period that begins on or after 1 November 2026.
- An October period, or any period that began before 1 November, was billed in the old system. Its unpaid part goes in the opening balance.

## Before cutover (T−14 to T−1)

1. **Templates.** The client fills every template from the import screen (Data import → Download template). ID, phone, IBAN, cheque-number and money columns must be **Text** in Excel; numbers there are refused.
2. **Staging rehearsal.** Restore last night's production backup to staging, set a go-live date, and run a dry run with the full file set. Repeat until it reports no problems.
3. **Cutover date: 1 November 2026**, agreed with the client. Everything is as at the start of that day:
   - scheduled rent and head-lease payments start with the first period that begins on or after it;
   - the period containing it was billed in the old system, and its unpaid part is in the opening balance.
4. **What to import.** The files hold the position at the start of the cutover day:
   - import only the agreement running at cutover. A renewal in the old system that starts after cutover is not imported: enter it as a renewal in the app after go-live;
   - agreement units that ended before cutover are not imported (the import refuses them);
   - post-dated cheques dated before cutover but still not deposited are imported as held, and deposited as usual after go-live.
5. **Security review** (sign each line):
   - `APP_DEBUG=false`, `APP_ENV=production`, HTTPS only, HSTS on.
   - Every user with Finance, Management, Admin or Vendor Support has two-factor on (enforced at sign-in).
   - The app connects as `rms_app` (no DDL, no DROP); migrations ran as `rms_migrate`; `php artisan rms:integrity-check` reports nothing.
   - `composer audit` reports nothing unresolved; the server packages are current.
   - Last night's backup restored on staging with its documents (`php artisan backup:list`; the restore check passed).
   - Forge heartbeats exist for every scheduled job and alert the vendor.
   - Roles reviewed with the client: who is Finance, Management, Leasing, Property Manager; building assignments for Leasing.
6. **Training** (one session per role, on staging with the rehearsal data):
   - Leasing: customers, agreements, renewals, move-outs.
   - Finance: payments, cheques, payments out, credit notes, settlements, owner statements, remittances.
   - Management: the approvals screen and the reports.

## Evening of Saturday 31 October 2026 (must finish before 00:00)

Imports close at 00:00 on Sunday 1 November 2026, so everything below happens on the evening before.

1. `php artisan rms:setting go_live_at 2026-11-01`, if it is not set already.
2. **Freeze the old system** at the close of business and export from it:
   - each customer's balance (per agreement unit where the client tracks it);
   - each deposit held;
   - each cheque still held;
   - each managed owner's balance.
   Keep the totals: they are checked against the import's totals.
3. Upload the full file set and run a **dry run**. Fix and repeat until it is clean.
4. **Reconcile.** Compare the dry run's totals with the old system's totals from step 2:
   - customer balances;
   - deposits held;
   - cheques;
   - owner balances.
   They must match to the fil. Investigate any difference before importing.
5. **Import** the full file set once. Each run is one transaction; nothing is saved unless every row of every file passes.
6. **Fallback.** If the import cannot be committed before 00:00, move the date before anything is committed: `php artisan rms:setting go_live_at 2026-12-01`, and repeat this evening's steps on Monday 30 November 2026. Moving to the next 1st keeps billing and owner statements on whole months. Never move it after a commit: the import is as at its date.

## Cutover day: Sunday 1 November 2026

1. Spot-check with the client:
   - three customer statements;
   - one multi-unit agreement's schedule;
   - one managed owner's ledger;
   - one leased contract's head-lease schedule.

## First week

- **Sun 1 Nov, 01:00:** invoices due are issued. Check the run's log and heartbeat.
- **Sun 1 Nov, 02:30:** the integrity check reports nothing.
- **Mon 2 Nov, 07:00:** the first digest emails go out (cheques; approvals and expiring agreements). Mondays also bring the ID-documents digest. Confirm that they arrive.
- **UAT sign-off, by Sat 7 Nov 2026.** The client walks through the spec §14 flows on production data and signs; the flows are listed in the UAT checklist below.
- **Tue 1 Dec 2026, 04:00:** the first owner statements are drafted, for November 2026. No statement is drafted for October or earlier, because the opening owner balance carries them. Finance reviews the drafts before submitting.

## UAT checklist (spec §14)

- [ ] Agreement approval → schedule and deposit invoice (flow 1)
- [ ] Payment allocation, partial and full; receipt PDF (flow 2)
- [ ] Credit note on a partly paid line (flow 9)
- [ ] Cheque held → deposited → cleared; a bounce and its replacement (flow 5)
- [ ] Renewal and move-out with a deposit settlement (flow 8)
- [ ] Managed owner statement finalised and remitted (flows 6, 14)
- [ ] Head-lease payment paid once (flows 7, 14)
- [ ] A role without a building cannot see it (flow 11)
