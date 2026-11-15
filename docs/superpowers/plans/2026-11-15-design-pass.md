# Design Pass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the staff app, the tenant and owner portals and the sign-in pages one charcoal-and-gold look, with summary figures on lists, grouped forms and reworked key screens, without changing how anything works.

**Architecture:** Colour tokens live in `resources/css/app.css` (`@theme`), and Flux's accent variables point at them, so every Flux button, link and focus ring turns gold at once. Twelve small anonymous Blade components under `resources/views/components/ui/` (page header, stats, filters, table, empty state, section, form actions, summary, fact, section nav, brand, nav group, auth card) carry the look; views are converted file by file to use them. List figures come from one aggregate query per list, added as a private `stats()` method on the list's Livewire component over the same `filtered()` query the list uses.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, Flux 2.20 (free edition only: no Flux Pro components), Tailwind 4 (`@theme`, `@custom-variant dark`), Alpine (shipped with Livewire), Pest. Existing helpers used: `App\Reports\Queries` (`occupiedUnitIds`, `weekStart`, `weekEnd`, `chequesToDeposit`, `bouncedCheques`, `overdueInvoices`, `expiringAgreements`, `approvalsToDecide`), `App\Support\Fils`, `App\Models\CompanySetting`, `<x-status-badge>`, `<x-building-filter>`, `<x-date-input>`, `<x-custom-fields.*>`.

**Spec:** `docs/superpowers/specs/2026-11-15-design-pass-design.md` (approved). Read it first. Where this plan and the spec differ, a ruling below says why.

## Global Constraints

