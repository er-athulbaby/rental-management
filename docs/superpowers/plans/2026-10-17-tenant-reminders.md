# Tenant Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Email tenants automatically, and on demand, about rent due, overdue invoices, post-dated cheques coming up and agreements nearing their end: one bilingual combined email per tenant per day, never sent twice, every attempt logged.

**Architecture:** `App\Reminders\ReminderFinder` reads issued invoices, held cheques and active agreements and returns, for a date, each tenant's matches for every switched-on type on its rule days (`ReminderItem`s). The daily command `rms:reminders:send` queues one `SendTenantReminders` job per tenant. The job finds that tenant's matches again and hands them to `ReminderSender`. The sender writes one `reminder_sends` row per item, then sends one combined message through a `Channel`. The rows carry a generated unique key, so a rerun finds them and sends nothing twice. `EmailChannel` sends the `TenantReminder` mailable (an HTML Blade view, English then Arabic RTL). A `Reminders\RecordPanel` Livewire component sits on the invoice, cheque, agreement and tenant pages. It previews and sends a manual reminder and lists the record's reminders. `Reminders\Index` is the log, with filters and an Excel export. An Admin page edits the four types.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, `Illuminate\Mail\Mailable`, spatie/simple-excel, spatie/laravel-permission, spatie/laravel-activitylog (`App\Audit\Audit`), `App\Support\Fils`, `<x-building-filter>`, `<x-date-input>`.

**Spec:** `docs/superpowers/specs/2026-10-17-tenant-reminders-design.md`. Read it first; this plan argues from it.

## Global Constraints

- Money is BHD in fils with 3 decimals. In PHP use `App\Support\Fils` (`fromDecimal`, `toDecimal`, `grouped`) and never floats.
- Timezone is Asia/Bahrain. Dates display as dd/mm/yyyy (`format('d/m/Y')`). "Today" is `CarbonImmutable::today('Asia/Bahrain')`.
- "Reminders only read money records." Actions are the only write path for money. Reminders write only `reminder_settings`, `reminder_sends` and `customers.no_reminders`. "No money tables touched; no triggers; `EXPECTED_TRIGGERS` unchanged" (spec §8). `IntegrityCheck::EXPECTED_TRIGGERS` stays 61.
- All UI strings go through `__()`. Write "tenant", never "customer", in UI copy. The building filter is `<x-building-filter>`. "Arabic text in the email comes from the settings page or fixed bilingual templates" (spec §9).
- "Tenant emails come from the tenant record only. Manual sends check permission and building scope; tampered Livewire input gives a validation message, never a 500" (spec §9). Public methods on Livewire components can be called from the browser. Never expose a public method that takes a user id or returns data without an authorization check. Internals are `private` or `static`.
- Types and defaults (spec §3): `rent_due` 3 days before the due date; `overdue` 1, 7, 14 days after becoming overdue; `cheque` 2 days before the cheque date; `renewal` 60, 30 days before the end date. "Every type is off until an Admin turns it on." Days are "whole numbers 1–365, at most 5 per type"; intro lines are "≤300 characters each, with safe defaults".
- "Matching day: an item matches a rule when today (Asia/Bahrain) is exactly the rule's day relative to its date. A missed day (server down) is not caught up" (spec §3).
- Subject: "the most urgent type present (overdue → rent due → cheque → renewal), in English · Arabic, plus the company name" (spec §4). "From: company name + `MAIL_FROM_ADDRESS`; reply-to: the company email."
- Daily run: "`rms:reminders:send`, scheduled daily at 08:00 Asia/Bahrain, `withoutOverlapping`, heartbeat `HEARTBEAT_TENANT_REMINDERS`. It does nothing while no type is enabled, or while mail is not configured (`MAIL_MAILER` is `log` or `array`)" (spec §5).
- Jobs: "one queued job per tenant (tries 1, timeout 120). One failure never blocks another tenant." The queue's `retry_after` is 330. "Failures: recorded as `failed` with the error; not retried automatically" (spec §5).
- Audit: "every automatic batch (`reminder.sent`, counts per type) and every manual send (`reminder.sent`, the item)". The log's Excel export is "audited `report.exported`" (spec §5, §6).
- Tests run serially (`php artisan test`, never `--parallel`) against `rms_test`. Never run `migrate:fresh` on the dev DB `rms`. Dev migrations use `php artisan migrate --database=migrator --force`.
- PHP binary: `/c/Users/ababy/.config/herd/bin/php85/php.exe` (written `php` below). Gates before each commit: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, and `npm run build` when a view changed.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file. Alpine attributes on Flux components need `:x-show`-style binding.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Rulings made while planning

1. **Overdue counts from `invoices.grace_until`.** `IssueInvoice` stamps `grace_until = due_date + grace days` when the invoice is issued. It uses the agreement's `grace_days`, or `CompanySetting::default_grace_days` when there is no agreement (`IssueInvoice.php:78-80`). That is exactly spec §3's rule, already stored. `Invoice::displayLabel()`, `Queries::overdueInvoices()` and the Ageing report already treat an invoice as overdue when today > `grace_until`. So overdue day N is `grace_until + N`. The email's "days overdue" is the same count (today − `grace_until`), so the email, the rule day and the Ageing report agree.
2. **"No later agreement renewing them"** means no agreement with `previous_agreement_id` = this one whose status is `draft`, `pending_approval` or `active`. `RenewAgreement` already uses that set to refuse a second renewal. Soft-deleted drafts do not count. A new `Agreement::renewals()` relation expresses it.
3. **The unique key (spec §8).** A STORED generated column `auto_key = IF(manual, NULL, CONCAT_WS('#', customer_id, type, item_type, item_id, rule_day, sent_on))` carries a UNIQUE index. NULLs never collide, so manual rows are free. This copies `approvals.pending_key`, which MySQL already accepts here. MySQL forbids an ON DELETE/UPDATE action on a foreign key whose column is a base column of a stored generated column, so `customer_id` and `sent_by` get plain foreign keys with no action. Customers are only soft-deleted. A CHECK makes `rule_day` required on automatic rows. No trigger is added. `IntegrityCheck` counts only triggers (`rms_trigger_count()`), so generated columns and CHECKs are invisible to it and `EXPECTED_TRIGGERS` stays 61.
4. **The settings rows are inserted by the migration**, all switched off, like `unit_types`. `tests/TestCase.php` must add `reminder_settings` to `$exceptTables`. Otherwise the Concurrency tests' `DatabaseTruncation` empties the table and every later reminder test finds no types.
5. **A transient `sending` status.** The sender claims an item by inserting its row as `sending`, then mails, then sets the whole batch to `sent` or `failed`. A killed job's `failed()` turns leftover `sending` rows into `failed`. The log filter offers sent, failed and skipped, as spec §6 lists.
6. **Skips are logged per item** with the codes `Opted out` and `No email`, plus `Email is not set up` for manual sends. The codes are stored in English and shown through `ReminderSend::reasonText()`. Skips use the same unique key, so a rerun does not log them twice. Spec §6 says "items"; the log shows **one row per item**, and `batch` groups the items of one message.
7. **Audit only what was sent.** One `reminder.sent` per message, with the tenant as subject. Automatic messages log `{batch, counts: {type: n}}`. Manual messages log `{batch, manual: true, type, item_type, item_id}`, with the sender as causer. Failed and skipped attempts are already in the log, so they are not audited.
8. **"Mail not configured"** is `EmailChannel::ready()`: `config('mail.default')` is not `log` or `array`. The manual button is also refused then, with the reason "Email is not set up". A "sent" reminder that went to a log file would mislead staff. The spec is silent on this.
9. **Manual send qualification.** An invoice gets `overdue` when today > `grace_until`, else `rent_due`. A cheque gets `cheque`. An agreement gets `renewal` when its end date is between today and today + 90, both inclusive. Each record must still meet its type's automatic rule: issued with a balance and not a credit note; received and `held`; active with no notice and no renewal. The settings switch is ignored (spec §6: "Works when the type is switched off").
10. **Greeting.** The English section uses `name_en`. The Arabic section uses `Customer::displayName('ar')`, which is `name_ar` when set, else `name_en`.
11. **The email is a `Mailable` with a plain HTML Blade view**, not markdown. Markdown would turn `*` or `_` in a name or intro into formatting, and it cannot set `dir="rtl"` on one section. Tenants are not `User`s, so it goes out with `Mail::to($tenant->email, $tenant->name_en)`. No other Arabic or RTL email exists to reuse.
12. **`Channel` has four methods**: `name`, `ready`, `unavailableReason` and `send` (which throws on failure). Spec §7 requires the interface. It also lets tests inject a failing channel. It is bound to `EmailChannel` in `AppServiceProvider`.
13. **The preview is the same `TenantReminder`'s `render()`**, shown in a sandboxed `<iframe srcdoc>`. It is built in `render()` only when a send is possible. `RecordPanel` has no public state besides the locked record kind and id, as in `Documents\Panel`. `send()` is its only action, and it re-checks the record, the `view` policy (permission plus building scope), the qualification and the refusal.
14. **Visibility of reminder rows** (log page and record lists). Invoice and cheque rows need `finance.view`; agreement rows need `agreements.view`. Building scope comes from each record's own `visibleTo`. The building filter uses each record's `inBuilding`. Leasing therefore sees only renewal reminders in its own buildings.
15. **Tampering.** The settings form (`$settings`) and the log filters are untyped `mixed` and validated before use, as in `StatementPage`. A bad URL falls back to the defaults.
16. **Days** are stored distinct and sorted ascending. Each type needs 1–5 days, because an enabled type with no day would never send.
17. **Routes.** The settings page is `admin.reminders` (`/admin/reminders`, `can:settings.manage`, under Administration). The log is `reminders.index` (`/reminders`, under Leasing). It has no `can:` middleware because it needs either permission, so `mount()` checks it and returns 403.
18. **The job finds its tenant's matches again for the run date**, so items paid, deposited, renewed or given notice since the command ran are dropped (spec §3). The command loads every match once only to learn which tenants to queue. That is a `ponytail:` ceiling and is fine for one company's records.

## File Structure

```
database/migrations/2026_12_18_000100_create_reminder_tables.php   NEW  reminder_settings (+4 rows), reminder_sends (+auto_key), customers.no_reminders (Task 1)
app/Models/ReminderSetting.php                       NEW  TYPES, label(), parseDays() (Task 1)
app/Models/ReminderSend.php                          NEW  log row, display helpers (Task 1); visibleTo / inBuilding scopes (Task 6)
app/Models/Customer.php                              MOD  no_reminders fillable + cast (Task 1)
app/Livewire/Admin/TenantReminders.php               NEW  settings page (Task 1)
resources/views/livewire/admin/tenant-reminders.blade.php  NEW (Task 1)
routes/admin.php                                     MOD  admin.reminders (Task 1)
resources/views/layouts/app/sidebar.blade.php        MOD  Administration → Tenant reminders (Task 1); Leasing → Reminders (Task 7)
tests/TestCase.php                                   MOD  exceptTables + reminder_settings (Task 1)
app/Reminders/ReminderItem.php                       NEW  one matched item (Task 2)
app/Reminders/ReminderFinder.php                     NEW  due() / manual() (Task 2)
app/Models/Agreement.php                             MOD  renewals() (Task 2)
app/Reminders/Channel.php                            NEW  interface (Task 3)
app/Reminders/EmailChannel.php                       NEW  (Task 3)
app/Mail/TenantReminder.php                          NEW  combined bilingual email (Task 3)
resources/views/mail/tenant-reminder.blade.php       NEW  (Task 3)
app/Providers/AppServiceProvider.php                 MOD  Channel → EmailChannel (Task 3)
app/Reminders/ReminderSender.php                     NEW  log + send + audit (Task 4)
app/Jobs/SendTenantReminders.php                     NEW  per-tenant job (Task 4)
app/Console/Commands/SendTenantRemindersCommand.php  NEW  rms:reminders:send (Task 4)
routes/console.php, config/services.php, .env.example, docs/new-company-setup.md  MOD  schedule, heartbeat, docs (Task 4)
app/Livewire/Reminders/RecordPanel.php               NEW  Send reminder + preview (Task 5); reminders list (Task 6)
resources/views/livewire/reminders/record-panel.blade.php  NEW (Task 5; list Task 6)
resources/views/livewire/{invoices,cheques,agreements}/show.blade.php  MOD  embed the panel (Task 5)
app/Actions/Customers/SaveCustomer.php, app/Livewire/Customers/Form.php, resources/views/livewire/customers/form.blade.php  MOD  opt-out switch (Task 5); panel on the tenant page (Task 6)
app/Livewire/Reminders/Index.php                     NEW  log page + Excel (Task 7)
resources/views/livewire/reminders/index.blade.php   NEW  (Task 7)
routes/property.php                                  MOD  reminders.index (Task 7)
tests/Pest.php                                       MOD  reminderTenant() (Task 2), logReminder() (Task 6)
tests/Feature/Reminders/SettingsTest.php             NEW  (Task 1)
tests/Feature/Reminders/FinderTest.php               NEW  (Task 2)
tests/Feature/Reminders/EmailTest.php                NEW  (Task 3)
tests/Feature/Reminders/DailyRunTest.php             NEW  (Task 4)
tests/Feature/Reminders/SendButtonTest.php           NEW  (Task 5)
tests/Feature/Reminders/RecordListsTest.php          NEW  (Task 6)
tests/Feature/Reminders/LogPageTest.php              NEW  (Task 7)
```

---

### Task 1: Storage, the Tenant reminders settings page

**Files:**
- Create: `database/migrations/2026_12_18_000100_create_reminder_tables.php`
- Create: `app/Models/ReminderSetting.php`, `app/Models/ReminderSend.php`
- Create: `app/Livewire/Admin/TenantReminders.php`, `resources/views/livewire/admin/tenant-reminders.blade.php`
- Modify: `app/Models/Customer.php` (docblock, `$fillable`, `casts()`)
- Modify: `routes/admin.php`, `resources/views/layouts/app/sidebar.blade.php` (Administration group)
- Modify: `tests/TestCase.php` (`$exceptTables`)
- Test: `tests/Feature/Reminders/SettingsTest.php`

**Interfaces:**
- Consumes: `Customer`, `Invoice`, `Cheque`, `Agreement`, `User`, `WithActor`, `PermissionName::SettingsManage` (`settings.manage`).
- Produces:
  - `ReminderSetting` (`type`, `enabled` bool, `days` list<int>, `intro_en`, `intro_ar`; `LogsActivity`).
    - `ReminderSetting::TYPES = ['overdue', 'rent_due', 'cheque', 'renewal']` (urgency order).
    - `ReminderSetting::label(string $type): string`.
    - `ReminderSetting::parseDays(string $text): ?list<int>`.
  - `ReminderSend`. Columns: `customer_id`, `type`, `item_type` `'invoice'|'cheque'|'agreement'`, `item_id`, `rule_day` ?int, `sent_on` CarbonImmutable, `channel`, `status`, `reason` ?string, `manual` bool, `sent_by` ?int, `batch` string.
    - Constants: `STATUSES = ['sending', 'sent', 'failed', 'skipped']`, `OPTED_OUT = 'Opted out'`, `NO_EMAIL = 'No email'`, `MAIL_NOT_SET_UP = 'Email is not set up'`.
    - Relations: `customer()`, `sender()`, `invoice()`, `cheque()`, `agreement()`. The last three are BelongsTo on `item_id`.
    - Helpers: `itemLabel(): string`, `itemUrl(): string`, `statusLabel(): string`, `statusColor(): string`, `static reasonText(?string): string`.
  - `customers.no_reminders` (bool, default false), fillable and cast on `Customer`.
  - Route `admin.reminders` → `App\Livewire\Admin\TenantReminders`, with public `mixed $settings` and `save()`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Reminders/SettingsTest.php`:

```php
<?php

use App\Enums\RoleName as R;
use App\Livewire\Admin\TenantReminders;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ReminderSend;
use App\Models\ReminderSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->admin = matrixUser(R::Admin, $this->building);
});

test('a fresh install has the four types, all off, with the default days and intro lines', function () {
    $settings = ReminderSetting::query()->get()->keyBy('type');

    expect($settings->keys()->sort()->values()->all())->toBe(['cheque', 'overdue', 'renewal', 'rent_due'])
        ->and($settings->every(fn (ReminderSetting $s) => $s->enabled === false && $s->intro_en !== '' && $s->intro_ar !== ''))->toBeTrue()
        ->and($settings['rent_due']->days)->toBe([3])
        ->and($settings['overdue']->days)->toBe([1, 7, 14])
        ->and($settings['cheque']->days)->toBe([2])
        ->and($settings['renewal']->days)->toBe([30, 60])
        ->and(Customer::factory()->create()->fresh()->no_reminders)->toBeFalse();
});

