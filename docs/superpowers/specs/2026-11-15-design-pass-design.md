# Design pass — design

**Status:** direction chosen in conversation, 15 November 2026 (scope: everything, one look; depth: restyle plus
reworked key screens; pain points: lists hard to scan, forms feel long; direction C, charcoal and gold).

## 1. Purpose

Make the staff app, the tenant and owner portals and the sign-in pages attractive and easier to use, with one shared
look, without changing how anything works.

**Out of scope:** PDFs and emails (contracts, invoices, receipts, statements, reminders); any change to behaviour,
permissions, routes, validation or button wording; new features; a staff English/Arabic switch.

## 2. The look

- **Charcoal and gold.** Sidebar and portal header in charcoal; the work area white (dark mode: near-black); gold for
  the active menu item, primary buttons, links and focus rings.
- Gold on white must pass WCAG AA: buttons and link text use a dark gold (about `#8A5A0B`, white text on it ≥ 4.5:1);
  the bright gold (about `#E0A526`) is used only on charcoal.
- Status colours carry meaning and are the same everywhere: green = paid, active, cleared, finished; red = overdue,
  bounced, rejected, reversed; amber = pending, held, draft awaiting action; grey = draft, closed, cancelled,
  inactive; blue = informational (upcoming, deposited).
- Font stays Instrument Sans; Arabic text falls back to a legible Arabic face already available on the system stack.
- Tokens live in `resources/css/app.css` (`@theme`): accent, accent-content, accent-foreground, a `brand` gold ramp and
  the charcoal surface. Light and dark both defined. No colour is hard-coded in a view.

## 3. Shared building blocks (Blade components)

Built once under `resources/views/components/ui/` and used by every screen:

| Component | What it gives |
|---|---|
| `ui.page-header` | Title, optional one-line hint, breadcrumb back link, action slot on the right |
| `ui.stat` / `ui.stats` | Summary cards: label, value (tabular digits), optional tone (danger, success) |
| `ui.filters` | One row: search, selects, date range, "Clear filters" when any is set |
| `ui.table` wrappers / CSS | Shaded sticky header, row hover, zebra off, amounts right-aligned with `tabular-nums`, link column emphasised |
| `status-badge` (existing) | The single mapping of every status enum to a colour and label |
| `ui.empty` | Icon, one line, optional action button, for empty lists |
| `ui.section` | A titled card for a group of form fields, with an optional hint |
| `ui.form-actions` | Sticky bar at the bottom of long forms with Save / Cancel |
| `ui.summary` | Header card for a record page: title, status badge, key facts grid, actions |

Rules: components take no data-access logic; they render what they are given. All strings through `__()` with
`lang/ar.json` entries. Every component works in dark mode and in RTL (logical properties: `ms-`, `me-`, `ps-`, `pe-`,
`text-start`, `text-end`).

## 4. Shell

- **Sidebar:** charcoal; company logo and name at the top (from Company settings, falling back to the app name);
  groups Leasing, Finance, Ledger, Reports, Property, Maintenance, Administration are collapsible; the group holding
  the current page is open; the open/closed choice is remembered per browser (`localStorage`, read with try/catch).
  Permissions and items unchanged. Approvals keeps its count badge.
- **Mobile:** the existing header/drawer pattern, restyled.
- **User menu:** unchanged items, restyled.

## 5. Lists (every index page)

Each list gets: `ui.page-header` with its primary action; `ui.stats` where the page has natural totals (invoices:
owed, overdue; payments: collected this month; cheques: held, to deposit this week, bounced; agreements: active,
pending approval, expiring in 60 days; units: available, occupied, blocked; maintenance: open, urgent, overdue work
orders; approvals: waiting); `ui.filters`; the table rules of §3; `ui.empty`. Totals come from queries the page
already runs or one small aggregate added to the component, scoped exactly like the list (building scope included).
No list loses a column, filter or action.

## 6. Forms (every create/edit page)

Fields grouped with `ui.section` under plain titles (for example tenant: Identity, Contact, Emergency contact,
Reminders and notes; unit: Location, Details, Money, Availability). Required fields marked; existing help text kept;
two-column grid on desktop, one column on phones; `ui.form-actions` on forms taller than one screen. Field names,
`wire:model` bindings, validation and button labels are unchanged.

## 7. Reworked key screens

- **Dashboard:** greeting line with the date; tiles as `ui.stats` with tone (overdue in red); charts kept; a "Needs
  attention" list (overdue invoices count, cheques to deposit, bounced cheques, approvals waiting, agreements
  expiring, urgent maintenance), each linking to its filtered list and shown only to roles that can open it.
- **Tenant page and agreement page:** `ui.summary` at the top (status, balance or rent, key dates, primary actions),
  then the existing panels as titled sections in a sensible order; long pages get an in-page section nav.
- **Record payment, new agreement, owner contract, company settings:** regrouped per §6, with the running total
  (payment allocation, agreement monthly rent) kept visible beside the form on desktop.
- **Bank reconciliation show page:** the figures as `ui.stats` with the difference highlighted (red until 0.000).

## 8. Portals and sign-in

- Tenant and owner portals: same tokens; charcoal header with the company logo; phone-first cards; nav as a
  horizontally scrolling tab row on phones and a row on desktop (as today, restyled); balances and next-due amounts as
  `ui.stats`; lists as cards on phones and tables on desktop. RTL verified in Arabic.
- Sign-in, two-factor, forgot/reset password and portal sign-in: a centred card on desktop with a charcoal brand
  panel (logo, company name); a single column on phones.

## 9. Safety and quality

- No behaviour change: the full test suite must pass unchanged, except tests that assert on markup that legitimately
  moved (updated minimally, never weakened).
- Accessibility: AA contrast for text and controls, visible focus rings, labels kept on every input, tables keep
  header cells, icon-only buttons have labels, no information by colour alone (badges keep their text).
- Livewire rules unchanged (locked ids, tampered input gives a message).
- Performance: no new N+1 queries; list totals are single aggregate queries.
- Dark mode and RTL checked on every shared component.

## 10. Stages

1. Tokens, shared components, sidebar and shell.
2. Lists.
3. Forms.
4. Reworked key screens.
5. Portals and sign-in.
6. Sweep of every remaining screen (ledger, reports, admin, maintenance, modals), dark mode and RTL pass,
   before/after screenshots of every staff and portal page, full suite.

## 11. Testing

- Component tests: each `ui.*` component renders its slots, tone classes and Arabic strings.
- Page smoke tests (existing access tests) keep passing for every role.
- New: list stat figures equal the list's own data on a worked example (invoices, cheques, agreements, units).
- Sidebar: the current page's group renders open; items still follow permissions.
- Screenshot run (the headless-Chrome script used for the training guide) for every page in light mode, a sample in
  dark mode, and the portals in Arabic; reviewed by eye before finishing.