- Spec §1: out of scope are PDFs and emails (contracts, invoices, receipts, statements, reminders); any change to behaviour, permissions, routes, validation or button wording; new features; a staff English/Arabic switch.
- Spec §2: "Charcoal and gold." Gold on white passes WCAG AA: buttons and link text use the dark gold `#8A5A0B` (white text on it 5.92:1); the bright gold `#E0A526` is used only on charcoal or near-black (7.76:1 on `#1C1C1F`). "No colour is hard-coded in a view": views use token utilities (`bg-charcoal`, `text-accent-content`, `bg-brand-400`, …) or Tailwind's zinc/red/amber/green/blue scale; no hex in Blade.
- Spec §3: "components take no data-access logic; they render what they are given. All strings through `__()` with `lang/ar.json` entries. Every component works in dark mode and in RTL (logical properties: `ms-`, `me-`, `ps-`, `pe-`, `text-start`, `text-end`)."
- Spec §5: "Totals come from queries the page already runs or one small aggregate added to the component, scoped exactly like the list (building scope included). No list loses a column, filter or action."
- Spec §6: "Field names, `wire:model` bindings, validation and button labels are unchanged."
- Spec §9: "the full test suite must pass unchanged, except tests that assert on markup that legitimately moved (updated minimally, never weakened)." AA contrast, visible focus rings, labels kept on every input, tables keep header cells, icon-only buttons have labels, no information by colour alone (badges keep their text). No new N+1 queries; list totals are single aggregate queries.
- Keep unchanged in every task: field names, `wire:model`/`wire:click`/`wire:submit`/`wire:key`/`wire:confirm` attributes, `@can`/`@canany`/`@if` permission wrappers, route names and parameters, validation, every button's and link's text, every heading's text (tests assert on them), Livewire public properties and public methods (no new public methods: a public method is browser-callable).
- Livewire: ids stay `#[Locked]`; tampered input gives a message, never a 500. New PHP in components is `private` (or `public static` for pure helpers already following that pattern, like `Dashboard::tiles`).
- Say "tenant", never "customer", in UI text. Dates show as dd/mm/yyyy. BHD amounts show with 3 decimals (through `Fils`, never floats in PHP).
- RTL-safe utilities only: `ms-`/`me-`/`ps-`/`pe-`/`start-`/`end-`/`text-start`/`text-end`; never `ml-`/`mr-`/`pl-`/`pr-`/`left-`/`right-`/`text-left`/`text-right` in new markup. Dark mode on everything (`dark:` variants for every colour you add).
- Strings: every new `__()` key gets an entry in `lang/ar.json`, inserted directly after the line `    "Credit refund reversal": "عكس استرداد رصيد",`. Before inserting a key, check it is absent (`grep -n '"Key":' lang/ar.json`); skip any key already present (an existing test fails on duplicate keys, and another fails on a missing Arabic key for ledger and portal views). Each task also appends its new keys to the `$keys` list in `tests/Feature/Shell/DesignPassArabicTest.php` (created in Task 1).
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file (the compiler mis-pairs them). New code in this plan uses block `@php … @endphp` only.
- PHP binary: `/c/Users/ababy/.config/herd/bin/php85/php.exe` (written `php` below).
- Tests run serially on the `rms_test` database: never `--parallel`, never two test processes at once.
- Gates before every commit: `php vendor/bin/pint --dirty`; `php vendor/bin/phpstan clear-result-cache` then `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors); `npm run build`.
- Never run `migrate:fresh` on the dev database `rms`. Never read `.env`. No migrations are needed by this plan.
- Commit messages end with a blank line and the `Co-Authored-By:` trailer the executing session is told to use (earlier plans used `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`). Never commit `.superpowers/`, `docs/training/` or screenshots.

## Rulings made while planning (the spec was silent or needed a decision)

1. **Which filters the list figures follow.** Every figure follows the page's visibility (`visibleTo`), its building filter and every other filter **except the one the figure itself describes** (the status select, the tab, the "expiring" select). This is how the invoices page already computes its "Owed"/"Overdue" totals (`InvoiceTabsTest` "the counts follow the other filters"), so all lists now agree. To make this exact, each list with figures gets a private `filtered(): Builder` used by both the list and `stats()`.
2. **Figure definitions** reuse the dashboard's, so the same words mean the same numbers:
   - Cheques "To deposit this week" = received cheques, status held, `cheque_date` between `Queries::weekStart()` (Monday) and `Queries::weekEnd()` (Sunday) — the dashboard tile's `Queries::chequesToDeposit($user, weekEnd, weekStart)`.
   - Payments "Collected this month" = confirmed payments, method not `deposit_applied`, `received_on` from the 1st of this month to today (Asia/Bahrain) — the dashboard tile's definition.
   - Agreements "Expiring in 60 days" = active, `end_date` between today and today + 60 days — `Queries::expiringAgreements`.
   - Units: "Occupied" = in `Queries::occupiedUnitIds($user, today)`; "Blocked" = `blocked = 1` and not occupied (a let unit shows as occupied, as `Unit::status()` does); "Vacant" = neither. Reserved units count as vacant, so the figure is labelled **Vacant**, not "Available" (the spec's word), to stay true.
   - Maintenance requests: "Open" and "In progress" are the tab counts the page already computes; "Urgent" = open or in-progress requests with priority urgent (the dashboard card's definition). "Overdue work orders" goes on the Work orders page, defined as `WorkOrder::isOverdue()` is: assigned or in progress and `scheduled_on` before today.
   - Approvals: "Waiting" and "For you to decide" are counted from the rows the page already loads (no query).
   - Invoices: the existing `$owed` and `$counts` (no new query). The inline "Owed: … BHD" / "Overdue: … BHD" line under the tabs is replaced by the figures; `InvoiceTabsTest` changes from `assertSee('Owed: 160.000 BHD')`/`assertSee('Overdue: 100.000 BHD')` to `assertSeeInOrder(['Owed', '160.000 BHD', 'Overdue', '100.000 BHD'])` (same two figures, still asserted).
3. **"Clear filters"** is a link to the page's own route without filter parameters (keeping the current tab), not a new Livewire method. Every list filter is either a `#[Url]` property (reset by the fresh URL) or a non-URL property such as the custom-field `$more` (reset because the component mounts again). No new public methods.
4. **Company logo.** The logo is stored on the private `local` disk (`settings/logo.*`). To show it in the sidebar, the portal header and on sign-in pages, one read-only route is added: `GET /brand/logo` named `brand.logo`, public (sign-in pages are for guests; a company logo is public branding), 404 when no logo is set, `Cache-Control: public, max-age=86400`, cache-busted by `?v=<updated_at timestamp>`. It reads only `company_settings.logo_path` (never a user-supplied path). No existing route changes. Without a logo, the brand shows the company name's first letter on a gold tile.
5. **Brand name.** `CompanySetting::brand()` returns the Arabic company name when the locale is Arabic and `name_ar` is filled, else the English name; the app name when no settings row exists yet (fresh install). Layouts call it and pass it to `<x-ui.brand>`, so components stay free of data access.
6. **Sidebar groups** are native `<details>` elements (no new JS dependency): the group holding the current page is rendered `open` by the server; the others open from `localStorage` key `rms.nav.<group>` (`'open'`/`'closed'`), read and written inside `try/catch`, default closed. "Platform" (Dashboard, Approvals) stays a plain, always-visible group. The sidebar is charcoal in both light and dark mode: it carries the `dark` class, which gives Flux's light-on-dark item styles and the bright-gold accent inside it.
7. **Sticky table headers.** CSS `position: sticky` cannot stick to the page from inside Flux's horizontal scroll container, so `<x-ui.table>` caps the table's own scroll area at `75dvh` and sticks the header inside it. Short tables never reach the cap and look unchanged.
8. **Required fields** are marked by CSS (a red asterisk after the label) on any Flux field whose control has `required` or `aria-required="true"`. Form tasks add `aria-required="true"` (never `required`, which would change how the browser submits) to fields whose server rule is unconditionally `required`, or conditionally required where the condition is known in the view (named per file).
9. **Running totals** on Record payment and the agreement form are computed in the browser by Alpine from the component's `$wire` state, in integer fils, purely for display; the server still validates and computes everything. No new Livewire properties, no `wire:model` modifiers changed.
10. **Status colours** (spec §2) change only where the spec names a colour the old mapping did not give: `draft` → grey (was amber), `scheduled` → blue (was grey; "upcoming"), `pending` → amber (approval status; was grey), `finished` → green. Everything else keeps its colour. Translated display labels (an invoice's "Paid"/"Overdue"/"Partially paid"/"Unpaid"/"Credit note" in Arabic on the portal) now map to the same colours as in English (before, Arabic labels fell through to grey).
11. **Portal lists** stay cards at every width, laid out as rows on wider screens, instead of rendering a second table for desktop: duplicated markup would double every figure and break the portal tests' `assertSeeInOrder` reading order. Spec §8's intent (scannable on desktop) is kept.
12. **Dead starter-kit files** are deleted rather than restyled: `layouts/app/header.blade.php`, `layouts/auth/card.blade.php`, `layouts/auth/split.blade.php` (no view uses them), `components/app-logo.blade.php` (replaced by `<x-ui.brand>` in Task 2) and `components/app-logo-icon.blade.php` (replaced in Task 10).
13. **Pages the spec does not name:** the public landing page `/` (`welcome.blade.php`, the starter kit's) is left untouched; the public agreement verification page (`verify.blade.php`) and the 500 page get the tokens only (Task 12).
14. **Dark mode surfaces:** the work area is `zinc-950` (near-black, spec §2), cards and sections `zinc-900`, borders `zinc-700`/`zinc-800`. Bright gold on `zinc-950` is 9.03:1.
15. **Dashboard "Needs attention"** lists only items with something to do (count > 0) and shows "Nothing needs attention." otherwise. "Cheques to deposit this week" links to the held-cheques list (the list has no date filter). The two money tiles that mean trouble get a tone: "Overdue total" and "Open bounced cheques" are red when not zero (a `tone` key added to `Dashboard::tiles()`; existing tests pluck label/value and are unaffected).
16. **Empty lists:** one generic message ("Nothing to show here.", plus "Try clearing the filters." when a filter is set). A page that already has its own empty text (an `@empty` row, "Nothing is waiting for approval.") keeps that text.
17. **Screenshot run:** the headless-Chrome script used for the training guide is not in the repository. The final task's screenshots are taken by the controller in the Browser pane on the local Herd site after the user signs in (staff sign-in needs two-factor; portal sign-in needs an emailed code), saved to the session scratchpad, never committed.
18. **Primary "New …" buttons** get a leading `icon="plus"`; wording unchanged.

## File Structure

New files:

| File | Responsibility |
|---|---|
| `resources/views/components/ui/page-header.blade.php` | Page title (the h1), hint, back link, `meta` and `actions` slots |
| `resources/views/components/ui/stats.blade.php` | Grid of figure cards (`cols` 2/3/4/5) |
| `resources/views/components/ui/stat.blade.php` | One figure: label, value (tabular digits), tone, hint, optional link |
| `resources/views/components/ui/filters.blade.php` | Filter row card with an optional "Clear filters" link |
| `resources/views/components/ui/table.blade.php` | Card around a `flux:table`; hooks the `.ui-table` CSS |
| `resources/views/components/ui/empty.blade.php` | Empty-list message with optional action slot |
| `resources/views/components/ui/section.blade.php` | Titled card for a group of fields or a record panel; optional `actions` slot |
| `resources/views/components/ui/form-actions.blade.php` | Sticky Save/Cancel bar for long forms |
| `resources/views/components/ui/summary.blade.php` | Record header card: title, status badge, actions, facts |
| `resources/views/components/ui/fact.blade.php` | One `dt`/`dd` pair inside a summary |
| `resources/views/components/ui/section-nav.blade.php` | Sticky in-page links for long record pages |
| `resources/views/components/ui/brand.blade.php` | Company logo (or letter tile) and name (Task 2) |
| `resources/views/components/ui/nav-group.blade.php` | Collapsible sidebar group remembered per browser (Task 2) |
| `resources/views/components/ui/auth-card.blade.php` | Sign-in card with the charcoal brand panel (Task 10) |
| `app/Http/Controllers/BrandLogoController.php` | Streams the company logo (Task 2) |
| `tests/Feature/Shell/UiComponentsTest.php` | Component rendering, tones, Arabic strings, status colours |
| `tests/Feature/Shell/DesignPassArabicTest.php` | Every key the pass adds has Arabic; every `__()` in `components/ui` has Arabic |
| `tests/Feature/Shell/ShellDesignTest.php` | Sidebar groups, brand, logo route (Task 2) |
| `tests/Feature/Cheques/ChequeStatsTest.php`, `tests/Feature/Agreements/AgreementStatsTest.php`, `tests/Feature/Payments/PaymentStatsTest.php` | List figures on worked examples (Task 3; the approvals figures are a test appended to `ApprovalsScreenTest.php`) |
| `tests/Feature/Units/UnitStatsTest.php`, `tests/Feature/Maintenance/ListStatsTest.php` | List figures on worked examples (Task 4) |
| `tests/Feature/Reports/DashboardAttentionTest.php` | "Needs attention" items and permissions (Task 7) |

Modified: `resources/css/app.css`, `resources/views/components/status-badge.blade.php`, `resources/views/components/custom-fields/section.blade.php`, `resources/views/layouts/app/sidebar.blade.php`, `resources/views/layouts/auth/simple.blade.php`, `resources/views/layouts/portal.blade.php`, `resources/views/components/auth-header.blade.php`, `routes/web.php`, `app/Models/CompanySetting.php`, `app/Livewire/Dashboard.php`, the list components `app/Livewire/{Cheques,Payments,Agreements,Units}/Index.php`, `app/Livewire/Maintenance/{Requests,WorkOrders}.php`, `lang/ar.json`, `tests/Feature/Invoices/InvoiceTabsTest.php`, and the Livewire views listed per task.

Deleted: `resources/views/layouts/app/header.blade.php`, `resources/views/layouts/auth/card.blade.php`, `resources/views/layouts/auth/split.blade.php`, `resources/views/components/app-logo.blade.php`, `resources/views/components/app-logo-icon.blade.php`.

## Style guide (the conversion recipes)

Every conversion task below repeats the rules it needs in its own text; this section is the reference they were copied from.

### Component reference (built in Tasks 1, 2 and 10)

```blade
<x-ui.page-header :title="__('Invoices')" :hint="null" :back="route('units.index')">   {{-- back and hint optional --}}
    <x-slot:meta>…badge or one-line facts under the title…</x-slot:meta>               {{-- optional --}}
    <x-slot:actions>…buttons…</x-slot:actions>                                        {{-- optional --}}
</x-ui.page-header>

<x-ui.stats :cols="3">                                                                 {{-- cols: 2, 3, 4 (default), 5 --}}
    <x-ui.stat :label="__('Held')" :value="$stats['held']" :hint="…" tone="danger" :href="…" />   {{-- tone: danger|warning|success|null --}}
</x-ui.stats>

<x-ui.filters :clear="$anyFilterSet ? route('cheques.index') : null">…existing filter controls…</x-ui.filters>

<x-ui.table>
    <flux:table :paginate="$rows">…unchanged…</flux:table>
    @if ($rows->isEmpty())
        <x-ui.empty :filtered="$anyFilterSet" />
    @endif
</x-ui.table>

<x-ui.section :title="__('Identity')" :hint="null" id="identity">…fields…</x-ui.section>  {{-- title optional; slot actions optional --}}
<x-ui.form-actions :cancel="route('units.index')"><flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button></x-ui.form-actions>

<x-ui.summary :title="$agreement->label()" :status="$agreement->status" :back="route('agreements.index')">
    <x-slot:actions>…</x-slot:actions>
    <x-slot:facts><x-ui.fact :label="__('Tenant')">…</x-ui.fact></x-slot:facts>
</x-ui.summary>
<x-ui.section-nav :items="[['units', __('Units')], ['documents', __('Documents')]]" />
```

### How to convert an index page

1. Replace the heading row (`<div class="flex …"><flux:heading size="xl" level="1">…</flux:heading> …buttons… </div>`) with `<x-ui.page-header :title="…">`; same title text. Put the existing buttons, modal triggers and their `@can`/`@if` wrappers, unchanged, in `<x-slot:actions>`. A descriptive `<flux:text>` directly under the heading becomes the `hint`. Add `icon="plus"` to a primary "New …"/"Record …"/"Enter …" button; change no wording.
2. Figures (only where the task lists them) go right under the header in `<x-ui.stats>`, reading the `stats` view data the task adds.
3. Wrap the filter controls, unchanged, in `<x-ui.filters :clear="…">`. The clear URL and the "any filter set" condition are given per page. Export buttons move into the header's actions slot.
4. Tabs (invoices, maintenance requests): keep the markup and `wire:click`; active tab classes become `border-accent text-accent-content` (was `border-zinc-800 text-zinc-900 dark:border-white dark:text-white`); focus ring `focus-visible:ring-accent` (was `focus-visible:ring-zinc-400`).
5. Replace `<div class="overflow-x-auto">` around the `flux:table` with `<x-ui.table>`. Do not touch columns, rows, cells, `wire:key`s or pagination. Money columns: `align="end"` on the column and `class="text-end tabular-nums"` on the cell (add where missing). After `</flux:table>`, inside `<x-ui.table>`, add `@if ($rows->isEmpty()) <x-ui.empty :filtered="…" /> @endif` — unless the table already has an `@forelse … @empty` row, which stays as it is.
6. Modals on the page keep their content; nothing inside them changes in list tasks.

**Before** (`resources/views/livewire/customers/index.blade.php`):

```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Tenants') }}</flux:heading>
        @can('create', \App\Models\Customer::class)
            <flux:button variant="primary" :href="route('customers.create')" wire:navigate>{{ __('New tenant') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <x-building-filter :buildings="$this->buildings" label="" />
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name, mobile, ID number or unit')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />

    @if ($hint)
        <flux:callout icon="information-circle" :heading="$hint" />
    @endif

    <div class="overflow-x-auto">
        <flux:table :paginate="$customers">
            …columns and rows…
        </flux:table>
    </div>
</section>
```

**After:**

```blade
@php
    $filtered = $search !== '' || $this->building || ! empty($more);
@endphp
<section class="w-full space-y-6">
    <x-ui.page-header :title="__('Tenants')">
        <x-slot:actions>
            @can('create', \App\Models\Customer::class)
                <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>{{ __('New tenant') }}</flux:button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filters :clear="$filtered ? route('customers.index') : null">
        <x-building-filter :buildings="$this->buildings" label="" />
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name, mobile, ID number or unit')" icon="magnifying-glass" class="sm:max-w-xs" />
    </x-ui.filters>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />

    @if ($hint)
        <flux:callout icon="information-circle" :heading="$hint" />
    @endif

    <x-ui.table>
        <flux:table :paginate="$customers">
            …columns and rows, unchanged…
        </flux:table>
        @if ($customers->isEmpty())
            <x-ui.empty :filtered="$filtered" />
        @endif
    </x-ui.table>
</section>
```

### How to convert a form

1. Header: `<x-ui.page-header>` with the same title text and `:back="route('<list route>')"`. A status badge or "next tenant from …" line under the title moves into `<x-slot:meta>`.
2. Group fields into `<x-ui.section :title="…">` cards in the order given per file. Inside a section use `<div class="grid gap-4 sm:grid-cols-2">`; textareas, radio groups, checkbox groups, repeaters and long selects get `class="sm:col-span-2"` (on the field, or on a wrapping `<div class="sm:col-span-2">` for custom components). An existing `<flux:fieldset>`/`<flux:legend>` pair becomes a section whose title is the legend's text.
3. Keep every `<fieldset @disabled(! $canEdit)>` wrapper: it now wraps the sections.
4. Required: add `aria-required="true"` to each field named per file (unconditional `required` rules, from the Action the file names). Never add `required`; never remove an existing `required`.
5. Submit: on a form named "long" in its task, wrap the existing submit button, unchanged, in `<x-ui.form-actions :cancel="route('<list route>')">` as the form's last child. On short forms put the button in `<div class="flex justify-end">`.
6. Icon-only buttons get `:aria-label="__('…')"` (keys given per file).
7. Panels after the form (`<livewire:documents.panel>`, `<livewire:reminders.record-panel>`, `<x-maintenance-requests>`, `<x-custom-fields.display>`) are left where they are.

**Before** (`resources/views/livewire/units/form.blade.php`, top and the first fields):

```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $unit ? __('Unit :code', ['code' => $unit->code]) : __('New unit') }}</flux:heading>
    @if ($unit)
        <x-status-badge :status="$unit->status()" />
        …
    @endif

    <form wire:submit="save" class="space-y-4">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:select wire:model="form.building_id" :label="__('Building')" :disabled="(bool) $unit">…</flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.code" … />
                …all fields in one grid…
            </div>
            …
        </fieldset>
        @if ($canEdit)
            <x-custom-fields.section … />
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>
```

**After:** the complete file is in Task 5, Step 3 (it is that task's worked example).

---

### Task 1: Tokens, shared components and status colours

**Files:**
- Modify: `resources/css/app.css`
- Create: `resources/views/components/ui/{page-header,stats,stat,filters,table,empty,section,form-actions,summary,fact,section-nav}.blade.php`
- Modify: `resources/views/components/status-badge.blade.php`
- Modify: `resources/views/components/custom-fields/section.blade.php`
- Modify: `lang/ar.json`
- Test: `tests/Feature/Shell/UiComponentsTest.php`, `tests/Feature/Shell/DesignPassArabicTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces (anonymous Blade components; every later task uses these exact props and slots):
  - `<x-ui.page-header :title="string" :hint="?string" :back="?string url" :back-label="?string">` slots `meta`, `actions`. Renders the page's `<h1>` through `<flux:heading size="xl" level="1">`.
  - `<x-ui.stats :cols="int 2|3|4|5 = 4">` wrapping `<x-ui.stat :label="string" :value="string|int" :tone="null|'danger'|'warning'|'success'" :hint="?string" :href="?string">`. Renders `data-ui-stat data-tone="<tone or neutral>"`.
  - `<x-ui.filters :clear="?string url">` (shows "Clear filters" when `clear` is set).
  - `<x-ui.table>` (adds class `ui-table`).
  - `<x-ui.empty :title="?string" :icon="string = 'inbox'" :filtered="bool = false">` with an optional slot for action buttons.
  - `<x-ui.section :title="?string" :hint="?string">` with optional slot `actions`; renders `<section data-ui-section>` and an `<h2>` when titled; pass `id="…"` for in-page links.
  - `<x-ui.form-actions :cancel="?string url">`.
  - `<x-ui.summary :title="string" :status="BackedEnum|string|null" :subtitle="?string" :back="?string url">` slots `meta`, `actions`, `facts`; `<x-ui.fact :label="string">` slot = the value.
  - `<x-ui.section-nav :items="list<array{0: string id, 1: string label}>">`.
  - CSS tokens (utilities): `bg-charcoal`, `bg-charcoal-raised`, `border-charcoal-line`, `bg-brand-{50…950}`, `text-brand-*`, `text-accent-content`, `bg-accent`, `text-accent-foreground`, `border-accent`, `ring-accent`.
  - `<x-status-badge :status>` renders `data-tone="<green|red|amber|blue|zinc>"`.
  - `tests/Feature/Shell/DesignPassArabicTest.php` with a `$keys` list later tasks append to.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Shell/UiComponentsTest.php`:

```php
<?php

use App\Enums\AgreementStatus;
use App\Enums\InvoiceStatus;

/*
 * Design pass (spec 2026-11-15 §3, §11): each ui.* component renders its slots, tone classes and Arabic strings.
 */

test('page header: back link, the h1 title, the hint, then the actions', function () {
    $this->blade('<x-ui.page-header title="Invoices" hint="Everything billed" back="/back"><x-slot:actions><button>New</button></x-slot:actions></x-ui.page-header>')
        ->assertSeeInOrder(['href="/back"', 'Back', '<h1', 'Invoices', 'Everything billed', '<button>New</button>'], false);

    $this->blade('<x-ui.page-header title="Units" />')->assertSee('Units')->assertDontSee('href=', false);
});

test('stat: label then value, the tone class and data-tone, an optional link and hint', function () {
    $this->blade('<x-ui.stat label="Overdue" value="100.000 BHD" tone="danger" />')
        ->assertSeeInOrder(['Overdue', '100.000 BHD'])
        ->assertSee('data-tone="danger"', false)
        ->assertSee('text-red-700', false)
        ->assertSee('tabular-nums', false);

    $this->blade('<x-ui.stat label="Held" :value="2" hint="800.000 BHD" href="/cheques" />')
        ->assertSeeInOrder(['Held', '2', '800.000 BHD'])
        ->assertSee('href="/cheques"', false)
        ->assertSee('data-tone="neutral"', false);
});

test('stats: the column count sets the grid', function () {
    $this->blade('<x-ui.stats :cols="2"><span>a</span></x-ui.stats>')->assertSee('grid-cols-2', false)->assertDontSee('lg:grid-cols-4', false);
    $this->blade('<x-ui.stats><span>a</span></x-ui.stats>')->assertSee('lg:grid-cols-4', false);
});

test('filters: "Clear filters" only when a clear link is given, in Arabic too', function () {
    $this->blade('<x-ui.filters><input name="q"></x-ui.filters>')->assertSee('name="q"', false)->assertDontSee('Clear filters');
    $this->blade('<x-ui.filters clear="/list"><input name="q"></x-ui.filters>')->assertSee('Clear filters')->assertSee('href="/list"', false);

    app()->setLocale('ar');
    $this->blade('<x-ui.filters clear="/list" />')->assertSee('مسح عوامل التصفية');
});

test('empty: the default line, the filtered hint, a custom title and the action slot', function () {
    $this->blade('<x-ui.empty />')->assertSee('Nothing to show here.')->assertDontSee('Try clearing the filters.');
    $this->blade('<x-ui.empty :filtered="true" />')->assertSee('Try clearing the filters.');
    $this->blade('<x-ui.empty title="No cheques yet."><a href="/new">Enter cheques</a></x-ui.empty>')
        ->assertSee('No cheques yet.')->assertSee('<a href="/new">Enter cheques</a>', false);

    app()->setLocale('ar');
    $this->blade('<x-ui.empty :filtered="true" />')->assertSee('لا يوجد ما يُعرض هنا.')->assertSee('جرّب مسح عوامل التصفية.');
});

test('section: an h2 title and hint when titled, the id passes through, and no header when untitled', function () {
    $this->blade('<x-ui.section title="Identity" hint="Who they are" id="identity"><p>fields</p></x-ui.section>')
        ->assertSeeInOrder(['id="identity"', '<h2', 'Identity', 'Who they are', '<p>fields</p>'], false);
    $this->blade('<x-ui.section><p>fields</p></x-ui.section>')->assertSee('data-ui-section', false)->assertDontSee('<h2', false);
});

test('form actions: a sticky bar with Cancel before the buttons', function () {
    $this->blade('<x-ui.form-actions cancel="/units"><button>Save</button></x-ui.form-actions>')
        ->assertSee('sticky', false)
        ->assertSeeInOrder(['href="/units"', 'Cancel', '<button>Save</button>'], false);
});

test('summary: title, status badge, actions and facts', function () {
    $this->blade('<x-ui.summary title="AGR-1" :status="$s"><x-slot:actions><button>Renew</button></x-slot:actions><x-slot:facts><x-ui.fact label="Tenant">Sara</x-ui.fact></x-slot:facts></x-ui.summary>',
        ['s' => AgreementStatus::Active])
        ->assertSeeInOrder(['<h1', 'AGR-1', 'Active', '<button>Renew</button>', '<dt', 'Tenant', '<dd', 'Sara'], false)
        ->assertSee('data-tone="green"', false);
});

test('section nav: one in-page link per item', function () {
    $this->blade('<x-ui.section-nav :items="$items" />', ['items' => [['units', 'Units'], ['documents', 'Documents']]])
        ->assertSeeInOrder(['href="#units"', 'Units', 'href="#documents"', 'Documents'], false)
        ->assertSee('aria-label="On this page"', false);
});

test('table: the card carries the ui-table hook', function () {
    $this->blade('<x-ui.table><table></table></x-ui.table>')->assertSee('ui-table', false);
});

test('status colours follow spec §2', function (mixed $status, string $tone) {
    $this->blade('<x-status-badge :status="$s" />', ['s' => $status])->assertSee('data-tone="'.$tone.'"', false);
})->with([
    'draft is grey' => [InvoiceStatus::Draft, 'zinc'],
    'scheduled is blue' => [InvoiceStatus::Scheduled, 'blue'],
    'pending approval is amber' => [AgreementStatus::PendingApproval, 'amber'],
    'active is green' => [AgreementStatus::Active, 'green'],
    'overdue is red' => ['Overdue', 'red'],
    'paid is green' => ['Paid', 'green'],
    'closed is grey' => [AgreementStatus::Closed, 'zinc'],
    'finished is green' => ['Finished', 'green'],
]);

test('an Arabic display label keeps its colour', function () {
    app()->setLocale('ar');

    $this->blade('<x-status-badge :status="$s" />', ['s' => __('Paid')])->assertSee('data-tone="green"', false)->assertSee(__('Paid'));
    $this->blade('<x-status-badge :status="$s" />', ['s' => __('Overdue')])->assertSee('data-tone="red"', false);
});
```

Create `tests/Feature/Shell/DesignPassArabicTest.php`:

```php
<?php

/*
 * Design pass (spec 2026-11-15 §3): every string the pass adds has one Arabic translation. Each task appends its keys to
 * $keys; the second test reads every __() in the shared ui components.
 */

test('every string the design pass adds has an Arabic translation', function () {
    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = [
        // Task 1: shared components
        'Clear filters', 'Nothing to show here.', 'Try clearing the filters.', 'On this page',
    ];

    expect(array_values(array_diff($keys, array_keys($ar))))->toBe([]);
});

test('every __() string in the ui components has an Arabic translation', function () {
    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $missing = [];
    foreach (glob(resource_path('views/components/ui/*.blade.php')) as $file) {
        preg_match_all('/__\(\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1/', (string) file_get_contents($file), $m);
        foreach ($m[2] as $key) {
            if (! array_key_exists(stripslashes($key), $ar)) {
                $missing[] = basename($file).': '.stripslashes($key);
            }
        }
    }

    expect($missing)->toBe([]);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test tests/Feature/Shell/UiComponentsTest.php tests/Feature/Shell/DesignPassArabicTest.php`
Expected: FAIL — "Unable to locate a class or view for component [ui.page-header]" and the missing Arabic keys.

- [ ] **Step 3: Tokens and shared CSS in `resources/css/app.css`**

Replace the whole `@theme { … }` block and the `@layer theme { .dark { … } }` block (lines 11–40 today) with:

```css
@theme {
    /* Arabic has no glyphs in Instrument Sans: the browser falls back to the next face that has them (spec §2). */
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, 'Segoe UI', Tahoma, 'Geeza Pro', 'Noto Sans Arabic', sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';

    --color-zinc-50: #fafafa;
    --color-zinc-100: #f5f5f5;
    --color-zinc-200: #e5e5e5;
    --color-zinc-300: #d4d4d4;
    --color-zinc-400: #a3a3a3;
    --color-zinc-500: #737373;
    --color-zinc-600: #525252;
    --color-zinc-700: #404040;
    --color-zinc-800: #262626;
    --color-zinc-900: #171717;
    --color-zinc-950: #0a0a0a;

    /*
     * Design pass §2: charcoal and gold. Contrast (WCAG 2): brand-700 #8A5A0B on white 5.92:1 and white on it 5.92:1
     * (buttons, links); brand-400 #E0A526 on charcoal 7.76:1 and on zinc-950 9.03:1 (only on dark surfaces);
     * zinc-300 on charcoal 11.50:1, zinc-400 on charcoal 6.74:1 (sidebar text).
     */
    --color-brand-50: #fdf8ec;
    --color-brand-100: #f9edcc;
    --color-brand-200: #f2d894;
    --color-brand-300: #ebbf55;
    --color-brand-400: #e0a526;
    --color-brand-500: #c68a12;
    --color-brand-600: #a8700c;
    --color-brand-700: #8a5a0b;
    --color-brand-800: #70480e;
    --color-brand-900: #5c3b10;
    --color-brand-950: #352005;

    --color-charcoal: #1c1c1f;
    --color-charcoal-raised: #2a2a2e;
    --color-charcoal-line: #3a3a3f;

    --color-accent: var(--color-brand-700);
    --color-accent-content: var(--color-brand-700);
    --color-accent-foreground: var(--color-white);
}

@layer theme {
    /* Dark surfaces (dark mode, and the always-charcoal sidebar and portal header, which carry the "dark" class). */
    .dark {
        --color-accent: var(--color-brand-400);
        --color-accent-content: var(--color-brand-400);
        --color-accent-foreground: var(--color-charcoal);
    }
}
```

In the existing `@layer base { … }` block, after the `border-color` rule (inside the same `@layer base`), add:

```css
    /* Spec §9: a visible focus ring on everything focusable; Flux controls keep their own. */
    :where(a, button, summary, [tabindex]:not([tabindex='-1'])):focus-visible {
        outline: 2px solid var(--color-accent);
        outline-offset: 2px;
    }
```

At the end of the file, append:

```css
/*
 * Design pass §3: list tables inside <x-ui.table>. Unlayered, so these win over Flux's utility classes.
 * The scroll area is capped so the shaded header can stick inside it (plan ruling 7).
 */
.ui-table ui-table-scroll-area {
    max-height: 75dvh;
}

.ui-table [data-flux-column] {
    @apply sticky top-0 z-10 bg-zinc-50 py-2.5 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300;
}

.ui-table [data-flux-column]:first-child,
.ui-table [data-flux-cell]:first-child {
    padding-inline-start: 1rem;
}

.ui-table [data-flux-column]:last-child,
.ui-table [data-flux-cell]:last-child {
    padding-inline-end: 1rem;
}

.ui-table tbody tr:hover > [data-flux-cell] {
    @apply bg-zinc-50 dark:bg-white/5;
}

.ui-table [data-flux-cell] > [data-flux-link]:first-child {
    @apply font-semibold;
}

.ui-table [data-flux-pagination] {
    @apply px-4 pb-3;
}

/* Design pass §6: a required field's label ends in a red asterisk; aria-required marks server-required fields without changing how the form submits (plan ruling 8). */
[data-flux-field]:has(:is(input, select, textarea):is([required], [aria-required='true'])) [data-flux-label]::after {
    content: ' *';
    @apply text-red-600 dark:text-red-400;
}
```

- [ ] **Step 4: Create the components**

`resources/views/components/ui/page-header.blade.php`:

```blade
@props(['title', 'hint' => null, 'back' => null, 'backLabel' => null])
{{-- Design pass §3: the page's h1, an optional one-line hint and back link, and the page's actions at the end. --}}
<div {{ $attributes->class('flex flex-wrap items-start justify-between gap-x-4 gap-y-3') }} data-ui-page-header>
    <div class="min-w-0 space-y-1">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-accent-content dark:text-zinc-400">
                <flux:icon.chevron-left variant="micro" class="rtl:rotate-180" />{{ $backLabel ?? __('Back') }}
            </a>
        @endif
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
        @if (filled($hint))
            <flux:text>{{ $hint }}</flux:text>
        @endif
        {{ $meta ?? '' }}
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
```

`resources/views/components/ui/stats.blade.php`:

```blade
@props(['cols' => 4])
{{-- Design pass §3: the summary figures of a page; two per row on phones. --}}
<div {{ $attributes->class([
    'grid gap-3 sm:gap-4',
    match ((int) $cols) {
        2 => 'grid-cols-2',
        3 => 'grid-cols-1 sm:grid-cols-3',
        5 => 'grid-cols-2 lg:grid-cols-5',
        default => 'grid-cols-2 lg:grid-cols-4',
    },
]) }} data-ui-stats>
    {{ $slot }}
</div>
```

`resources/views/components/ui/stat.blade.php`:

```blade
@props(['label', 'value', 'tone' => null, 'hint' => null, 'href' => null])
{{-- Design pass §3: one figure. The tone colours the value; the label always says what it is (never colour alone). --}}
@php
    $valueClass = match ($tone) {
        'danger' => 'text-red-700 dark:text-red-400',
        'warning' => 'text-amber-700 dark:text-amber-400',
        'success' => 'text-green-700 dark:text-green-400',
        default => 'text-zinc-900 dark:text-white',
    };
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'block min-w-0 rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900',
        'transition hover:border-accent' => $href,
    ]) }} data-ui-stat data-tone="{{ $tone ?? 'neutral' }}">
    <span class="block truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
    <span class="mt-1 block truncate text-xl font-semibold tabular-nums sm:text-2xl {{ $valueClass }}">{{ $value }}</span>
    @if (filled($hint))
        <span class="mt-0.5 block truncate text-xs text-zinc-500 tabular-nums dark:text-zinc-400">{{ $hint }}</span>
    @endif
</{{ $tag }}>
```

`resources/views/components/ui/filters.blade.php`:

```blade
@props(['clear' => null])
{{-- Design pass §3: one row of filters. $clear is the page's URL without filters, given only when a filter is set (plan ruling 3). --}}
<div {{ $attributes->class('flex flex-col gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 sm:flex-row sm:flex-wrap sm:items-end dark:border-zinc-700 dark:bg-zinc-900') }} data-ui-filters>
    {{ $slot }}
    @if ($clear)
        <flux:button variant="ghost" size="sm" icon="x-mark" :href="$clear" wire:navigate class="sm:ms-auto">{{ __('Clear filters') }}</flux:button>
    @endif
</div>
```

`resources/views/components/ui/table.blade.php`:

```blade
{{-- Design pass §3: the card around a list's flux:table; the .ui-table rules in app.css shade and stick the header. --}}
<div {{ $attributes->class('ui-table min-w-0 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900') }} data-ui-table>
    {{ $slot }}
</div>
```

`resources/views/components/ui/empty.blade.php`:

```blade
@props(['title' => null, 'icon' => 'inbox', 'filtered' => false])
{{-- Design pass §3: an empty list says so in one line; the slot holds an optional action. --}}
<div {{ $attributes->class('flex flex-col items-center gap-2 px-6 py-10 text-center') }} data-ui-empty>
    <flux:icon :icon="$icon" class="size-8 text-zinc-400 dark:text-zinc-500" />
    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $title ?? __('Nothing to show here.') }}</p>
    @if ($filtered)
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Try clearing the filters.') }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-2 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
```

`resources/views/components/ui/section.blade.php`:

```blade
@props(['title' => null, 'hint' => null])
{{-- Design pass §3/§6: a titled card for a group of form fields or a record panel. Pass id="…" for the in-page nav. --}}
<section {{ $attributes->class('scroll-mt-20 rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900') }} data-ui-section>
    @if (filled($title))
        <header class="flex flex-wrap items-start justify-between gap-2 border-b border-zinc-200 px-4 py-3 sm:px-6 dark:border-zinc-700">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                @if (filled($hint))
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $hint }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif
    <div class="space-y-4 p-4 sm:p-6">{{ $slot }}</div>
</section>
```

`resources/views/components/ui/form-actions.blade.php`:

```blade
@props(['cancel' => null])
{{-- Design pass §6: the Save bar of a long form stays in view at the bottom of the screen. --}}
<div {{ $attributes->class('sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-2 rounded-xl border border-zinc-200 bg-white/95 px-4 py-3 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95') }} data-ui-form-actions>
    @if ($cancel)
        <flux:button variant="ghost" :href="$cancel" wire:navigate>{{ __('Cancel') }}</flux:button>
    @endif
    {{ $slot }}
</div>
```

`resources/views/components/ui/summary.blade.php`:

```blade
@props(['title', 'status' => null, 'subtitle' => null, 'back' => null])
{{-- Design pass §3/§7: the header card of a record page: title (the h1), status, primary actions, key facts. --}}
<div {{ $attributes->class('rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900') }} data-ui-summary>
    <div class="flex flex-wrap items-start justify-between gap-4 p-4 sm:p-6">
        <div class="min-w-0 space-y-1">
            @if ($back)
                <a href="{{ $back }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-accent-content dark:text-zinc-400">
                    <flux:icon.chevron-left variant="micro" class="rtl:rotate-180" />{{ __('Back') }}
                </a>
            @endif
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
                @if ($status !== null)
                    <x-status-badge :status="$status" />
                @endif
            </div>
            @if (filled($subtitle))
                <flux:text>{{ $subtitle }}</flux:text>
            @endif
            {{ $meta ?? '' }}
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
    @isset($facts)
        <dl class="grid gap-x-6 gap-y-4 border-t border-zinc-200 p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-3 dark:border-zinc-700">{{ $facts }}</dl>
    @endisset
</div>
```

`resources/views/components/ui/fact.blade.php`:

```blade
@props(['label'])
<div {{ $attributes->class('min-w-0') }}>
    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
    <dd class="mt-0.5 font-medium text-zinc-900 tabular-nums dark:text-white">{{ $slot }}</dd>
</div>
```

`resources/views/components/ui/section-nav.blade.php`:

```blade
@props(['items'])
{{-- Design pass §7: in-page links for a long record page. $items: list of [id, label]; each id is a section on the page. --}}
<nav aria-label="{{ __('On this page') }}" {{ $attributes->class('sticky top-0 z-20 -mx-2 overflow-x-auto bg-white/95 px-2 py-2 backdrop-blur dark:bg-zinc-950/95') }} data-ui-section-nav>
    <ul class="flex gap-1">
        @foreach ($items as [$id, $label])
            <li class="shrink-0">
                <a href="#{{ $id }}" class="block rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white">{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
```

- [ ] **Step 5: Status colours** — replace `resources/views/components/status-badge.blade.php` entirely:

```blade
@props(['status'])
{{--
    A status badge coloured by what the status means, always with its words (colour is never the only cue).
    Takes a status enum (uses its label()) or a display label such as an invoice's "Overdue" or "Partially paid",
    in English or already translated (the portal's Arabic): a translated display label maps back to its key.
    Design pass §2: green = done or good, red = needs fixing, amber = waiting for someone, blue = information, grey = not live.
--}}
@php
    $english = $status instanceof \BackedEnum ? (method_exists($status, 'label') ? $status->label() : str($status->value)->headline()->toString()) : (string) $status;
    $displayKeys = ['paid' => 'Paid', 'overdue' => 'Overdue', 'partially_paid' => 'Partially paid', 'unpaid' => 'Unpaid', 'credit_note' => 'Credit note'];
    $key = $status instanceof \BackedEnum
        ? $status->value
        : (collect($displayKeys)->search(fn (string $en) => __($en) === $english) ?: str($english)->snake()->toString());
    $label = __($english); // the portal's Arabic (spec 2026-10-18 tenant portal §4); English is unchanged
    $color = match ($key) {
        'active', 'paid', 'cleared', 'confirmed', 'available', 'finalised', 'approved', 'completed', 'recorded', 'resolved', 'finished' => 'green',
        'issued', 'scheduled', 'occupied', 'deposited', 'unpaid', 'partially_paid', 'in_progress', 'assigned' => 'blue',
        'pending_approval', 'pending', 'reserved', 'held', 'notice_given', 'open' => 'amber',
        'overdue', 'bounced', 'blocked', 'reversed', 'rejected', 'terminated', 'expired' => 'red',
        default => 'zinc', // draft, closed, renewed, ended, replaced, returned, cancelled, credit note
    };
@endphp
<flux:badge size="sm" :color="$color" data-tone="{{ $color }}" {{ $attributes }}>{{ $label }}</flux:badge>
```

- [ ] **Step 6: The custom-fields "More details" card uses `<x-ui.section>`.** In `resources/views/components/custom-fields/section.blade.php`, replace

```blade
    <flux:fieldset class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:legend>{{ __('More details') }}</flux:legend>
```

with

```blade
    <x-ui.section :title="__('More details')">
```

and the matching closing `</flux:fieldset>` (the one that closes this outer fieldset, just before `@endif` at the end of the file) with `</x-ui.section>`. The inner grid and every field stay as they are.

- [ ] **Step 7: Arabic.** In `lang/ar.json`, directly after `    "Credit refund reversal": "عكس استرداد رصيد",`, insert (check each key is absent first):

```json
    "Clear filters": "مسح عوامل التصفية",
    "Nothing to show here.": "لا يوجد ما يُعرض هنا.",
    "Try clearing the filters.": "جرّب مسح عوامل التصفية.",
    "On this page": "في هذه الصفحة",
```

(`Back` and `Cancel` already have entries.)

- [ ] **Step 8: Run the tests to see them pass**

Run: `php artisan test tests/Feature/Shell/UiComponentsTest.php tests/Feature/Shell/DesignPassArabicTest.php tests/Feature/CustomFields tests/Feature/Ledger/LedgerAccessTest.php tests/Feature/Portal/ArabicTest.php`
Expected: PASS.

- [ ] **Step 9: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build` (must compile the `bg-charcoal`/`bg-brand-*` utilities and the `.ui-table` rules without error).

```bash
git add resources/css/app.css resources/views/components/ui resources/views/components/status-badge.blade.php resources/views/components/custom-fields/section.blade.php lang/ar.json tests/Feature/Shell/UiComponentsTest.php tests/Feature/Shell/DesignPassArabicTest.php
git commit -m "$(cat <<'EOF'
Design pass foundation: charcoal and gold tokens, ui components (page header, stats, filters, table, empty, section, form actions, summary, section nav), status colours per spec, required-field asterisk

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Shell — charcoal sidebar, collapsible groups, company brand

**Files:**
- Create: `resources/views/components/ui/brand.blade.php`, `resources/views/components/ui/nav-group.blade.php`, `app/Http/Controllers/BrandLogoController.php`
- Modify: `app/Models/CompanySetting.php`, `routes/web.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/ar.json` (no new keys in this task)
- Delete: `resources/views/layouts/app/header.blade.php`, `resources/views/layouts/auth/card.blade.php`, `resources/views/layouts/auth/split.blade.php`, `resources/views/components/app-logo.blade.php`
- Test: `tests/Feature/Shell/ShellDesignTest.php`; existing `tests/Feature/Shell/AppShellTest.php`

**Interfaces:**
- Consumes (Task 1): tokens `bg-charcoal`, `bg-charcoal-raised`, `border-charcoal-line`, `bg-brand-400`, `text-charcoal`; the `.dark` accent override.
- Produces:
  - `CompanySetting::brand(): array{name: string, logo: ?string}` — `logo` is `route('brand.logo', ['v' => <updated_at timestamp>])` or null.
  - Route `GET /brand/logo` named `brand.logo` (`BrandLogoController`).
  - `<x-ui.brand :name="string" :logo="?string" :href="?string">` (other attributes such as `wire:navigate` pass through).
  - `<x-ui.nav-group :heading="string" key="string" :current="bool">` rendering `<details data-nav-group="<key>" data-current open …>` when current.

- [ ] **Step 1: Write the failing tests** — create `tests/Feature/Shell/ShellDesignTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('the sidebar opens the group holding the current page and leaves the others closed', function () {
    CompanySetting::factory()->create();
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    $this->actingAs($finance)->get(route('invoices.index'))->assertOk()
        ->assertSee('data-nav-group="finance" data-current open', false)
        ->assertSee('data-nav-group="leasing"', false)
        ->assertDontSee('data-nav-group="leasing" data-current', false);

    $this->actingAs($finance)->get(route('ledger.index'))->assertOk()
        ->assertSee('data-nav-group="ledger" data-current open', false)
        ->assertDontSee('data-nav-group="finance" data-current', false);
});

test('groups and items still follow permissions', function () {
    CompanySetting::factory()->create();
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($leasing)->get(route('dashboard'))->assertOk()
        ->assertSee('data-nav-group="leasing"', false)
        ->assertDontSee('data-nav-group="finance"', false)
        ->assertDontSee('data-nav-group="ledger"', false)
        ->assertDontSee('data-nav-group="administration"', false)
        ->assertDontSee(route('invoices.index'));
});

test('the sidebar shows the company name, and the logo once one is uploaded', function () {
    Storage::fake('local');
    CompanySetting::factory()->create(['name_en' => 'Gulf Homes W.L.L.']);
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($leasing)->get(route('dashboard'))->assertOk()
        ->assertSee('Gulf Homes W.L.L.')->assertDontSee(route('brand.logo'));

    Storage::disk('local')->put('settings/logo.png', 'png-bytes');
    CompanySetting::query()->whereKey(1)->update(['logo_path' => 'settings/logo.png']);

    $this->actingAs($leasing)->get(route('dashboard'))->assertOk()->assertSee(route('brand.logo'), false);
});

test('brand() names the company in Arabic for Arabic pages and falls back to the app name without settings', function () {
    expect(CompanySetting::brand())->toBe(['name' => config('app.name'), 'logo' => null]);

    CompanySetting::factory()->create(['name_en' => 'Gulf Homes', 'name_ar' => 'بيوت الخليج']);
    app()->setLocale('ar');

    expect(CompanySetting::brand()['name'])->toBe('بيوت الخليج');
});

test('the logo route serves the uploaded logo to anyone, and 404s without one', function () {
    Storage::fake('local');
    CompanySetting::factory()->create();

    $this->get(route('brand.logo'))->assertNotFound();

    Storage::disk('local')->put('settings/logo.png', 'png-bytes');
    CompanySetting::query()->whereKey(1)->update(['logo_path' => 'settings/logo.png']);

    $response = $this->get(route('brand.logo'))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('max-age=86400')->toContain('public');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test tests/Feature/Shell/ShellDesignTest.php`
Expected: FAIL — `Route [brand.logo] not defined`, `Call to undefined method App\Models\CompanySetting::brand()`.

- [ ] **Step 3: `CompanySetting::brand()`** — in `app/Models/CompanySetting.php`, after the `current()` method, add:

```php
    /**
     * Design pass §4/§8: the name and logo the sidebar, the portal header and the sign-in pages show. The Arabic name on
     * Arabic pages when there is one; the app name before the first settings row exists (plan ruling 5). The logo URL
     * carries the settings' updated_at so a new upload is fetched at once (plan ruling 4).
     *
     * @return array{name: string, logo: ?string}
     */
    public static function brand(): array
    {
        $company = static::query()->find(1);
        if ($company === null) {
            return ['name' => (string) config('app.name'), 'logo' => null];
        }
        $arabic = app()->getLocale() === 'ar' && filled($company->name_ar);

        return [
            'name' => $arabic ? (string) $company->name_ar : $company->name_en,
            'logo' => filled($company->logo_path) ? route('brand.logo', ['v' => $company->updated_at?->getTimestamp()]) : null,
        ];
    }
```

- [ ] **Step 4: The logo route.** Create `app/Http/Controllers/BrandLogoController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Design pass plan ruling 4: the company logo for the sidebar, the portal header and the sign-in pages. Public (sign-in
 * pages are for guests; a logo is public branding). The path comes only from company settings, never from the request.
 */
class BrandLogoController
{
    public function __invoke(): StreamedResponse
    {
        $path = CompanySetting::query()->whereKey(1)->value('logo_path');
        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
    }
}
```

In `routes/web.php`, directly after the line `Route::get('v/{token}', VerifyAgreementController::class)->middleware('throttle:30,1')->name('agreements.verify');` (the one at the top, line 9), add:

```php
Route::get('brand/logo', BrandLogoController::class)->name('brand.logo'); // design pass plan ruling 4: public, read-only
```

and add `use App\Http\Controllers\BrandLogoController;` to the file's `use` list (keep it alphabetical).

- [ ] **Step 5: The brand and nav-group components.**

`resources/views/components/ui/brand.blade.php`:

```blade
@props(['name', 'logo' => null, 'href' => null])
{{-- Design pass §4/§8: the company's logo (Company settings) and name; without a logo, the name's first letter on a gold tile. --}}
@php
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class('flex min-w-0 items-center gap-3') }} data-ui-brand>
    @if ($logo)
        <img src="{{ $logo }}" alt="" class="size-9 shrink-0 rounded-md bg-white object-contain p-0.5">
    @else
        <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-md bg-brand-400 text-base font-bold text-charcoal">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
    @endif
    <span class="truncate text-sm font-semibold">{{ $name }}</span>
</{{ $tag }}>
```

`resources/views/components/ui/nav-group.blade.php`:

```blade
@props(['heading', 'key', 'current' => false])
{{--
    Design pass §4 (plan ruling 6): a collapsible sidebar group. The group holding the current page is always open; the
    others open or stay closed as this browser last left them (localStorage "rms.nav.<key>", read in try/catch, so a
    blocked storage only means "closed").
--}}
<details data-nav-group="{{ $key }}" {{ $current ? 'data-current open' : '' }}
    x-data
    x-init="const k = 'rms.nav.{{ $key }}';
        if (! {{ $current ? 'true' : 'false' }}) { try { if (localStorage.getItem(k) === 'open') $el.open = true } catch (e) {} }
        $el.addEventListener('toggle', () => { try { localStorage.setItem(k, $el.open ? 'open' : 'closed') } catch (e) {} })"
    {{ $attributes->class('group/nav') }}>
    <summary class="my-px flex h-8 cursor-pointer list-none items-center gap-2 rounded-lg px-3 text-sm font-medium text-zinc-400 select-none hover:bg-charcoal-raised hover:text-white [&::-webkit-details-marker]:hidden">
        <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 group-open/nav:hidden rtl:rotate-180" />
        <flux:icon.chevron-down variant="micro" class="hidden size-4 shrink-0 group-open/nav:block" />
        <span class="truncate">{{ $heading }}</span>
    </summary>
    <div class="grid ps-2">{{ $slot }}</div>
</details>
```

- [ ] **Step 6: The sidebar layout.** Replace `resources/views/layouts/app/sidebar.blade.php` entirely. Every item, its icon, label, route, `:current` pattern and `@can`/`@canany` wrapper is the same as today; only the group wrappers, the brand and the colours change.

```blade
@php
    $brand = \App\Models\CompanySetting::brand();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-950">
        {{-- Design pass §4: charcoal in both modes. The "dark" class gives Flux's light-on-dark item styles and the bright-gold accent (plan ruling 6). --}}
        <flux:sidebar sticky collapsible="mobile" class="dark border-e border-charcoal-line bg-charcoal text-white">
            <flux:sidebar.header>
                <x-ui.brand :name="$brand['name']" :logo="$brand['logo']" :href="route('dashboard')" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    @can('approvals.decide')
                        <flux:sidebar.item icon="check-badge" :href="route('approvals.index')" :current="request()->routeIs('approvals.*')"
                            :badge="\App\Models\Approval::pending()->count() ?: null" wire:navigate>{{ __('Approvals') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                <x-ui.nav-group key="leasing" :heading="__('Leasing')" :current="request()->routeIs('customers.*', 'agreements.*', 'reminders.*')">
                    @can('customers.view')
                        <flux:sidebar.item icon="identification" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate>{{ __('Tenants') }}</flux:sidebar.item>
                    @endcan
                    @can('agreements.view')
                        <flux:sidebar.item icon="document-text" :href="route('agreements.index')" :current="request()->routeIs('agreements.*')" wire:navigate>{{ __('Agreements') }}</flux:sidebar.item>
                    @endcan
                    @canany(['finance.view', 'agreements.view'])
                        <flux:sidebar.item icon="bell" :href="route('reminders.index')" :current="request()->routeIs('reminders.*')" wire:navigate>{{ __('Reminders') }}</flux:sidebar.item>
                    @endcanany
                </x-ui.nav-group>

                @can('finance.view')
                    <x-ui.nav-group key="finance" :heading="__('Finance')"
                        :current="request()->routeIs('invoices.*', 'payments.*', 'cheques.*', 'disbursements.*', 'owner-payables.*', 'owner-statements.*', 'deposit-settlements.*')">
                        <flux:sidebar.item icon="banknotes" :href="route('invoices.index')" :current="request()->routeIs('invoices.*')" wire:navigate>{{ __('Invoices') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="credit-card" :href="route('payments.index')" :current="request()->routeIs('payments.*')" wire:navigate>{{ __('Payments') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="banknotes" :href="route('cheques.index')" :current="request()->routeIs('cheques.*')" wire:navigate>{{ __('Cheques') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="arrow-up-right" :href="route('disbursements.index')" :current="request()->routeIs('disbursements.*')" wire:navigate>{{ __('Payments out') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="calendar-days" :href="route('owner-payables.index')" :current="request()->routeIs('owner-payables.*')" wire:navigate>{{ __('Head-lease due') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="document-chart-bar" :href="route('owner-statements.index')" :current="request()->routeIs('owner-statements.*')" wire:navigate>{{ __('Owner statements') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="shield-check" :href="route('deposit-settlements.index')" :current="request()->routeIs('deposit-settlements.*')" wire:navigate>{{ __('Deposit settlements') }}</flux:sidebar.item>
                    </x-ui.nav-group>
                @endcan

                @canany(['ledger.view', 'ledger.admin'])
                    <x-ui.nav-group key="ledger" :heading="__('Ledger')" :current="request()->routeIs('ledger.*')">
                        @can('ledger.view')
                            <flux:sidebar.item icon="book-open" :href="route('ledger.index')" :current="request()->routeIs('ledger.index')" wire:navigate>{{ __('Ledger health') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="scale" :href="route('ledger.trial-balance')" :current="request()->routeIs('ledger.trial-balance')" wire:navigate>{{ __('Trial balance') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="building-library" :href="route('ledger.balance-sheet')" :current="request()->routeIs('ledger.balance-sheet')" wire:navigate>{{ __('Balance sheet') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="chart-bar" :href="route('ledger.profit-and-loss')" :current="request()->routeIs('ledger.profit-and-loss')" wire:navigate>{{ __('Profit and loss') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="book-open" :href="route('ledger.general-ledger')" :current="request()->routeIs('ledger.general-ledger')" wire:navigate>{{ __('General ledger') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="queue-list" :href="route('ledger.journals')" :current="request()->routeIs('ledger.journals')" wire:navigate>{{ __('Journal list') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="arrows-right-left" :href="route('ledger.cash-movements')" :current="request()->routeIs('ledger.cash-movements')" wire:navigate>{{ __('Cash and bank movements') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="receipt-percent" :href="route('ledger.vat-returns')" :current="request()->routeIs('ledger.vat-returns')" wire:navigate>{{ __('VAT returns') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="banknotes" :href="route('ledger.bank-reconciliation')" :current="request()->routeIs('ledger.bank-reconciliation*')" wire:navigate>{{ __('Bank reconciliation') }}</flux:sidebar.item>
                        @endcan
                        @can('ledger.manage')
                            <flux:sidebar.item icon="pencil-square" :href="route('ledger.journals.create')" :current="request()->routeIs('ledger.journals.create')" wire:navigate>{{ __('New journal') }}</flux:sidebar.item>
                        @endcan
                        @can('ledger.admin')
                            <flux:sidebar.item icon="list-bullet" :href="route('ledger.accounts')" :current="request()->routeIs('ledger.accounts')" wire:navigate>{{ __('Accounts and rules') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="flag" :href="route('ledger.setup')" :current="request()->routeIs('ledger.setup')" wire:navigate>{{ __('Start date and opening balances') }}</flux:sidebar.item>
                        @endcan
                    </x-ui.nav-group>
                @endcanany

                @canany(['reports.operational', 'reports.financial'])
                    <x-ui.nav-group key="reports" :heading="__('Reports')" :current="request()->routeIs('reports.*')">
                        <flux:sidebar.item icon="document-chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.index')" wire:navigate>{{ __('All reports') }}</flux:sidebar.item>
                        @can('reports.financial')
                            <flux:sidebar.item icon="chart-bar" :href="route('reports.building-profitability')" :current="request()->routeIs('reports.building-profitability')" wire:navigate>{{ __('Building profitability') }}</flux:sidebar.item>
                        @endcan
                    </x-ui.nav-group>
                @endcanany

                <x-ui.nav-group key="property" :heading="__('Property')" :current="request()->routeIs('buildings.*', 'units.*', 'owners.*', 'owner-contracts.*', 'expenses.*')">
                    @can('buildings.view')
                        <flux:sidebar.item icon="building-office-2" :href="route('buildings.index')" :current="request()->routeIs('buildings.*')" wire:navigate>{{ __('Buildings') }}</flux:sidebar.item>
                    @endcan
                    @can('buildings.view')
                        <flux:sidebar.item icon="home-modern" :href="route('units.index')" :current="request()->routeIs('units.*')" wire:navigate>{{ __('Units') }}</flux:sidebar.item>
                    @endcan
                    @can('owners.view')
                        <flux:sidebar.item icon="user-group" :href="route('owners.index')" :current="request()->routeIs('owners.*')" wire:navigate>{{ __('Owners') }}</flux:sidebar.item>
                    @endcan
                    @can('owners.view')
                        <flux:sidebar.item icon="document-text" :href="route('owner-contracts.index')" :current="request()->routeIs('owner-contracts.*')" wire:navigate>{{ __('Owner contracts') }}</flux:sidebar.item>
                    @endcan
                    @can('viewAny', \App\Models\Expense::class)
                        <flux:sidebar.item icon="receipt-percent" :href="route('expenses.index')" :current="request()->routeIs('expenses.*')" wire:navigate>{{ __('Expenses') }}</flux:sidebar.item>
                    @endcan
                </x-ui.nav-group>

                @can('maintenance.view')
                    <x-ui.nav-group key="maintenance" :heading="__('Maintenance')" :current="request()->routeIs('maintenance.*')">
                        <flux:sidebar.item icon="wrench-screwdriver" :href="route('maintenance.requests.index')" :current="request()->routeIs('maintenance.requests.*')" wire:navigate>{{ __('Requests') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('maintenance.work-orders.index')" :current="request()->routeIs('maintenance.work-orders.*')" wire:navigate>{{ __('Work orders') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="truck" :href="route('maintenance.vendors.index')" :current="request()->routeIs('maintenance.vendors.*')" wire:navigate>{{ __('Vendors') }}</flux:sidebar.item>
                    </x-ui.nav-group>
                @endcan

                @canany(['users.manage', 'roles.manage', 'settings.manage', 'templates.manage', 'audit.view', 'import.run'])
                    <x-ui.nav-group key="administration" :heading="__('Administration')" :current="request()->routeIs('admin.*', 'import.*')">
                        @can('users.manage')
                            <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>{{ __('Users') }}</flux:sidebar.item>
                        @endcan
                        @can('roles.manage')
                            <flux:sidebar.item icon="key" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.*')" wire:navigate>{{ __('Roles') }}</flux:sidebar.item>
                        @endcan
                        @can('settings.manage')
                            <flux:sidebar.item icon="building-office" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>{{ __('Company settings') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="sparkles" :href="route('admin.facilities')" :current="request()->routeIs('admin.facilities')" wire:navigate>{{ __('Facilities') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="home-modern" :href="route('admin.unit-types')" :current="request()->routeIs('admin.unit-types')" wire:navigate>{{ __('Unit types') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="building-library" :href="route('admin.banks')" :current="request()->routeIs('admin.banks')" wire:navigate>{{ __('Banks') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="adjustments-horizontal" :href="route('admin.custom-fields')" :current="request()->routeIs('admin.custom-fields')" wire:navigate>{{ __('Custom fields') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="envelope" :href="route('admin.reminders')" :current="request()->routeIs('admin.reminders')" wire:navigate>{{ __('Tenant reminders') }}</flux:sidebar.item>
                            <flux:sidebar.item icon="server" :href="route('admin.email')" :current="request()->routeIs('admin.email')" wire:navigate>{{ __('Email settings') }}</flux:sidebar.item>
                        @endcan
                        @can('templates.manage')
                            <flux:sidebar.item icon="document-duplicate" :href="route('admin.templates.index')" :current="request()->routeIs('admin.templates.*')" wire:navigate>{{ __('Contract templates') }}</flux:sidebar.item>
                        @endcan
                        @can('audit.view')
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('admin.audit')" :current="request()->routeIs('admin.audit')" wire:navigate>{{ __('Audit log') }}</flux:sidebar.item>
                        @endcan
                        @can('import.run')
                            <flux:sidebar.item icon="arrow-up-tray" :href="route('import.index')" :current="request()->routeIs('import.*')" wire:navigate>{{ __('Data import') }}</flux:sidebar.item>
                        @endcan
                    </x-ui.nav-group>
                @endcanany
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="px-2 text-xs leading-5 text-zinc-400">
                <div>{{ __('Version :v', ['v' => config('app.version')]) }}</div>
                <div>{{ __('Developed by') }} <a href="https://deverra.me" target="_blank" rel="noopener" class="font-medium text-zinc-200 underline decoration-zinc-600 underline-offset-2 hover:text-white">DeVerra Technologies</a></div>
            </div>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile header: the same charcoal, the brand, the menu toggle and the user menu (items unchanged). -->
        <flux:header class="dark border-b border-charcoal-line bg-charcoal text-white lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <x-ui.brand class="ms-2" :name="$brand['name']" :logo="$brand['logo']" :href="route('dashboard')" wire:navigate />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
```

`resources/views/components/desktop-user-menu.blade.php` is not changed: inside the dark sidebar it takes Flux's dark styles (spec §4 "unchanged items, restyled").

- [ ] **Step 7: Delete the unused starter files**

```bash
git rm resources/views/layouts/app/header.blade.php resources/views/layouts/auth/card.blade.php resources/views/layouts/auth/split.blade.php resources/views/components/app-logo.blade.php
```

Then confirm nothing references them: `grep -rn "app.header\|auth.card\|auth.split\|x-app-logo " resources app` must print nothing (`x-app-logo-icon` is still used by `layouts/auth/simple.blade.php` until Task 10).

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/Shell tests/Feature/Permissions tests/Feature/DashboardTest.php tests/Feature/Auth`
Expected: PASS.

- [ ] **Step 9: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add -A app/Models/CompanySetting.php app/Http/Controllers/BrandLogoController.php routes/web.php resources/views/layouts resources/views/components tests/Feature/Shell/ShellDesignTest.php
git commit -m "$(cat <<'EOF'
Design pass shell: charcoal sidebar with collapsible groups remembered per browser (current group open), company logo and name, public logo route, unused starter layouts removed

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Lists A — leasing and finance

**Files:**
- Modify views: `resources/views/livewire/{customers/index,agreements/index,reminders/index,invoices/index,payments/index,cheques/index,disbursements/index,owner-payables/index,owner-statements/index,deposit-settlements/index,approvals/index}.blade.php`
- Modify components: `app/Livewire/Agreements/Index.php`, `app/Livewire/Payments/Index.php`, `app/Livewire/Cheques/Index.php`
- Modify: `lang/ar.json`, `tests/Feature/Shell/DesignPassArabicTest.php`, `tests/Feature/Invoices/InvoiceTabsTest.php`
- Test (new): `tests/Feature/Cheques/ChequeStatsTest.php`, `tests/Feature/Agreements/AgreementStatsTest.php`, `tests/Feature/Payments/PaymentStatsTest.php`; one test appended to `tests/Feature/Approvals/ApprovalsScreenTest.php`

**Interfaces:**
- Consumes (Task 1): `<x-ui.page-header :title :hint>` slots `actions`; `<x-ui.stats :cols>`; `<x-ui.stat :label :value :tone :hint :href>`; `<x-ui.filters :clear>`; `<x-ui.table>`; `<x-ui.empty :title :filtered>`.
- Produces: view data `stats` on `Agreements\Index` (`array{active: int, pending: int, expiring: int}`), `Payments\Index` (`array{collected: string, receipts: int}`), `Cheques\Index` (`array{held: int, held_amount: string, week: int, bounced: int}`). Private `filtered(): Builder` and `stats(): array` on each.

**Rules for this task (from the style guide):**
- Keep every field name, `wire:model`/`wire:click`/`wire:key`/`wire:confirm`, `@can`/`@if` wrapper, route, button and heading text, column and filter. No new public properties or methods.
- Header → `<x-ui.page-header :title="<same text>">`; existing buttons/modal triggers/export buttons, unchanged, in `<x-slot:actions>`; add `icon="plus"` to the primary "New …"/"Enter …"/"Record …" button; a description `<flux:text>` under the heading becomes `:hint`.
- Filters → wrapped unchanged in `<x-ui.filters :clear="$filtered ? <clear url> : null">`, with `$filtered` defined in an `@php … @endphp` block at the top of the file (condition per file below).
- Tabs → active classes `border-accent text-accent-content` (was `border-zinc-800 text-zinc-900 dark:border-white dark:text-white`), focus ring `focus-visible:ring-accent`.
- `<div class="overflow-x-auto">` → `<x-ui.table>`; table untouched; money columns `align="end"` + cells `text-end tabular-nums`; after `</flux:table>` add `@if ($<rows>->isEmpty()) <x-ui.empty :filtered="$filtered" /> @endif` unless an `@forelse … @empty` row exists (keep it).
- Modals keep their content.
- New strings via `__()`; Arabic entries in `lang/ar.json` after `    "Credit refund reversal": "عكس استرداد رصيد",` (only keys absent); keys appended to `tests/Feature/Shell/DesignPassArabicTest.php`.
- RTL-safe utilities only; `dark:` variant for every colour added; no hex in Blade; Blade uses `@php … @endphp` blocks only.

- [ ] **Step 1: Write the failing figure tests**

`tests/Feature/Cheques/ChequeStatsTest.php`:

```php
<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Cheques\Entry;
use App\Livewire\Cheques\Index;
use App\Livewire\Cheques\Show;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain')); // a Monday: this week is 05/10 to 11/10
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-03-31'], [Unit::factory()->create()]);
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->agreement, $this->finance)); // 6 rent invoices of 400.000
});