test('an admin switches a type on and edits its days and intro lines; days are stored sorted', function () {
    Livewire::actingAs($this->admin)->test(TenantReminders::class)
        ->assertSee('Tenant reminders')->assertSee('Days after the grace period ends')
        ->set('settings.overdue.enabled', true)
        ->set('settings.overdue.days', '14, 1 7')
        ->set('settings.overdue.intro_en', 'Please pay what is overdue.')
        ->set('settings.overdue.intro_ar', 'يرجى سداد المبالغ المتأخرة.')
        ->call('save')->assertHasNoErrors();

    $overdue = ReminderSetting::query()->where('type', 'overdue')->sole();
    expect($overdue->enabled)->toBeTrue()->and($overdue->days)->toBe([1, 7, 14])
        ->and($overdue->intro_en)->toBe('Please pay what is overdue.')->and($overdue->intro_ar)->toBe('يرجى سداد المبالغ المتأخرة.')
        ->and(ReminderSetting::query()->where('type', 'rent_due')->sole()->enabled)->toBeFalse();
});

test('days are 1 to 5 different whole numbers from 1 to 365; intros are required, at most 300 characters; tampering is a message, never a 500', function (string $key, mixed $value) {
    Livewire::actingAs($this->admin)->test(TenantReminders::class)
        ->set($key, $value)->assertOk()
        ->call('save')->assertOk()->assertHasErrors($key);

    // Nothing saved: cheque, overdue, renewal, rent_due keep their defaults.
    expect(ReminderSetting::query()->orderBy('type')->get()->map(fn (ReminderSetting $s) => $s->days)->all())->toBe([[2], [1, 7, 14], [30, 60], [3]])
        ->and(ReminderSetting::query()->where('enabled', true)->count())->toBe(0);
})->with([
    'day zero' => ['settings.rent_due.days', '0'],
    'day 366' => ['settings.rent_due.days', '366'],
    'six days' => ['settings.overdue.days', '1, 2, 3, 4, 5, 6'],
    'repeated day' => ['settings.overdue.days', '7, 7'],
    'not a number' => ['settings.cheque.days', 'soon'],
    'a fraction' => ['settings.cheque.days', '1.5'],
    'no days' => ['settings.renewal.days', ''],
    'days as an array' => ['settings.renewal.days', ['30']],
    'English intro too long' => ['settings.rent_due.intro_en', str_repeat('x', 301)],
    'Arabic intro too long' => ['settings.rent_due.intro_ar', str_repeat('ب', 301)],
    'blank Arabic intro' => ['settings.rent_due.intro_ar', '  '],
    'intro as an array' => ['settings.rent_due.intro_en', ['x']],
    'switch not a boolean' => ['settings.cheque.enabled', 'maybe'],
    'a type as text' => ['settings.overdue', 'x'],
    'the whole form as text' => ['settings', 'x'],
]);

test('only Admin and Vendor Support open the page, and the Administration menu links it', function () {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route('admin.reminders'))->status();

        expect($status)->toBe(in_array($role, [R::Admin, R::VendorSupport], true) ? 200 : 403, "admin.reminders as {$role->value}");
    }
    $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('admin.reminders'));
});

test('an automatic reminder is logged once per tenant, type, item, rule day and date; manual ones never collide', function () {
    $tenant = Customer::factory()->create();
    $row = ['customer_id' => $tenant->id, 'type' => 'overdue', 'item_type' => 'invoice', 'item_id' => 5, 'rule_day' => 7,
        'sent_on' => '2026-11-10', 'channel' => 'email', 'status' => 'sent', 'batch' => 'b1'];
    ReminderSend::create($row);

    expect(fn () => ReminderSend::create([...$row, 'batch' => 'b2']))->toThrow(UniqueConstraintViolationException::class);

    ReminderSend::create([...$row, 'rule_day' => 14, 'batch' => 'b3']);         // another rule day
    ReminderSend::create([...$row, 'sent_on' => '2026-11-11', 'batch' => 'b4']); // another date
    ReminderSend::create([...$row, 'rule_day' => null, 'manual' => true, 'batch' => 'b5']);
    ReminderSend::create([...$row, 'rule_day' => null, 'manual' => true, 'batch' => 'b6']);

    expect(ReminderSend::count())->toBe(5);
});

test('the log refuses unknown types, items and statuses, and an automatic row without a rule day', function (array $change) {
    $tenant = Customer::factory()->create();

    expect(fn () => ReminderSend::create(['customer_id' => $tenant->id, 'type' => 'overdue', 'item_type' => 'invoice', 'item_id' => 5, 'rule_day' => 7,
        'sent_on' => '2026-11-10', 'channel' => 'email', 'status' => 'sent', 'batch' => 'b1', ...$change]))->toThrow(QueryException::class);
})->with([
    'type' => [['type' => 'late_fee']],
    'item type' => [['item_type' => 'payment']],
    'status' => [['status' => 'queued']],
    'automatic without a rule day' => [['rule_day' => null]],
]);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reminders/SettingsTest.php`
Expected: FAIL with `Class "App\Models\ReminderSetting" not found` (or `Table 'rms_test.reminder_settings' doesn't exist`).

- [ ] **Step 3: Write the migration** `database/migrations/2026_12_18_000100_create_reminder_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-17 §8: reminder settings (one row per type, all off), the reminder log, and the tenant's opt-out.
 * Plan ruling 3: automatic rows are unique through a generated key; MySQL forbids FK actions on a generated column's
 * base column, so customer_id and sent_by have none (customers are only soft-deleted). No triggers.
 */
return new class extends Migration
{
    /** type => [days, intro_en, intro_ar]. */
    private const array DEFAULTS = [
        'overdue' => [[1, 7, 14], 'Our records show the following amounts are overdue. Please arrange payment as soon as possible.',
            'تشير سجلاتنا إلى أن المبالغ التالية متأخرة السداد. يرجى المبادرة بالدفع في أقرب وقت.'],
        'rent_due' => [[3], 'This is a friendly reminder that the following rent is due soon.', 'نود تذكيركم بأن الإيجار التالي يستحق قريباً.'],
        'cheque' => [[2], 'The following cheque will be presented to the bank soon.', 'سيتم تقديم الشيك التالي إلى البنك قريباً.'],
        'renewal' => [[30, 60], 'Your tenancy agreement is ending soon.', 'تقترب اتفاقية الإيجار الخاصة بكم من نهايتها.'],
    ];

    public function up(): void
    {
        Schema::create('reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->unique();
            $table->boolean('enabled')->default(false);
            $table->json('days');
            $table->string('intro_en', 300);
            $table->string('intro_ar', 300);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE reminder_settings ADD CONSTRAINT reminder_settings_type_chk CHECK (type IN ('overdue', 'rent_due', 'cheque', 'renewal'))");

        $now = now();
        DB::table('reminder_settings')->insert(array_map(
            fn (string $type, array $d) => ['type' => $type, 'enabled' => false, 'days' => json_encode($d[0]), 'intro_en' => $d[1], 'intro_ar' => $d[2],
                'created_at' => $now, 'updated_at' => $now],
            array_keys(self::DEFAULTS), self::DEFAULTS,
        ));

        Schema::create('reminder_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('type', 20);
            $table->string('item_type', 20);
            $table->unsignedBigInteger('item_id');
            $table->unsignedSmallInteger('rule_day')->nullable();
            $table->date('sent_on');
            $table->string('channel', 20)->default('email');
            $table->string('status', 10);
            $table->string('reason', 500)->nullable();
            $table->boolean('manual')->default(false);
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->char('batch', 36);
            $table->timestamps();
            $table->index(['item_type', 'item_id']);
            $table->index('sent_on');
            $table->index('batch');
        });

        // Automatic rows: one per tenant, type, item, rule day and date. NULLs never collide, so manual rows are free.
        DB::statement(<<<'SQL'
            ALTER TABLE reminder_sends
                ADD COLUMN auto_key VARCHAR(120) GENERATED ALWAYS AS (IF(manual, NULL, CONCAT_WS('#', customer_id, type, item_type, item_id, rule_day, sent_on))) STORED,
                ADD UNIQUE KEY reminder_sends_auto_key_unique (auto_key),
                ADD CONSTRAINT reminder_sends_type_chk CHECK (type IN ('overdue', 'rent_due', 'cheque', 'renewal')),
                ADD CONSTRAINT reminder_sends_item_chk CHECK (item_type IN ('invoice', 'cheque', 'agreement')),
                ADD CONSTRAINT reminder_sends_status_chk CHECK (status IN ('sending', 'sent', 'failed', 'skipped')),
                ADD CONSTRAINT reminder_sends_rule_chk CHECK (manual OR rule_day IS NOT NULL)
            SQL);

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('no_reminders')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('no_reminders'));
        Schema::dropIfExists('reminder_sends');
        Schema::dropIfExists('reminder_settings');
    }
};
```

- [ ] **Step 4: Keep the seeded rows through truncation.** In `tests/TestCase.php` replace

```php
    /** Reference rows the migrations insert; emptying them would break every later test that saves a unit. */
    protected array $exceptTables = ['unit_types'];
```

with

```php
    /** Reference rows the migrations insert; emptying them would break every later test that saves a unit or sends a reminder. */
    protected array $exceptTables = ['unit_types', 'reminder_settings'];
```

- [ ] **Step 5: The models.** Create `app/Models/ReminderSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One reminder type's settings (spec 2026-10-17 §3). The migration creates the four rows, all switched off.
 *
 * @property int $id
 * @property string $type
 * @property bool $enabled
 * @property list<int> $days
 * @property string $intro_en
 * @property string $intro_ar
 */
class ReminderSetting extends Model
{
    use LogsActivity;

    /** The types, most urgent first: the email's subject and section order (spec §4). */
    public const array TYPES = ['overdue', 'rent_due', 'cheque', 'renewal'];

    protected $fillable = ['enabled', 'days', 'intro_en', 'intro_ar'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'days' => 'array'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['enabled', 'days', 'intro_en', 'intro_ar'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    public static function label(string $type): string
    {
        return match ($type) {
            'overdue' => __('Overdue'),
            'rent_due' => __('Rent due'),
            'cheque' => __('Cheque'),
            'renewal' => __('Renewal'),
            default => $type,
        };
    }

    /**
     * "14, 1 7" → [1, 7, 14]; null unless it is 1 to 5 different whole numbers from 1 to 365 (spec §3).
     *
     * @return list<int>|null
     */
    public static function parseDays(string $text): ?array
    {
        $parts = preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === [] || count($parts) > 5) {
            return null;
        }
        $days = [];
        foreach ($parts as $part) {
            if (! ctype_digit($part) || (int) $part < 1 || (int) $part > 365) {
                return null;
            }
            $days[] = (int) $part;
        }
        if (count(array_unique($days)) !== count($days)) {
            return null;
        }
        sort($days);

        return $days;
    }
}
```

Create `app/Models/ReminderSend.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of one reminder message (spec 2026-10-17 §8); `batch` groups the items of one message. Automatic rows are
 * unique per tenant, type, item, rule day and date through the generated `auto_key` (plan ruling 3); manual rows never
 * collide. invoice(), cheque() and agreement() all read item_id: use only the one item_type names.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $type
 * @property string $item_type 'invoice' | 'cheque' | 'agreement'
 * @property int $item_id
 * @property int|null $rule_day
 * @property CarbonImmutable $sent_on
 * @property string $channel
 * @property string $status 'sending' | 'sent' | 'failed' | 'skipped'
 * @property string|null $reason a skip code below, or the error of a failed send
 * @property bool $manual
 * @property int|null $sent_by
 * @property string $batch
 */
class ReminderSend extends Model
{
    public const array STATUSES = ['sending', 'sent', 'failed', 'skipped'];

    /** Skip reasons, stored in English and shown through reasonText(). */
    public const string OPTED_OUT = 'Opted out';

    public const string NO_EMAIL = 'No email';

    public const string MAIL_NOT_SET_UP = 'Email is not set up';

    protected $fillable = ['customer_id', 'type', 'item_type', 'item_id', 'rule_day', 'sent_on', 'channel', 'status', 'reason', 'manual', 'sent_by', 'batch'];

    protected function casts(): array
    {
        return ['sent_on' => 'immutable_date', 'manual' => 'boolean', 'rule_day' => 'integer', 'item_id' => 'integer'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<Invoice, $this> only when item_type is 'invoice' */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'item_id');
    }

    /** @return BelongsTo<Cheque, $this> only when item_type is 'cheque' */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class, 'item_id');
    }

    /** @return BelongsTo<Agreement, $this> only when item_type is 'agreement' */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class, 'item_id');
    }

    public function itemLabel(): string
    {
        return match ($this->item_type) {
            'invoice' => $this->invoice?->label() ?? '#'.$this->item_id,
            'cheque' => __('Cheque :number', ['number' => $this->cheque?->cheque_no ?? (string) $this->item_id]),
            default => $this->agreement?->label() ?? '#'.$this->item_id,
        };
    }

    public function itemUrl(): string
    {
        return match ($this->item_type) {
            'invoice' => route('invoices.show', $this->item_id),
            'cheque' => route('cheques.show', $this->item_id),
            default => route('agreements.show', $this->item_id),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'sending' => __('Sending'),
            'sent' => __('Sent'),
            'failed' => __('Failed'),
            default => __('Skipped'),
        };
    }

    /** A Flux badge colour. */
    public function statusColor(): string
    {
        return match ($this->status) {
            'sending' => 'amber',
            'sent' => 'green',
            'failed' => 'red',
            default => 'zinc',
        };
    }

    /** A stored reason in words: skip codes are translated; a failed send's error is shown as it is. */
    public static function reasonText(?string $reason): string
    {
        return match ($reason) {
            null => '',
            self::OPTED_OUT => __('The tenant has opted out of reminders.'),
            self::NO_EMAIL => __('The tenant has no email address.'),
            self::MAIL_NOT_SET_UP => __('Email is not set up.'),
            default => $reason,
        };
    }
}
```

In `app/Models/Customer.php`, add to the class docblock after ` * @property string $mobile`:

```php
 * @property string|null $email
 * @property bool $no_reminders "Don't send reminders" (spec 2026-10-17 §3)
```

Add `'no_reminders'` at the end of `$fillable`, after `'notes'`. Change `casts()` to:

```php
        return ['type' => CustomerType::class, 'id_type' => IdType::class, 'no_reminders' => 'boolean'];
```

- [ ] **Step 6: The settings page.** Create `app/Livewire/Admin/TenantReminders.php`:

```php
<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\WithActor;
use App\Models\ReminderSetting;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Spec 2026-10-17 §3: per type, on/off, the rule days and the intro lines in English and Arabic. Every type starts off. */
class TenantReminders extends Component
{
    use WithActor;

    /**
     * type => ['enabled' => bool, 'days' => '1, 7, 14', 'intro_en' => string, 'intro_ar' => string]. Untyped on purpose:
     * a tampered payload (text, arrays) must reach validation, not throw a TypeError (a 500).
     */
    public mixed $settings = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $this->settings = ReminderSetting::query()->get()->mapWithKeys(fn (ReminderSetting $s) => [$s->type => [
            'enabled' => $s->enabled, 'days' => implode(', ', $s->days), 'intro_en' => $s->intro_en, 'intro_ar' => $s->intro_ar,
        ]])->all();
    }

    public function save(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $settings = $this->validate($this->settingsRules())['settings'];

        DB::transaction(function () use ($settings) {
            foreach (ReminderSetting::TYPES as $type) {
                ReminderSetting::query()->where('type', $type)->sole()->update([
                    'enabled' => (bool) $settings[$type]['enabled'],
                    'days' => ReminderSetting::parseDays($settings[$type]['days']),
                    'intro_en' => trim($settings[$type]['intro_en']),
                    'intro_ar' => trim($settings[$type]['intro_ar']),
                ]);
            }
        });

        Flux::toast(variant: 'success', text: __('Reminder settings saved.'));
    }

    /** @return array<string, list<mixed>> */
    private function settingsRules(): array
    {
        $rules = ['settings' => ['required', 'array']];
        foreach (ReminderSetting::TYPES as $type) {
            $rules["settings.{$type}"] = ['required', 'array'];
            $rules["settings.{$type}.enabled"] = ['required', 'boolean'];
            $rules["settings.{$type}.days"] = ['bail', 'required', 'string', 'max:60', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ReminderSetting::parseDays($value) === null) {
                    $fail(__('Enter 1 to 5 different whole numbers from 1 to 365, separated by commas.'));
                }
            }];
            $rules["settings.{$type}.intro_en"] = ['required', 'string', 'max:300'];
            $rules["settings.{$type}.intro_ar"] = ['required', 'string', 'max:300'];
        }

        return $rules;
    }

    public function render(): View
    {
        return view('livewire.admin.tenant-reminders', [
            'types' => [
                'rent_due' => [__('Rent due'), __('Days before the due date')],
                'overdue' => [__('Overdue'), __('Days after the grace period ends')],
                'cheque' => [__('Post-dated cheque'), __('Days before the cheque date')],
                'renewal' => [__('Agreement ending'), __('Days before the agreement ends')],
            ],
        ])->title(__('Tenant reminders'));
    }
}
```

