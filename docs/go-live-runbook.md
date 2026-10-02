# Go-live runbook

One install per company (spec D2). Times are Asia/Bahrain. Vendor Support runs the import; Finance and Management sign off the figures.

## Before cutover (T−14 to T−1)

1. **Templates.** The client fills every template from the import screen (Data import → Download template). ID, phone, IBAN, cheque-number and money columns must be **Text** in Excel; numbers there are refused.
2. **Staging rehearsal.** Restore last night's production backup to staging, set a go-live date, and run a dry run with the full file set. Repeat until it reports no problems.
3. **Agree the cutover date** with the client. Everything is as at the start of that day:
   - scheduled rent and head-lease payments start with the first period that begins on or after it;
   - the period containing it was billed in the old system, and its unpaid part is in the opening balance.
4. **Freeze the old system's figures** at the close of the day before cutover. Export from it:
   - each customer's balance (per agreement unit where the client tracks it);
   - each deposit held;
   - each cheque still held;
   - each managed owner's balance.
   Keep the totals: they are checked against the import's totals.
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

## Cutover day

1. `php artisan rms:setting go_live_at <cutover date>`. Set it **the day before** cutover: imports close once that day starts.
2. Upload the full file set and run a **dry run**. Fix and repeat until it is clean.
3. Compare the dry run's totals with the old system's totals from step 4 above:
   - customer balances;
   - deposits held;
   - cheques;
   - owner balances.
   They must match to the fil. Investigate any difference before importing.
4. **Import.** It runs in one transaction; nothing is saved unless every row of every file passes.
5. Spot-check with the client:
   - three customer statements;
   - one multi-unit agreement's schedule;
   - one managed owner's ledger;
   - one leased contract's head-lease schedule.

## First week

- Day 1, 01:00: invoices due are issued; check the run's log and heartbeat.
- Day 1, 02:30: the integrity check reports nothing.
- **UAT sign-off.** The client walks through the spec §14 flows on production data, as listed in the UAT checklist below, and signs.
- 1st of the next month, 04:00: the first owner statements are drafted. Finance reviews them before submitting.

## UAT checklist (spec §14)

- [ ] Agreement approval → schedule and deposit invoice (flow 1)
- [ ] Payment allocation, partial and full; receipt PDF (flow 2)
- [ ] Credit note on a partly paid line (flow 9)
- [ ] Cheque held → deposited → cleared; a bounce and its replacement (flow 5)
- [ ] Renewal and move-out with a deposit settlement (flow 8)
- [ ] Managed owner statement finalised and remitted (flows 6, 14)
- [ ] Head-lease payment paid once (flows 7, 14)
- [ ] A role without a building cannot see it (flow 11)