test('cheque figures: held (count and amount), to deposit this week and bounced, under every filter but the status', function () {
    Livewire::withQueryParams(['agreement' => $this->agreement->id])->actingAs($this->finance)->test(Entry::class)
        ->set('bank_name', 'NBB')
        ->set('rows.0.cheque_no', '501')->set('rows.0.cheque_date', '2026-10-07') // this week
        ->set('rows.1.cheque_no', '502')                                           // 01/11/2026
        ->set('rows.2.cheque_no', '503')                                           // 01/12/2026, bounced below
        ->call('save')->assertHasNoErrors();
    $bounced = Cheque::where('cheque_no', '503')->sole();
    Livewire::actingAs($this->finance)->test(Index::class)->set('selected', [$bounced->id])->set('depositedOn', '2026-10-05')->call('depositSelected')->assertHasNoErrors();
    Livewire::actingAs($this->finance)->test(Show::class, ['cheque' => $bounced->fresh()])
        ->set('bouncedOn', '2026-10-05')->set('bounceReason', 'Signature mismatch')->call('bounce')->assertHasNoErrors();

    $all = ['held' => 2, 'held_amount' => '800.000', 'week' => 1, 'bounced' => 1];
    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertViewHas('stats', $all)
        ->assertSeeInOrder(['Held', '2', '800.000 BHD', 'To deposit this week', '1', 'Bounced', '1'])
        ->set('status', 'all')->assertViewHas('stats', $all) // the status filter does not change them
        ->set('search', '501')->assertViewHas('stats', ['held' => 1, 'held_amount' => '400.000', 'week' => 1, 'bounced' => 0])
        ->set('search', '')->set('building', Building::factory()->create()->id) // a building none of them is in
        ->assertViewHas('stats', ['held' => 0, 'held_amount' => '0.000', 'week' => 0, 'bounced' => 0]);
});
```

`tests/Feature/Agreements/AgreementStatsTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Agreements\Index;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->leasing = matrixUser(RoleName::Leasing, $this->a); // sees building A only
});