Create `resources/views/livewire/admin/tenant-reminders.blade.php`:

```blade
<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Tenant reminders') }}</flux:heading>
    <flux:text>{{ __('Emails sent to tenants every morning at 08:00: one email per tenant, in English and Arabic. Each type stays off until you switch it on. Staff can still send a reminder by hand from an invoice, cheque or agreement.') }}</flux:text>
    <flux:error name="settings" />

    <form wire:submit="save" class="space-y-4">
        @foreach ($types as $type => [$label, $daysLabel])
            <flux:fieldset wire:key="reminder-{{ $type }}" class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:legend>{{ $label }}</flux:legend>
                <div class="space-y-4">
                    <flux:switch wire:model="settings.{{ $type }}.enabled" :label="__('Send automatically')" />
                    <flux:input wire:model="settings.{{ $type }}.days" :label="$daysLabel" :placeholder="__('e.g. 1, 7, 14')" />
                    <flux:textarea wire:model="settings.{{ $type }}.intro_en" :label="__('Intro line (English)')" rows="2" />
                    <flux:textarea wire:model="settings.{{ $type }}.intro_ar" :label="__('Intro line (Arabic)')" rows="2" dir="rtl" />
                    <flux:error name="settings.{{ $type }}" />
                </div>
            </flux:fieldset>
        @endforeach
        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
```

- [ ] **Step 7: Route and menu.** In `routes/admin.php` add `use App\Livewire\Admin\TenantReminders;` to the imports (alphabetical, after `Roles`), and after the `banks` route:

```php
    Route::livewire('reminders', TenantReminders::class)->middleware('can:settings.manage')->name('reminders');
```

In `resources/views/layouts/app/sidebar.blade.php`, inside `@can('settings.manage')` in the Administration group, after the Banks item:

```blade
                            <flux:sidebar.item icon="envelope" :href="route('admin.reminders')" :current="request()->routeIs('admin.reminders')" wire:navigate>{{ __('Tenant reminders') }}</flux:sidebar.item>
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test tests/Feature/Reminders/SettingsTest.php`
Expected: PASS.

- [ ] **Step 9: Migrate the dev database and run the gates**

Run: `php artisan migrate --database=migrator --force`, then `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: the migration runs; Pint fixes or passes; PHPStan reports no errors; the build succeeds.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_12_18_000100_create_reminder_tables.php app/Models/ReminderSetting.php app/Models/ReminderSend.php app/Models/Customer.php \
  app/Livewire/Admin/TenantReminders.php resources/views/livewire/admin/tenant-reminders.blade.php routes/admin.php \
  resources/views/layouts/app/sidebar.blade.php tests/TestCase.php tests/Feature/Reminders/SettingsTest.php
git commit -m "$(cat <<'EOF'
Tenant reminders storage and settings: reminder_settings (all off), reminder_sends with the automatic unique key, customers.no_reminders, Admin page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Matching — `ReminderFinder`

**Files:**
- Create: `app/Reminders/ReminderItem.php`, `app/Reminders/ReminderFinder.php`
- Modify: `app/Models/Agreement.php` (new `renewals()`)
- Modify: `tests/Pest.php` (add `reminderTenant()`)
- Test: `tests/Feature/Reminders/FinderTest.php`

**Interfaces:**
- Consumes: `ReminderSetting::TYPES` and the `enabled`/`days` columns (Task 1); `Invoice`, `Cheque`, `Agreement`, `InvoiceStatus`, `InvoiceType`, `ChequeDirection`, `ChequeStatus`, `AgreementStatus`.
- Produces:
  - `final readonly class ReminderItem(string $type, Invoice|Cheque|Agreement $record, ?int $ruleDay)` with `itemType(): string` (`'invoice'|'cheque'|'agreement'`) and `tenant(): Customer`.
  - `ReminderFinder::due(CarbonImmutable $today, ?int $customerId = null): array<int, list<ReminderItem>>`. Keys are customer ids. Only switched-on types appear, in urgency order. Records come with their `customer` loaded, invoices with `lines.unit.building` and agreements with `agreementUnits.unit.building`.
  - `ReminderFinder::manual(Invoice|Cheque|Agreement $record, CarbonImmutable $today): ?ReminderItem` (rule day null).
  - `ReminderFinder::RENEWAL_WINDOW = 90`.
  - `Agreement::renewals(): HasMany<Agreement>`.
  - Test helper `reminderTenant(array $attributes, string $end, ?Building $building = null): array{0: Customer, 1: Agreement}`. It makes an active agreement from 01/01/2026 to `$end` with 5 grace days, on a new unit, rent 400.

- [ ] **Step 1: Add the test helper.** In `tests/Pest.php` append:

```php
/** A tenant (Customer attributes) with an active agreement from 01/01/2026 to $end, 5 grace days, on a new unit (reminder tests). */
function reminderTenant(array $attributes, string $end, ?Building $building = null): array
{
    $tenant = Customer::factory()->create($attributes);
    $unit = $building === null ? Unit::factory()->create() : Unit::factory()->for($building)->create();
    $agreement = activeAgreement(['customer_id' => $tenant->id, 'start_date' => '2026-01-01', 'end_date' => $end, 'grace_days' => 5], [$unit]);

    return [$tenant, $agreement];
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Reminders/FinderTest.php`:

```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Cheques\EndCheque;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Reminders\ReminderFinder;
use App\Reminders\ReminderItem;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/** [type, item_type, item id, rule day] for each of the tenant's matches on the date, optionally of one type. */
function reminderMatches(Customer $tenant, string $date, ?string $type = null): array
{
    $items = ReminderFinder::due(CarbonImmutable::parse($date, 'Asia/Bahrain'))[$tenant->id] ?? [];

    return array_values(array_map(fn (ReminderItem $i) => [$i->type, $i->itemType(), $i->record->getKey(), $i->ruleDay],
        array_filter($items, fn (ReminderItem $i) => $type === null || $i->type === $type)));
}

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['default_grace_days' => 3]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    ReminderSetting::query()->update(['enabled' => true]);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    // Ends 09/01/2027: 60 days before is 10/11/2026, 30 days before is 10/12/2026.
    [$this->tenant, $this->agreement] = reminderTenant(['email' => 'alpha@tenant.test'], '2027-01-09');
    // Due 13/11 with the agreement's 5 grace days (grace until 18/11): rent due on 10/11, overdue from 19/11.
    $this->invoice = issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-13', $this->agreement);
});

test('rent due matches exactly its rule days before the due date', function (string $date, array $days) {
    expect(reminderMatches($this->tenant, $date, 'rent_due'))->toBe(array_map(fn (int $d) => ['rent_due', 'invoice', $this->invoice->id, $d], $days));
})->with([
    'the day before' => ['2026-11-09', []],
    'three days before' => ['2026-11-10', [3]],
    'the day after' => ['2026-11-11', []],
    'the due date' => ['2026-11-13', []],
]);

test('overdue counts from the end of the agreement\'s grace days', function (string $date, array $days) {
    expect(reminderMatches($this->tenant, $date, 'overdue'))->toBe(array_map(fn (int $d) => ['overdue', 'invoice', $this->invoice->id, $d], $days));
})->with([
    'last day of grace' => ['2026-11-18', []],
    'first day overdue' => ['2026-11-19', [1]],
    'second day overdue' => ['2026-11-20', []],
    'a week overdue' => ['2026-11-25', [7]],
    'two weeks overdue' => ['2026-12-02', [14]],
]);

test('an invoice without an agreement uses the company default grace days', function () {
    $manual = issuedInvoice($this->tenant, [['net' => '50.000', 'type' => 'other']], '2026-11-02'); // grace until 05/11 (3 days)

    expect(reminderMatches($this->tenant, '2026-11-05', 'overdue'))->toBe([])
        ->and(reminderMatches($this->tenant, '2026-11-06', 'overdue'))->toBe([['overdue', 'invoice', $manual->id, 1]]);
});

