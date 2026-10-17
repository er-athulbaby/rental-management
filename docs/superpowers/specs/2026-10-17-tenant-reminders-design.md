# Tenant reminders — design

**Status:** approved in conversation, 17 October 2026. Rank 4 of the Buildium comparison, first version.
**Channel now:** email. **Next:** WhatsApp, plugged into the same rules, log and button (§7).

## 1. Purpose

Remind tenants automatically, and on demand, about rent due, overdue invoices, post-dated cheques coming up, and
agreements nearing their end — to cut overdue rent without staff chasing by hand.

**Out of scope:** WhatsApp and SMS sending (§7 only prepares for them); payment links; reminders to owners; late fees;
tenant replies handled in the app.

## 2. Approach

A daily command reads the existing invoices, cheques and agreements, matches them against the reminder rules, and
records one row per match in a **reminder log** whose unique key stops a reminder repeating. Matches for one tenant on
one day are combined into one message, sent through a **channel**. Email is the only channel in this version; a
channel is a small class (`send(tenant, combined reminder) → outcome`) so WhatsApp can be added later without
touching rules, log or screens.

Rejected: sending when records change (reminders depend on dates passing, so a daily check is needed anyway).

## 3. Reminder types and rules

| Type | Applies to | Default days (editable) |
|---|---|---|
| `rent_due` | issued invoices, not credit notes, balance > 0 | 3 before the due date |
| `overdue` | the same, past due date + the agreement's grace days (company default for invoices without an agreement) | 1, 7, 14 after becoming overdue |
| `cheque` | received cheques with status `held` | 2 before the cheque date |
| `renewal` | `active` agreements with no `notice_date` and no later agreement renewing them | 60, 30 before the end date |

- **Settings:** a new Admin page **Tenant reminders** (permission `settings.manage`, under Administration). Per type: on
  / off switch, the list of days (whole numbers 1–365, at most 5 per type), and a short intro line in English and in
  Arabic (≤300 characters each, with safe defaults). **Every type is off until an Admin turns it on.**
- **Matching day:** an item matches a rule when today (Asia/Bahrain) is exactly the rule's day relative to its date. A
  missed day (server down) is not caught up — the next rule day still fires.
- **Never reminded:** tenants with no email address (logged as skipped "No email"); tenants with the new **"Don't send
  reminders"** switch on their record (logged as skipped "Opted out"); items that no longer qualify at send time
  (paid, cheque no longer held, agreement renewed or notice given) are dropped from the message.

## 4. The message

- **Combined:** all matches for one tenant on one run become one email. Subject: the most urgent type present
  (overdue → rent due → cheque → renewal), in English · Arabic, plus the company name — e.g. "Rent overdue · إيجار
  متأخر · <company>".
- **Body, bilingual:** English section then Arabic section (RTL). Greeting with the tenant's name (Arabic name when
  set, else English). Per type, the Admin's intro line, then system-generated lines:
  - invoices: number, unit(s), due date (dd/mm/yyyy), balance (BHD, 3 dp), days overdue for overdue ones;
  - cheques: number, bank, cheque date, amount, "please ensure funds are available";
  - renewals: agreement number, units, end date, "please contact us to renew".
- **Footer:** company name, phone, email, address (from Company settings). From: company name + `MAIL_FROM_ADDRESS`;
  reply-to: the company email.
- **No figures typed by staff.** All values come from the records at send time.

## 5. Sending

- **Daily run:** `rms:reminders:send`, scheduled daily at 08:00 Asia/Bahrain, `withoutOverlapping`, heartbeat
  `HEARTBEAT_TENANT_REMINDERS`. It does nothing while no type is enabled, or while mail is not configured
  (`MAIL_MAILER` is `log` or `array`).
- **Per tenant:** one queued job per tenant (tries 1, timeout 120). One failure never blocks another tenant.
- **Never twice:** `reminder_sends` is unique on (customer, type, item, rule day, sent-on date) for automatic sends; a
  rerun the same day finds the rows and skips them.
- **Failures:** recorded as `failed` with the error; not retried automatically.
- **Audit:** every automatic batch (`reminder.sent`, counts per type) and every manual send (`reminder.sent`, the item).

## 6. Screens

- **"Send reminder" button** on an invoice with a balance (finance.view), a held cheque (finance.view), and an active
  agreement within 90 days of its end (agreements.view); building scope applies. Opens a preview of the exact email,
  then **Send** after a confirmation. Works when the type is switched off; refused for opted-out tenants or tenants
  without email (button disabled with the reason). Logged with `manual = true` and the sender; does not stop the
  automatic reminder.
- **Reminders log** page (Leasing → Reminders; finance.view or agreements.view, building scope): date, tenant, type,
  items, channel, status (sent / failed / skipped), reason, manual or automatic. Filters: date range, type, status,
  building. Excel export (audited `report.exported`).
- **On records:** the tenant, invoice, cheque and agreement pages list their reminders (date, type, status).
- **Tenant form:** a "Don't send reminders" switch.

## 7. WhatsApp later (prepared, not built)

`App\Reminders\Channel` interface; `EmailChannel` implements it. A later WhatsApp version adds `WhatsAppChannel`
(approved template name per type, the tenant's mobile), a per-tenant channel preference, and provider settings. The
`channel` column on `reminder_sends` is stored now (`email`).

## 8. Storage

- `reminder_settings`: one row per type — enabled, days (JSON list), intro_en, intro_ar, timestamps.
- `reminder_sends`: id, customer_id, type, item_type (`invoice` | `cheque` | `agreement`), item_id, rule_day
  (nullable for manual), sent_on (date), channel, status, reason, manual (bool), sent_by (nullable user), batch key
  (groups the items of one message), timestamps. Unique (customer_id, type, item_type, item_id, rule_day, sent_on)
  where manual = false (enforced by a generated column).
- `customers.no_reminders` (bool, default false).
- No money tables touched; no triggers; `EXPECTED_TRIGGERS` unchanged.

## 9. Safety

Reminders only read money records. Tenant emails come from the tenant record only. Manual sends check permission and
building scope; tampered Livewire input gives a validation message, never a 500. All strings through `__()` (Arabic
text in the email comes from the settings page or fixed bilingual templates); UI says "tenant".

## 10. Testing

- Each type's matching on its rule days (and not the day before/after); grace days; credit notes excluded; non-held
  cheques excluded; renewed / notice-given agreements excluded.
- Combining: several items of different types for one tenant → one email, subject by urgency.
- Skips: no email, opted out, all types off, mail not configured.
- Never twice: a second run the same day sends nothing; a manual send doesn't block the automatic one.
- Failure isolation: one tenant's mail failure, others still sent; status `failed` recorded.
- Email content: bilingual sections, figures, dates dd/mm/yyyy, company footer, escaping of names.
- Manual button: permission, building scope, preview equals the sent email, disabled reasons.
- Log page and Excel; per-record lists; settings validation (days 1–365, ≤5, intro length).