test('agreement figures: active, pending approval and expiring in 60 days, in the viewer\'s buildings, under every filter but status and end date', function () {
    $ending = activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-11-30'], [Unit::factory()->for($this->a)->create()]); // ends in 56 days
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2027-09-30'], [Unit::factory()->for($this->a)->create()]);
    $unit = Unit::factory()->for($this->a)->create();
    $pending = Agreement::factory()->create(['start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $pending->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => $unit->list_rent, 'deposit_amount' => '400.000',
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $pending->forceFill(['status' => 'pending_approval'])->save();
    Agreement::factory()->create(); // a draft with no units by someone else: not visible, and a draft is never counted
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-11-15'], [Unit::factory()->for($this->b)->create()]); // building B: not visible

    $all = ['active' => 2, 'pending' => 1, 'expiring' => 1];
    Livewire::actingAs($this->leasing)->test(Index::class)
        ->assertViewHas('stats', $all)
        ->assertSeeInOrder(['Active', '2', 'Pending approval', '1', 'Expiring in 60 days', '1'])
        ->set('status', 'draft')->set('expiring', 30)->assertViewHas('stats', $all) // neither filter changes them
        ->set('status', '')->set('expiring', null)->set('search', $ending->number)
        ->assertViewHas('stats', ['active' => 1, 'pending' => 0, 'expiring' => 1]);
});
```

`tests/Feature/Payments/PaymentStatsTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Payments\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
});

test('payment figures: collected this month (1st to today, confirmed) under the list\'s filters', function () {
    $pay = fn (string $on, string $amount, string $method) => app(RecordPayment::class)->handle($this->finance, $this->customer,
        ['received_on' => $on, 'method' => $method, 'amount' => $amount]);
    $pay('2026-09-30', '500', 'cash');            // last month: not counted
    $first = $pay('2026-10-01', '40', 'cash');
    $pay('2026-10-20', '100', 'bank_transfer');

    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertViewHas('stats', ['collected' => '140.000', 'receipts' => 2])
        ->assertSeeInOrder(['Collected this month', '140.000 BHD', 'Receipts this month', '2'])
        ->set('search', $first->number)->assertViewHas('stats', ['collected' => '40.000', 'receipts' => 1])
        ->set('search', '')->set('building', Building::factory()->create()->id) // a building none of them is in
        ->assertViewHas('stats', ['collected' => '0.000', 'receipts' => 0]);
});
```

Append to `tests/Feature/Approvals/ApprovalsScreenTest.php` (its `beforeEach` gives `$this->finance`, `$this->management` and a draft `$this->contract`; `SubmitOwnerContract` and `Index` are already imported there):

```php
test('approval figures: how many wait, and how many this approver may decide', function () {
    app(SubmitOwnerContract::class)->handle($this->finance, $this->contract);

    Livewire::actingAs($this->management)->test(Index::class)
        ->assertSeeInOrder(['Waiting', '1', 'For you to decide', '1']);
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test tests/Feature/Cheques/ChequeStatsTest.php tests/Feature/Agreements/AgreementStatsTest.php tests/Feature/Payments/PaymentStatsTest.php tests/Feature/Approvals`
Expected: FAIL — `stats` is not in the view data; "Waiting" not seen.

- [ ] **Step 3: Component figures.**

`app/Livewire/Cheques/Index.php`: add `use App\Reports\Queries;` and `use App\Support\Fils;` to the `use` list. Replace the whole `render()` method with:

```php
    /** @return Builder<Cheque> every filter but the status: visibility, received cheques, building and search (plan ruling 1) */
    private function filtered(): Builder
    {
        return Cheque::query()->visibleTo($this->actor())
            ->where('direction', 'received')
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('cheque_no', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))));
    }

    /**
     * Design pass §5: held (count and amount), to deposit this week (held, dated Monday to Sunday of this week, as the
     * dashboard tile counts it) and bounced. One aggregate query over filtered().
     *
     * @return array{held: int, held_amount: string, week: int, bounced: int}
     */
    private function stats(): array
    {
        $held = ChequeStatus::Held->value;
        $row = (array) ($this->filtered()->toBase()->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS held_count, COALESCE(SUM(CASE WHEN status = ? THEN amount END), 0) AS held_amount, '
            .'SUM(CASE WHEN status = ? AND cheque_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS week_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS bounced_count',
            [$held, $held, $held, Queries::weekStart(), Queries::weekEnd(), ChequeStatus::Bounced->value],
        )->first() ?? []);

        return [
            'held' => (int) ($row['held_count'] ?? 0),
            'held_amount' => Fils::toDecimal(Fils::fromDecimal((string) ($row['held_amount'] ?? '0'))),
            'week' => (int) ($row['week_count'] ?? 0),
            'bounced' => (int) ($row['bounced_count'] ?? 0),
        ];
    }

    public function render(): View
    {
        $cheques = $this->filtered()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->with(['customer:id,name_en', 'invoice:id,number,status'])
            ->orderBy('cheque_date')->orderBy('id')
            ->paginate(50);

        return view('livewire.cheques.index', [
            'cheques' => $cheques,
            'stats' => $this->stats(),
            'statuses' => ChequeStatus::cases(),
            'canManage' => $canManage = $this->actor()->can('manage', Cheque::class),
            // Type-to-search: by agreement number, or the customer's name, mobile, ID or unit code.
            'agreementResults' => $canManage && mb_strlen(trim($this->agreementSearch)) >= 2
                ? $this->activeAgreements()
                    ->where(fn ($q) => $q->where('number', 'like', '%'.trim($this->agreementSearch).'%')
                        ->orWhereHas('customer', fn ($c) => $c->search($this->agreementSearch)))
                    ->with('customer:id,name_en,mobile')->orderBy('number')->limit(8)->get(['id', 'number', 'customer_id'])
                : collect(),
        ]);
    }
```

`app/Livewire/Payments/Index.php`: add `use App\Enums\PaymentMethod;`, `use App\Enums\PaymentStatus;`, `use App\Support\Fils;`, `use Illuminate\Database\Eloquent\Builder;`. Replace `render()` with:

```php
    /** @return Builder<Payment> the list's filters: visible tenants, building and search */
    private function filtered(): Builder
    {
        return Payment::query()
            ->whereIn('customer_id', Customer::query()->visibleTo($this->actor())->select('id'))
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhere('reference', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))));
    }

    /**
     * Design pass §5: collected this month as the dashboard counts it (confirmed, deposit-applied left out, from the 1st to
     * today), under the list's filters. One aggregate query.
     *
     * @return array{collected: string, receipts: int}
     */
    private function stats(): array
    {
        $row = (array) ($this->filtered()
            ->where('status', PaymentStatus::Confirmed)->where('method', '!=', PaymentMethod::DepositApplied)
            ->whereBetween('received_on', [now('Asia/Bahrain')->startOfMonth()->toDateString(), now('Asia/Bahrain')->toDateString()])
            ->toBase()->selectRaw('COUNT(*) AS receipt_count, COALESCE(SUM(amount), 0) AS collected')->first() ?? []);

        return [
            'collected' => Fils::toDecimal(Fils::fromDecimal((string) ($row['collected'] ?? '0'))),
            'receipts' => (int) ($row['receipt_count'] ?? 0),
        ];
    }

    public function render(): View
    {
        $payments = $this->filtered()
            ->with(['customer:id,name_en', 'tenders'])
            ->latest('received_on')->latest('id')
            ->paginate(50);

        return view('livewire.payments.index', [
            'payments' => $payments,
            'stats' => $this->stats(),
            'canCreate' => $canCreate = $this->actor()->can('create', Payment::class),
            'customerResults' => $canCreate ? Customer::findFor($this->actor(), $this->customerSearch) : collect(),
        ]);
    }
```

`app/Livewire/Agreements/Index.php`: add `use Illuminate\Database\Eloquent\Builder;`. Replace `render()` with:

```php
    /** @return Builder<Agreement> every filter but status and end date: visibility, building, search, custom fields (plan ruling 1) */
    private function filtered(): Builder
    {
        return Agreement::query()
            ->visibleTo($this->actor())
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Agreement, $this->more));
    }

    /**
     * Design pass §5: active, pending approval, and active ending between today and today + 60 days (the dashboard's
     * Queries::expiringAgreements). One aggregate query over filtered().
     *
     * @return array{active: int, pending: int, expiring: int}
     */
    private function stats(): array
    {
        $active = AgreementStatus::Active->value;
        $row = (array) ($this->filtered()->toBase()->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_count, '
            .'SUM(CASE WHEN status = ? AND end_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS expiring_count',
            [$active, AgreementStatus::PendingApproval->value, $active, now('Asia/Bahrain')->toDateString(), now('Asia/Bahrain')->addDays(60)->toDateString()],
        )->first() ?? []);

        return ['active' => (int) ($row['active_count'] ?? 0), 'pending' => (int) ($row['pending_count'] ?? 0), 'expiring' => (int) ($row['expiring_count'] ?? 0)];
    }

    public function render(): View
    {
        $today = now('Asia/Bahrain')->toDateString();

        $agreements = $this->filtered()
            ->with('customer:id,name_en')
            ->with('agreementUnits.unit:id,code,building_id', 'agreementUnits.unit.building:id,code')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when(in_array($this->expiring, [30, 60, 90], true), fn ($q) => $q
                ->where('status', AgreementStatus::Active)
                ->whereBetween('end_date', [$today, now('Asia/Bahrain')->addDays((int) $this->expiring)->toDateString()]))
            ->latest('id')
            ->paginate(25);

        return view('livewire.agreements.index', ['agreements' => $agreements, 'stats' => $this->stats(), 'statuses' => AgreementStatus::cases(),
            'customFields' => CustomFields::active(CustomFieldEntity::Agreement)]);
    }
```

- [ ] **Step 4: Convert the views.** For each file apply the rules above, plus:

1. `customers/index.blade.php` — exactly the "After" in the style guide: `$filtered = $search !== '' || $this->building || ! empty($more);` clear `route('customers.index')`; empty under the table.
2. `agreements/index.blade.php` — `$filtered = $status !== '' || $expiring || $search !== '' || $this->building || ! empty($more);` clear `route('agreements.index')`. Under the header:

```blade
    <x-ui.stats :cols="3">
        <x-ui.stat :label="__('Active')" :value="$stats['active']" :href="route('agreements.index', ['status' => 'active'])" />
        <x-ui.stat :label="__('Pending approval')" :value="$stats['pending']" :tone="$stats['pending'] > 0 ? 'warning' : null" :href="route('agreements.index', ['status' => 'pending_approval'])" />
        <x-ui.stat :label="__('Expiring in 60 days')" :value="$stats['expiring']" :tone="$stats['expiring'] > 0 ? 'warning' : null" :href="route('agreements.index', ['expiring' => 60])" />
    </x-ui.stats>
```

   The four filter controls go in `<x-ui.filters>`; `<x-custom-fields.filter>` stays right after it. Empty state under the table.
3. `reminders/index.blade.php` — the `<flux:text>` under the heading becomes `:hint`; the "Export to Excel" button (with its wrapper `div` removed) moves to the actions slot. `$filtered = filled($type) || filled($status) || filled($building);` (from/to are always set by `mount()`), clear `route('reminders.index')`. The table keeps its `@forelse … @empty` row.
4. `invoices/index.blade.php` — header actions: the modal trigger (add `icon="plus"` to its button). Under the header:

```blade
    <x-ui.stats>
        <x-ui.stat :label="__('Owed')" :value="__(':amount BHD', ['amount' => $owed['unpaid']])" />
        <x-ui.stat :label="__('Overdue')" :value="__(':amount BHD', ['amount' => $owed['overdue']])" :tone="$owed['overdue'] !== '0.000' ? 'danger' : null" />
        <x-ui.stat :label="__('Overdue invoices')" :value="$counts['overdue']" :tone="$counts['overdue'] > 0 ? 'danger' : null" :href="route('invoices.index', ['tab' => 'overdue'])" />
        <x-ui.stat :label="__('Drafts')" :value="$counts['drafts']" :href="route('invoices.index', ['tab' => 'drafts'])" />
    </x-ui.stats>
```

   Delete the `@if (in_array($currentTab, ['unpaid', 'overdue'], true)) <flux:text …>…Owed/Overdue…</flux:text> @endif` block from the tab row (the figures replace it, plan ruling 2). Restyle the active tab per the rules. `$filtered = $type !== '' || $search !== '' || $this->building;` clear `route('invoices.index', ['tab' => $currentTab])`. Empty state under the table.
5. `payments/index.blade.php` — `$filtered = $search !== '' || $this->building;` clear `route('payments.index')`. Under the header:

```blade
    <x-ui.stats :cols="2">
        <x-ui.stat :label="__('Collected this month')" :value="__(':amount BHD', ['amount' => $stats['collected']])" />
        <x-ui.stat :label="__('Receipts this month')" :value="$stats['receipts']" />
    </x-ui.stats>
```

6. `cheques/index.blade.php` — `$filtered = $status !== 'held' || $search !== '' || $this->building;` clear `route('cheques.index')`. The status select, building filter and search go in `<x-ui.filters>`; the deposit controls (`@if ($canManage && $status === 'held')` date input and "Deposit selected" button) stay outside, in their own `<div class="flex flex-wrap items-end gap-3">` directly under the filters, unchanged, followed by the two `<flux:error>` lines. Under the header:

```blade
    <x-ui.stats :cols="3">
        <x-ui.stat :label="__('Held')" :value="$stats['held']" :hint="__(':amount BHD', ['amount' => $stats['held_amount']])" :href="route('cheques.index', ['status' => 'held'])" />
        <x-ui.stat :label="__('To deposit this week')" :value="$stats['week']" :tone="$stats['week'] > 0 ? 'warning' : null" />
        <x-ui.stat :label="__('Bounced')" :value="$stats['bounced']" :tone="$stats['bounced'] > 0 ? 'danger' : null" :href="route('cheques.index', ['status' => 'bounced'])" />
    </x-ui.stats>
```

7. `disbursements/index.blade.php` — `$filtered = $status !== 'all' || $this->building;` clear `route('disbursements.index')`.
8. `owner-payables/index.blade.php` — heading `<flux:heading size="xl">` becomes the page header (it gains `level="1"` through the component). Building select and "Due by" date go in filters; the "Export to Excel" button (`@can('reports.financial')`) moves to actions. `$filtered = (bool) $building;` clear `route('owner-payables.index')`. Keep the `@forelse/@empty` row and the "Pay head-lease" modal as is.
9. `owner-statements/index.blade.php` — `$filtered = $status !== '' || $building || $contract;` clear `route('owner-statements.index')`. Keep the `@forelse/@empty` row.
10. `deposit-settlements/index.blade.php` — `$filtered = $status !== 'open' || $this->building;` clear `route('deposit-settlements.index')`. Keep the `@forelse/@empty` row.
11. `approvals/index.blade.php` — page header `:title="__('Pending approvals')"`; under it:

```blade
    <x-ui.stats :cols="2">
        <x-ui.stat :label="__('Waiting')" :value="$rows->count()" :tone="$rows->isNotEmpty() ? 'warning' : null" />
        <x-ui.stat :label="__('For you to decide')" :value="$rows->where('mine', false)->count()" />
    </x-ui.stats>
```

   Cards unchanged; the `@empty` branch becomes `<x-ui.empty :title="__('Nothing is waiting for approval.')" icon="check-badge" />` (same text).

- [ ] **Step 5: Update the invoices tabs test (markup moved, plan ruling 2).** In `tests/Feature/Invoices/InvoiceTabsTest.php`, replace

```php
    $page->assertSee('Owed: 160.000 BHD')
        ->assertSee($this->overdue->number)->assertSee($this->notYetDue->number)->assertDontSee($this->paid->number)
        ->set('tab', 'overdue')->assertSee('Overdue: 100.000 BHD')->assertSee('49 days overdue')->assertDontSee($this->notYetDue->number)
```

with

```php
    $page->assertSeeInOrder(['Owed', '160.000 BHD', 'Overdue', '100.000 BHD'])
        ->assertSee($this->overdue->number)->assertSee($this->notYetDue->number)->assertDontSee($this->paid->number)
        ->set('tab', 'overdue')->assertSeeInOrder(['Owed', '160.000 BHD', 'Overdue', '100.000 BHD'])->assertSee('49 days overdue')->assertDontSee($this->notYetDue->number)
```

- [ ] **Step 6: Arabic.** Insert in `lang/ar.json` after the anchor line (only absent keys):

```json
    "Owed": "المستحق",
    "Overdue invoices": "فواتير متأخرة",
    "Drafts": "المسودات",
    "Collected this month": "المحصَّل هذا الشهر",
    "Receipts this month": "إيصالات هذا الشهر",
    "To deposit this week": "للإيداع هذا الأسبوع",
    "Pending approval": "بانتظار الموافقة",
    "Expiring in 60 days": "تنتهي خلال 60 يوماً",
    "Waiting": "بالانتظار",
    "For you to decide": "بانتظار قرارك",