test('paid and fully credited invoices never match, nor do credit notes', function () {
    app(RecordPayment::class)->handle($this->finance, $this->tenant, ['received_on' => '2026-11-10', 'method' => 'cash', 'amount' => '400.000']);
    expect($this->invoice->fresh()->balance)->toBe('0.000')
        ->and(reminderMatches($this->tenant, '2026-11-10', 'rent_due'))->toBe([]);

    $other = issuedInvoice($this->tenant, [['net' => '200.000', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-13', $this->agreement);
    $cn = app(SaveCreditNote::class)->handle($this->finance, $other, null, ['reason' => 'Waived', 'lines' => [['credited_line_id' => $other->lines->first()->id, 'amount' => '200.000']]]);
    app(DecideApproval::class)->handle($this->management, app(SubmitCreditNote::class)->handle($this->finance, $cn), true);

    $invoiceMatches = collect(range(0, 40))
        ->flatMap(fn (int $d) => reminderMatches($this->tenant, CarbonImmutable::parse('2026-11-01')->addDays($d)->toDateString()))
        ->filter(fn (array $m) => $m[1] === 'invoice');
    expect($other->fresh()->balance)->toBe('0.000')->and($invoiceMatches->all())->toBe([]);
});

test('a held received cheque matches its rule days before the cheque date; a cancelled one does not', function () {
    [$held, $cancelled] = app(RecordCheques::class)->handle($this->finance, $this->tenant, $this->agreement, [
        ['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000'],
        ['cheque_no' => '502', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000'],
    ])->all();
    app(EndCheque::class)->handle($this->finance, $cancelled, 'cancelled');

    expect(reminderMatches($this->tenant, '2026-11-09', 'cheque'))->toBe([])
        ->and(reminderMatches($this->tenant, '2026-11-10', 'cheque'))->toBe([['cheque', 'cheque', $held->id, 2]])
        ->and(reminderMatches($this->tenant, '2026-11-11', 'cheque'))->toBe([]);
});

test('an active agreement matches 60 and 30 days before its end, unless a renewal exists or notice was given', function () {
    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toBe([['renewal', 'agreement', $this->agreement->id, 60]])
        ->and(reminderMatches($this->tenant, '2026-12-10', 'renewal'))->toBe([['renewal', 'agreement', $this->agreement->id, 30]])
        ->and(reminderMatches($this->tenant, '2026-11-11', 'renewal'))->toBe([]);

    $renewal = Agreement::factory()->create(['customer_id' => $this->tenant->id, 'previous_agreement_id' => $this->agreement->id,
        'start_date' => '2027-01-10', 'end_date' => '2028-01-09']); // a draft renewal is enough
    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toBe([]);

    $renewal->delete(); // a dropped draft is soft-deleted
    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toHaveCount(1);

    $this->agreement->forceFill(['notice_date' => '2026-11-01', 'planned_exit_date' => '2027-01-09'])->save();
    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toBe([]);
});

test('only switched-on types match, on the days the Admin chose', function () {
    ReminderSetting::query()->where('type', 'renewal')->update(['enabled' => false]);
    ReminderSetting::query()->where('type', 'rent_due')->sole()->update(['days' => [5]]);

    expect(reminderMatches($this->tenant, '2026-11-10'))->toBe([])
        ->and(reminderMatches($this->tenant, '2026-11-08'))->toBe([['rent_due', 'invoice', $this->invoice->id, 5]]);
});

test('matches are grouped by tenant in urgency order, include tenants who will be skipped, and can be found for one tenant', function () {
    $late = issuedInvoice($this->tenant, [['net' => '100.000', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-04', $this->agreement); // grace until 09/11
    [$beta] = reminderTenant(['email' => 'beta@tenant.test'], '2027-01-09');
    [$optedOut] = reminderTenant(['email' => null, 'no_reminders' => true], '2027-01-09');
    $today = CarbonImmutable::parse('2026-11-10', 'Asia/Bahrain');

    expect(reminderMatches($this->tenant, '2026-11-10'))->toBe([
        ['overdue', 'invoice', $late->id, 1], ['rent_due', 'invoice', $this->invoice->id, 3], ['renewal', 'agreement', $this->agreement->id, 60],
    ])
        ->and(array_keys(ReminderFinder::due($today)))->toEqualCanonicalizing([$this->tenant->id, $beta->id, $optedOut->id])
        ->and(array_keys(ReminderFinder::due($today, $beta->id)))->toBe([$beta->id]);
});

test('a manual reminder: rent due until grace ends, then overdue; held cheques; agreements within 90 days of their end; nothing once settled', function () {
    $on = fn (string $date) => CarbonImmutable::parse($date, 'Asia/Bahrain');

    expect(ReminderFinder::manual($this->invoice, $on('2026-11-18')))->type->toBe('rent_due')->ruleDay->toBeNull()
        ->and(ReminderFinder::manual($this->invoice, $on('2026-11-19'))->type)->toBe('overdue')
        ->and(ReminderFinder::manual($this->agreement, $on('2026-10-10')))->toBeNull()          // 91 days before the end
        ->and(ReminderFinder::manual($this->agreement, $on('2026-10-11'))->type)->toBe('renewal') // exactly 90
        ->and(ReminderFinder::manual($this->agreement, $on('2027-01-10')))->toBeNull();          // already ended

    $cheque = app(RecordCheques::class)->handle($this->finance, $this->tenant, $this->agreement,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-12-01', 'amount' => '400.000']])->first();
    expect(ReminderFinder::manual($cheque, $on('2026-11-10'))->type)->toBe('cheque');

    app(EndCheque::class)->handle($this->finance, $cheque, 'cancelled');
    app(RecordPayment::class)->handle($this->finance, $this->tenant, ['received_on' => '2026-11-10', 'method' => 'cash', 'amount' => '400.000']);
    expect(ReminderFinder::manual($cheque->fresh(), $on('2026-11-10')))->toBeNull()
        ->and(ReminderFinder::manual($this->invoice->fresh(), $on('2026-11-10')))->toBeNull();
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reminders/FinderTest.php`
Expected: FAIL with `Class "App\Reminders\ReminderFinder" not found`.

- [ ] **Step 4: The renewal relation.** In `app/Models/Agreement.php`, after `previous()`:

```php
    /** @return HasMany<Agreement, $this> agreements that renew this one (spec 2026-10-17 §3, plan ruling 2) */
    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'previous_agreement_id');
    }
```

- [ ] **Step 5: The item.** Create `app/Reminders/ReminderItem.php`:

```php
<?php

namespace App\Reminders;

use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use LogicException;

/** One record due a reminder: its type, the record, and the rule day it matched (null for a manual send). */
final readonly class ReminderItem
{
    /** @param  'overdue'|'rent_due'|'cheque'|'renewal'  $type */
    public function __construct(public string $type, public Invoice|Cheque|Agreement $record, public ?int $ruleDay) {}

    /** The record's kind as stored in reminder_sends.item_type. */
    public function itemType(): string
    {
        return match (true) {
            $this->record instanceof Invoice => 'invoice',
            $this->record instanceof Cheque => 'cheque',
            default => 'agreement',
        };
    }

    public function tenant(): Customer
    {
        return $this->record->customer ?? throw new LogicException('A reminder item always has a tenant.');
    }
}
```

- [ ] **Step 6: The finder.** Create `app/Reminders/ReminderFinder.php`:

```php
<?php

namespace App\Reminders;

use App\Enums\AgreementStatus;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\ReminderSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Spec 2026-10-17 §3: the invoices, cheques and agreements due a reminder. Reads only. */
final class ReminderFinder
{
    /** A manual renewal reminder is offered from this many days before the end date (spec §6). */
    public const int RENEWAL_WINDOW = 90;

    /** The date each type counts its rule days from: overdue counts after it (plan ruling 1), the others before. */
    private const array DATE_COLUMN = ['overdue' => 'grace_until', 'rent_due' => 'due_date', 'cheque' => 'cheque_date', 'renewal' => 'end_date'];

    /**
     * Every switched-on type's matches on $today, by tenant id, most urgent type first. An item matches when $today is
     * exactly one of its type's rule days from its date; a missed day is not caught up. Opted-out tenants and tenants
     * without email are included: the sender logs them as skipped.
     *
     * @return array<int, list<ReminderItem>>
     */
    public static function due(CarbonImmutable $today, ?int $customerId = null): array
    {
        $settings = ReminderSetting::query()->where('enabled', true)->get()->keyBy('type');
        $found = [];
        foreach (ReminderSetting::TYPES as $type) {
            foreach ($settings->get($type)->days ?? [] as $day) {
                $date = $type === 'overdue' ? $today->subDays($day) : $today->addDays($day);
                $records = self::base($type)->where(self::DATE_COLUMN[$type], $date->toDateString())
                    ->when($customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id))
                    ->orderBy('id')->get();
                foreach ($records as $record) {
                    $found[(int) $record->customer_id][] = new ReminderItem($type, $record, $day);
                }
            }
        }

        return $found;
    }

    /**
     * The reminder a person may send now for the record, whatever the settings (spec §6, plan ruling 9); null when the
     * record does not qualify.
     */
    public static function manual(Invoice|Cheque|Agreement $record, CarbonImmutable $today): ?ReminderItem
    {
        $type = match (true) {
            $record instanceof Invoice => $record->grace_until !== null && $record->grace_until->lt($today) ? 'overdue' : 'rent_due',
            $record instanceof Cheque => 'cheque',
            default => 'renewal',
        };
        $fresh = self::base($type)->whereKey($record->getKey())
            ->when($type === 'renewal', fn (Builder $q) => $q->whereBetween('end_date', [$today->toDateString(), $today->addDays(self::RENEWAL_WINDOW)->toDateString()]))
            ->first();

        return $fresh === null ? null : new ReminderItem($type, $fresh, null);
    }

    /**
     * What qualifies for the type whatever the date, with what the email needs loaded.
     *
     * @return Builder<Invoice>|Builder<Cheque>|Builder<Agreement>
     */
    private static function base(string $type): Builder
    {
        return match ($type) {
            'overdue', 'rent_due' => Invoice::query()->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote)
                ->where('balance', '>', 0)->whereHas('customer')->with(['customer', 'lines.unit.building']),
            'cheque' => Cheque::query()->where('direction', ChequeDirection::Received)->where('status', ChequeStatus::Held)
                ->whereHas('customer')->with('customer'),
            default => Agreement::query()->where('status', AgreementStatus::Active)->whereNull('notice_date')
                ->whereDoesntHave('renewals', fn (Builder $r) => $r->whereIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval, AgreementStatus::Active]))
                ->whereHas('customer')->with(['customer', 'agreementUnits.unit.building']),
        };
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Reminders/FinderTest.php`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `php vendor/bin/pint --dirty` and `php vendor/bin/phpstan analyse --memory-limit=2G`.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add app/Reminders/ReminderItem.php app/Reminders/ReminderFinder.php app/Models/Agreement.php tests/Pest.php tests/Feature/Reminders/FinderTest.php
git commit -m "$(cat <<'EOF'
Reminder matching: rule days per type, overdue from grace_until, held cheques, renewals without notice or renewal, manual qualification

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: The message — `Channel`, `EmailChannel`, the bilingual `TenantReminder`

**Files:**
- Create: `app/Reminders/Channel.php`, `app/Reminders/EmailChannel.php`
- Create: `app/Mail/TenantReminder.php`, `resources/views/mail/tenant-reminder.blade.php`
- Modify: `app/Providers/AppServiceProvider.php` (`register()`)
- Test: `tests/Feature/Reminders/EmailTest.php`

**Interfaces:**
- Consumes: `ReminderItem` (Task 2); `ReminderSetting::TYPES` and the intro columns, `ReminderSend::NO_EMAIL` (Task 1); `CompanySetting::current()`; `Customer::displayName()`; `Fils`.
- Produces:
  - `interface Channel { name(): string; ready(): bool; unavailableReason(Customer $tenant): ?string; send(Customer $tenant, list<ReminderItem> $items): void }`. `send()` throws on failure. It is bound to `EmailChannel` in the container.
  - `EmailChannel`. `name()` returns `'email'`. `ready()` is false while `mail.default` is `log` or `array`. `unavailableReason()` returns `ReminderSend::NO_EMAIL` when the email is blank.
  - `TenantReminder(Customer $tenant, list<ReminderItem> $items)`, a `Mailable` with public `$tenant` and `$items`, plus `subjectLine(): string`, `envelope()` and `content()`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Reminders/EmailTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Mail\TenantReminder;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ReminderSetting;
use App\Models\Unit;
use App\Reminders\Channel;
use App\Reminders\EmailChannel;
use App\Reminders\ReminderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    CompanySetting::factory()->create(['phone' => '+973 1700 0000', 'address_en' => 'Building 1, Road 2, Seef', 'address_ar' => 'مبنى 1، طريق 2، السيف']);
    config(['mail.from.address' => 'no-reply@rms.test']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->tenant = Customer::factory()->create(['name_en' => 'Ali <b>& Sons</b>', 'name_ar' => 'علي وأولاده', 'email' => 'ali@tenant.test']);
    $unit = Unit::factory()->for(Building::factory()->create(['name' => 'Seef Tower']))->create(['code' => '101']);
    $this->agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2027-01-09', 'grace_days' => 5], [$unit]);
    // Due 04/11 with 5 grace days (grace until 09/11): 1 day overdue today.
    $this->invoice = issuedInvoice($this->tenant, [['net' => '1250.500', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-04', $this->agreement);
    $this->cheque = (new Cheque)->forceFill(['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']);
    $this->item = fn (string $type) => match ($type) {
        'overdue' => new ReminderItem('overdue', $this->invoice, 1),
        'rent_due' => new ReminderItem('rent_due', $this->invoice, 3),
        'cheque' => new ReminderItem('cheque', $this->cheque, 2),
        default => new ReminderItem('renewal', $this->agreement, 60),
    };
    $this->mail = fn (string ...$types) => new TenantReminder($this->tenant, array_map($this->item, $types));
});

test('the subject names the most urgent type in English and Arabic, then the company', function (array $types, string $subject) {
    expect(($this->mail)(...$types)->subjectLine())->toBe($subject.' · Demo Properties W.L.L.');
})->with([
    'overdue beats everything' => [['renewal', 'cheque', 'rent_due', 'overdue'], 'Rent overdue · إيجار متأخر'],
    'rent due beats cheque and renewal' => [['renewal', 'cheque', 'rent_due'], 'Rent due soon · إيجار مستحق قريباً'],
    'cheque beats renewal' => [['renewal', 'cheque'], 'Cheque date coming up · اقتراب موعد الشيك'],
    'renewal alone' => [['renewal'], 'Agreement ending soon · اقتراب انتهاء العقد'],
]);

test('from the company name at MAIL_FROM_ADDRESS, replying to the company email', function () {
    ($this->mail)('overdue')->assertFrom('no-reply@rms.test', 'Demo Properties W.L.L.')->assertHasReplyTo('office@demo.test')
        ->assertHasSubject('Rent overdue · إيجار متأخر · Demo Properties W.L.L.');
});

test('English then Arabic: each type with its intro line and the figures from the records, dates dd/mm/yyyy', function () {
    $intro = ReminderSetting::query()->get()->keyBy('type');
    $n = $this->invoice->number;
    $ag = $this->agreement->number;

    ($this->mail)('renewal', 'cheque', 'overdue')->assertSeeInOrderInHtml([
        'dir="ltr"', e('Dear Ali <b>& Sons</b>,'),
        $intro['overdue']->intro_en, "Invoice {$n}, Seef Tower 101, due 04/11/2026, balance BHD 1,250.500, 1 day overdue",
        $intro['cheque']->intro_en, 'Cheque 501, NBB, dated 12/11/2026, BHD 400.000: please ensure funds are available.',
        $intro['renewal']->intro_en, "Agreement {$ag}, Seef Tower 101, ends 09/01/2027: please contact us to renew.",
        'dir="rtl"', 'عزيزي المستأجر علي وأولاده،',
        $intro['overdue']->intro_ar, "فاتورة {$n}، Seef Tower 101، تاريخ الاستحقاق 04/11/2026، الرصيد 1,250.500 د.ب، أيام التأخير 1",
        $intro['cheque']->intro_ar, 'شيك رقم 501، NBB، بتاريخ 12/11/2026، المبلغ 400.000 د.ب: يرجى التأكد من توفر الرصيد.',
        $intro['renewal']->intro_ar, "العقد {$ag}، Seef Tower 101، ينتهي في 09/01/2027: يرجى التواصل معنا للتجديد.",
    ], false);
});

test('names and the Admin\'s intro lines are escaped; the footer carries the company details', function () {
    ReminderSetting::query()->where('type', 'overdue')->sole()->update(['intro_en' => 'Pay <script>now</script> & thanks']);

    ($this->mail)('overdue')
        ->assertSeeInHtml('Dear Ali <b>& Sons</b>,') // assertSeeInHtml escapes the needle: this checks the escaped form
        ->assertDontSeeInHtml('<b>&', false)
        ->assertSeeInHtml('Pay <script>now</script> & thanks')
        ->assertDontSeeInHtml('<script>', false)
        ->assertSeeInOrderInHtml(['Demo Properties W.L.L.', '+973 1700 0000', 'office@demo.test', 'Building 1, Road 2, Seef', 'شركة ديمو للعقارات ذ.م.م', 'مبنى 1، طريق 2، السيف']);
});

test('the Arabic greeting falls back to the English name', function () {
    $this->tenant->update(['name_ar' => null]);

    ($this->mail)('renewal')->assertSeeInHtml('عزيزي المستأجر Ali <b>& Sons</b>،');
});

test('the email channel: ready only with a real mailer; no address means "No email"; it sends one combined email', function () {
    $channel = app(Channel::class);
    expect($channel)->toBeInstanceOf(EmailChannel::class);

    foreach (['log' => false, 'array' => false, 'smtp' => true] as $mailer => $ready) {
        config(['mail.default' => $mailer]);
        expect($channel->ready())->toBe($ready, $mailer);
    }
    expect($channel->name())->toBe('email')
        ->and($channel->unavailableReason($this->tenant))->toBeNull()
        ->and($channel->unavailableReason(Customer::factory()->make(['email' => null])))->toBe('No email')
        ->and($channel->unavailableReason(Customer::factory()->make(['email' => ' '])))->toBe('No email');

    Mail::fake();
    $channel->send($this->tenant, [($this->item)('overdue'), ($this->item)('renewal')]);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('ali@tenant.test') && count($m->items) === 2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reminders/EmailTest.php`
Expected: FAIL with `Class "App\Mail\TenantReminder" not found`.

- [ ] **Step 3: The channel.** Create `app/Reminders/Channel.php`:

```php
<?php

namespace App\Reminders;

use App\Models\Customer;

/**
 * Spec 2026-10-17 §7: how a combined reminder reaches a tenant. Email now. A WhatsApp channel later implements the same
 * four methods, so the rules, the log and the screens do not change.
 */
interface Channel
{
    /** Stored in reminder_sends.channel. */
    public function name(): string;

    /** False while the channel cannot send at all; nothing is sent then. */
    public function ready(): bool;

    /** Why this tenant cannot be reached on this channel (a ReminderSend reason code), or null. */
    public function unavailableReason(Customer $tenant): ?string;

    /**
     * Sends one message with every item. Throws when it could not be sent.
     *
     * @param  list<ReminderItem>  $items
     */
    public function send(Customer $tenant, array $items): void;
}
```

Create `app/Reminders/EmailChannel.php`:

```php
<?php

namespace App\Reminders;

use App\Mail\TenantReminder;
use App\Models\Customer;
use App\Models\ReminderSend;
use Illuminate\Support\Facades\Mail;

/** Email, to the address on the tenant record only (spec 2026-10-17 §9). */
final class EmailChannel implements Channel
{
    public function name(): string
    {
        return 'email';
    }

    /** Spec §5: mail is not configured while MAIL_MAILER is log or array. */
    public function ready(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function unavailableReason(Customer $tenant): ?string
    {
        return filled($tenant->email) ? null : ReminderSend::NO_EMAIL;
    }

    public function send(Customer $tenant, array $items): void
    {
        Mail::to((string) $tenant->email, $tenant->name_en)->send(new TenantReminder($tenant, $items));
    }
}
```

In `app/Providers/AppServiceProvider.php` add `use App\Reminders\Channel;` and `use App\Reminders\EmailChannel;`, and replace the body of `register()` (`//`) with:

```php
        $this->app->bind(Channel::class, EmailChannel::class); // spec 2026-10-17 §7: WhatsApp later
```

- [ ] **Step 4: The mailable.** Create `app/Mail/TenantReminder.php`:

```php
<?php

namespace App\Mail;

use App\Models\AgreementUnit;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ReminderSetting;
use App\Models\Unit;
use App\Reminders\ReminderItem;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Spec 2026-10-17 §4: one email per tenant per run, English then Arabic (RTL). Every figure comes from the records at
 * send time; Blade escapes names and intro lines. A plain HTML view, not markdown (plan ruling 11).
 */
class TenantReminder extends Mailable
{
    /** @param  list<ReminderItem>  $items */
    public function __construct(public Customer $tenant, public array $items) {}

    public function envelope(): Envelope
    {
        $company = CompanySetting::current();

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $company->name_en),
            replyTo: filled($company->email) ? [new Address((string) $company->email, $company->name_en)] : [],
            subject: $this->subjectLine(),
        );
    }

    /** The most urgent type present (overdue → rent due → cheque → renewal), in English · Arabic, then the company. */
    public function subjectLine(): string
    {
        $present = array_map(fn (ReminderItem $i) => $i->type, $this->items);
        $type = collect(ReminderSetting::TYPES)->first(fn (string $t) => in_array($t, $present, true));
        [$en, $ar] = match ($type) {
            'overdue' => [__('Rent overdue'), 'إيجار متأخر'],
            'rent_due' => [__('Rent due soon'), 'إيجار مستحق قريباً'],
            'cheque' => [__('Cheque date coming up'), 'اقتراب موعد الشيك'],
            default => [__('Agreement ending soon'), 'اقتراب انتهاء العقد'],
        };

        return implode(' · ', [$en, $ar, CompanySetting::current()->name_en]);
    }

    public function content(): Content
    {
        $company = CompanySetting::current();
        $settings = ReminderSetting::query()->get()->keyBy('type');
        $today = CarbonImmutable::today('Asia/Bahrain');
        $sections = [];
        foreach (ReminderSetting::TYPES as $type) {
            $items = array_values(array_filter($this->items, fn (ReminderItem $i) => $i->type === $type));
            if ($items !== []) {
                $setting = $settings->get($type);
                $sections[] = ['intro_en' => $setting->intro_en ?? '', 'intro_ar' => $setting->intro_ar ?? '',
                    'lines' => array_map(fn (ReminderItem $i) => self::line($i, $today), $items)];
            }
        }

        return new Content(view: 'mail.tenant-reminder', with: [
            'subjectLine' => $this->subjectLine(),
            'nameEn' => $this->tenant->name_en,
            'nameAr' => $this->tenant->displayName('ar'),
            'sections' => $sections,
            'company' => ['name_en' => $company->name_en, 'name_ar' => $company->name_ar, 'phone' => $company->phone, 'email' => $company->email,
                'address_en' => $company->address_en, 'address_ar' => $company->address_ar],
        ]);
    }

    /** @return array{en: string, ar: string} one item's line, built from the record */
    private static function line(ReminderItem $item, CarbonImmutable $today): array
    {
        $r = $item->record;
        if ($r instanceof Invoice) {
            $units = self::units($r->lines->map(fn (InvoiceLine $line) => $line->unit));
            $balance = Fils::grouped(Fils::fromDecimal($r->balance));
            $due = $r->due_date->format('d/m/Y');
            $days = $item->type === 'overdue' && $r->grace_until !== null ? (int) $r->grace_until->diffInDays($today) : null; // plan ruling 1

            return [
                'en' => implode(', ', array_filter([__('Invoice :number', ['number' => $r->label()]), $units, __('due :date', ['date' => $due]),
                    __('balance BHD :amount', ['amount' => $balance]), $days === null ? null : trans_choice(':count day overdue|:count days overdue', $days)])),
                'ar' => implode('، ', array_filter(['فاتورة '.$r->label(), $units, 'تاريخ الاستحقاق '.$due, 'الرصيد '.$balance.' د.ب',
                    $days === null ? null : 'أيام التأخير '.$days])),
            ];
        }
        if ($r instanceof \App\Models\Cheque) {
            $amount = Fils::grouped(Fils::fromDecimal($r->amount));
            $date = $r->cheque_date->format('d/m/Y');

            return [
                'en' => __('Cheque :number, :bank, dated :date, BHD :amount: please ensure funds are available.',
                    ['number' => $r->cheque_no, 'bank' => $r->bank_name, 'date' => $date, 'amount' => $amount]),
                'ar' => 'شيك رقم '.$r->cheque_no.'، '.$r->bank_name.'، بتاريخ '.$date.'، المبلغ '.$amount.' د.ب: يرجى التأكد من توفر الرصيد.',
            ];
        }
        $units = self::units($r->agreementUnits->map(fn (AgreementUnit $au) => $au->unit));
        $end = $r->end_date->format('d/m/Y');

        return [
            'en' => implode(', ', array_filter([__('Agreement :number', ['number' => $r->label()]), $units, __('ends :date', ['date' => $end])]))
                .': '.__('please contact us to renew.'),
            'ar' => implode('، ', array_filter(['العقد '.$r->label(), $units, 'ينتهي في '.$end])).': يرجى التواصل معنا للتجديد.',
        ];
    }

    /**
     * "Seef Tower 101 / Seef Tower 102"; '' when there are none (a manual invoice without units).
     *
     * @param  Collection<int, Unit|null>  $units
     */
    private static function units(Collection $units): string
    {
        return $units->filter()->map(fn (Unit $u) => $u->building->name.' '.$u->code)->unique()->implode(' / ');
    }
}
```

Import `App\Models\Cheque` at the top and write `$r instanceof Cheque` (the `\App\Models\Cheque` above only shows the class; Pint keeps the import form).

- [ ] **Step 5: The view.** Create `resources/views/mail/tenant-reminder.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin: 0; padding: 24px; background: #ffffff; color: #18181b; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.6;">
    <div dir="ltr" lang="en" style="text-align: left;">
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        @foreach ($sections as $section)
            <p>{{ $section['intro_en'] }}</p>
            <ul>
                @foreach ($section['lines'] as $line)
                    <li>{{ $line['en'] }}</li>
                @endforeach
            </ul>
        @endforeach
        <p>{{ __('Thank you.') }}</p>
    </div>

    <hr style="border: 0; border-top: 1px solid #e4e4e7; margin: 24px 0;">

    <div dir="rtl" lang="ar" style="text-align: right;">
        <p>عزيزي المستأجر {{ $nameAr }}،</p>
        @foreach ($sections as $section)
            <p>{{ $section['intro_ar'] }}</p>
            <ul>
                @foreach ($section['lines'] as $line)
                    <li>{{ $line['ar'] }}</li>
                @endforeach
            </ul>
        @endforeach
        <p>شكراً لكم.</p>
    </div>

    <hr style="border: 0; border-top: 1px solid #e4e4e7; margin: 24px 0;">

    <div style="font-size: 13px; color: #52525b;">
        <p style="margin: 0;">{{ $company['name_en'] }}</p>
        @if (filled($company['phone']))<p style="margin: 0;">{{ $company['phone'] }}</p>@endif
        @if (filled($company['email']))<p style="margin: 0;">{{ $company['email'] }}</p>@endif
        @if (filled($company['address_en']))<p style="margin: 0;">{{ $company['address_en'] }}</p>@endif
        @if (filled($company['name_ar']) || filled($company['address_ar']))
            <p dir="rtl" lang="ar" style="margin: 8px 0 0; text-align: right;">{{ $company['name_ar'] }}@if (filled($company['name_ar']) && filled($company['address_ar'])) · @endif{{ $company['address_ar'] }}</p>
        @endif
    </div>
</body>
</html>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Reminders/EmailTest.php`
Expected: PASS.

- [ ] **Step 7: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add app/Reminders/Channel.php app/Reminders/EmailChannel.php app/Mail/TenantReminder.php resources/views/mail/tenant-reminder.blade.php \
  app/Providers/AppServiceProvider.php tests/Feature/Reminders/EmailTest.php
git commit -m "$(cat <<'EOF'
Reminder message: Channel interface, EmailChannel, bilingual combined email with the urgency subject, company footer and escaping

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: The daily run — sender, per-tenant job, command, schedule and heartbeat

**Files:**
- Create: `app/Reminders/ReminderSender.php`
- Create: `app/Jobs/SendTenantReminders.php`
- Create: `app/Console/Commands/SendTenantRemindersCommand.php`
- Modify: `routes/console.php`, `config/services.php`, `.env.example`, `docs/new-company-setup.md`
- Test: `tests/Feature/Reminders/DailyRunTest.php`

**Interfaces:**
- Consumes: `ReminderFinder::due` and `ReminderItem` (Task 2); `Channel` and `TenantReminder` (Task 3); `ReminderSend` and its constants, `ReminderSetting` (Task 1); `Audit::log`.
- Produces:
  - `ReminderSender::__construct(Channel $channel)`.
  - `ReminderSender::refusal(Customer $tenant): ?string`, returning a `ReminderSend` reason code or null.
  - `ReminderSender::send(Customer $tenant, list<ReminderItem> $items, CarbonImmutable $today, ?User $by = null): ?string`. It returns `'sent'`, `'failed'` or `'skipped'`, or null when every item was already logged.
  - `SendTenantReminders(int $customerId, string $date)`. Public `$tries = 1`, `$timeout = 120`, `$failOnTimeout = true`, plus `handle(ReminderSender)` and `failed(Throwable)`.
  - The command `rms:reminders:send`, scheduled at 08:00 daily.
  - The config key `services.forge.heartbeats.tenant_reminders`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Reminders/DailyRunTest.php`:

```php
<?php

use App\Actions\Cheques\EndCheque;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Jobs\SendTenantReminders;
use App\Mail\TenantReminder;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ReminderSend;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Reminders\Channel;
use App\Reminders\EmailChannel;
use App\Reminders\ReminderItem;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['phone' => '+973 1700 0000', 'address_en' => 'Seef, Manama']);
    config(['mail.default' => 'smtp']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    ReminderSetting::query()->update(['enabled' => true]);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    // Alpha has one of every type today: overdue day 1, rent due in 3 days, a cheque in 2 days, the agreement ends in 60 days.
    [$this->alpha, $agA] = reminderTenant(['name_en' => 'Alpha Tenant', 'email' => 'alpha@tenant.test'], '2027-01-09');
    $au = $agA->agreementUnits->first();
    $this->overdue = issuedInvoice($this->alpha, [['net' => '400.000', 'au' => $au]], '2026-11-04', $agA); // grace until 09/11
    $this->due = issuedInvoice($this->alpha, [['net' => '400.000', 'au' => $au]], '2026-11-13', $agA);
    $this->cheque = app(RecordCheques::class)->handle($this->finance, $this->alpha, $agA,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']])->first();
    // Beta, Gamma (no email) and Delta (opted out): rent due in 3 days only.
    foreach (['beta' => ['email' => 'beta@tenant.test'], 'gamma' => ['email' => null], 'delta' => ['email' => 'delta@tenant.test', 'no_reminders' => true]] as $key => $attrs) {
        [$this->{$key}, $agreement] = reminderTenant(['name_en' => ucfirst($key).' Tenant', ...$attrs], '2027-08-31');
        issuedInvoice($this->{$key}, [['net' => '250.000', 'au' => $agreement->agreementUnits->first()]], '2026-11-13', $agreement);
    }
});

test('one combined email per tenant, subject by urgency; skips are logged with their reason; each sent message is audited', function () {
    $this->artisan('rms:reminders:send')->expectsOutputToContain('4 tenant(s) to remind.')->assertSuccessful();

    Mail::assertSentCount(2);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('alpha@tenant.test')
        && $m->subjectLine() === 'Rent overdue · إيجار متأخر · Demo Properties W.L.L.' && count($m->items) === 4);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('beta@tenant.test')
        && $m->subjectLine() === 'Rent due soon · إيجار مستحق قريباً · Demo Properties W.L.L.');

    $alphaRows = ReminderSend::query()->where('customer_id', $this->alpha->id)->orderBy('id')->get();
    expect($alphaRows->map(fn (ReminderSend $r) => [$r->type, $r->rule_day, $r->status, $r->manual])->all())
        ->toBe([['overdue', 1, 'sent', false], ['rent_due', 3, 'sent', false], ['cheque', 2, 'sent', false], ['renewal', 60, 'sent', false]])
        ->and($alphaRows->pluck('batch')->unique())->toHaveCount(1)
        ->and(ReminderSend::query()->where('customer_id', $this->gamma->id)->sole())->status->toBe('skipped')->reason->toBe('No email')
        ->and(ReminderSend::query()->where('customer_id', $this->delta->id)->sole())->status->toBe('skipped')->reason->toBe('Opted out')
        ->and(ReminderSend::count())->toBe(7);

    $audits = Activity::query()->where('event', 'reminder.sent')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->firstWhere('subject_id', $this->alpha->id)->properties['counts'])->toEqual(['overdue' => 1, 'rent_due' => 1, 'cheque' => 1, 'renewal' => 1])
        ->and($audits->firstWhere('subject_id', $this->alpha->id)->subject_type)->toBe($this->alpha->getMorphClass());
});

test('never twice: a second run the same day sends and logs nothing more', function () {
    $this->artisan('rms:reminders:send')->assertSuccessful();
    $this->artisan('rms:reminders:send')->assertSuccessful();

    Mail::assertSentCount(2);
    expect(ReminderSend::count())->toBe(7)->and(Activity::query()->where('event', 'reminder.sent')->count())->toBe(2);
});

test('one job per tenant; an item that stopped qualifying before its job ran is dropped from the email', function () {
    Queue::fake();
    $this->artisan('rms:reminders:send')->assertSuccessful();

    Queue::assertPushed(SendTenantReminders::class, 4);
    Queue::assertPushed(SendTenantReminders::class, fn (SendTenantReminders $job) => $job->customerId === $this->alpha->id && $job->date === '2026-11-10');

    app(EndCheque::class)->handle($this->finance, $this->cheque, 'cancelled');
    app()->call([new SendTenantReminders($this->alpha->id, '2026-11-10'), 'handle']);

    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('alpha@tenant.test')
        && array_map(fn (ReminderItem $i) => $i->type, $m->items) === ['overdue', 'rent_due', 'renewal']);
    expect(ReminderSend::query()->where('item_type', 'cheque')->exists())->toBeFalse();
});

test('nothing is sent while every type is off or mail is not set up', function (string $case, string $message) {
    match ($case) {
        'off' => ReminderSetting::query()->update(['enabled' => false]),
        default => config(['mail.default' => $case]),
    };

    $this->artisan('rms:reminders:send')->expectsOutputToContain($message)->assertSuccessful();

    Mail::assertNothingSent();
    expect(ReminderSend::count())->toBe(0);
})->with([
    'all types off' => ['off', 'No reminder type is switched on'],
    'mail to the log' => ['log', 'Mail is not set up'],
    'mail to an array' => ['array', 'Mail is not set up'],
]);

test('only switched-on types are sent', function () {
    ReminderSetting::query()->where('type', '!=', 'renewal')->update(['enabled' => false]);

    $this->artisan('rms:reminders:send')->assertSuccessful();

    Mail::assertSentCount(1);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('alpha@tenant.test')
        && $m->subjectLine() === 'Agreement ending soon · اقتراب انتهاء العقد · Demo Properties W.L.L.');
});