```

In `tests/Feature/Shell/DesignPassArabicTest.php`, add inside `$keys`:

```php
        // Task 3: lists A
        'Owed', 'Overdue invoices', 'Drafts', 'Collected this month', 'Receipts this month', 'To deposit this week', 'Pending approval',
        'Expiring in 60 days', 'Waiting', 'For you to decide',
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/Cheques tests/Feature/Agreements tests/Feature/Payments tests/Feature/Approvals tests/Feature/Invoices tests/Feature/Customers tests/Feature/Reminders tests/Feature/Disbursements tests/Feature/OwnerStatements tests/Feature/Deposits tests/Feature/CustomFields tests/Feature/Permissions tests/Feature/Shell`
Expected: PASS.

- [ ] **Step 8: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Livewire/Agreements/Index.php app/Livewire/Payments/Index.php app/Livewire/Cheques/Index.php resources/views/livewire lang/ar.json tests/Feature
git commit -m "$(cat <<'EOF'
Design pass lists A: tenants, agreements, reminders, invoices, payments, cheques, payments out, head-lease, owner statements, deposit settlements and approvals get page headers, filters, figures (one aggregate each, scoped like the list) and empty states

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Lists B — property and maintenance

**Files:**
- Modify views: `resources/views/livewire/{buildings/index,units/index,owners/index,owner-contracts/index,expenses/index}.blade.php`, `resources/views/livewire/maintenance/{requests,work-orders,vendors}.blade.php`
- Modify components: `app/Livewire/Units/Index.php`, `app/Livewire/Maintenance/Requests.php`, `app/Livewire/Maintenance/WorkOrders.php`
- Modify: `lang/ar.json`, `tests/Feature/Shell/DesignPassArabicTest.php`
- Test (new): `tests/Feature/Units/UnitStatsTest.php`, `tests/Feature/Maintenance/ListStatsTest.php`

**Interfaces:**
- Consumes (Task 1): `<x-ui.page-header>`, `<x-ui.stats>`, `<x-ui.stat>`, `<x-ui.filters :clear>`, `<x-ui.table>`, `<x-ui.empty>`. Existing `App\Reports\Queries::occupiedUnitIds(User $user, string $on): Query\Builder`.
- Produces: view data `stats` on `Units\Index` (`array{occupied: int, vacant: int, blocked: int}`) and `Maintenance\WorkOrders` (`array{open: int, overdue: int}`); view data `urgent` (int) on `Maintenance\Requests`.

**Rules for this task (from the style guide):**
- Keep every field name, `wire:model`/`wire:click`/`wire:key`/`wire:confirm`, `@can`/`@if` wrapper, route, button and heading text, column and filter. No new public properties or methods.
- Header → `<x-ui.page-header :title="<same text>">`; buttons unchanged in `<x-slot:actions>`; `icon="plus"` on the primary "New …"/"Record …" button.
- Filters → wrapped unchanged in `<x-ui.filters :clear="$filtered ? <clear url> : null">`, `$filtered` defined in an `@php … @endphp` block at the top (condition per file).
- Tabs → active `border-accent text-accent-content`, focus ring `focus-visible:ring-accent`.
- `<div class="overflow-x-auto">` → `<x-ui.table>`; table untouched; money columns `align="end"` + `text-end tabular-nums`; empty state after `</flux:table>` unless an `@forelse … @empty` row exists.
- New strings via `__()`; Arabic in `lang/ar.json` after `    "Credit refund reversal": "عكس استرداد رصيد",` (absent keys only); keys appended to `DesignPassArabicTest`.
- RTL-safe utilities, `dark:` for every colour, no hex, `@php … @endphp` blocks only.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Units/UnitStatsTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Units\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->leasing = matrixUser(RoleName::Leasing, $this->a); // assigned buildings only
});

test('unit figures: occupied today, vacant and blocked, in the viewer\'s buildings, under the list\'s filters', function () {
    $let = Unit::factory()->for($this->a)->create(['code' => 'A1']);
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$let]);
    Unit::factory()->for($this->a)->create(['code' => 'A2', 'blocked' => true, 'blocked_reason' => 'Repairs']);
    Unit::factory()->for($this->a)->create(['code' => 'A3']);
    Unit::factory()->for($this->b)->create(['code' => 'B1']); // building B: not visible

    Livewire::actingAs($this->leasing)->test(Index::class)
        ->assertViewHas('stats', ['occupied' => 1, 'vacant' => 1, 'blocked' => 1])
        ->assertSeeInOrder(['Occupied', '1', 'Vacant', '1', 'Blocked', '1'])
        ->set('search', 'A3')->assertViewHas('stats', ['occupied' => 0, 'vacant' => 1, 'blocked' => 0]);
});
```

`tests/Feature/Maintenance/ListStatsTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Maintenance\Requests;
use App\Livewire\Maintenance\WorkOrders;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['name' => 'Seef Tower']);
    $this->b = Building::factory()->create(['name' => 'Juffair Heights']);
    $this->unitA = Unit::factory()->for($this->a)->create(['code' => '101']);
    $this->unitB = Unit::factory()->for($this->b)->create(['code' => '201']);
    $this->pm = matrixUser(RoleName::PropertyManager, $this->a);   // logs requests and work orders (sees every building)
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);      // views building A only
    $this->vendor = maintenanceVendor(['trades' => ['plumbing']]);
});

test('request figures: open and in progress (the tab counts) and urgent, in the viewer\'s buildings, under the filters', function () {
    maintenanceRequest($this->pm, $this->unitA, ['priority' => 'urgent']);                    // urgent, open
    $busy = maintenanceRequest($this->pm, $this->unitA, ['priority' => 'urgent']);
    workOrderFor($this->pm, $busy, $this->vendor);                                            // urgent, in progress
    maintenanceRequest($this->pm, $this->unitA);                                              // normal, open
    maintenanceRequest($this->pm, $this->unitB, ['priority' => 'urgent']);                    // building B: not visible

    Livewire::actingAs($this->leasing)->test(Requests::class)
        ->assertViewHas('counts', fn (array $c) => $c['open'] === 2 && $c['in_progress'] === 1)
        ->assertViewHas('urgent', 2)
        ->assertSeeInOrder(['Open', '2', 'In progress', '1', 'Urgent', '2'])
        ->set('category', 'electrical')->assertViewHas('urgent', 0);
});

test('work order figures: open, and overdue (scheduled before today, still open), under every filter but the status', function () {
    workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitA), $this->vendor, ['scheduled_on' => '2026-10-10']); // overdue
    workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitA), $this->vendor, ['scheduled_on' => '2026-10-20']);
    workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitB), $this->vendor, ['scheduled_on' => '2026-10-01']); // building B

    Livewire::actingAs($this->leasing)->test(WorkOrders::class)
        ->assertViewHas('stats', ['open' => 2, 'overdue' => 1])
        ->assertSeeInOrder(['Open work orders', '2', 'Overdue work orders', '1'])
        ->set('status', 'completed')->assertViewHas('stats', ['open' => 2, 'overdue' => 1]);
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test tests/Feature/Units/UnitStatsTest.php tests/Feature/Maintenance/ListStatsTest.php`
Expected: FAIL — no `stats`/`urgent` view data.

- [ ] **Step 3: Component figures.**

`app/Livewire/Units/Index.php`: add `use App\Reports\Queries;` and `use Illuminate\Database\Eloquent\Builder;`. Replace `render()` with:

```php
    /** @return Builder<Unit> the list's filters: visibility, building, type, code search and custom fields */
    private function filtered(): Builder
    {
        return Unit::query()
            ->visibleTo($this->actor())
            ->when($this->buildingId, fn ($q) => $q->where('building_id', $this->buildingId))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->search !== '', fn ($q) => $q->where('code', 'like', '%'.$this->search.'%'))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Unit, $this->more));
    }

    /**
     * Design pass §5 (plan ruling 2): occupied today (Queries::occupiedUnitIds, as the dashboard's occupancy counts it),
     * blocked and not occupied, and vacant (neither; reserved units included). One aggregate query: filtered() left-joined
     * to today's occupied unit ids.
     *
     * @return array{occupied: int, vacant: int, blocked: int}
     */
    private function stats(): array
    {
        $row = (array) ($this->filtered()->toBase()
            ->leftJoinSub(Queries::occupiedUnitIds($this->actor(), now('Asia/Bahrain')->toDateString()), 'occ', 'occ.unit_id', '=', 'units.id')
            ->selectRaw('COUNT(*) AS unit_count, COUNT(occ.unit_id) AS occupied_count, SUM(CASE WHEN occ.unit_id IS NULL AND units.blocked = 1 THEN 1 ELSE 0 END) AS blocked_count')
            ->first() ?? []);
        $occupied = (int) ($row['occupied_count'] ?? 0);
        $blocked = (int) ($row['blocked_count'] ?? 0);

        return ['occupied' => $occupied, 'vacant' => (int) ($row['unit_count'] ?? 0) - $occupied - $blocked, 'blocked' => $blocked];
    }

    public function render(): View
    {
        $units = $this->filtered()
            ->withOccupancy()
            ->with(['building:id,code,name', 'unitType:code,name'])
            ->orderBy('building_id')->orderBy('code')
            ->paginate(50);

        return view('livewire.units.index', [
            'units' => $units,
            'stats' => $this->stats(),
            'customFields' => CustomFields::active(CustomFieldEntity::Unit),
            'types' => UnitType::query()->orderBy('name')->get(['code', 'name']),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
```

`app/Livewire/Maintenance/Requests.php`: in `render()`, add one entry to the view data array, after `'counts' => …,`:

```php
            // Design pass §5: open or in-progress urgent requests under the filters (the dashboard card's definition). One count.
            'urgent' => $this->filtered()->where('priority', MaintenancePriority::Urgent)
                ->whereIn('status', [MaintenanceStatus::Open, MaintenanceStatus::InProgress])->count(),
```

(`MaintenancePriority` and `MaintenanceStatus` are already imported there; check and add the `use` lines if not.)

`app/Livewire/Maintenance/WorkOrders.php`: replace `render()` with:

```php
    /** @return Builder<WorkOrder> every filter but the status: visibility, vendor, the request's building/priority/category, search */
    private function filtered(): Builder
    {
        $priority = MaintenancePriority::tryFrom(is_string($this->priority) ? $this->priority : '');
        $category = Trade::tryFrom(is_string($this->category) ? $this->category : '');
        $search = is_string($this->search) ? trim($this->search) : '';

        return WorkOrder::query()->visibleTo($this->actor())
            ->when(self::idOf($this->vendor), fn (Builder $q, int $v) => $q->where('vendor_id', $v))
            ->whereHas('maintenanceRequest', fn (Builder $r) => $r
                ->when(self::idOf($this->building), fn (Builder $q, int $b) => $q->inBuilding($b))
                ->when($priority, fn (Builder $q, MaintenancePriority $p) => $q->where('priority', $p))
                ->when($category, fn (Builder $q, Trade $c) => $q->where('category', $c)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('maintenanceRequest', fn (Builder $r) => $r->where('number', 'like', '%'.addcslashes($search, '%_\\').'%'))));
    }

    /**
     * Design pass §5: open (assigned or in progress) and overdue (open and scheduled before today, as WorkOrder::isOverdue).
     * One aggregate query over filtered().
     *
     * @return array{open: int, overdue: int}
     */
    private function stats(): array
    {
        $open = [WorkOrderStatus::Assigned->value, WorkOrderStatus::InProgress->value];
        $row = (array) ($this->filtered()->toBase()->selectRaw(
            'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS open_count, SUM(CASE WHEN status IN (?, ?) AND scheduled_on < ? THEN 1 ELSE 0 END) AS overdue_count',
            [...$open, ...$open, now('Asia/Bahrain')->toDateString()],
        )->first() ?? []);

        return ['open' => (int) ($row['open_count'] ?? 0), 'overdue' => (int) ($row['overdue_count'] ?? 0)];
    }

    public function render(): View
    {
        $status = WorkOrderStatus::tryFrom(is_string($this->status) ? $this->status : '');

        $workOrders = $this->filtered()
            ->when($status, fn (Builder $q, WorkOrderStatus $s) => $q->where('status', $s))
            ->with(['vendor:id,name', 'maintenanceRequest:id,number,building_id,unit_id,priority', 'maintenanceRequest.building:id,code', 'maintenanceRequest.unit:id,code'])
            ->orderByRaw('scheduled_on IS NULL')->orderBy('scheduled_on')->orderBy('id')
            ->paginate(50);

        return view('livewire.maintenance.work-orders', [
            'workOrders' => $workOrders,
            'stats' => $this->stats(),
            'statuses' => WorkOrderStatus::cases(),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'priorities' => MaintenancePriority::cases(),
            'categories' => Trade::cases(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
```

- [ ] **Step 4: Convert the views.** Apply the rules, plus:

1. `buildings/index.blade.php` — `$filtered = $search !== '';` clear `route('buildings.index')`.
2. `units/index.blade.php` — `$filtered = $buildingId || $type !== '' || $search !== '' || ! empty($more);` clear `route('units.index')`. Under the header:

```blade
    <x-ui.stats :cols="3">
        <x-ui.stat :label="__('Occupied')" :value="$stats['occupied']" />
        <x-ui.stat :label="__('Vacant')" :value="$stats['vacant']" />
        <x-ui.stat :label="__('Blocked')" :value="$stats['blocked']" />
    </x-ui.stats>
```

3. `owners/index.blade.php` — `$filtered = $search !== '';` clear `route('owners.index')`.
4. `owner-contracts/index.blade.php` — `$filtered = $status !== '' || $search !== '';` clear `route('owner-contracts.index')`.
5. `expenses/index.blade.php` — `$filtered = $buildingId || $status !== '';` clear `route('expenses.index')`. Money column "Total (BHD)" already `align="end"`; check its cells carry `text-end tabular-nums`.
6. `maintenance/requests.blade.php` — restyle the tabs per the rules. `$filtered = filled($building) || filled($priority) || filled($category) || filled($vendor) || filled($search);` clear `route('maintenance.requests.index', ['tab' => $currentTab])`. Under the header:

```blade
    <x-ui.stats :cols="3">
        <x-ui.stat :label="__('Open')" :value="$counts['open']" />
        <x-ui.stat :label="__('In progress')" :value="$counts['in_progress']" />
        <x-ui.stat :label="__('Urgent')" :value="$urgent" :tone="$urgent > 0 ? 'danger' : null" :href="route('maintenance.requests.index', ['priority' => 'urgent', 'tab' => 'open'])" />
    </x-ui.stats>
```

7. `maintenance/work-orders.blade.php` — the bare `<flux:heading size="xl" level="1">` becomes the page header. `$filtered = filled($status) || filled($building) || filled($priority) || filled($category) || filled($vendor) || filled($search);` clear `route('maintenance.work-orders.index')`. Under the header:

```blade
    <x-ui.stats :cols="2">
        <x-ui.stat :label="__('Open work orders')" :value="$stats['open']" />
        <x-ui.stat :label="__('Overdue work orders')" :value="$stats['overdue']" :tone="$stats['overdue'] > 0 ? 'danger' : null" />
    </x-ui.stats>
```

8. `maintenance/vendors.blade.php` — `$filtered = filled($trade) || filled($type) || $active !== '1' || filled($search);` clear `route('maintenance.vendors.index')`.

- [ ] **Step 5: Arabic.** Insert after the anchor line (absent keys only):

```json
    "Occupied": "مشغولة",
    "Blocked": "محجوبة",
    "Urgent": "عاجلة",
    "Open work orders": "أوامر عمل مفتوحة",
    "Overdue work orders": "أوامر عمل متأخرة",
```

Add to `$keys` in `DesignPassArabicTest.php`:

```php
        // Task 4: lists B
        'Occupied', 'Blocked', 'Urgent', 'Open work orders', 'Overdue work orders',
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/Units tests/Feature/Buildings tests/Feature/Owners tests/Feature/OwnerContracts tests/Feature/Expenses tests/Feature/Maintenance tests/Feature/CustomFields tests/Feature/Permissions tests/Feature/Shell`
Expected: PASS.

- [ ] **Step 7: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Livewire/Units/Index.php app/Livewire/Maintenance resources/views/livewire lang/ar.json tests/Feature
git commit -m "$(cat <<'EOF'
Design pass lists B: buildings, units, owners, owner contracts, expenses, maintenance requests, work orders and vendors; unit and maintenance figures from one aggregate each, scoped like the list

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Forms A — property, leasing amendments and maintenance

**Files:**
- Modify: `resources/views/livewire/{units/form,buildings/form,owners/form,agreements/amendment-form,expenses/form}.blade.php`, `resources/views/livewire/maintenance/{request-form,vendor-form}.blade.php`
- Modify: `lang/ar.json`, `tests/Feature/Shell/DesignPassArabicTest.php`

**Interfaces:**
- Consumes (Task 1): `<x-ui.page-header :title :back>` slot `meta`; `<x-ui.section :title :hint>` (slot `actions`); `<x-ui.form-actions :cancel>`; the CSS asterisk for `required`/`aria-required="true"`.
- Produces: nothing other tasks use.

**Rules for this task (from the style guide):**
- Keep every field, label, `wire:model` (with its modifiers), `:disabled`, `required`, `wire:submit`, button text, `@can`/`@if`/`@unless`, error line, and the panels after the form. No PHP changes.
- Header → `<x-ui.page-header :title="<same text>" :back="route('<list>')">`; status badge / "next tenant from" line → `<x-slot:meta>`.
- Fields → `<x-ui.section :title>` cards in the order given; inside, `<div class="grid gap-4 sm:grid-cols-2">`; textareas, radio groups, checkbox groups, repeaters, pickers → `sm:col-span-2`. A `<flux:fieldset><flux:legend>X</flux:legend>` pair becomes `<x-ui.section :title="__('X')">`.
- Keep `<fieldset @disabled(! $canEdit)>`; it wraps the sections (give it `class="space-y-6"`).
- Required: add `aria-required="true"` to the fields named per file; never add `required`.
- Long forms (named per file): the submit button, unchanged, inside `<x-ui.form-actions :cancel="route('<list>')">` as the form's last child (inside any existing `@if ($canEdit)`). Short forms: `<div class="flex justify-end">…button…</div>`.
- Icon-only buttons get `:aria-label`.
- The `<section>` root keeps its `max-w-*` class; use `space-y-6`.
- New strings via `__()`; Arabic after `    "Credit refund reversal": "عكس استرداد رصيد",` (absent keys only); keys appended to `DesignPassArabicTest`. RTL-safe utilities, `dark:` for colours, no hex, `@php … @endphp` blocks only.

- [ ] **Step 1: Arabic keys first** (so the guard test fails until the views use them correctly is not needed; this task adds keys). Insert after the anchor line (absent keys only):

```json
    "Location": "الموقع",
    "Details": "التفاصيل",
    "Money": "المبالغ",
    "Availability": "الإتاحة",
    "Management and notes": "الإدارة والملاحظات",
```

Add to `$keys` in `DesignPassArabicTest.php`:

```php
        // Task 5: forms A
        'Location', 'Details', 'Money', 'Availability', 'Management and notes',
```

Run: `php artisan test tests/Feature/Shell/DesignPassArabicTest.php` — Expected: PASS.

- [ ] **Step 2: Run the existing screen tests first to know the baseline**

Run: `php artisan test tests/Feature/Units tests/Feature/Buildings tests/Feature/Owners tests/Feature/Expenses tests/Feature/Maintenance tests/Feature/Agreements/AmendmentScreensTest.php tests/Feature/CustomFields`
Expected: PASS (note any failure before you change anything; there should be none).

- [ ] **Step 3: `units/form.blade.php`** (long form) — replace the file with:

```blade
<section class="w-full max-w-3xl space-y-6">
    <x-ui.page-header :title="$unit ? __('Unit :code', ['code' => $unit->code]) : __('New unit')" :back="route('units.index')">
        @if ($unit)
            <x-slot:meta>
                <div class="flex flex-wrap items-center gap-2">
                    <x-status-badge :status="$unit->status()" />
                    @if ($next = $unit->nextTenantFrom())
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('next tenant from :date', ['date' => $next->format('d/m/Y')]) }}</span>
                    @endif
                </div>
            </x-slot:meta>
        @endif
    </x-ui.page-header>

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-6">
            <x-ui.section :title="__('Location')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="form.building_id" :label="__('Building')" :disabled="(bool) $unit" aria-required="true" class="sm:col-span-2">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($buildings as $building)
                            <option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="form.code" :label="__('Unit code')" :required="(bool) $unit"
                        :description="$unit ? null : __('Leave blank to number it from the floor: 101, 102, G01.')" />
                    <flux:input wire:model="form.floor" :label="__('Floor')" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Details')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="form.use" :label="__('Use')" aria-required="true">
                        @foreach ($uses as $use)<option value="{{ $use->value }}">{{ str($use->value)->headline() }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model.live="form.type" :label="__('Type')" aria-required="true">
                        @foreach ($types as $type)<option value="{{ $type->code }}">{{ $type->name }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.bedrooms" :label="__('Bedrooms')" type="number" min="0" />
                    <flux:input wire:model="form.bathrooms" :label="__('Bathrooms')" type="number" min="0" />
                    <flux:input wire:model="form.area_sqm" :label="__('Area (m²)')" inputmode="decimal" />
                    <flux:select wire:model="form.furnishing" :label="__('Furnishing')" aria-required="true">
                        @foreach ($furnishings as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.ewa_account_no" :label="__('EWA account no.')" />
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Money')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.list_rent" :label="__('List rent / month (BHD)')" inputmode="decimal" required />
                    <flux:input wire:model="form.list_deposit" :label="__('List deposit (BHD)')" inputmode="decimal" />
                    <flux:input wire:model="form.list_service_charge" :label="__('Service charge / month (BHD)')" inputmode="decimal" />
                    <flux:select wire:model="form.default_tax_category" :label="__('Tax category')">
                        <option value="">{{ __('Company default for this use') }}</option>
                        @foreach ($taxCategories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
                    </flux:select>
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Availability')">
                <flux:checkbox wire:model="form.blocked" :label="__('Blocked (not available to let)')" />
                <flux:input wire:model="form.blocked_reason" :label="__('Blocked reason')" />
                <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
            </x-ui.section>
        </fieldset>
        @if ($canEdit)
            <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
            <x-ui.form-actions :cancel="route('units.index')">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </x-ui.form-actions>
        @endif
    </form>
    @unless ($canEdit)
        <x-custom-fields.display :items="$customDetails" />
    @endunless

    @if ($unit)
        @if ($maintenance)
            <x-maintenance-requests :requests="$maintenance" />
        @endif
        <livewire:documents.panel :documentable="$unit" :key="'docs-unit-'.$unit->id" />
    @endif
</section>
```

(Required marks follow `App\Actions\Units\SaveUnit`: `building_id`, `use`, `type`, `furnishing` and `list_rent` are unconditionally required; `code` is required when editing, which its existing `:required="(bool) $unit"` already marks; `floor` and `blocked_reason` are conditional and stay unmarked.)

- [ ] **Step 4: The other forms.** Read each file, then group as listed. Required fields come from the Action named; check its `Validator::make` rules and mark only unconditional `required` rules.

1. `buildings/form.blade.php` (long; Action `app/Actions/Buildings/SaveBuilding.php`) — sections: **Building** (`__('Building')`: Name, Code, Type), **Location** (Location, Address, Floors, Parking), **Facilities** (`__('Facilities')`: the facilities checkbox group, `sm:col-span-2`), **Management and notes** (Property manager, Notes). Custom-fields section after the fieldset; Save in `<x-ui.form-actions :cancel="route('buildings.index')">`. Header title stays `$building?->name ?? __('New building')` (read the file for the exact expression), back `route('buildings.index')`.
2. `owners/form.blade.php` (long; Action `app/Actions/Owners/SaveOwner.php`) — sections: **Identity** (`__('Identity')`: Owner type radio `sm:col-span-2`, Name (English), Name (Arabic), ID type, ID number, nationality), **Contact** (`__('Contact')`: Phone, Email, Address `sm:col-span-2`, Notes `sm:col-span-2`), **Bank details** (the existing fieldset/legend → section with the same title). Owner-portal card after the form becomes `<x-ui.section :title="<its existing heading text>">` with the same content. Save in form actions, cancel `route('owners.index')`.
3. `agreements/amendment-form.blade.php` (short; Action: the one its `submit()` calls under `app/Actions/Agreements/`) — one untitled `<x-ui.section>` holding the fields in a two-column grid; the "Send for approval" button in `<div class="flex justify-end">`. Back link `route('agreements.show', <the agreement>)` (read the component for the agreement variable; if none is in the view data, omit `back`).
4. `expenses/form.blade.php` (long; Action `app/Actions/Expenses/RecordExpense.php`) — sections: **Details** (Building, Unit, Category, Date, Description `sm:col-span-2`), **Amount** (`__('Amount')`: Net, VAT), **Charge to** (`__('Charge to')`: the charge-to select and the Company/Owner/Tenant pickers that follow it, the owner approval note `sm:col-span-2`, the file button `sm:col-span-2`). "Record" in form actions, cancel `route('expenses.index')`.
5. `maintenance/request-form.blade.php` (long; Action `app/Actions/Maintenance/LogMaintenanceRequest.php`: `building_id`, `channel`, `category`, `priority`, `description` and `reported_by_name` are required — confirm in the file) — sections: **Location** (Building, Unit), **Reported by** (`__('Reported by')`: the tenant picker `sm:col-span-2`, Reported by, How it was reported), **Problem** (`__('Problem')`: Category, Priority, Problem textarea `sm:col-span-2`, the entry-permitted checkbox `sm:col-span-2`). "Log request" in form actions, cancel `route('maintenance.requests.index')`.
6. `maintenance/vendor-form.blade.php` (short; Action `app/Actions/Maintenance/SaveVendor.php`) — sections: **Details** (Name, Type, Mobile, Email, CR number, VAT number), **Trades** (`__('Trades')`: the trades checkbox group `sm:col-span-2`, Notes `sm:col-span-2`, the Active switch). Save in `<div class="flex justify-end">`; header meta: the existing status badge if present.

If an Action path above does not exist, find the class the component's save method calls (`grep -n "function save" -A8 app/Livewire/<Component>.php`) and use that.

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Units tests/Feature/Buildings tests/Feature/Owners tests/Feature/Expenses tests/Feature/Maintenance tests/Feature/Agreements/AmendmentScreensTest.php tests/Feature/Agreements/AmendmentTest.php tests/Feature/CustomFields tests/Feature/Portal/OwnerInviteTest.php tests/Feature/Shell`
Expected: PASS.

- [ ] **Step 6: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add resources/views/livewire lang/ar.json tests/Feature/Shell/DesignPassArabicTest.php
git commit -m "$(cat <<'EOF'
Design pass forms A: unit, building, owner, amendment, expense, maintenance request and vendor forms grouped into titled sections, required fields marked, sticky Save on long forms

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Forms B — finance, admin and ledger

**Files:**
- Modify: `resources/views/livewire/invoices/{manual-form,credit-note-form}.blade.php`, `resources/views/livewire/cheques/entry.blade.php`, `resources/views/livewire/disbursements/create.blade.php`, `resources/views/livewire/admin/{users/form,roles/edit,contract-templates/edit,email-settings,tenant-reminders}.blade.php`, `resources/views/livewire/ledger/{new-journal,setup}.blade.php`

**Interfaces:**
- Consumes (Task 1): `<x-ui.page-header :title :back>`, `<x-ui.section :title>`, `<x-ui.form-actions :cancel>`, `<x-ui.table>`.
- Produces: nothing other tasks use.

**Rules for this task (from the style guide):**
- Keep every field, label, `wire:model` (with modifiers), `wire:submit`, `wire:loading`/`wire:target`, button text, `@can`/`@if`, error line. No PHP changes.
- Header → `<x-ui.page-header :title="<same text>" :back="route('<list>')">`.
- Fields → `<x-ui.section>` cards; inside, `<div class="grid gap-4 sm:grid-cols-2">`; wide controls `sm:col-span-2`. A `<flux:fieldset><flux:legend>X</flux:legend>` pair becomes `<x-ui.section :title="__('X')">`.
- Required: add `aria-required="true"` to fields whose rule in the named Action is unconditionally `required`; never add `required`.
- Long forms (named per file): submit button unchanged inside `<x-ui.form-actions :cancel="route('<list>')">`; short forms: `<div class="flex justify-end">`.
- Line-item tables inside forms (manual invoice lines, cheque rows, journal lines, opening balances) go in `<x-ui.table>` when they are a `flux:table`; a row-of-inputs repeater stays as it is inside the section. Icon-only remove buttons get `:aria-label="__('Remove')"` (key exists).
- Ledger views: every `__()` key in `resources/views/livewire/ledger/*.blade.php` must exist in `lang/ar.json` (`LedgerAccessTest`); add no new strings there.
- RTL-safe utilities, `dark:` for colours, no hex, `@php … @endphp` blocks only. No new strings are expected in this task; if you add one, add its Arabic after `    "Credit refund reversal": "عكس استرداد رصيد",` and its key to `DesignPassArabicTest`.

- [ ] **Step 1: Baseline**

Run: `php artisan test tests/Feature/Invoices tests/Feature/Cheques tests/Feature/Disbursements tests/Feature/Users tests/Feature/Roles tests/Feature/ContractTemplates tests/Feature/Settings tests/Feature/Reminders tests/Feature/Ledger/ManualJournalTest.php tests/Feature/Ledger/LedgerSetupTest.php tests/Feature/Ledger/LedgerAccessTest.php`
Expected: PASS before any change.

- [ ] **Step 2: Convert each file**

1. `invoices/manual-form.blade.php` (long) — header back `route('invoices.index')`. Section 1 untitled: the due date and the other header fields; section 2 untitled: the line repeater, its add-line button and the total line, as they are. "Save draft" in form actions, cancel `route('invoices.index')`.
2. `invoices/credit-note-form.blade.php` (short) — one untitled section; "Send for approval" in `flex justify-end`; back `route('invoices.index')`.
3. `cheques/entry.blade.php` (long) — section 1 untitled: bank select, account holder; section 2 untitled: the per-invoice rows (cheque no., date, amount). "Save cheques" in form actions, cancel `route('cheques.index')`.
4. `disbursements/create.blade.php` (long) — one untitled section for "Pay to" and its payee pickers; one untitled section for the unpaid-expense list (vendor bills) when shown; one untitled section for Method/Amount/Reason; the existing "Total: :t BHD" line stays where it is. "Send for approval" in form actions, cancel `route('disbursements.index')`.
5. `admin/users/form.blade.php` (short) — one untitled section: Name, Email (two columns), Roles checkbox group `sm:col-span-2`, Assigned buildings group `sm:col-span-2`. Save in `flex justify-end`; back `route('admin.users.index')`.
6. `admin/roles/edit.blade.php` (short) — one untitled section around the permission list; back `route('admin.roles.index')`.
7. `admin/contract-templates/edit.blade.php` (long) — one untitled section; Save in form actions, cancel `route('admin.templates.index')`.
8. `admin/email-settings.blade.php` (long) — each `flux:fieldset/legend` → section with the legend text (**Mail server**, **Sender**, **Send test email**); two-column grids inside; the Save button of the settings form in form actions (no cancel: there is no list page — pass no `cancel`); the test-email form's button stays in its section.
9. `admin/tenant-reminders.blade.php` (short) — one untitled section; Save in `flex justify-end`.
10. `ledger/new-journal.blade.php` (long) — header back `route('ledger.journals')`; lines table in `<x-ui.table>` inside an untitled section; Save button (same text) in form actions, cancel `route('ledger.journals')`.
11. `ledger/setup.blade.php` (long) — header (no back); the start-date form in one untitled section; the opening balances table in `<x-ui.table>` inside another untitled section; each form keeps its own submit button, the longer one in form actions without cancel.

- [ ] **Step 3: Run the tests**

Run the Step 1 command again, plus `tests/Feature/Shell`.
Expected: PASS.

- [ ] **Step 4: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add resources/views/livewire
git commit -m "$(cat <<'EOF'
Design pass forms B: manual invoice, credit note, cheque entry, payment out, users, roles, templates, email and reminder settings, new journal and ledger setup in section cards with sticky Save on long forms

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Key screens A — dashboard, tenant page, agreement page

**Files:**
- Modify: `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`, `resources/views/livewire/customers/form.blade.php`, `resources/views/livewire/agreements/show.blade.php`
- Modify: `lang/ar.json`, `tests/Feature/Shell/DesignPassArabicTest.php`
- Test (new): `tests/Feature/Reports/DashboardAttentionTest.php`

**Interfaces:**
- Consumes (Task 1): `<x-ui.page-header :title :hint>`, `<x-ui.stats>`, `<x-ui.stat :tone :href>`, `<x-ui.section :title id>`, `<x-ui.form-actions :cancel>`, `<x-ui.summary :title :status :back>` slots `actions`/`facts`/`meta`, `<x-ui.fact :label>`, `<x-ui.section-nav :items>`. Existing `App\Reports\Queries`.
- Produces: `Dashboard::attention(User $user): list<array{label: string, count: int, url: string}>`; tiles gain an optional `tone` key (`'danger'`).

**Rules for this task (from the style guide):**
- Keep every field, label, `wire:model`, `wire:click`, `wire:confirm`, `wire:submit`, button and heading text, `@can`/`@if`, error line, modal, child `<livewire:…>` component and its `:key`.
- Record page: `<x-ui.summary>` at the top (title = the old h1 text, `status`, the existing action buttons unchanged in `actions`, key facts in `facts` as `<x-ui.fact>` with the same labels and values), then the existing panels each as `<x-ui.section :title="<its existing heading text>" id="…">` (remove the panel's own `flux:heading`, the section shows it), with `<x-ui.section-nav>` listing them.
- Forms: fields grouped in `<x-ui.section>`, two-column grids, wide fields `sm:col-span-2`, `aria-required="true"` on unconditionally required fields, long forms in `<x-ui.form-actions>`.
- New strings via `__()`; Arabic after `    "Credit refund reversal": "عكس استرداد رصيد",` (absent keys only); keys appended to `DesignPassArabicTest`. RTL-safe utilities, `dark:` for colours, no hex, `@php … @endphp` blocks only.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Reports/DashboardAttentionTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Dashboard;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
});

test('needs attention: only items with something to do, each for a role that can open its list', function () {
    $customer = Customer::factory()->create();
    issuedInvoice($customer, [['net' => '100.000']], '2026-09-01'); // overdue
    activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-11-30'],
        [Unit::factory()->for($this->building)->create()]); // ends within 60 days

    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    expect(Dashboard::attention($finance))
        ->toContain(['label' => 'Overdue invoices', 'count' => 1, 'url' => route('invoices.index', ['tab' => 'overdue'])])
        ->and(collect(Dashboard::attention($finance))->pluck('label')->all())->not->toContain('Bounced cheques'); // none bounced

    $leasing = matrixUser(RoleName::Leasing, $this->building); // no finance.view: no invoice or cheque items
    expect(Dashboard::attention($leasing))
        ->toBe([['label' => 'Agreements expiring in 60 days', 'count' => 1, 'url' => route('agreements.index', ['expiring' => 60])]]);

    Livewire::actingAs($leasing)->test(Dashboard::class)
        ->assertSeeInOrder(['Needs attention', 'Agreements expiring in 60 days', '1']);
});

test('needs attention says so when nothing is waiting', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->building);

    expect(Dashboard::attention($leasing))->toBe([]);
    Livewire::actingAs($leasing)->test(Dashboard::class)->assertSee('Nothing needs attention.');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test tests/Feature/Reports/DashboardAttentionTest.php`
Expected: FAIL — `Call to undefined method App\Livewire\Dashboard::attention()`.

- [ ] **Step 3: `Dashboard.php`.** Add `use App\Enums\MaintenancePriority;` and `use App\Enums\MaintenanceStatus;` if absent (both are already imported today). Change the two tile lines to carry a tone:

```php
            $overdue = $money((string) (Queries::overdueInvoices($user)->sum('balance') ?: '0'));
            $tiles[] = ['label' => __('Overdue total'), 'url' => route('reports.ageing'), 'value' => $overdue, 'tone' => $overdue !== '0.000' ? 'danger' : null];
```

replacing the existing `$tiles[] = ['label' => __('Overdue total'), …];` line, and

```php
            $bounced = Queries::bouncedCheques($user)->count();
            $tiles[] = ['label' => __('Open bounced cheques'), 'url' => route('reports.cheques', ['kind' => 'bounced']), 'value' => (string) $bounced, 'tone' => $bounced > 0 ? 'danger' : null];
```

replacing the existing `$tiles[] = ['label' => __('Open bounced cheques'), …];` line. Update the `tiles()` docblock return type to `list<array{label: string, value: string, url: string, tone?: ?string}>`.

After `tiles()`, add:

```php
    /**
     * Design pass §7: what needs doing now, each linking to its list (filtered where the list can be), each only for a
     * role that can open that list. Items with nothing to do are left out (plan ruling 15).
     *
     * @return list<array{label: string, count: int, url: string}>
     */
    public static function attention(User $user): array
    {
        $items = [];
        if ($user->can('finance.view')) {
            $items[] = ['label' => __('Overdue invoices'), 'count' => Queries::overdueInvoices($user)->count(), 'url' => route('invoices.index', ['tab' => 'overdue'])];
            $items[] = ['label' => __('Cheques to deposit this week'), 'count' => Queries::chequesToDeposit($user, Queries::weekEnd(), Queries::weekStart())->count(),
                'url' => route('cheques.index', ['status' => 'held'])];
            $items[] = ['label' => __('Bounced cheques'), 'count' => Queries::bouncedCheques($user)->count(), 'url' => route('cheques.index', ['status' => 'bounced'])];
        }
        if ($user->can('approvals.decide')) {
            $items[] = ['label' => __('Approvals waiting'), 'count' => Queries::approvalsToDecide($user)->count(), 'url' => route('approvals.index')];
        }
        if ($user->can('agreements.view')) {
            $items[] = ['label' => __('Agreements expiring in 60 days'), 'count' => Queries::expiringAgreements($user, 60)->count(),
                'url' => route('agreements.index', ['expiring' => 60])];
        }
        if ($user->can('maintenance.view')) {
            $items[] = ['label' => __('Urgent maintenance requests'),
                'count' => MaintenanceRequest::query()->visibleTo($user)->where('priority', MaintenancePriority::Urgent)
                    ->whereIn('status', [MaintenanceStatus::Open, MaintenanceStatus::InProgress])->count(),
                'url' => route('maintenance.requests.index', ['priority' => 'urgent', 'tab' => 'open'])];
        }

        return array_values(array_filter($items, fn (array $item) => $item['count'] > 0));
    }