test('one tenant\'s mail failure is recorded as failed, the others are still sent, and it is not retried', function () {
    app()->bind(Channel::class, fn () => new class(new EmailChannel) implements Channel
    {
        public function __construct(private EmailChannel $email) {}

        public function name(): string
        {
            return 'email';
        }

        public function ready(): bool
        {
            return true;
        }

        public function unavailableReason(Customer $tenant): ?string
        {
            return $this->email->unavailableReason($tenant);
        }

        public function send(Customer $tenant, array $items): void
        {
            throw_if($tenant->email === 'alpha@tenant.test', RuntimeException::class, 'SMTP said no');
            $this->email->send($tenant, $items);
        }
    });

    $this->artisan('rms:reminders:send')->assertSuccessful();
    $this->artisan('rms:reminders:send')->assertSuccessful();

    Mail::assertSentCount(1);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('beta@tenant.test'));
    expect(ReminderSend::query()->where('customer_id', $this->alpha->id)->pluck('status')->unique()->all())->toBe(['failed'])
        ->and(ReminderSend::query()->where('customer_id', $this->alpha->id)->pluck('reason')->unique()->all())->toBe(['SMTP said no'])
        ->and(Activity::query()->where('event', 'reminder.sent')->count())->toBe(1);
});

test('a job killed mid-send marks its claimed rows failed', function () {
    ReminderSend::create(['customer_id' => $this->alpha->id, 'type' => 'overdue', 'item_type' => 'invoice', 'item_id' => $this->overdue->id, 'rule_day' => 1,
        'sent_on' => '2026-11-10', 'channel' => 'email', 'status' => 'sending', 'batch' => 'b1']);

    (new SendTenantReminders($this->alpha->id, '2026-11-10'))->failed(new RuntimeException('Timed out'));

    expect(ReminderSend::sole())->status->toBe('failed')->reason->toBe('Timed out');
});

test('the run is scheduled daily at 08:00 without overlap; each job runs once within 120 seconds', function () {
    $event = scheduledEvent('rms:reminders:send');
    $job = new SendTenantReminders(1, '2026-11-10');

    expect($event->expression)->toBe('0 8 * * *')->and($event->withoutOverlapping)->toBeTrue()
        ->and(config('app.timezone'))->toBe('Asia/Bahrain')
        ->and($job->tries)->toBe(1)->and($job->timeout)->toBe(120)->and($job->failOnTimeout)->toBeTrue()
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan(120);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reminders/DailyRunTest.php`
Expected: FAIL. The first tests fail with `The command "rms:reminders:send" does not exist.`; the job tests with `Class "App\Jobs\SendTenantReminders" not found`.

- [ ] **Step 3: The sender.** Create `app/Reminders/ReminderSender.php`:

```php
<?php

namespace App\Reminders;

use App\Audit\Audit;
use App\Models\Customer;
use App\Models\ReminderSend;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spec 2026-10-17 §5: logs one row per item, then sends the combined message once through the channel. Automatic rows
 * are unique per tenant, type, item, rule day and date, so a rerun finds them and nothing goes twice. Reads money
 * records only; writes only reminder_sends.
 */
final class ReminderSender
{
    public function __construct(private readonly Channel $channel) {}

    /** Why nothing can be sent to this tenant now (a ReminderSend reason code), or null. */
    public function refusal(Customer $tenant): ?string
    {
        return match (true) {
            $tenant->no_reminders => ReminderSend::OPTED_OUT,
            ! $this->channel->ready() => ReminderSend::MAIL_NOT_SET_UP,
            default => $this->channel->unavailableReason($tenant),
        };
    }

    /**
     * @param  list<ReminderItem>  $items  all for $tenant
     * @param  User|null  $by  the person sending by hand; null for the daily run
     * @return string|null 'sent', 'failed' or 'skipped'; null when every item was already logged
     */
    public function send(Customer $tenant, array $items, CarbonImmutable $today, ?User $by = null): ?string
    {
        $reason = $this->refusal($tenant);
        $batch = (string) Str::uuid();
        $kept = [];
        foreach ($items as $item) {
            try {
                ReminderSend::create([
                    'customer_id' => $tenant->id, 'type' => $item->type, 'item_type' => $item->itemType(), 'item_id' => $item->record->getKey(),
                    'rule_day' => $item->ruleDay, 'sent_on' => $today->toDateString(), 'channel' => $this->channel->name(),
                    'status' => $reason === null ? 'sending' : 'skipped', 'reason' => $reason,
                    'manual' => $by !== null, 'sent_by' => $by?->id, 'batch' => $batch,
                ]);
                $kept[] = $item;
            } catch (UniqueConstraintViolationException) {
                // Already logged for this rule today (a rerun): never twice.
            }
        }
        if ($kept === []) {
            return null;
        }
        if ($reason !== null) {
            return 'skipped';
        }

        try {
            $this->channel->send($tenant, $kept);
            $status = 'sent';
            $error = null;
        } catch (Throwable $e) {
            report($e);
            $status = 'failed';
            $error = Str::limit($e->getMessage(), 490);
        }
        ReminderSend::query()->where('batch', $batch)->update(['status' => $status, 'reason' => $error]);

        if ($status === 'sent') { // plan ruling 7
            Audit::log('reminder.sent', $tenant, properties: $by === null
                ? ['batch' => $batch, 'counts' => array_count_values(array_map(fn (ReminderItem $i) => $i->type, $kept))]
                : ['batch' => $batch, 'manual' => true, 'type' => $kept[0]->type, 'item_type' => $kept[0]->itemType(), 'item_id' => $kept[0]->record->getKey()],
                causer: $by);
        }

        return $status;
    }
}
```

- [ ] **Step 4: The job.** Create `app/Jobs/SendTenantReminders.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\ReminderSend;
use App\Reminders\ReminderFinder;
use App\Reminders\ReminderSender;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/** Spec 2026-10-17 §5: one tenant's reminders for one run date. A failure here never touches another tenant's job. */
class SendTenantReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    /** One attempt only: a retry could email the tenant twice. A failure stays `failed` in the log (spec §5). */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $customerId, public string $date) {}

    public function handle(ReminderSender $sender): void
    {
        $tenant = Customer::query()->find($this->customerId);
        if ($tenant === null) {
            return;
        }
        $today = CarbonImmutable::parse($this->date, 'Asia/Bahrain');
        // Found again now: items paid, deposited, renewed or given notice since the command ran drop out (plan ruling 18).
        $items = ReminderFinder::due($today, $tenant->id)[$tenant->id] ?? [];
        if ($items !== []) {
            $sender->send($tenant, $items, $today);
        }
    }

    /** A killed or timed-out job must not leave its rows in `sending`. */
    public function failed(Throwable $e): void
    {
        ReminderSend::query()->where('customer_id', $this->customerId)->where('sent_on', $this->date)->where('status', 'sending')
            ->update(['status' => 'failed', 'reason' => Str::limit($e->getMessage(), 490)]);
    }
}
```

- [ ] **Step 5: The command.** Create `app/Console/Commands/SendTenantRemindersCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\SendTenantReminders;
use App\Models\ReminderSetting;
use App\Reminders\Channel;
use App\Reminders\ReminderFinder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Spec 2026-10-17 §5: queue one reminder job per tenant with something due today. Reads only; the jobs log and send. */
class SendTenantRemindersCommand extends Command
{
    protected $signature = 'rms:reminders:send';