```

(Every item lists its keys in the order `label`, `count`, `url`: the test compares arrays with `toBe`.)

In `render()`, add `'attention' => self::attention($user),` to the view data.

- [ ] **Step 4: `dashboard.blade.php`** — replace the file with:

```blade
<div class="space-y-6">
    <x-ui.page-header :title="__('Dashboard')"
        :hint="__('Hello, :name', ['name' => auth()->user()->name]).' · '.now('Asia/Bahrain')->format('l d/m/Y')" />

    @if ($tiles === [])
        <flux:text>{{ __('Welcome. Use the menu to get started.') }}</flux:text>
    @else
        <x-ui.stats>
            @foreach ($tiles as $tile)
                <x-ui.stat :label="$tile['label']" :href="$tile['url']" :tone="$tile['tone'] ?? null"
                    :value="preg_match('/^-?\d+\.\d{3}$/', $tile['value']) ? number_format((float) $tile['value'], 3) : $tile['value']" />
            @endforeach
        </x-ui.stats>
    @endif

    @if ($tiles !== [] || $attention !== [])
        <x-ui.section :title="__('Needs attention')">
            @if ($attention === [])
                <flux:text>{{ __('Nothing needs attention.') }}</flux:text>
            @else
                <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($attention as $item)
                        <li wire:key="attention-{{ $loop->index }}">
                            <a href="{{ $item['url'] }}" wire:navigate class="flex items-center justify-between gap-3 py-2 text-sm hover:text-accent-content">
                                <span>{{ $item['label'] }}</span>
                                <span class="flex items-center gap-1 font-semibold tabular-nums">
                                    {{ $item['count'] }}
                                    <flux:icon.chevron-right variant="micro" class="text-zinc-400 rtl:rotate-180" />
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.section>
    @endif

    @if ($collections || $occupancy)
        <div @class(['grid gap-4', 'lg:grid-cols-3' => $collections && $occupancy])>
            @if ($collections)
                <div class="lg:col-span-2"><x-report-chart :chart="$collections" /></div>
            @endif
            @if ($occupancy)
                <figure class="viz flex flex-col rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <figcaption class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Occupancy today') }}</span>
                        <flux:link :href="route('reports.occupancy')" wire:navigate class="text-xs">{{ __('By building') }}</flux:link>
                    </figcaption>
                    <div class="flex flex-1 items-center justify-center gap-6 py-4">
                        <div class="relative grid place-items-center">
                            <progress class="viz-ring" max="100" value="{{ $occupancy['percent'] }}" style="--value: {{ $occupancy['percent'] }}" aria-label="{{ __('Occupancy') }}"></progress>
                            <span class="absolute text-xl font-semibold text-zinc-900 dark:text-white">{{ $occupancy['units'] > 0 ? number_format($occupancy['percent'], 1).'%' : '—' }}</span>
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex items-center gap-2"><span class="size-2.5 rounded-sm" style="background: var(--viz-1)"></span><dt class="text-zinc-500">{{ __('Occupied') }}</dt><dd class="ms-auto ps-3 font-medium text-zinc-900 dark:text-white">{{ $occupancy['occupied'] }}</dd></div>
                            <div class="flex items-center gap-2"><span class="size-2.5 rounded-sm" style="background: var(--viz-track)"></span><dt class="text-zinc-500">{{ __('Vacant') }}</dt><dd class="ms-auto ps-3 font-medium text-zinc-900 dark:text-white">{{ $occupancy['units'] - $occupancy['occupied'] }}</dd></div>
                        </dl>
                    </div>
                    <p class="text-xs text-zinc-500">{{ __('Of :n units that can be let (blocked units left out).', ['n' => $occupancy['units']]) }}</p>
                </figure>
            @endif
        </div>
    @endif

    @if ($urgent)
        <x-ui.section :title="__('Urgent maintenance')">
            <x-slot:actions>
                <flux:link :href="route('maintenance.requests.index', ['priority' => 'urgent', 'tab' => 'open'])" wire:navigate class="text-xs">{{ __('Open: :n', ['n' => $urgent['count']]) }}</flux:link>
            </x-slot:actions>
            @if ($urgent['requests']->isEmpty())
                <flux:text class="text-sm">{{ __('No urgent requests.') }}</flux:text>
            @else
                <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($urgent['requests'] as $r)
                        <li wire:key="urgent-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <span><flux:link :href="route('maintenance.requests.show', $r)" wire:navigate>{{ $r->number }}</flux:link> · {{ $r->place() }} · {{ $r->summary() }}</span>
                            <span class="text-zinc-500">{{ $r->created_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.section>
    @endif
</div>
```

(The "Needs attention" card shows for anyone with tiles or items, so a user with no permissions still sees only the welcome line. `DashboardTest` keeps passing.)

- [ ] **Step 5: Tenant page (`customers/form.blade.php`).** Restructure, keeping every field, binding and button:

1. Top: for an existing tenant (`$customer`), `<x-ui.summary :title="$customer->name_en" :back="route('customers.index')">` with `actions` = the existing buttons from the account card (Record payment, Statement, Manual invoice; same `@if`/`@can`), and `facts` = `<x-ui.fact :label="__('Outstanding')">{{ $account['outstanding'] }}</x-ui.fact>`, `<x-ui.fact :label="__('Credit')">{{ $account['credit'] }}</x-ui.fact>` (both only inside `@if ($account)`), `<x-ui.fact :label="__('Mobile')">{{ $customer->mobile }}</x-ui.fact>`, `<x-ui.fact :label="__('ID')">{{ $customer->id_type->label() }} {{ $customer->maskedId() }}</x-ui.fact>`. For a new tenant: `<x-ui.page-header :title="__('New tenant')" :back="route('customers.index')" />`. Remove the old `flux:heading` and the old account `flux:card`.
2. For an existing tenant, under the summary: `<x-ui.section-nav :items="…">` with, in order and only when the section is shown: `['details', __('Details')]`, `['portal', __('Tenant portal')]` (when `$portal`), `['maintenance', __('Maintenance')]` (when `$maintenance`), `['reminders', __('Reminders')]`, `['documents', __('Documents')]`. Build the array in an `@php … @endphp` block at the top of the file.
3. The form gets `id="details"` on its wrapping element (`<form wire:submit="save" id="details" class="scroll-mt-20 space-y-6">`). Inside `<fieldset @disabled(! $canEdit) class="space-y-6">`: sections **Identity** (Tenant type radio `sm:col-span-2`, Name (English) `aria` already `required`, Name (Arabic), ID type, ID number, nationality), **Contact** (Mobile, Email, Contact person when company, Address `sm:col-span-2`), **Emergency contact** (Name, Phone), **Reminders and notes** (the "Don't send reminders" switch `sm:col-span-2`, Notes `sm:col-span-2`). Required (from `SaveCustomer`): `type` and `id_type` get `aria-required="true"`; `name_en`, `id_number`, `mobile` already carry `required`.
4. After the fieldset (inside `@if ($canEdit)`): the custom-fields section, then `<x-ui.form-actions :cancel="route('customers.index')">` with the Save button.
5. The portal card becomes `<x-ui.section :title="__('Tenant portal')" id="portal">` with the same content (drop its own `flux:heading`).
6. Wrap `<x-maintenance-requests>` in `<div id="maintenance" class="scroll-mt-20">`, the reminders panel in `<div id="reminders" class="scroll-mt-20">`, the documents panel in `<div id="documents" class="scroll-mt-20">`. The root `<section>` becomes `class="w-full max-w-4xl space-y-6"`.

`PaymentScreensTest` asserts "Outstanding", "0.000", "Credit", "100.000" on this page: the facts keep those labels and values.

- [ ] **Step 6: Agreement page (`agreements/show.blade.php`).** Keep the two `@use` lines and the `@php` block at the top (it uses block `@php`; the inline `@php($lastRejection = …)` later in the file must become a block too, so the file has no inline `@php(...)`: rewrite it as `@php $lastRejection = …; @endphp`).

1. Replace the header `div` (h1 + badge + action buttons) and the `<dl>` of facts with:

```blade
    <x-ui.summary :title="$agreement->label()" :status="$agreement->status" :back="route('agreements.index')">
        <x-slot:actions>
            …the existing buttons, dropdown, modal trigger and PDF button/imported text, exactly as they are today…
        </x-slot:actions>
        <x-slot:facts>
            …one <x-ui.fact :label="<the dt text>"> per existing <dt>/<dd> pair, the dd content unchanged (links, discount line, notice line under its @if)…
        </x-slot:facts>
    </x-ui.summary>
```

2. The error lines, the rejection callout and the "Renewed by" line stay right after the summary.
3. `<x-ui.section-nav>` (array built in the top `@php` block) with, when shown: `['units', __('Units')]`; `['details', __('More details')]` when custom fields show; `['amendments', __('Amendments')]` when `$amendments->isNotEmpty()`; `['notice', __('Notice and move-out')]` when the notice/move-out block shows (same condition as that block: `auth()->user()->can('agreements.manage') && in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true)`); `['invoices', __('Invoices')]` when `$invoices->isNotEmpty()`; `['cheques', __('Cheques to return')]` when `$chequesToReturn->isNotEmpty()`; `['settlements', __('Deposit settlements')]` when `$settlements->isNotEmpty()`; `['approvals', __('Approval history')]` when `$approvals->isNotEmpty()`; `['reminders', __('Reminders')]`; `['documents', __('Documents')]`.
4. Panels → sections, contents unchanged: units table + move-out photos → `<x-ui.section :title="__('Units')" id="units">` (units table in `<x-ui.table>`); the custom-fields form/display → wrap in `<div id="details" class="scroll-mt-20">`; each `<div class="space-y-…"><flux:heading size="lg">X</flux:heading>…</div>` panel (Amendments, Invoices, Cheques to return, Deposit settlements, Approval history) → `<x-ui.section :title="__('X')" id="…">` without its own heading (the Invoices table goes in `<x-ui.table>`); the two `<details>` blocks (notice, move-out) → inside one `<x-ui.section :title="__('Notice and move-out')" id="notice">`, the `<details>` elements unchanged; reminders and documents panels wrapped in `<div id="reminders" class="scroll-mt-20">` / `<div id="documents" class="scroll-mt-20">`.
5. The renew modal is unchanged. Root `<section class="w-full max-w-5xl space-y-6">`.

`AmendmentTest` asserts `assertSeeInOrder([__('Cheques to return'), '777123'])`: the section title still comes before the rows.

- [ ] **Step 7: Arabic.** Insert after the anchor line (absent keys only; "Overdue invoices" may already be there from Task 3):

```json
    "Hello, :name": "مرحباً، :name",
    "Needs attention": "يحتاج إلى متابعة",
    "Nothing needs attention.": "لا يوجد ما يحتاج إلى متابعة.",
    "Overdue invoices": "فواتير متأخرة",
    "Cheques to deposit this week": "شيكات للإيداع هذا الأسبوع",
    "Bounced cheques": "شيكات مرتجعة",
    "Approvals waiting": "موافقات بالانتظار",
    "Agreements expiring in 60 days": "عقود تنتهي خلال 60 يوماً",
    "Urgent maintenance requests": "طلبات صيانة عاجلة",
    "Details": "التفاصيل",
    "Reminders": "التذكيرات",
    "Reminders and notes": "التذكيرات والملاحظات",
    "Amendments": "التعديلات",
    "Notice and move-out": "الإشعار والإخلاء",
    "Approval history": "سجل الموافقات",
```

Add to `$keys` in `DesignPassArabicTest.php`:

```php
        // Task 7: key screens A
        'Hello, :name', 'Needs attention', 'Nothing needs attention.', 'Cheques to deposit this week', 'Bounced cheques', 'Approvals waiting',
        'Agreements expiring in 60 days', 'Urgent maintenance requests', 'Reminders', 'Reminders and notes', 'Amendments', 'Notice and move-out',
        'Approval history',
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/Reports tests/Feature/DashboardTest.php tests/Feature/Maintenance/SectionsTest.php tests/Feature/Customers tests/Feature/Agreements tests/Feature/Payments tests/Feature/Portal/InviteTest.php tests/Feature/CustomFields tests/Feature/Reminders tests/Feature/Documents tests/Feature/Shell`
Expected: PASS.

- [ ] **Step 9: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Livewire/Dashboard.php resources/views/livewire lang/ar.json tests/Feature
git commit -m "$(cat <<'EOF'
Design pass key screens A: dashboard greeting, toned tiles and a Needs attention list by permission; tenant and agreement pages with a summary card, titled sections and in-page navigation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Key screens B — new agreement, record payment, owner contract, company settings, bank reconciliation

**Files:**
- Modify: `resources/views/livewire/agreements/form.blade.php`, `resources/views/livewire/payments/create.blade.php`, `app/Livewire/Payments/Create.php`, `resources/views/livewire/owner-contracts/form.blade.php`, `resources/views/livewire/admin/company-settings.blade.php`, `resources/views/livewire/ledger/bank-reconciliation-show.blade.php`
- Modify: `lang/ar.json`, `tests/Feature/Shell/DesignPassArabicTest.php`, `tests/Feature/Payments/PaymentScreensTest.php` (one line added)

**Interfaces:**
- Consumes (Task 1): `<x-ui.page-header :title :back>`, `<x-ui.section :title>` (slot `actions`), `<x-ui.form-actions :cancel>`, `<x-ui.stats>`, `<x-ui.stat :tone>`, `<x-ui.table>`.
- Produces: view data `owed` (string, BHD 3 dp) on `Payments\Create`.

**Rules for this task (from the style guide):**
- Keep every field, label, `wire:model` (and its modifiers), `wire:click`, `wire:submit`, `wire:loading`, button text, error line, `@if`. No new Livewire properties or public methods.
- Fields grouped in `<x-ui.section>`; two-column grids; wide fields `sm:col-span-2`; `aria-required="true"` on unconditionally required fields (never `required`); long forms end in `<x-ui.form-actions>`.
- Running totals are display-only Alpine reading `$wire` state, in integer fils (plan ruling 9).
- Icon-only buttons get `:aria-label`.
- Ledger view (`bank-reconciliation-show`): every `__()` key must exist in `lang/ar.json` (`LedgerAccessTest`); this task adds no ledger strings.
- New strings via `__()`; Arabic after `    "Credit refund reversal": "عكس استرداد رصيد",` (absent keys only); keys appended to `DesignPassArabicTest`. RTL-safe utilities, `dark:` for colours, no hex, `@php … @endphp` blocks only.

- [ ] **Step 1: The failing assertion for the payment summary.** In `tests/Feature/Payments/PaymentScreensTest.php`, test "Finance records a payment that pays oldest first by default" (its `beforeEach` issues invoice `a` for 400.000 and `b` for 300.000 to the tenant), change

```php
        ->assertSee($this->a->number)
        ->set('form.amount', '500')
```

to

```php
        ->assertSee($this->a->number)
        ->assertViewHas('owed', '700.000')->assertSeeInOrder(['Owed now', '700.000 BHD'])
        ->set('form.amount', '500')
```

Run: `php artisan test tests/Feature/Payments/PaymentScreensTest.php`
Expected: FAIL — `owed` is not in the view data.

- [ ] **Step 2: `Payments\Create` view data.** In `app/Livewire/Payments/Create.php`, add `use App\Support\Fils;` if absent, and change `render()` to compute the open invoices once and pass their total:

```php
    public function render(): View
    {
        $customer = Customer::findOrFail($this->customerId);
        $open = Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Issued)
            ->where('type', '!=', InvoiceType::CreditNote)->where('balance', '>', 0)->orderBy('due_date')->orderBy('id')->get();

        return view('livewire.payments.create', [
            'customer' => $customer,
            'methods' => PaymentMethod::manual(),
            'isSplit' => $this->isSplit(),
            'tendersTotal' => $this->isSplit() ? $this->tendersTotal() : null,
            'open' => $open,
            // Design pass §7: what the tenant owes now, beside the form (the loaded rows, no extra query).
            'owed' => Fils::toDecimal($open->sum(fn (Invoice $i) => Fils::fromDecimal((string) $i->balance))),
        ]);
    }
```

- [ ] **Step 3: `payments/create.blade.php`** — two columns on desktop: the form, and a sticky summary beside it.

1. Header: `<x-ui.page-header :title="__('Record payment — :name', ['name' => $customer->name_en])" :back="route('customers.edit', $customer)" />`.
2. Wrap form + aside in `<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">`. Root `<section class="w-full max-w-5xl space-y-6">`.
3. Inside the form: section 1 untitled — Method, Received on, Amount and Reference (when not split) in the existing two-column grid; the split "Paid by" fieldset → `<x-ui.section :title="__('Paid by')">` (same content, including the "Total: :t BHD" line); Notes and the two error lines in section 1 after the grid; "Allocate to" fieldset → `<x-ui.section :title="__('Allocate to')">` (same content). Required (from `RecordPayment`): Method, Received on, Amount (non-split) get `aria-required="true"`. Submit button (same attributes) in `<x-ui.form-actions :cancel="route('customers.edit', $customer)">`.
4. The aside (after the form, inside the grid):

```blade
        <aside class="lg:sticky lg:top-6" aria-label="{{ __('Running total') }}"
            x-data="{
                fils(v) { const m = /^\s*(\d{1,9})(?:\.(\d{1,3}))?\s*$/.exec(String(v ?? '')); return m ? Number(m[1]) * 1000 + Number((m[2] ?? '').padEnd(3, '0')) : 0 },
                show(f) { return Math.floor(f / 1000) + '.' + String(f % 1000).padStart(3, '0') },
                received() { return @js($isSplit) ? Object.values($wire.tenders ?? {}).reduce((t, r) => t + this.fils(r?.amount), 0) : this.fils($wire.form?.amount) },
                allocated() { return Object.values($wire.split ?? {}).reduce((t, v) => t + this.fils(v), 0) },
            }">
            <x-ui.section :title="__('Running total')">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Owed now') }}</dt><dd class="font-semibold tabular-nums">{{ __(':amount BHD', ['amount' => $owed]) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Open invoices') }}</dt><dd class="font-semibold tabular-nums">{{ $open->count() }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('This payment') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(received())"></span> {{ __('BHD') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Allocated by hand') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(allocated())"></span> {{ __('BHD') }}</dd></div>
                </dl>
            </x-ui.section>
        </aside>
```

(`BHD` gets its Arabic entry in Step 8. The aside reads the same `$wire.form.amount`, `$wire.tenders`, `$wire.split` the inputs bind; it changes no binding.)

- [ ] **Step 4: `agreements/form.blade.php`** — same two-column layout with a running total.

1. Header: `<x-ui.page-header :title="$agreementId ? __('Edit draft agreement') : __('New agreement')" :back="$agreementId ? route('agreements.show', $agreementId) : route('agreements.index')" />`. Root `<section class="w-full max-w-6xl space-y-6">`; form + aside in `<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">`.
2. In the form: `<flux:error name="form.status" />` first; **Tenant** section (`__('Tenant')`: the customer label, search input, results list, error) — the old fieldset/legend; **Terms** section (`__('Terms')`: start/end dates, frequency, billing day, grace days, notice days, contract template `sm:col-span-2`); **Units** section (`__('Units')`: the building/unit pickers and Add unit button in the section's `actions` slot is **not** used — keep them in the body as today; each unit card unchanged). The trash and x-mark icon buttons get `:aria-label="__('Remove unit')"` and `:aria-label="__('Remove charge')"`. Custom-fields section; "Save draft" in `<x-ui.form-actions :cancel="…same URL as back…">`. Required (from the agreement save Action): start date, end date and frequency get `aria-required="true"` on the `x-date-input`/select (the date component passes attributes to its input).
3. The aside:

```blade
        <aside class="lg:sticky lg:top-6" aria-label="{{ __('Running total') }}"
            x-data="{
                fils(v) { const m = /^\s*(\d{1,9})(?:\.(\d{1,3}))?\s*$/.exec(String(v ?? '')); return m ? Number(m[1]) * 1000 + Number((m[2] ?? '').padEnd(3, '0')) : 0 },
                show(f) { return Math.floor(f / 1000) + '.' + String(f % 1000).padStart(3, '0') },
                lines() { return Object.values($wire.units ?? {}) },
                charges(type) { return this.lines().reduce((t, u) => t + Object.values(u?.charges ?? {}).filter(c => type === null || c?.type === type).reduce((s, c) => s + this.fils(c?.monthly_amount), 0), 0) },
                deposits() { return this.lines().reduce((t, u) => t + this.fils(u?.deposit_amount), 0) },
            }">
            <x-ui.section :title="__('Running total')">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Monthly rent') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(charges('rent'))"></span> {{ __('BHD') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('All monthly charges') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(charges(null))"></span> {{ __('BHD') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Total deposit') }}</dt><dd class="font-semibold tabular-nums"><span x-text="show(deposits())"></span> {{ __('BHD') }}</dd></div>
                </dl>
            </x-ui.section>
        </aside>
```

- [ ] **Step 5: `owner-contracts/form.blade.php`** (long) — header `<x-ui.page-header :title="$contractId ? __('Edit draft contract') : __('New owner contract')" :back="route('owner-contracts.index')" />`, root `max-w-3xl`. Sections: **Owner and building** (Owner, Building), **Terms** (Arrangement radio `sm:col-span-2`, start/end dates, then the leased or managed fields exactly under their `@if`), **Units** (`__('Units')`, with the "Whole building" button moved into the section's `<x-slot:actions>` under its existing `@if`; the checkbox grid and error unchanged), then an untitled section with "Replaces contract (successor)" and Notes `sm:col-span-2`. Required (from the owner-contract save Action): owner, building, type, start date get `aria-required="true"`. "Save draft" in form actions, cancel `route('owner-contracts.index')`.
- [ ] **Step 6: `admin/company-settings.blade.php`** (long) — header `<x-ui.page-header :title="__('Company settings')" />`, root `max-w-3xl space-y-6`. Each fieldset/legend → `<x-ui.section>` with the same title (**Profile**, **Contract layout**, **Tax**, **Locale and billing**) and a two-column grid: Profile — Name (English), Name (Arabic), CR number, Phone, Email, Website, Address (English) `sm:col-span-2`, Address (Arabic) `sm:col-span-2`, the logo field `sm:col-span-2`; Contract layout — all fields `sm:col-span-2` as today; Tax — VAT registered `sm:col-span-2`, TRN, VAT rate, the two default-tax selects; Locale and billing — Currency code, Date format, Default grace days, Invoice lead days, Proration basis. Save in `<x-ui.form-actions>` (no cancel).
- [ ] **Step 7: `ledger/bank-reconciliation-show.blade.php`** — replace the header `div` and the status `<p>` with:

```blade
    <x-ui.page-header :title="__('Bank reconciliation').' · '.$rec->account->label().' · '.$rec->end_date->format('d/m/Y')"
        :hint="$open ? __('Open') : __('Finished :date by :name', ['date' => $rec->finished_at?->timezone('Asia/Bahrain')->format('d/m/Y H:i'), 'name' => $rec->finisher?->name])"
        :back="route('ledger.bank-reconciliation', ['account' => (string) $rec->ledger_account_id])" />
```

(the old "Back" button becomes the back link: same text, same destination). Replace the figures `<dl>` with:

```blade
    @php
        [$opening, $closing, $inTransit, $outstanding, $adjusted, $ledger, $difference, $unmatchedLines, $openingPlusMatched] = $figures;
    @endphp
    <x-ui.stats>
        <x-ui.stat :label="$closing[0]" :value="$closing[1]" />
        <x-ui.stat :label="$ledger[0]" :value="$ledger[1]" />
        <x-ui.stat :label="$difference[0]" :value="$difference[1]" :tone="$difference[1] === '0.000' ? 'success' : 'danger'" />
        <x-ui.stat :label="$unmatchedLines[0]" :value="$unmatchedLines[1]" :tone="$unmatchedLines[1] === '0' ? null : 'warning'" />
    </x-ui.stats>
    <x-ui.section>
        <dl class="grid max-w-xl gap-1 tabular-nums">
            @foreach ($figures as [$label, $value])
                <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
    </x-ui.section>
```

(The full list of nine figures stays, so the page still shows every figure the report has; the four that matter most lead.) The action buttons row and the error lines stay. Each `<div class="space-y-2"><flux:heading size="lg">X</flux:heading><flux:table>…` block becomes `<x-ui.section :title="__('X')">` with the `flux:table` in `<x-ui.table>`; "Showing :shown of :total" lines unchanged. If the file has an inline `@php(...)`, convert it to a block.

- [ ] **Step 8: Arabic.** Insert after the anchor line (absent keys only):

```json
    "Running total": "المجموع الجاري",
    "Owed now": "المستحق الآن",
    "Open invoices": "الفواتير المفتوحة",
    "This payment": "هذه الدفعة",
    "Allocated by hand": "المخصَّص يدوياً",
    "All monthly charges": "كل الرسوم الشهرية",
    "Total deposit": "إجمالي التأمين",
    "Terms": "الشروط",
    "Owner and building": "المالك والعقار",
    "Remove unit": "إزالة الوحدة",
    "Remove charge": "إزالة الرسم",
    "BHD": "د.ب",
```

Add to `$keys`:

```php
        // Task 8: key screens B
        'Running total', 'Owed now', 'Open invoices', 'This payment', 'Allocated by hand', 'All monthly charges', 'Total deposit', 'Terms',
        'Owner and building', 'Remove unit', 'Remove charge', 'BHD',
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test tests/Feature/Payments tests/Feature/Agreements tests/Feature/OwnerContracts tests/Feature/Settings tests/Feature/Ledger tests/Feature/CustomFields tests/Feature/Shell`
Expected: PASS.

- [ ] **Step 10: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Livewire/Payments/Create.php resources/views/livewire lang/ar.json tests/Feature
git commit -m "$(cat <<'EOF'
Design pass key screens B: record payment and new agreement with a running total beside the form, owner contract and company settings regrouped, bank reconciliation figures as stats with the difference red until 0.000

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Tenant and owner portals

**Files:**
- Modify: `resources/views/layouts/portal.blade.php`, every view in `resources/views/livewire/portal/*.blade.php` except `login.blade.php`, every view in `resources/views/livewire/portal/owner/*.blade.php`

**Interfaces:**
- Consumes: `CompanySetting::brand()` (Task 2), `<x-ui.brand :name :logo :href>` (Task 2), `<x-ui.stats :cols>`, `<x-ui.stat :tone>` (Task 1).
- Produces: nothing other tasks use.

**Rules for this task:**
- Do not change the `<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">` line (`SignInTest` asserts it).
- Keep every string, the reading order of every page (portal tests use `assertSeeInOrder`), every link and route, every form, `wire:model`, `wire:submit`, tab `wire:click`, and `aria-current`/`aria-selected`.
- No new strings expected. Every `__()` in portal views and `layouts/portal.blade.php` must have Arabic (`tests/Feature/Portal/ArabicTest.php`); if you add one, add the Arabic after `    "Credit refund reversal": "عكس استرداد رصيد",` and the key to `DesignPassArabicTest`.
- RTL is first-class here: logical utilities only; check every page with `?lang`/locale Arabic in Task 13.
- Cards: `rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900`. Lists stay cards at every width (plan ruling 11): within a card, use `sm:flex-row`/`sm:grid-cols-*` so facts sit in one row on wider screens.
- Dark mode on everything; no hex; `@php … @endphp` blocks only.

- [ ] **Step 1: Baseline**

Run: `php artisan test tests/Feature/Portal tests/Feature/CustomFields/PortalTest.php`
Expected: PASS before changes.

- [ ] **Step 2: The portal layout.** In `resources/views/layouts/portal.blade.php`:

1. In the top `@php` block, replace the `$company`/`$companyName` lines with `$brand = \App\Models\CompanySetting::brand();` (it gives the same Arabic/English name and the logo).
2. `<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">`.
3. `<header class="dark border-b border-charcoal-line bg-charcoal text-white">` (the `dark` class gives the ghost buttons light text and the bright-gold accent).
4. Replace the company-name link `<a href="…" class="font-semibold">{{ $companyName }}</a>` with `<x-ui.brand :name="$brand['name']" :logo="$brand['logo']" :href="route($account ? ($ownerSide ? 'portal.owner.home' : 'portal.home') : 'portal.login')" />` (same href expression).
5. The Tenant/Owner switch and the nav pills: current = `bg-brand-400 text-charcoal`, others = `text-zinc-300 hover:bg-charcoal-raised hover:text-white` (replace the two class strings in both `@class` blocks).
6. `<main class="mx-auto max-w-3xl px-4 py-6">` unchanged.

- [ ] **Step 3: Portal pages.**

1. `portal/home.blade.php` — replace the two balance cards with:

```blade
    <x-ui.stats :cols="2">
        <x-ui.stat :label="__('Balance owed')" :value="__(':amount BHD', ['amount' => $owed])" :tone="in_array($owed, ['0.000', '0'], true) ? null : 'danger'" />
        <x-ui.stat :label="__('Credit')" :value="__(':amount BHD', ['amount' => $credit])" />
    </x-ui.stats>
```

   The "Next invoice due" card: `flux:card` → the card class above. Each list section ("Upcoming cheques", "Open maintenance requests", "Agreements ending soon"): wrap the rows in `<ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">` with each row an `<li class="… p-3">` keeping its `wire:key` and content (convert the row `div` to `li`); the `@empty` text stays outside the list (use `@if ($x->isEmpty()) … @else <ul>…</ul> @endif` with the same text).
2. `portal/owner/home.blade.php` — the "Last payout" and "Next lease payment" cards become `<x-ui.stats :cols="2">` with `<x-ui.stat :label="__('Last payout')" :value="$lastPayout ? __(':amount BHD', […same…]) : __('No payouts yet.')" :hint="$lastPayout?->paid_on?->format('d/m/Y')" />` and `<x-ui.stat :label="__('Next lease payment')" :value="$nextPayable ? __(':amount BHD', […same…]) : __('Nothing is due.')" :hint="$nextPayable ? __('Due :date', ['date' => $nextPayable->due_date->format('d/m/Y')]) : null" />`. Keep these after the "Balance owed to you" block, as today (`OwnerHomeAndMaintenanceTest` reads this order). Balance cards, occupancy rows and ending contracts become card lists as in item 1.
3. `portal/invoices.blade.php` and every other list page (`agreements`, `payments`, `cheques`, `statement`, `documents`, `maintenance`, `maintenance-show`, `new-maintenance-request`, and the owner pages `buildings`, `contracts`, `documents`, `expenses`, `maintenance`, `maintenance-show`, `payments`, `statements`): tab buttons' current class → `bg-accent text-accent-foreground` (was `bg-zinc-900 text-white dark:bg-white dark:text-zinc-900`); list containers → the card class with `dark:bg-zinc-900` (was `dark:bg-zinc-800`); `flux:card` stays (Flux's own dark style). Headings unchanged. The new-maintenance-request form: one untitled `<x-ui.section>` around the fields; submit button unchanged.

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/Portal tests/Feature/CustomFields/PortalTest.php tests/Feature/Shell`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add resources/views/layouts/portal.blade.php resources/views/livewire/portal
git commit -m "$(cat <<'EOF'
Design pass portals: charcoal header with the company logo, gold current tab, balances as figures, card lists in light and dark, Arabic order unchanged

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Sign-in pages

**Files:**
- Create: `resources/views/components/ui/auth-card.blade.php`
- Modify: `resources/views/layouts/auth/simple.blade.php`, `resources/views/components/auth-header.blade.php`, `resources/views/livewire/portal/login.blade.php`
- Delete: `resources/views/components/app-logo-icon.blade.php`
- Test: `tests/Feature/Shell/ShellDesignTest.php` (two tests added)

**Interfaces:**
- Consumes: `CompanySetting::brand()`, `<x-ui.brand :name :logo>` (Task 2).
- Produces: `<x-ui.auth-card :name="string" :logo="?string">` (slot = the form), renders `data-ui-auth-card`.

**Rules for this task:**
- Keep every form, field name, `wire:model`, `wire:submit`, `@csrf`, `data-test` attribute, button text, link and status/error line of the sign-in, two-factor, forgot/reset/confirm password and portal sign-in pages. Do not touch `livewire/auth/*.blade.php` beyond what is listed.
- No new strings. RTL-safe utilities (the portal sign-in is used in Arabic), `dark:` on every colour, no hex, `@php … @endphp` blocks only.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/Shell/ShellDesignTest.php`:

```php
test('the staff sign-in page is a card with the company brand panel', function () {
    CompanySetting::factory()->create(['name_en' => 'Gulf Homes W.L.L.']);

    $this->get(route('login'))->assertOk()
        ->assertSee('data-ui-auth-card', false)
        ->assertSeeInOrder(['Gulf Homes W.L.L.', 'Log in to your account']);
});

test('the portal sign-in page is the same card, in Arabic too', function () {
    CompanySetting::factory()->create(['name_en' => 'Gulf Homes', 'name_ar' => 'بيوت الخليج']);

    $this->get(route('portal.login'))->assertOk()->assertSee('data-ui-auth-card', false)->assertSee('Sign in to the portal');
});
```

Run: `php artisan test tests/Feature/Shell/ShellDesignTest.php` — Expected: FAIL (no `data-ui-auth-card`).

- [ ] **Step 2: The card component** — `resources/views/components/ui/auth-card.blade.php`:

```blade
@props(['name', 'logo' => null])
{{-- Design pass §8: a centred card; on desktop a charcoal brand panel beside the form, on phones a brand strip above it. --}}
<div {{ $attributes->class('grid w-full max-w-3xl overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm md:grid-cols-[2fr_3fr] dark:border-zinc-800 dark:bg-zinc-900') }} data-ui-auth-card>
    <div class="dark flex flex-col justify-between gap-6 bg-charcoal p-6 text-white md:p-8">
        <x-ui.brand :name="$name" :logo="$logo" />
        <div aria-hidden="true" class="hidden h-1 w-16 rounded-full bg-brand-400 md:block"></div>
    </div>
    <div class="p-6 sm:p-8">{{ $slot }}</div>
</div>
```

- [ ] **Step 3: The staff auth layout** — replace `resources/views/layouts/auth/simple.blade.php`:

```blade
@php
    $brand = \App\Models\CompanySetting::brand();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-100 antialiased dark:bg-zinc-950">
        <div class="flex min-h-svh items-center justify-center p-4 sm:p-6 md:p-10">
            <x-ui.auth-card :name="$brand['name']" :logo="$brand['logo']">
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </x-ui.auth-card>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
```

`resources/views/components/auth-header.blade.php`: change `class="flex w-full flex-col text-center"` to `class="flex w-full flex-col text-start"` (the title sits beside the brand panel now).

- [ ] **Step 4: The portal sign-in** — in `resources/views/livewire/portal/login.blade.php`, wrap the existing content:

```blade
@php
    $brand = \App\Models\CompanySetting::brand();
@endphp
<section class="flex justify-center">
    <x-ui.auth-card :name="$brand['name']" :logo="$brand['logo']">
        <div class="space-y-6">
            …the existing heading, status line, @if/@elseif/@else forms and buttons, unchanged…
        </div>
    </x-ui.auth-card>
</section>
```

(The portal header above the card keeps the language switch, so the brand shows twice on this one page; that is accepted.)

- [ ] **Step 5: Delete the starter logo icon**

```bash
git rm resources/views/components/app-logo-icon.blade.php
```

`grep -rn "app-logo-icon" resources app` must print nothing.

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/Auth tests/Feature/Portal tests/Feature/Settings tests/Feature/Shell`
Expected: PASS.

- [ ] **Step 7: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add -A resources/views/components resources/views/layouts/auth resources/views/livewire/portal/login.blade.php tests/Feature/Shell/ShellDesignTest.php
git commit -m "$(cat <<'EOF'
Design pass sign-in: staff and portal sign-in, two-factor and password pages as one card with a charcoal company brand panel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Sweep A — record pages and shared panels

**Files:**
- Modify: `resources/views/livewire/{invoices/show,payments/show,cheques/show,disbursements/show,deposit-settlements/show,owner-contracts/show,owner-statements/show,expenses/show,customers/statement}.blade.php`, `resources/views/livewire/maintenance/request-show.blade.php`, `resources/views/livewire/documents/panel.blade.php`, `resources/views/livewire/reminders/record-panel.blade.php`, `resources/views/components/maintenance-requests.blade.php`, `resources/views/components/customer-picker.blade.php`

**Interfaces:**
- Consumes (Task 1): `<x-ui.summary :title :status :subtitle :back>` slots `actions`/`facts`/`meta`; `<x-ui.fact :label>`; `<x-ui.section :title>` (slot `actions`); `<x-ui.table>`; `<x-ui.page-header>`.
- Produces: nothing other tasks use.

**Rules for this task (the record-page recipe):**
- Top of each show page: replace the heading row (h1, status badge, action buttons) and any key-facts `<dl>` with `<x-ui.summary :title="<same title expression>" :status="<same status the badge showed>" :back="route('<its list>')">`; the action buttons, dropdowns and modal triggers move unchanged into `<x-slot:actions>`; each `<dt>`/`<dd>` pair becomes `<x-ui.fact :label="<dt text>">…<dd content unchanged>…</x-ui.fact>` in `<x-slot:facts>`. A page whose header has no status badge passes no `status`.
- Each panel `<div class="space-y-…"><flux:heading size="lg">X</flux:heading>…</div>` (or a `flux:card` with a heading) becomes `<x-ui.section :title="__('X')">` with the same contents and no inner heading; a `flux:table` inside goes in `<x-ui.table>`.
- Forms on show pages (clear a cheque, bounce, reverse, approve, complete a work order…): fields in a two-column grid inside their section; buttons, `wire:submit`, `wire:confirm`, errors unchanged.
- Keep every string, binding, `wire:key`, child Livewire component and its `:key`, `@can`/`@if`, and the reading order of headings and figures (tests use `assertSeeInOrder`).
- Shared panels (`documents/panel`, `reminders/record-panel`, `components/maintenance-requests`): the outer card/heading becomes `<x-ui.section :title="<same heading text>">`; inner lists unchanged. `customer-picker`: results list container gets `rounded-lg border border-zinc-200 dark:border-zinc-700` and rows `hover:bg-zinc-50 dark:hover:bg-zinc-800` (already close; align only).
- No new strings expected (if you add one: Arabic after `    "Credit refund reversal": "عكس استرداد رصيد",`, key to `DesignPassArabicTest`). RTL-safe utilities, `dark:` for colours, no hex, `@php … @endphp` blocks only (convert any inline `@php(...)` in a file you touch if the file also has a block).

- [ ] **Step 1: Baseline**

Run: `php artisan test tests/Feature/Invoices tests/Feature/Payments tests/Feature/Cheques tests/Feature/Disbursements tests/Feature/Deposits tests/Feature/OwnerContracts tests/Feature/OwnerStatements tests/Feature/Expenses tests/Feature/Customers tests/Feature/Maintenance tests/Feature/Documents tests/Feature/Reminders`
Expected: PASS before changes.

- [ ] **Step 2: Convert, file by file** — back routes: invoices/show → `route('invoices.index')`; payments/show → `route('payments.index')`; cheques/show → `route('cheques.index')`; disbursements/show → `route('disbursements.index')`; deposit-settlements/show → `route('deposit-settlements.index')`; owner-contracts/show → `route('owner-contracts.index')`; owner-statements/show → `route('owner-statements.index')`; expenses/show → `route('expenses.index')`; maintenance/request-show → `route('maintenance.requests.index')`; customers/statement → `route('customers.edit', $customer)` (use the page's customer variable) with `<x-ui.page-header>` instead of a summary (a statement has no status), its from/to inputs in `<x-ui.filters>` with no clear link, its tables in `<x-ui.table>`.

- [ ] **Step 3: Run the Step 1 tests again, plus `tests/Feature/Shell`**
Expected: PASS.

- [ ] **Step 4: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add resources/views
git commit -m "$(cat <<'EOF'
Design pass sweep A: invoice, payment, cheque, payment-out, deposit settlement, owner contract, owner statement, expense and maintenance request pages with a summary card and titled sections; tenant statement, documents, reminders and maintenance panels aligned

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Sweep B — ledger, reports, admin lists, settings, import, public pages

**Files:**
- Modify: `resources/views/livewire/ledger/{home,accounts,journal-show,bank-reconciliations,cash-movements,vat-returns}.blade.php`; `resources/views/livewire/reports/{index,builder,package,table,building-profitability,income-statement,owner-income-statement}.blade.php` and `resources/views/livewire/reports/partials/*.blade.php`; `resources/views/livewire/admin/{users/index,roles/index,contract-templates/index,audit-log,banks,facilities,unit-types,custom-field-settings}.blade.php`; `resources/views/livewire/import/index.blade.php`; `resources/views/components/settings/layout.blade.php`, `resources/views/livewire/settings/{profile,security,appearance}.blade.php`, `resources/views/livewire/settings/two-factor/recovery-codes.blade.php`, `resources/views/partials/settings-heading.blade.php`; `resources/views/verify.blade.php`, `resources/views/errors/500.blade.php`

**Interfaces:**
- Consumes (Task 1): `<x-ui.page-header>`, `<x-ui.filters :clear>`, `<x-ui.table>`, `<x-ui.empty>`, `<x-ui.section>`, `<x-ui.form-actions>`, `<x-ui.stats>`/`<x-ui.stat>` (only where the page already shows figures).
- Produces: nothing.

**Rules for this task:**
- Index recipe for lists: page header (same title; buttons/exports in actions), filters wrapped in `<x-ui.filters>` (clear URL = the page's route without filter params; condition = any filter differs from its default — read the component's property defaults and `mount()`), `<div class="overflow-x-auto">` → `<x-ui.table>`, money columns `align="end"` + `text-end tabular-nums`, empty state unless an `@empty` row exists.
- Inline add/edit forms on admin list pages (banks, facilities, unit types, custom fields): their fields go in an untitled `<x-ui.section>` above the table; buttons unchanged.
- Report pages: keep every figure, total row, chart (`<figure class="viz …">`, `data-group-level`, `data-legend`, `aria-label`s — `BuilderScreenTest` and `ComparisonTest` assert these), export button and the order of rows. Only the page header, filter row and table card change. A report's existing summary figures may become `<x-ui.stats>` only if their label and value text stay identical and in the same order.
- Ledger views: every `__()` key must exist in `lang/ar.json` (`LedgerAccessTest`); add no new strings there.
- Settings pages: `components/settings/layout.blade.php` — the side navlist keeps its items; wrap the content column's form area in an untitled `<x-ui.section>`; headings unchanged.
- `verify.blade.php` and `errors/500.blade.php`: if they include `partials.head`, change only body/background/text classes to the tokens (`bg-zinc-100 dark:bg-zinc-950`, headings `text-zinc-900 dark:text-white`, links `text-accent-content`); change no text (`AppShellTest` asserts the 500 text). If they do not include `partials.head`, leave them.
- `welcome.blade.php` is not touched (plan ruling 13).
- Keep every string, binding, route, `@can`, `wire:*`. No new strings expected (if any: Arabic after `    "Credit refund reversal": "عكس استرداد رصيد",`, key to `DesignPassArabicTest`). RTL-safe utilities, `dark:` for colours, no hex, `@php … @endphp` blocks only.

- [ ] **Step 1: Baseline**

Run: `php artisan test tests/Feature/Ledger tests/Feature/Reports tests/Feature/ReportBuilder tests/Feature/ReportPackages tests/Feature/Users tests/Feature/Roles tests/Feature/ContractTemplates tests/Feature/Audit tests/Feature/Banks tests/Feature/Units/UnitTypesTest.php tests/Feature/CustomFields tests/Feature/Import tests/Feature/Settings tests/Feature/Agreements/VerifyPageTest.php tests/Feature/Shell`
Expected: PASS before changes.

- [ ] **Step 2: Convert each file per the rules.** Known filter defaults: `Admin\Users\Index` — `search` `''`, `status` `'active'`; `Admin\AuditLog` — `event`, `causerId`, `subjectType`, `from`, `to` (read `mount()` for date defaults); `Ledger\CashMovements` — `filterFrom`, `filterTo`, `filterKind`, `filterAccount` (read `mount()`); `Ledger\BankReconciliations` — `account` (a choice, not a filter: no clear link); `Admin\CustomFieldSettings` — `entity` (a choice: no clear link).

- [ ] **Step 3: Run the Step 1 tests again**
Expected: PASS.

- [ ] **Step 4: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add resources/views
git commit -m "$(cat <<'EOF'
Design pass sweep B: ledger, reports, admin lists, data import, user settings, the verification and error pages in the shared look; figures, charts and report order unchanged

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: Final pass — dark mode, RTL, modals, screenshots, full suite

**Files:**
- Modify: any view found wanting in this pass (fixes only; same rules as the task that converted it).

**Interfaces:**
- Consumes: everything above.
- Produces: the finished branch.

**Rules for this task:**
- Fixes only: no new components, no new strings unless a fix needs one (then Arabic after `    "Credit refund reversal": "عكس استرداد رصيد",` and the key in `DesignPassArabicTest`).
- Keep every string, binding, route, permission and button text.

- [ ] **Step 1: Leftover scan.** Each of these must print nothing (or only lines you can justify in the commit message):

```bash
grep -rnE '\b(ml|mr|pl|pr)-[0-9]|\b(left|right)-[0-9]|text-(left|right)\b' resources/views --include=*.blade.php | grep -v "resources/views/pdf\|resources/views/mail\|welcome.blade.php"
grep -rnE '#[0-9a-fA-F]{6}\b' resources/views --include=*.blade.php | grep -v "resources/views/pdf\|resources/views/mail\|welcome.blade.php"
grep -rn 'class="overflow-x-auto"' resources/views/livewire
grep -rnE 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' resources/views
grep -rnE '@php\(' resources/views/livewire resources/views/components resources/views/layouts
```

For the last command, a file that has inline `@php(...)` **and** an `@php … @endphp` block must be converted to blocks only; a file with only inline `@php(...)` may stay.

- [ ] **Step 2: Modals.** `grep -rln "<flux:modal" resources/views/livewire` — open each listed file; inside each modal, check headings use `flux:heading size="lg"`, primary buttons are `variant="primary"` (gold), inputs keep labels, and there is no hard-coded colour. Fix only what is off.

- [ ] **Step 3: Screenshot run (controller, not a subagent; plan ruling 17).** Start the Browser pane on the local Herd site for this folder (`http://rentalmanagementsystem.test`; if it differs, use the URL `herd links` prints). Ask the user to sign in as an Admin (two-factor), then as a tenant on the portal. Capture into the session scratchpad (never the repository):
  - light mode, every staff page: dashboard; every list in Tasks 3, 4 and 12; every form in Tasks 5, 6 and 8 (create and edit); tenant page; agreement page; every record page in Task 11; bank reconciliation list and show; company settings; user settings; sign-in and two-factor pages (signed out);
  - dark mode (Appearance → Dark): dashboard, invoices list, tenant page, agreement page, record payment, bank reconciliation show, company settings, sign-in;
  - phone width (375×812): dashboard, sidebar drawer open, invoices list, tenant page, record payment;
  - portals in Arabic (language switch): tenant home, invoices, statement, maintenance; owner home, statements; portal sign-in; and the same in English.
  Review each by eye for: contrast (gold text only dark gold on white, bright gold only on charcoal/near-black), focus rings visible when tabbing, RTL mirroring (chevrons, back links, sidebar/drawer side, number alignment), nothing clipped at 375 px, sticky Save bars not covering the last field, dark-mode surfaces with no white flashes. Fix what is wrong (Step 1 rules), re-capture, and list the fixes in the commit message.

- [ ] **Step 4: Full suite (serially, one process).**

Run: `php artisan test`
Expected: PASS, with no test changed in this plan other than `InvoiceTabsTest` (Task 3), `PaymentScreensTest` (Task 8, one assertion added) and the new tests listed in File Structure. If any other test fails, fix the view (not the test) unless the test asserts markup that legitimately moved; then update it minimally, never weakening it, and say so in the commit message.

- [ ] **Step 5: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add resources/views lang/ar.json tests/Feature
git commit -m "$(cat <<'EOF'
Design pass final: dark mode, RTL and modal fixes from the screenshot review; full suite green

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review

**Spec coverage.**
- §2 look and tokens, AA gold, status colours, font fallback → Task 1 (tokens, contrast figures in the CSS comment, status-badge, font stack); dark mode → every task's rules and Task 13.
- §3 components (page-header, stat/stats, filters, table, status-badge, empty, section, form-actions, summary) → Task 1; rules on `__()`, dark, RTL → Task 1 tests and every task's rules.
- §4 shell (charcoal sidebar, logo and name, collapsible groups remembered, current open, permissions unchanged, approvals badge, mobile header, user menu) → Task 2.
- §5 lists with the named figures (invoices, payments, cheques, agreements, units, maintenance, approvals) → Tasks 3 and 4 with exact queries and worked-example tests; other lists → Tasks 3, 4 and 12.
- §6 forms (sections, required marks, two columns, sticky actions, bindings unchanged) → Tasks 5, 6, 8 (and the tenant form in Task 7).
- §7 key screens (dashboard with Needs attention; tenant and agreement pages with summary and section nav; record payment, new agreement, owner contract, company settings with running totals; bank reconciliation stats with the difference red until 0.000) → Tasks 7 and 8.
- §8 portals and sign-in → Tasks 9 and 10.
- §9 safety (suite unchanged except moved markup, AA, focus rings, labels, icon-button labels, no colour alone, Livewire rules, single aggregates, dark/RTL on components) → Global Constraints, Task 1 (focus ring, badges keep text), each task's rules, Task 13.
- §10 stages → Tasks 1–2 (stage 1), 3–4 (2), 5–6 (3), 7–8 (4), 9–10 (5), 11–13 (6).
- §11 testing (component tests; access tests keep passing; list figures on worked examples for invoices, cheques, agreements, units — plus payments, maintenance, approvals; sidebar current group and permissions; screenshot run) → Tasks 1, 2, 3, 4, 13.

**Placeholder scan.** The tasks that convert many similar views (5, 6, 11, 12) give per-file grouping, routes, required fields and the conditions, not full markup, by design (161 views); the worked examples in the style guide and Task 5 Step 3 carry complete code. Task 3's approvals assertion depends on the existing test file's fixture names and says how to adapt it. Task 8 Step 1's expected `owed` value depends on that test's fixture and says how to compute it.

**Type consistency.** `stats` shapes: cheques `{held, held_amount, week, bounced}`, payments `{collected, receipts}`, agreements `{active, pending, expiring}`, units `{occupied, vacant, blocked}`, work orders `{open, overdue}`; maintenance requests `urgent` (int) and the existing `counts`; dashboard `attention` items `{label, count, url}` (Task 7 Step 3 notes the key order); `CompanySetting::brand()` `{name, logo}`; component props as listed in Task 1's Interfaces, used with the same names in Tasks 2–12.