    protected $description = 'Email tenants today\'s rent due, overdue, cheque and renewal reminders.';

    public function handle(Channel $channel): int
    {
        if (! ReminderSetting::query()->where('enabled', true)->exists()) {
            $this->info('No reminder type is switched on; nothing sent.');

            return self::SUCCESS;
        }
        if (! $channel->ready()) {
            $this->warn('Mail is not set up (MAIL_MAILER is log or array); nothing sent.');

            return self::SUCCESS;
        }

        $today = CarbonImmutable::today('Asia/Bahrain');
        // ponytail: loads every match once just to learn the tenants; fine for one company's records, query ids only if it grows.
        $tenants = array_keys(ReminderFinder::due($today));
        foreach ($tenants as $id) {
            SendTenantReminders::dispatch($id, $today->toDateString());
        }
        $this->info(sprintf('%d tenant(s) to remind.', count($tenants)));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Schedule, heartbeat and docs.** In `routes/console.php`, append:

```php

Schedule::command('rms:reminders:send')->dailyAt('08:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.tenant_reminders')), (string) $url);
```

In `config/services.php`, after `'report_schedules' => env('HEARTBEAT_REPORT_SCHEDULES'),`:

```php
            'tenant_reminders' => env('HEARTBEAT_TENANT_REMINDERS'),
```

In `.env.example`, after `HEARTBEAT_REPORT_SCHEDULES=`:

```
HEARTBEAT_TENANT_REMINDERS=
```

In `docs/new-company-setup.md`, change `| \`HEARTBEAT_*\` (13 keys) |` to `| \`HEARTBEAT_*\` (14 keys) |`. Then add this row to Appendix C after the `rms:reports:send-scheduled` row:

```
| `php artisan rms:reminders:send` | Emails today's tenant reminders (also runs daily at 08:00). Does nothing until a type is switched on under Administration → Tenant reminders, or while `MAIL_MAILER` is `log` or `array`. |
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Reminders/DailyRunTest.php tests/Feature/Backup/ScheduleTest.php`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `php vendor/bin/pint --dirty` and `php vendor/bin/phpstan analyse --memory-limit=2G`.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add app/Reminders/ReminderSender.php app/Jobs/SendTenantReminders.php app/Console/Commands/SendTenantRemindersCommand.php routes/console.php \
  config/services.php .env.example docs/new-company-setup.md tests/Feature/Reminders/DailyRunTest.php
git commit -m "$(cat <<'EOF'
Daily tenant reminders: rms:reminders:send at 08:00, one job per tenant, never twice, skips and failures logged, audited, heartbeat

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: "Send reminder" with preview on invoice, cheque and agreement pages; the tenant opt-out switch

**Files:**
- Create: `app/Livewire/Reminders/RecordPanel.php`, `resources/views/livewire/reminders/record-panel.blade.php`
- Modify: `resources/views/livewire/invoices/show.blade.php` (before the closing `</section>`)
- Modify: `resources/views/livewire/cheques/show.blade.php` (before the documents panel)
- Modify: `resources/views/livewire/agreements/show.blade.php` (before the documents panel)
- Modify: `app/Actions/Customers/SaveCustomer.php` (rule), `app/Livewire/Customers/Form.php` (form defaults), `resources/views/livewire/customers/form.blade.php` (switch)
- Test: `tests/Feature/Reminders/SendButtonTest.php`

**Interfaces:**
- Consumes: `ReminderFinder::manual` (Task 2); `ReminderSender::refusal` and `send` (Task 4); `TenantReminder::subjectLine` and `render` (Task 3); `ReminderSend::reasonText` (Task 1); the `view` policies of `Invoice`, `Cheque`, `Agreement` and `Customer`.
- Produces:
  - `<livewire:reminders.record-panel :record="$model" />`. It accepts an `Invoice`, `Cheque`, `Agreement` or `Customer`, and holds `#[Locked] string $kind` and `#[Locked] int $recordId`.
  - Its only action is `send(ReminderSender $sender): void`. Errors go to the key `reminder`.
  - View data: `sendable` bool, `refusal` ?string (in words), `email`, `subject`, `previewHtml` ?string.
  - `SaveCustomer` accepts `no_reminders` (`sometimes|boolean`). The tenant form holds `form.no_reminders`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Reminders/SendButtonTest.php`:

```php
<?php

use App\Actions\Cheques\EndCheque;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Customers\Form;
use App\Livewire\Reminders\RecordPanel;
use App\Mail\TenantReminder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReminderSend;
use App\Models\ReminderSetting;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    // Every reminder type is off (the install default). The agreement ends in 60 days.
    [$this->tenant, $this->agreement] = reminderTenant(['email' => 'alpha@tenant.test'], '2027-01-09', $this->a);
    $this->invoice = issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-13', $this->agreement);
    $this->cheque = app(RecordCheques::class)->handle($this->finance, $this->tenant, $this->agreement,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']])->first();
});

test('finance previews the exact email and sends it; it is logged as manual by the sender, with every type off', function () {
    $page = Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invoice])
        ->assertSee('Send reminder')->assertSee('Rent due soon · إيجار مستحق قريباً · Demo Properties W.L.L.')->assertSee('alpha@tenant.test');
    $preview = $page->viewData('previewHtml');

    $page->call('send')->assertHasNoErrors();

    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('alpha@tenant.test') && $m->render() === $preview);
    expect(ReminderSend::sole())->manual->toBeTrue()->sent_by->toBe($this->finance->id)->rule_day->toBeNull()
        ->type->toBe('rent_due')->item_type->toBe('invoice')->item_id->toBe($this->invoice->id)->status->toBe('sent');
    $audit = Activity::query()->where('event', 'reminder.sent')->sole();
    expect($audit->properties->only(['manual', 'type', 'item_type', 'item_id'])->all())
        ->toEqual(['manual' => true, 'type' => 'rent_due', 'item_type' => 'invoice', 'item_id' => $this->invoice->id])
        ->and($audit->causer_id)->toBe($this->finance->id);
});

test('a manual send does not stop the automatic reminder the same day', function () {
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invoice])->call('send')->assertHasNoErrors();
    ReminderSetting::query()->where('type', 'rent_due')->update(['enabled' => true]);

    $this->artisan('rms:reminders:send')->assertSuccessful();

    Mail::assertSentCount(2);
    expect(ReminderSend::query()->where('item_type', 'invoice')->orderBy('id')->pluck('manual')->all())->toBe([true, false]);
});

test('a held cheque and an agreement ending within 90 days each send their own type', function () {
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->cheque])
        ->assertSee('Cheque date coming up')->call('send')->assertHasNoErrors();
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->agreement])
        ->assertSee('Agreement ending soon')->call('send')->assertHasNoErrors();

    expect(ReminderSend::query()->orderBy('id')->pluck('type')->all())->toBe(['cheque', 'renewal']);
});

test('no button, and send refuses, where there is nothing to remind about', function () {
    [, $farOff] = reminderTenant(['email' => 'beta@tenant.test'], '2027-08-31', $this->a); // ends in more than 90 days
    app(EndCheque::class)->handle($this->finance, $this->cheque, 'cancelled');

    foreach ([$farOff, $this->cheque->fresh()] as $record) {
        Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $record])->assertDontSee('Send reminder')
            ->call('send')->assertHasErrors('reminder');
    }
    Mail::assertNothingSent();
});

test('opted-out tenants, tenants without email and mail not set up disable the button with the reason', function (string $case, string $reason) {
    match ($case) {
        'opted out' => $this->tenant->update(['no_reminders' => true]),
        'no email' => $this->tenant->update(['email' => null]),
        default => config(['mail.default' => 'log']),
    };

    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invoice])
        ->assertSee('Send reminder')->assertSee("Can't send: {$reason}")->assertViewHas('previewHtml', null)
        ->call('send')->assertHasErrors('reminder');

    Mail::assertNothingSent();
    expect(ReminderSend::count())->toBe(0);
})->with([
    ['opted out', 'The tenant has opted out of reminders.'],
    ['no email', 'The tenant has no email address.'],
    ['mail not set up', 'Email is not set up.'],
]);

test('permission and building scope: leasing sends renewals in its own buildings only, never invoice reminders', function () {
    $leasingA = matrixUser(RoleName::Leasing, $this->a);
    $leasingB = matrixUser(RoleName::Leasing, $this->b);

    Livewire::actingAs($leasingA)->test(RecordPanel::class, ['record' => $this->agreement])->assertSee('Send reminder')->call('send')->assertHasNoErrors();
    Livewire::actingAs($leasingB)->test(RecordPanel::class, ['record' => $this->agreement])->assertForbidden();
    Livewire::actingAs($leasingA)->test(RecordPanel::class, ['record' => $this->invoice])->assertForbidden();

    expect(ReminderSend::sole()->sent_by)->toBe($leasingA->id);
});

test('the invoice, cheque and agreement pages carry the panel', function () {
    $this->actingAs($this->finance)->get(route('invoices.show', $this->invoice))->assertOk()->assertSeeLivewire(RecordPanel::class);
    $this->actingAs($this->finance)->get(route('cheques.show', $this->cheque))->assertOk()->assertSeeLivewire(RecordPanel::class);
    $this->actingAs($this->finance)->get(route('agreements.show', $this->agreement))->assertOk()->assertSeeLivewire(RecordPanel::class);
});

test('the tenant form has the "Don\'t send reminders" switch; tampered values are messages, never a 500', function () {
    $admin = matrixUser(RoleName::Admin, $this->a);

    Livewire::actingAs($admin)->test(Form::class, ['customer' => $this->tenant])->assertSee("Don't send reminders")
        ->assertSet('form.no_reminders', false)
        ->set('form.no_reminders', true)->call('save')->assertHasNoErrors();
    expect($this->tenant->fresh()->no_reminders)->toBeTrue();

    foreach (['maybe', ['x']] as $bad) {
        Livewire::actingAs($admin)->test(Form::class, ['customer' => $this->tenant])->set('form.no_reminders', $bad)->call('save')
            ->assertOk()->assertHasErrors('form.no_reminders');
    }
    expect($this->tenant->fresh()->no_reminders)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reminders/SendButtonTest.php`
Expected: FAIL with `Class "App\Livewire\Reminders\RecordPanel" not found`.

- [ ] **Step 3: The panel component.** Create `app/Livewire/Reminders/RecordPanel.php`:

```php
<?php

namespace App\Livewire\Reminders;

use App\Livewire\Concerns\WithActor;
use App\Mail\TenantReminder;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ReminderSend;
use App\Reminders\ReminderFinder;
use App\Reminders\ReminderSender;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Spec 2026-10-17 §6: the "Send reminder" button, with a preview of the exact email, on invoice, cheque and agreement
 * pages, and the record's reminders. send() is the only browser-callable action; it re-checks everything (plan ruling 13).
 */
class RecordPanel extends Component
{
    use WithActor;

    /** Record classes the panel accepts => the kind it stores (reminder_sends.item_type, or 'customer'). */
    private const array KINDS = [Invoice::class => 'invoice', Cheque::class => 'cheque', Agreement::class => 'agreement', Customer::class => 'customer'];

    #[Locked]
    public string $kind = '';

    #[Locked]
    public int $recordId = 0;

    public function mount(Model $record): void
    {
        abort_unless(isset(self::KINDS[$record::class]), 403);
        $this->kind = self::KINDS[$record::class];
        $this->recordId = (int) $record->getKey();
    }

    /** The record, if the user may open it: permission and building scope, through its policy. */
    private function record(): Invoice|Cheque|Agreement|Customer
    {
        $record = match ($this->kind) {
            'invoice' => Invoice::query()->find($this->recordId),
            'cheque' => Cheque::query()->find($this->recordId),
            'agreement' => Agreement::query()->find($this->recordId),
            'customer' => Customer::query()->find($this->recordId),
            default => null,
        };
        abort_unless($record !== null && $this->actor()->can('view', $record), 403);

        return $record;
    }

    public function send(ReminderSender $sender): void
    {
        $record = $this->record();
        $today = CarbonImmutable::today('Asia/Bahrain');
        $item = $record instanceof Customer ? null : ReminderFinder::manual($record, $today);
        if ($item === null) {
            throw ValidationException::withMessages(['reminder' => __('There is nothing to remind the tenant about on this record.')]);
        }
        $tenant = $item->tenant();
        $refusal = $sender->refusal($tenant);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['reminder' => __('Can\'t send: :reason', ['reason' => ReminderSend::reasonText($refusal)])]);
        }

        $status = $sender->send($tenant, [$item], $today, $this->actor());

        Flux::modal('send-reminder')->close();
        if ($status === 'sent') {
            Flux::toast(variant: 'success', text: __('Reminder sent to :email.', ['email' => $tenant->email]));
        } else {
            Flux::toast(variant: 'danger', text: __('The reminder could not be sent. The reason is in the reminders list.'));
        }
    }

    public function render(): View
    {
        $record = $this->record();
        $item = $record instanceof Customer ? null : ReminderFinder::manual($record, CarbonImmutable::today('Asia/Bahrain'));
        $refusal = $item === null ? null : app(ReminderSender::class)->refusal($item->tenant());
        $mail = $item !== null && $refusal === null ? new TenantReminder($item->tenant(), [$item]) : null;

        return view('livewire.reminders.record-panel', [
            'sendable' => $item !== null,
            'refusal' => $refusal === null ? null : ReminderSend::reasonText($refusal),
            'email' => $item?->tenant()->email,
            'subject' => $mail?->subjectLine(),
            'previewHtml' => $mail?->render(),
        ]);
    }
}
```

- [ ] **Step 4: The panel view.** Create `resources/views/livewire/reminders/record-panel.blade.php`:

```blade
<div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="sm">{{ __('Reminders') }}</flux:heading>
        @if ($sendable)
            @if ($refusal === null)
                <flux:modal.trigger name="send-reminder">
                    <flux:button size="sm" icon="envelope">{{ __('Send reminder') }}</flux:button>
                </flux:modal.trigger>
            @else
                <flux:button size="sm" icon="envelope" disabled>{{ __('Send reminder') }}</flux:button>
            @endif
        @endif
    </div>
    @if ($sendable && $refusal !== null)
        <flux:text size="sm">{{ __('Can\'t send: :reason', ['reason' => $refusal]) }}</flux:text>
    @endif
    <flux:error name="reminder" />

    @if ($previewHtml !== null)
        <flux:modal name="send-reminder" class="w-full md:max-w-3xl">
            <div class="space-y-4">
                <flux:heading size="lg">{{ $subject }}</flux:heading>
                <flux:text>{{ __('To: :email', ['email' => $email]) }}</flux:text>
                <iframe sandbox="" srcdoc="{{ $previewHtml }}" title="{{ __('Email preview') }}"
                    class="h-[60vh] w-full rounded border border-zinc-200 bg-white dark:border-zinc-700"></iframe>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" icon="paper-airplane" wire:click="send"
                        wire:confirm="{{ __('Send this reminder to :email?', ['email' => $email]) }}">{{ __('Send') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
```

- [ ] **Step 5: Put the panel on the three pages.**

In `resources/views/livewire/invoices/show.blade.php`, insert before the final `</section>`:

```blade

    <livewire:reminders.record-panel :record="$invoice" :key="'reminders-invoice-'.$invoice->id" />
```

In `resources/views/livewire/cheques/show.blade.php`, insert before `    <livewire:documents.panel :documentable="$cheque" />`:

```blade
    <livewire:reminders.record-panel :record="$cheque" :key="'reminders-cheque-'.$cheque->id" />

```

In `resources/views/livewire/agreements/show.blade.php`, insert before `    <livewire:documents.panel :documentable="$agreement" :key="'docs-agreement-'.$agreement->id" />`:

```blade
    <livewire:reminders.record-panel :record="$agreement" :key="'reminders-agreement-'.$agreement->id" />

```

- [ ] **Step 6: The tenant opt-out.** In `app/Actions/Customers/SaveCustomer.php`, add after the `'notes'` rule:

```php
            'no_reminders' => ['sometimes', 'boolean'], // spec 2026-10-17 §6: "Don't send reminders"
```

In `app/Livewire/Customers/Form.php`, change the default to:

```php
    public array $form = ['type' => 'individual', 'id_type' => 'cpr', 'no_reminders' => false];
```

and in `mount()`, add `'no_reminders' => $customer->no_reminders,` after `'id_type' => $customer->id_type->value,`.

In `resources/views/livewire/customers/form.blade.php`, inside the Contact fieldset, after the address textarea (`<flux:textarea wire:model="form.address" … />`):

```blade
                    <flux:switch wire:model="form.no_reminders" :label="__('Don\'t send reminders')"
                        :description="__('No automatic or manual reminder emails go to this tenant.')" />
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Reminders/SendButtonTest.php tests/Feature/Customers tests/Feature/Invoices tests/Feature/Cheques tests/Feature/Agreements`
Expected: PASS. The existing screens still pass, because with `MAIL_MAILER=array` the panel only computes a refusal and never renders a preview.

- [ ] **Step 8: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Reminders/RecordPanel.php resources/views/livewire/reminders/record-panel.blade.php resources/views/livewire/invoices/show.blade.php \
  resources/views/livewire/cheques/show.blade.php resources/views/livewire/agreements/show.blade.php app/Actions/Customers/SaveCustomer.php \
  app/Livewire/Customers/Form.php resources/views/livewire/customers/form.blade.php tests/Feature/Reminders/SendButtonTest.php
git commit -m "$(cat <<'EOF'
Send reminder button with an exact preview on invoice, cheque and agreement pages; refusals shown; tenant opt-out switch

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Reminders on the records — visibility scopes and per-record lists

**Files:**
- Modify: `app/Models/ReminderSend.php` (scopes `visibleTo`, `inBuilding`)
- Modify: `app/Livewire/Reminders/RecordPanel.php` (`render()` adds `sends`, `showItem`)
- Modify: `resources/views/livewire/reminders/record-panel.blade.php` (the list)
- Modify: `resources/views/livewire/customers/form.blade.php` (panel on the tenant page)
- Modify: `tests/Pest.php` (add `logReminder()`)
- Test: `tests/Feature/Reminders/RecordListsTest.php`

**Interfaces:**
- Consumes: the `ReminderSend` helpers (Task 1); `RecordPanel` (Task 5); `Invoice::visibleTo/inBuilding`, `Cheque::visibleTo/inBuilding`, `Agreement::visibleTo/inBuilding`; `PermissionName::FinanceView`, `PermissionName::AgreementsView`.
- Produces:
  - `ReminderSend::query()->visibleTo(User $user)`. Invoice and cheque rows need `finance.view`; agreement rows need `agreements.view`; each is scoped by its record's `visibleTo` (plan ruling 14).
  - `ReminderSend::query()->inBuilding(int $buildingId)`.
  - The panel lists the record's last 20 reminders. On a tenant it lists the tenant's reminders that the viewer may see, with item links.
  - Test helper `logReminder(Customer $tenant, string $type, Invoice|Cheque|Agreement $item, array $attributes = []): ReminderSend`.

- [ ] **Step 1: Add the test helper.** In `tests/Pest.php` add `use App\Models\Cheque;` and `use App\Models\ReminderSend;` to the imports and append:

```php
/** A reminder log row for $item (reminder tests): automatic, rule day 1, sent today, its own batch, unless $attributes say otherwise. */
function logReminder(Customer $tenant, string $type, Invoice|Cheque|Agreement $item, array $attributes = []): ReminderSend
{
    return ReminderSend::create([
        'customer_id' => $tenant->id, 'type' => $type, 'item_id' => $item->id,
        'item_type' => match (true) { $item instanceof Invoice => 'invoice', $item instanceof Cheque => 'cheque', default => 'agreement' },
        'rule_day' => 1, 'sent_on' => now('Asia/Bahrain')->toDateString(), 'channel' => 'email', 'status' => 'sent',
        'batch' => (string) Str::uuid(), ...$attributes,
    ]);
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Reminders/RecordListsTest.php`:

```php
<?php

use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reminders\RecordPanel;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReminderSend;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    [$this->alpha, $this->agA] = reminderTenant(['name_en' => 'Alpha Tenant', 'email' => 'alpha@tenant.test'], '2027-01-09', $this->a);
    [$this->beta, $this->agB] = reminderTenant(['name_en' => 'Beta Tenant', 'email' => 'beta@tenant.test'], '2027-01-09', $this->b);
    $this->invA = issuedInvoice($this->alpha, [['net' => '400.000', 'au' => $this->agA->agreementUnits->first()]], '2026-11-04', $this->agA);
    $this->invB = issuedInvoice($this->beta, [['net' => '250.000', 'au' => $this->agB->agreementUnits->first()]], '2026-11-13', $this->agB);
    $this->chequeA = app(RecordCheques::class)->handle($this->finance, $this->alpha, $this->agA,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']])->first();
    $this->rows = [
        'invA' => logReminder($this->alpha, 'overdue', $this->invA),
        'chequeA' => logReminder($this->alpha, 'cheque', $this->chequeA, ['rule_day' => 2, 'status' => 'failed', 'reason' => 'SMTP said no']),
        'agA' => logReminder($this->alpha, 'renewal', $this->agA, ['rule_day' => 60, 'status' => 'skipped', 'reason' => 'No email']),
        'manual' => logReminder($this->alpha, 'overdue', $this->invA, ['rule_day' => null, 'manual' => true, 'sent_by' => $this->finance->id]),
        'invB' => logReminder($this->beta, 'rent_due', $this->invB, ['rule_day' => 3]),
    ];
});

test('reminders are visible as their records are: invoices and cheques need finance, agreements need agreements, in building scope', function () {
    $ids = fn ($user) => ReminderSend::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
    $r = collect($this->rows)->map(fn (ReminderSend $row) => $row->id);

    expect($ids($this->finance))->toBe($r->values()->all())
        ->and($ids(matrixUser(RoleName::Leasing, $this->a)))->toBe([$r['agA']])
        ->and($ids(matrixUser(RoleName::Leasing, $this->b)))->toBe([])
        ->and(ReminderSend::query()->inBuilding($this->a->id)->orderBy('id')->pluck('id')->all())->toBe([$r['invA'], $r['chequeA'], $r['agA'], $r['manual']])
        ->and(ReminderSend::query()->inBuilding($this->b->id)->pluck('id')->all())->toBe([$r['invB']]);
});

test('an invoice, cheque or agreement lists its own reminders: date, type, status, reason, manual or automatic', function () {
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invA])
        ->assertSeeInOrder(['10/11/2026', 'Overdue', 'Sent', 'Manual · '.$this->finance->name, '10/11/2026', 'Overdue', 'Sent', 'Automatic'])
        ->assertDontSee('SMTP said no');
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->chequeA])
        ->assertSee('Failed')->assertSee('SMTP said no')->assertDontSee('Overdue');
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->agA])
        ->assertSee('Skipped')->assertSee('The tenant has no email address.');
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invB])
        ->assertSee('Rent due')->assertDontSee('Overdue');
});

test('the tenant page lists the tenant\'s reminders the viewer may see, linking each record, without a send button', function () {
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->alpha])
        ->assertSee($this->invA->number)->assertSee('Cheque 501')->assertSee($this->agA->number)->assertDontSee($this->invB->number)
        ->assertSeeHtml(route('cheques.show', $this->chequeA))->assertDontSee('Send reminder');
    Livewire::actingAs(matrixUser(RoleName::Leasing, $this->a))->test(RecordPanel::class, ['record' => $this->alpha])
        ->assertSee($this->agA->number)->assertDontSee($this->invA->number)->assertDontSee('Cheque 501');

    $this->actingAs($this->finance)->get(route('customers.edit', $this->alpha))->assertOk()->assertSeeLivewire(RecordPanel::class);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reminders/RecordListsTest.php`
Expected: FAIL with `Call to undefined method Illuminate\Database\Eloquent\Builder::visibleTo()`.

- [ ] **Step 4: The scopes.** In `app/Models/ReminderSend.php` add the imports `use App\Enums\PermissionName;`, `use Illuminate\Database\Eloquent\Attributes\Scope;` and `use Illuminate\Database\Eloquent\Builder;`, then add these after `agreement()`:

```php
    /**
     * Rows whose record the user may open (plan ruling 14): invoices and cheques need finance.view, agreements need
     * agreements.view; building scope is each record's own visibleTo.
     *
     * @param  Builder<ReminderSend>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $finance = $user->can(PermissionName::FinanceView);
        $agreements = $user->can(PermissionName::AgreementsView);

        $query->where(function (Builder $q) use ($user, $finance, $agreements) {
            $q->whereRaw('1 = 0');
            if ($finance) {
                $q->orWhere(fn (Builder $i) => $i->where('item_type', 'invoice')->whereIn('item_id', Invoice::query()->visibleTo($user)->select('id')))
                    ->orWhere(fn (Builder $c) => $c->where('item_type', 'cheque')->whereIn('item_id', Cheque::query()->visibleTo($user)->select('id')));
            }
            if ($agreements) {
                $q->orWhere(fn (Builder $a) => $a->where('item_type', 'agreement')->whereIn('item_id', Agreement::query()->visibleTo($user)->select('id')));
            }
        });
    }

    /**
     * Rows whose record is in the building, by each record's own inBuilding.
     *
     * @param  Builder<ReminderSend>  $query
     */
    #[Scope]
    protected function inBuilding(Builder $query, int $buildingId): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $i) => $i->where('item_type', 'invoice')->whereIn('item_id', Invoice::query()->inBuilding($buildingId)->select('id')))
            ->orWhere(fn (Builder $c) => $c->where('item_type', 'cheque')->whereIn('item_id', Cheque::query()->inBuilding($buildingId)->select('id')))
            ->orWhere(fn (Builder $a) => $a->where('item_type', 'agreement')->whereIn('item_id', Agreement::query()->inBuilding($buildingId)->select('id'))));
    }
```

- [ ] **Step 5: The list in the panel.** In `app/Livewire/Reminders/RecordPanel.php` add `use Illuminate\Database\Eloquent\Builder;`. In `render()`, before `return view(…)`:

```php
        // Only what the viewer could open (plan ruling 14); on a tenant, every item of theirs.
        $sends = ReminderSend::query()->visibleTo($this->actor())
            ->when($record instanceof Customer,
                fn (Builder $q) => $q->where('customer_id', $record->getKey()),
                fn (Builder $q) => $q->where('item_type', $this->kind)->where('item_id', $record->getKey()))
            ->with(['invoice:id,number,status', 'cheque:id,cheque_no', 'agreement:id,number', 'sender:id,name'])
            ->latest('id')->limit(20)->get();
```

and add these two keys to the view data array:

```php
            'sends' => $sends,
            'showItem' => $record instanceof Customer,
```

In `resources/views/livewire/reminders/record-panel.blade.php`, add these two lines at the very top of the file:

```blade
@use('App\Models\ReminderSend')
@use('App\Models\ReminderSetting')
```

and replace `    <flux:error name="reminder" />` with:

```blade
    <flux:error name="reminder" />

    @if ($sends->isEmpty())
        <flux:text size="sm">{{ __('No reminders yet.') }}</flux:text>
    @else
        <ul class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
            @foreach ($sends as $s)
                <li class="flex flex-wrap items-center gap-2 py-2" wire:key="rs-{{ $s->id }}">
                    <span class="tabular-nums">{{ $s->sent_on->format('d/m/Y') }}</span>
                    <span>· {{ ReminderSetting::label($s->type) }}</span>
                    @if ($showItem)
                        <span>· <flux:link :href="$s->itemUrl()" wire:navigate>{{ $s->itemLabel() }}</flux:link></span>
                    @endif
                    <flux:badge size="sm" :color="$s->statusColor()">{{ $s->statusLabel() }}</flux:badge>
                    <span class="text-zinc-500">{{ $s->manual ? __('Manual · :name', ['name' => $s->sender?->name]) : __('Automatic') }}</span>
                    @if ($s->reason !== null)
                        <span class="text-zinc-500">· {{ ReminderSend::reasonText($s->reason) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
```

- [ ] **Step 6: The tenant page.** In `resources/views/livewire/customers/form.blade.php`, inside the final `@if ($customer)` block, before the documents panel:

```blade
        <livewire:reminders.record-panel :record="$customer" :key="'reminders-customer-'.$customer->id" />
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Reminders tests/Feature/Customers`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add app/Models/ReminderSend.php app/Livewire/Reminders/RecordPanel.php resources/views/livewire/reminders/record-panel.blade.php \
  resources/views/livewire/customers/form.blade.php tests/Pest.php tests/Feature/Reminders/RecordListsTest.php
git commit -m "$(cat <<'EOF'
Reminders on records: visibility by record permission and building, lists on invoice, cheque, agreement and tenant pages

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: The Reminders log page, Excel export, and the full suite

**Files:**
- Create: `app/Livewire/Reminders/Index.php`, `resources/views/livewire/reminders/index.blade.php`
- Modify: `routes/property.php` (`reminders.index`)
- Modify: `resources/views/layouts/app/sidebar.blade.php` (Leasing group)
- Test: `tests/Feature/Reminders/LogPageTest.php`

**Interfaces:**
- Consumes: `ReminderSend::visibleTo/inBuilding` (Task 6); `ReminderSend` helpers, `STATUSES`, `ReminderSetting::TYPES/label` (Task 1); `logReminder()` (Task 6); `Building::visibleTo`; `Audit::log`; `SimpleExcelWriter`.
- Produces:
  - Route `reminders.index` → `App\Livewire\Reminders\Index`.
  - Public `mixed` filters `$from`, `$to`, `$type`, `$status` and `$building`, all `#[Url]`. `$from` and `$to` default to the last 30 days.
  - `export(): BinaryFileResponse`, which downloads `reminders-<from>-<to>.xlsx` and is audited as `report.exported` with `{report: 'reminders', from, to, type, status, building}`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Reminders/LogPageTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reminders\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    [$this->alpha, $this->agA] = reminderTenant(['name_en' => 'Alpha Tenant', 'email' => 'alpha@tenant.test'], '2027-01-09', $this->a);
    [$this->beta, $this->agB] = reminderTenant(['name_en' => 'Beta Tenant', 'email' => null], '2027-08-31', $this->b);
    $this->invA = issuedInvoice($this->alpha, [['net' => '400.000', 'au' => $this->agA->agreementUnits->first()]], '2026-11-04', $this->agA);
    $this->invB = issuedInvoice($this->beta, [['net' => '250.000', 'au' => $this->agB->agreementUnits->first()]], '2026-11-12', $this->agB);
    logReminder($this->alpha, 'overdue', $this->invA);                                                                    // today, sent
    logReminder($this->alpha, 'renewal', $this->agA, ['rule_day' => 60, 'status' => 'failed', 'reason' => 'SMTP said no']);  // today, failed
    logReminder($this->beta, 'rent_due', $this->invB, ['rule_day' => 3, 'sent_on' => '2026-11-09', 'status' => 'skipped', 'reason' => 'No email']);
    logReminder($this->beta, 'renewal', $this->agB, ['rule_day' => null, 'manual' => true, 'sent_by' => $this->finance->id, 'sent_on' => '2026-10-01']); // before the default range
});

test('the log shows the last 30 days: date, tenant, type, item, channel, status, reason, and who sent it', function () {
    $html = Livewire::actingAs($this->finance)->test(Index::class)->assertSet('from', '2026-10-11')->assertSet('to', '2026-11-10')->html();

    expect(reportRowText($html, $this->invA->number))->toBe("10/11/2026 Alpha Tenant Overdue {$this->invA->number} Email Sent Automatic")
        ->and(reportRowText($html, 'SMTP said no'))->toBe("10/11/2026 Alpha Tenant Renewal {$this->agA->number} Email Failed SMTP said no Automatic")
        ->and(reportRowText($html, $this->invB->number))->toBe("09/11/2026 Beta Tenant Rent due {$this->invB->number} Email Skipped The tenant has no email address. Automatic")
        ->and($html)->not->toContain('01/10/2026');
});

test('filters: date range, type, status and building', function () {
    $page = Livewire::actingAs($this->finance)->test(Index::class);

    $page->set('from', '2026-09-01')->assertSee('01/10/2026')->assertSee('Manual · '.$this->finance->name);
    $page->set('type', 'renewal')->assertSee($this->agA->number)->assertDontSee($this->invA->number);
    $page->set('type', '')->set('status', 'failed')->assertSee('SMTP said no')->assertDontSee($this->invA->number);
    $page->set('status', '')->set('building', (string) $this->b->id)->assertSee($this->invB->number)->assertSee('01/10/2026')
        ->assertDontSee($this->invA->number);
});

test('leasing sees agreement reminders in its own buildings only', function () {
    $leasingA = matrixUser(RoleName::Leasing, $this->a);
    $leasingB = matrixUser(RoleName::Leasing, $this->b);

    Livewire::actingAs($leasingA)->test(Index::class)->set('from', '2026-09-01')
        ->assertSee($this->agA->number)->assertDontSee($this->invA->number)->assertDontSee('Beta Tenant');
    Livewire::actingAs($leasingB)->test(Index::class)->set('from', '2026-09-01')
        ->assertSee('Beta Tenant')->assertDontSee($this->invB->number)->assertDontSee('Alpha Tenant')
        ->set('building', (string) $this->a->id)->assertHasErrors('building');
});

test('Excel export of the filtered log is audited as report.exported', function () {
    Livewire::actingAs($this->finance)->test(Index::class)->set('status', 'failed')
        ->call('export')->assertFileDownloaded('reminders-2026-10-11-2026-11-10.xlsx');

    expect(Activity::query()->where('event', 'report.exported')->sole()->properties->all())
        ->toEqual(['report' => 'reminders', 'from' => '2026-10-11', 'to' => '2026-11-10', 'type' => '', 'status' => 'failed', 'building' => '']);
});

test('tampered filters are messages, never a 500, and never export', function (string $property, mixed $value) {
    Livewire::actingAs($this->finance)->test(Index::class)->set($property, $value)->assertOk()->assertHasErrors($property)
        ->call('export')->assertNoFileDownloaded();

    expect(Activity::query()->where('event', 'report.exported')->count())->toBe(0);
})->with([
    'from as an array' => ['from', ['x']],
    'from not a date' => ['from', 'abc'],
    'to before from' => ['to', '2026-10-01'],
    'unknown type' => ['type', 'late_fee'],
    'type as an array' => ['type', ['overdue']],
    'unknown status' => ['status', 'queued'],
    'unknown building' => ['building', '999999'],
    'building as an array' => ['building', ['1']],
]);

test('bad URL parameters fall back to the defaults; every role opens the page; a user with neither permission is refused', function () {
    Livewire::withQueryParams(['from' => ['x'], 'to' => 'zzz', 'type' => 'nope', 'building' => 'abc'])->actingAs($this->finance)->test(Index::class)
        ->assertSet('from', '2026-10-11')->assertSet('to', '2026-11-10')->assertSet('type', '')->assertSet('building', '')->assertHasNoErrors();

    foreach (RoleName::cases() as $role) {
        $this->actingAs(matrixUser($role, $this->a))->get(route('reminders.index'))->assertOk();
    }
    $this->actingAs(User::factory()->withTwoFactor()->create())->get(route('reminders.index'))->assertForbidden();
    $this->actingAs($this->finance)->get(route('dashboard'))->assertSee(route('reminders.index'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reminders/LogPageTest.php`
Expected: FAIL with `Class "App\Livewire\Reminders\Index" not found`.

- [ ] **Step 3: The component.** Create `app/Livewire/Reminders/Index.php`:

```php
<?php

namespace App\Livewire\Reminders;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\ReminderSend;
use App\Models\ReminderSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Spec 2026-10-17 §6: every reminder logged (sent, failed or skipped), with filters and an Excel export. */
class Index extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered payload (an array, "abc") must reach validation, not throw a TypeError (a 500).
    #[Url]
    public mixed $from = '';

    #[Url]
    public mixed $to = '';

    #[Url]
    public mixed $type = '';

    #[Url]
    public mixed $status = '';

    #[Url]
    public mixed $building = '';

    public function mount(): void
    {
        abort_unless($this->actor()->canAny(['finance.view', 'agreements.view']), 403);
        $today = CarbonImmutable::today('Asia/Bahrain');
        $this->from = $this->from ?: $today->subDays(30)->toDateString();
        $this->to = $this->to ?: $today->toDateString();
        if (validator($this->filters(), $this->filterRules())->fails()) { // a bad URL: the defaults, not an error page
            $this->from = $today->subDays(30)->toDateString();
            $this->to = $today->toDateString();
            $this->type = $this->status = $this->building = '';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
        $this->validate($this->filterRules());
    }

    public function export(): BinaryFileResponse
    {
        abort_unless($this->actor()->canAny(['finance.view', 'agreements.view']), 403);
        $this->validate($this->filterRules());
        Audit::log('report.exported', properties: ['report' => 'reminders', ...$this->filters()], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-reminders-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        $writer->addHeader([__('Date'), __('Tenant'), __('Type'), __('Item'), __('Channel'), __('Status'), __('Reason'), __('Sent by')]);
        foreach ($this->query()->get() as $row) { // ponytail: the whole range in memory; chunk it if a range ever holds tens of thousands
            $writer->addRow([$row->sent_on->format('d/m/Y'), $row->customer?->name_en ?? '', ReminderSetting::label($row->type), $row->itemLabel(),
                __('Email'), $row->statusLabel(), ReminderSend::reasonText($row->reason), $row->manual ? ($row->sender?->name ?? '') : __('Automatic')]);
        }
        $writer->close();

        return response()->download($path, "reminders-{$this->from}-{$this->to}.xlsx")->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'type' => $this->type, 'status' => $this->status, 'building' => $this->building];
    }

    /** @return array<string, list<mixed>> */
    private function filterRules(): array
    {
        return [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', 'string', Rule::in(ReminderSetting::TYPES)],
            'status' => ['nullable', 'string', Rule::in(ReminderSend::STATUSES)],
            'building' => ['nullable', Rule::in(Building::query()->visibleTo($this->actor())->pluck('id')->all())],
        ];
    }

    /** @return Builder<ReminderSend> nothing at all while a filter is invalid */
    private function query(): Builder
    {
        $valid = validator($this->filters(), $this->filterRules())->passes();

        return ReminderSend::query()->visibleTo($this->actor())
            ->when(! $valid, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($valid, fn (Builder $q) => $q->whereBetween('sent_on', [$this->from, $this->to])
                ->when(filled($this->type), fn (Builder $q) => $q->where('type', $this->type))
                ->when(filled($this->status), fn (Builder $q) => $q->where('status', $this->status))
                ->when(filled($this->building), fn (Builder $q) => $q->inBuilding((int) $this->building)))
            ->with(['customer:id,name_en', 'sender:id,name', 'invoice:id,number,status', 'cheque:id,cheque_no', 'agreement:id,number'])
            ->orderByDesc('sent_on')->orderByDesc('id');
    }

    public function render(): View
    {
        return view('livewire.reminders.index', [
            'rows' => $this->query()->paginate(50),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'types' => collect(ReminderSetting::TYPES)->mapWithKeys(fn (string $t) => [$t => ReminderSetting::label($t)])->all(),
            'statuses' => ['sent' => __('Sent'), 'failed' => __('Failed'), 'skipped' => __('Skipped')],
        ])->title(__('Reminders'));
    }
}
```

- [ ] **Step 4: The view.** Create `resources/views/livewire/reminders/index.blade.php`:

```blade
@use('App\Models\ReminderSend')
@use('App\Models\ReminderSetting')
<section class="w-full space-y-4">
    <flux:heading size="xl" level="1">{{ __('Reminders') }}</flux:heading>
    <flux:text>{{ __('Every reminder emailed to tenants, automatic or sent by hand, and the ones skipped.') }}</flux:text>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <x-date-input wire:model.live="from" :label="__('From')" />
        <x-date-input wire:model.live="to" :label="__('To')" />
        <flux:select wire:model.live="type" :label="__('Type')">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" :label="__('Status')">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>
        <x-building-filter :buildings="$buildings" />
        <div class="flex items-end"><flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export to Excel') }}</flux:button></div>
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Item') }}</flux:table.column>
                <flux:table.column>{{ __('Channel') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Reason') }}</flux:table.column>
                <flux:table.column>{{ __('Sent by') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $row)
                    <flux:table.row :key="'rs-'.$row->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $row->sent_on->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $row->customer?->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ ReminderSetting::label($row->type) }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="$row->itemUrl()" wire:navigate>{{ $row->itemLabel() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ __('Email') }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$row->statusColor()">{{ $row->statusLabel() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ ReminderSend::reasonText($row->reason) }}</flux:table.cell>
                        <flux:table.cell>{{ $row->manual ? __('Manual · :name', ['name' => $row->sender?->name]) : __('Automatic') }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="8">{{ __('No reminders in this period.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    {{ $rows->links() }}
</section>
```

- [ ] **Step 5: Route and menu.** In `routes/property.php` add `use App\Livewire\Reminders;` to the imports (alphabetical, after `use App\Livewire\Payments;`), and after the `agreements.pdf` route:

```php
    Route::livewire('reminders', Reminders\Index::class)->name('reminders.index'); // finance.view or agreements.view, checked in mount()
```

In `resources/views/layouts/app/sidebar.blade.php`, inside the Leasing group after the Agreements item:

```blade
                    @canany(['finance.view', 'agreements.view'])
                        <flux:sidebar.item icon="bell" :href="route('reminders.index')" :current="request()->routeIs('reminders.*')" wire:navigate>{{ __('Reminders') }}</flux:sidebar.item>
                    @endcanany
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Reminders/LogPageTest.php`
Expected: PASS.

- [ ] **Step 7: Full suite and gates.** Run these serially:
  - `php artisan test`: all tests pass, including `tests/Feature/Integrity` (still 61 triggers) and `tests/Feature/Backup/ScheduleTest.php`.
  - `php vendor/bin/pint --dirty`
  - `php vendor/bin/phpstan analyse --memory-limit=2G`
  - `npm run build`

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Reminders/Index.php resources/views/livewire/reminders/index.blade.php routes/property.php \
  resources/views/layouts/app/sidebar.blade.php tests/Feature/Reminders/LogPageTest.php
git commit -m "$(cat <<'EOF'
Reminders log page: date, tenant, type, item, status, reason and sender, filtered by date, type, status and building, Excel export audited

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (done while writing)

- **Spec coverage:**
  - §1 purpose and §2 approach: the daily command (Task 4), the log with its unique key (Tasks 1 and 4), combining per tenant (Tasks 3 and 4), and the channel (Task 3).
  - §3 types and rules: Task 2 covers every rule and Task 1 the settings page.
    - Grace days use the agreement's value or the company default (ruling 1).
    - Credit notes, non-held cheques, and renewed or noticed agreements are excluded (ruling 2).
    - The matching day is exact, and a missed day is not caught up.
    - Settings default to off, days are 1–365 with at most 5, and intros are ≤300 characters with defaults.
    - Never reminded: no email and opted out are logged as skipped (Task 4). Items that no longer qualify are dropped (Task 4, ruling 18).
  - §4 message: Task 3.
    - Combined, with the subject by urgency in English · Arabic plus the company.
    - Bilingual RTL body, the greeting with the Arabic-name fallback (ruling 10), and the Admin intros.
    - Invoice, cheque and renewal lines with dd/mm/yyyy dates and 3-dp BHD.
    - The company footer, from company name at `MAIL_FROM_ADDRESS`, reply-to the company email. All figures come from the records.
  - §5 sending: Task 4.
    - 08:00 daily with `withoutOverlapping` and the heartbeat.
    - No-op when every type is off or mail is not configured (ruling 8).
    - One job per tenant (tries 1, timeout 120) and failure isolation.
    - Never twice. Failures are recorded and not retried. Audit (ruling 7).
  - §6 screens.
    - Button and preview, permissions, building scope, disabled reasons, and works-when-off: Task 5.
    - Manual sends don't block the automatic one: Task 5.
    - Log page, filters and Excel: Task 7.
    - Per-record lists: Task 6.
    - Tenant switch: Task 5.
  - §7 `Channel` and the `channel` column: Tasks 1 and 3.
  - §8 storage: Task 1, with the generated key (ruling 3) and no triggers.
  - §9 safety: read-only finder, email only from the tenant record, policy checks in `RecordPanel`, mixed and validated inputs (ruling 15), and `__()` throughout.
  - §10 testing: every bullet maps to a test in `FinderTest`, `EmailTest`, `DailyRunTest`, `SendButtonTest`, `RecordListsTest`, `LogPageTest` or `SettingsTest`.
- **Placeholder scan:** none. One instruction in Task 3 Step 4 concerns an import line only: write `Cheque` with a `use` statement.
- **Type consistency:**
  - `ReminderItem(string $type, Invoice|Cheque|Agreement $record, ?int $ruleDay)` with `itemType()` and `tenant()`: the same in Tasks 2–6.
  - `ReminderFinder::due(CarbonImmutable, ?int): array<int, list<ReminderItem>>` and `::manual(record, CarbonImmutable): ?ReminderItem`: the same in Tasks 2, 4 and 5.
  - `Channel::name/ready/unavailableReason/send`: Tasks 3 and 4, and the failing channel in `DailyRunTest`.
  - `ReminderSender::refusal(Customer): ?string` and `::send(Customer, list<ReminderItem>, CarbonImmutable, ?User): ?string`: Tasks 4 and 5.
  - `TenantReminder($tenant, $items)` with `subjectLine()`: Tasks 3, 4 and 5.
  - `ReminderSend` constants (`OPTED_OUT`, `NO_EMAIL`, `MAIL_NOT_SET_UP`, `STATUSES`) and helpers (`reasonText`, `itemLabel`, `itemUrl`, `statusLabel`, `statusColor`): Tasks 1, 5, 6 and 7.
  - `ReminderSetting::TYPES/label/parseDays`: Tasks 1, 2, 3 and 7.
  - Routes `admin.reminders` and `reminders.index`. Command `rms:reminders:send`. Job `SendTenantReminders(int $customerId, string $date)`. Heartbeat key `tenant_reminders`.
- **Fixture arithmetic** (today 10/11/2026):
  - An agreement ending 09/01/2027 is 60 days away today, 30 days away on 10/12/2026, and exactly 90 days away on 11/10/2026.
  - An invoice due 13/11 with 5 grace days has grace until 18/11, so it is rent due today (3 days) and overdue day 1/7/14 on 19/11, 25/11 and 02/12.
  - An invoice due 04/11 has grace until 09/11, so it is overdue day 1 today.
  - A manual invoice due 02/11 with the company default of 3 days has grace until 05/11, so it is overdue day 1 on 06/11.
  - A cheque dated 12/11 matches today (2 days).
  - `DailyRunTest` logs 7 rows: Alpha 4, Beta 1, Gamma skipped 1, Delta skipped 1. It sends 2 emails and audits 2 messages.
  - The log page's default range is 11/10/2026–10/11/2026.
