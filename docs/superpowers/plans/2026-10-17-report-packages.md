# Report Packages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bundle saved custom reports and the 14 ready-made reports into a named **package**. A package downloads as one Excel workbook, one sheet per report, and can be emailed to staff on a schedule.

**Architecture:** Each ready-made report already builds its columns and rows inside its Livewire class. A package runs that same code **headless**. It does `new RentRollReport`, calls a new public `sheet(User $as, array $settings)` on the shared `ReportPage` / `StatementPage` traits, and gets back exactly what the report's own Excel export writes. The signed-in user is swapped for the run and always put back. A small registry (`ReadyMade`) maps the 14 report keys to their classes. `PackageRunner` turns each item's stored settings (building, relative period, options) into the report's own settings, runs it, and collects a `PackageSheet`. Saved reports run through `Engine` and `ExcelWriter::sheet()`. `PackageWorkbook` writes one sheet per item. Schedules reuse `report_schedules`, which gains a nullable `report_package_id`. The existing command and job learn a package branch, and the builder's schedule form moves into a trait and a partial that both screens use.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, spatie/simple-excel (multi-sheet writer: `addNewSheetAndMakeItCurrent`, `nameCurrentSheet`), spatie/laravel-permission, `App\Audit\Audit`, `App\Support\Fils`, `<x-building-filter>`.

**Spec:** `docs/superpowers/specs/2026-10-17-report-packages-design.md`. Read it first; this plan argues from it. The builder spec it builds on is `docs/superpowers/specs/2026-10-15-report-builder-design.md` §7.

## Global Constraints

- Money is BHD in fils with 3 decimals. Sum in SQL on DECIMAL. In PHP use `App\Support\Fils` (`fromDecimal`, `toDecimal`) and never floats, except for Excel cell values.
- Timezone is Asia/Bahrain. Dates display as dd/mm/yyyy. Times are app-timezone (Asia/Bahrain) wall time. `NextRun` returns Asia/Bahrain.
- Reports are read-only. Actions are the only write path for money. `IntegrityCheck::EXPECTED_TRIGGERS` stays 61. "No triggers; `EXPECTED_TRIGGERS` unchanged." (spec §7)
- "Read-only runs as `rms_app`. Item settings are whitelisted per report; ids are checked against what the runner's user can see on every run. Tampered Livewire input gives a validation message, never a 500." (spec §8)
- All UI strings go through `__()`. Write "tenant", never "customer". The building filter is `<x-building-filter>`.
- "**Package:** name (≤120), visibility `private` | `shared` (as saved reports), creator. The creator or a user with `settings.manage` edits, shares, schedules and deletes it. Anyone who can see it may download it." (spec §3)
- "**Items:** an ordered list, 1–15." Out of scope: "owner or outside recipients; PDF output; Building profitability, Head-lease due and Owner statements …; per-item recipients." (spec §1)
- "**Permissions:** no new permissions. Package screens need `reports.operational` or `reports.financial`. Each item runs only if the person it runs as has that report's permission; otherwise it is skipped with a note." (spec §3)
- Period: "a relative preset from `App\Reports\Builder\Period::presets()` … resolved in Asia/Bahrain on each run. For single-date reports the date is the period's last day, or "today" when the preset is "Today". Reports without dates ignore it. Statement reports use the months the period covers (capped at 12, most recent kept)." (spec §3)
- Sheets: "named after the report (cut to 31 characters, `(2)`, `(3)` on clashes, characters Excel forbids removed). Each sheet: a title row, a period row, a blank row, then the same header and rows as that report's own export. A skipped item gets a sheet with the reason only." (spec §4)
- Email: "subject "`<package name>` — `<period>`". Body: one line per item — name, real dates, row count, headline total (the report's first money total, if any) — and a list of skipped items with reasons. One `.xlsx` attachment; over 10 MB → no attachment, link only. "Open in the app" links to the package screen." (spec §5)
- Recipients: "active staff only, never Vendor Support, never outside addresses (existing rule)." Every item skipped for a recipient means nothing is sent to them, and this is noted on the delivery. (spec §5)
- Schedules behave "exactly as for saved reports": frequency, day, hour, recipients, `next_run_at`, deliveries, idempotency, failure counting, pausing (3 failures, creator deactivated) and audit (`report.scheduled`, `report.sent`). (spec §5)
- Scheduled jobs use `tries = 1` and `timeout = 300`. The queue's `retry_after` is 330. "a timeout is one failure and the creator is told." (spec §5)
- Download now is "audited as `report.exported` with `{report: 'package', package: <id>}`. Temp files are deleted after sending." (spec §6)
- The existing saved-report schedule tests (`tests/Feature/ReportBuilder/ScheduleScreenTest.php`, `ScheduledSendTest.php`, `FinalFixesTest.php`) must still pass unchanged.
- Tests run serially (`php artisan test`, never `--parallel`) against `rms_test`. Never run `migrate:fresh` on the dev DB `rms`. Dev migrations use `php artisan migrate --database=migrator --force`.
- PHP binary: `/c/Users/ababy/.config/herd/bin/php85/php.exe` (written `php` below). Gates before each commit: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G`, and `npm run build` when a view changed.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in a new file. Alpine attributes on Flux components need `:x-show`-style binding.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Rulings made while planning

1. **Headless = a plain `new`.** `Livewire\Component` has no constructor, and the reports keep their figures in protected methods that read only public properties and `actor()`. So `new RentRollReport` works outside a request without the Livewire lifecycle. The new trait method validates the settings first, assigns them to the public properties, and calls the same `columns()` / `rows()` (or `compute()`) that the export uses. `WithActor::asActor()` swaps the user with `Auth::setUser()` and puts it back in a `finally`: it restores the previous user, or calls `Auth::forgetUser()` when there was none.
2. **A `PackageReport` interface** (`packageForm`, `checkSettings`, `sheet`) is implemented by all 14 classes through the two traits. The runner and the screen need one typed handle for 14 classes. It is not an interface with only one implementation.
3. **Report keys** are the keys each export already audits: `Str::snake(Str::beforeLast(class_basename($class), 'Report'))`. For example `rent_roll`, `expiring_agreements`, `owner_income_statement`.
4. **Whitelisting a `ReportPage` building.** The screen relies on the `visibleTo` scopes inside `rows()`. Spec §8 asks for ids to be checked, so a package run adds `Rule::in(Building::visibleTo($as)->pluck('id'))`. Every other value must be a string. A tampered array therefore fails validation and never reaches a typed property, which would cause a TypeError (a 500). `StatementPage::scopeRules()` already whitelists its building and owner.
5. **MySQL refuses a CHECK on a column that a foreign-key action uses** (error 3823). So `report_schedules.saved_report_id`, the new `report_schedules.report_package_id` and `report_package_items.saved_report_id` get foreign keys **without** `ON DELETE` actions. Saved reports and packages are only ever soft-deleted, so the old cascade never fired. Deleting a package deletes its schedules in code, as `Builder::deleteReport()` already does for reports. `report_package_items.report_package_id` keeps its cascade, because no CHECK uses it.
6. **Statement months.** These run from the first month of the resolved range to its last month. The last month is capped at the current month, because a future month is not in the statement's month list (`monthOptions()`) and has no figures. At most the 12 most recent months are kept. No preset spans more than 12 months, so that cap is a guard and is not reachable through the screen.
7. **Period row and labels.** Range and month reports use `Period::label()` with real dates. Single-date reports use "As at dd/mm/yyyy" with the period's last day. Reports without dates use "As at <run date>". Saved reports use their own `periodLabel`, or "As at <run date>" when they have none.
8. **Headline total.** For `ReportPage` reports this is the first numeric column holding a 3-dp amount in the **last** row tagged `_kind => 'total'`. The total rows of Deposits held, Collections ("Total collected") and VAT summary now carry `_kind => 'total'`; the rent roll already does. These rows turn bold on screen, like the rent roll's totals. Reports without a total row have no headline: Ageing, Outstanding, Occupancy, Expiring, Overstays, Approvals, ID documents and Cheques. For statements it is the last total row (Result, or Net income to owner). For saved reports it is the first Money column's grand total.
9. **Row count** for a ready-made report is the number of rows written under the header, including subtotal and total rows, because that is what the sheet holds. Saved reports use `ReportResult::rowCount`.
10. **The subject's `<period>`** is the period of the first item that ran, or "As at <today>" when none ran.
11. **Saved-report access during a run** is subject access (`Registry::forUser`), the same rule the existing scheduled job applies. The editor can only **add** saved reports they can see (`SavedReport::visibleTo`). A soft-deleted saved report keeps its item, which is skipped with "Report deleted". Its name is read with `withTrashed()`.
12. **Audits** follow the builder: `report.saved`, `report.shared` and `report.deleted` with the package as subject. `report.scheduled` and `report.sent` also take the package as subject. Download now logs `report.exported` with `{report: 'package', package: id}`.
13. **Opening a private package** follows `SavedReportPolicy`. `view` means the creator, or a shared package, plus a report permission. An Admin edits any package they can open, but cannot open someone else's private one.
14. **Schedule UI reuse.** `Builder`'s `$schedule`, `$staffSearch`, `updatedSchedule`, `addSchedule`, `removeSchedule`, `resumeSchedule` and the staff picker move into `App\Livewire\Reports\Concerns\ManagesSchedules`. Its modal moves into `resources/views/livewire/reports/partials/schedule-modal.blade.php`. The builder's behaviour and tests are unchanged.
15. **Download now** builds the **saved** package. Unsaved edits on the screen are not included, so the button shows only once the package is saved.
16. **Defaults for a new ready-made item:** all buildings; period "Today" for single-date reports and "Last month" for the others; the first choice of each option. For the company income statement the owner's first choice is "All owners".
17. **Dropped-field note.** On a saved-report sheet, the "fields left out" note gets its own row between the period row and the blank row.
18. **Route.** `reports/packages/{package?}` is named `reports.package` and has no `can:` middleware, because it needs either permission. `mount()` checks it (403).
19. **Package periods** are `Period::presets()` without `custom`, because spec §3 says relative.

## File Structure

```
database/migrations/2026_12_17_000100_create_report_packages_tables.php   NEW  report_packages, report_package_items, schedules generalised (Task 1)
app/Models/ReportPackage.php                         NEW  (Task 1)
app/Models/ReportPackageItem.php                     NEW  (Task 1)
app/Models/ReportSchedule.php                        MOD  report_package_id, package() (Task 1)
app/Policies/ReportPackagePolicy.php                 NEW  view / update / delete (Task 1)
app/Livewire/Concerns/WithActor.php                  MOD  asActor() (Task 2)
app/Reports/Packages/PackageReport.php               NEW  interface + Form type (Task 2)
app/Reports/Packages/ReadyMade.php                   NEW  the 14 keys => classes (Task 2)
app/Livewire/Reports/Concerns/ReportPage.php         MOD  packageForm / checkSettings / sheet; export uses sheetData() (Task 2)
app/Livewire/Reports/Concerns/StatementPage.php      MOD  the same for statements; download uses statementSheet() (Task 2)
app/Livewire/Reports/*Report.php (14)                MOD  implements PackageReport; statements: title(), scopeOptions(), protected compute() (Task 2)
app/Livewire/Reports/{DepositsHeld,Collections,VatSummary}Report.php  MOD  '_kind' => 'total' on the total row (Task 2)
app/Reports/Builder/ExcelWriter.php                  MOD  sheet() returns header + rows; write() uses it (Task 3)
app/Reports/Packages/PackageSheet.php                NEW  one item's result (Task 3)
app/Reports/Packages/PackageResult.php               NEW  sheets + email lines (Task 3)
app/Reports/Packages/PackageRunner.php               NEW  run / resolve / clean / presets (Task 3)
app/Reports/Packages/PackageWorkbook.php             NEW  write / sheetName (Task 3)
app/Livewire/Reports/Package.php                     NEW  package screen (Task 4; schedules Task 5)
resources/views/livewire/reports/package.blade.php   NEW  (Task 4; schedule button Task 5)
app/Livewire/Reports/Index.php                       MOD  packages (Task 4)
resources/views/livewire/reports/index.blade.php     MOD  Packages area (Task 4)
routes/property.php                                  MOD  reports.package (Task 4)
app/Livewire/Reports/Concerns/ManagesSchedules.php   NEW  schedule form, moved out of Builder (Task 5)
resources/views/livewire/reports/partials/schedule-modal.blade.php  NEW  the modal, moved out of builder.blade.php (Task 5)
app/Livewire/Reports/Builder.php                     MOD  uses ManagesSchedules (Task 5)
resources/views/livewire/reports/builder.blade.php   MOD  includes the partial (Task 5)
app/Console/Commands/SendScheduledReports.php        MOD  loads package, pauses when gone (Task 6)
app/Jobs/SendScheduledReport.php                     MOD  package branch (Task 6)
app/Notifications/ScheduledPackage.php               NEW  package email (Task 6)
tests/Pest.php                                       MOD  packageWith() (Task 1); reportPackageFixture(), xlsxRows(), sheetFile() (Task 2)
tests/Feature/ReportPackages/StorageTest.php         NEW  (Task 1)
tests/Feature/ReportPackages/HeadlessTest.php        NEW  (Task 2)
tests/Feature/ReportPackages/RunnerTest.php          NEW  (Task 3)
tests/Feature/ReportPackages/PackageScreenTest.php   NEW  (Task 4; schedule tests appended in Task 5)
tests/Feature/ReportPackages/ScheduledPackageTest.php NEW (Task 6)
tests/Feature/Permissions/ReportsPermissionMatrixTest.php  MOD  reports.package (Task 4)
```

---

### Task 1: Storage, models and policy

**Files:**
- Create: `database/migrations/2026_12_17_000100_create_report_packages_tables.php`
- Create: `app/Models/ReportPackage.php`, `app/Models/ReportPackageItem.php`, `app/Policies/ReportPackagePolicy.php`
- Modify: `app/Models/ReportSchedule.php` (docblock, `$fillable`, new `package()`)
- Modify: `tests/Pest.php` (add `packageWith()`)
- Test: `tests/Feature/ReportPackages/StorageTest.php`

**Interfaces:**
- Consumes: `SavedReport`, `ReportSchedule`, `User`, `PermissionName::SettingsManage`.
- Produces:
  - `ReportPackage` (`name`, `visibility`, `created_by`, `updated_by`, soft deletes). Relations: `items(): HasMany<ReportPackageItem>` ordered by `position`, `schedules(): HasMany<ReportSchedule>`, `creator(): BelongsTo<User>`. Scope: `ReportPackage::visibleTo(User $user)` (the user's own packages plus shared ones).
  - `ReportPackageItem` (`report_package_id`, `position` int, `kind` `'saved'|'ready_made'`, `saved_report_id` ?int, `report` ?string, `settings` array cast), with `package(): BelongsTo`.
  - `ReportSchedule::package(): BelongsTo<ReportPackage>`, and `report_package_id` is fillable. `saved_report_id` is now `int|null`.
  - `ReportPackagePolicy::view|update|delete(User, ReportPackage): bool`, auto-discovered.
  - Test helper `packageWith(User $by, array $items, string $name = 'Monthly pack', string $visibility = 'private'): ReportPackage`, where `$items` is a list of `['kind', 'report'?, 'saved_report_id'?, 'settings'?]`.

- [ ] **Step 1: Add the test helper.** In `tests/Pest.php`, add `use App\Models\ReportPackage;` to the imports and append:

```php
/** A package by $by with the items in order (report-package tests). Items: ['kind', 'report'?, 'saved_report_id'?, 'settings'?]. */
function packageWith(User $by, array $items, string $name = 'Monthly pack', string $visibility = 'private'): ReportPackage
{
    $package = ReportPackage::create(['name' => $name, 'visibility' => $visibility, 'created_by' => $by->id, 'updated_by' => $by->id]);
    foreach (array_values($items) as $i => $item) {
        $package->items()->create(['position' => $i + 1, 'kind' => $item['kind'], 'saved_report_id' => $item['saved_report_id'] ?? null,
            'report' => $item['report'] ?? null, 'settings' => $item['settings'] ?? []]);
    }

    return $package;
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/ReportPackages/StorageTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportPackage;
use App\Models\ReportPackageItem;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->package = packageWith($this->finance, [['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['period' => 'today']]]);
    $this->saved = SavedReport::create(['name' => 'R', 'subject' => 'invoices', 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'invoices', 'columns' => ['number']]]);
});

test('an item is a saved report or a ready-made report, never both or neither', function (array $item) {
    $item = array_map(fn ($v) => $v === ':saved' ? $this->saved->id : $v, $item);

    expect(fn () => ReportPackageItem::create(['report_package_id' => $this->package->id, 'position' => 2, 'settings' => [], ...$item]))
        ->toThrow(QueryException::class);
})->with([
    'saved, no report' => [['kind' => 'saved']],
    'saved with a ready-made key too' => [['kind' => 'saved', 'saved_report_id' => ':saved', 'report' => 'rent_roll']],
    'ready-made, no key' => [['kind' => 'ready_made']],
    'ready-made with a saved report too' => [['kind' => 'ready_made', 'report' => 'rent_roll', 'saved_report_id' => ':saved']],
    'unknown kind' => [['kind' => 'pdf', 'report' => 'rent_roll']],
]);

test('both kinds of item store, and visibility is private or shared', function () {
    $this->package->items()->create(['position' => 2, 'kind' => 'saved', 'saved_report_id' => $this->saved->id, 'settings' => []]);

    expect($this->package->items()->count())->toBe(2)
        ->and(fn () => ReportPackage::create(['name' => 'X', 'visibility' => 'public', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id]))
        ->toThrow(QueryException::class);
});

test('a schedule belongs to exactly one saved report or package', function () {
    $base = ['frequency' => 'daily', 'hour' => 7, 'next_run_at' => now()->addDay(), 'created_by' => $this->finance->id];

    expect(fn () => ReportSchedule::create($base))->toThrow(QueryException::class)
        ->and(fn () => ReportSchedule::create([...$base, 'saved_report_id' => $this->saved->id, 'report_package_id' => $this->package->id]))->toThrow(QueryException::class);

    $forPackage = $this->package->schedules()->create($base);
    $forReport = $this->saved->schedules()->create($base);
    expect($forPackage->saved_report_id)->toBeNull()->and($forPackage->package->is($this->package))->toBeTrue()
        ->and($forReport->report_package_id)->toBeNull()->and($forReport->report->is($this->saved))->toBeTrue();
});

test('items come back in position order, go with their package, and a soft-deleted saved report keeps its item', function () {
    $this->package->items()->create(['position' => 3, 'kind' => 'saved', 'saved_report_id' => $this->saved->id, 'settings' => []]);
    $this->package->items()->create(['position' => 2, 'kind' => 'ready_made', 'report' => 'outstanding', 'settings' => []]);

    expect($this->package->items()->pluck('report')->all())->toBe(['rent_roll', 'outstanding', null]);

    $this->saved->delete();
    expect(ReportPackageItem::where('saved_report_id', $this->saved->id)->exists())->toBeTrue();

    $this->package->forceDelete();
    expect(ReportPackageItem::count())->toBe(0);
});

test('the creator or an admin changes a package; anyone with report access sees a shared one', function () {
    $other = matrixUser(RoleName::Finance, $this->building);
    $leasing = matrixUser(RoleName::Leasing, $this->building);
    $admin = matrixUser(RoleName::Admin, $this->building);
    $nobody = User::factory()->withTwoFactor()->create();

    expect($this->finance->can('view', $this->package))->toBeTrue()
        ->and($other->can('view', $this->package))->toBeFalse()
        ->and($this->finance->can('update', $this->package))->toBeTrue()
        ->and($admin->can('update', $this->package))->toBeTrue()
        ->and($admin->can('delete', $this->package))->toBeTrue()
        ->and($other->can('update', $this->package))->toBeFalse()
        ->and(ReportPackage::visibleTo($other)->count())->toBe(0);

    $this->package->update(['visibility' => 'shared']);

    expect($other->can('view', $this->package))->toBeTrue()
        ->and($leasing->can('view', $this->package))->toBeTrue()
        ->and($nobody->can('view', $this->package))->toBeFalse()
        ->and($other->can('update', $this->package))->toBeFalse()
        ->and($other->can('delete', $this->package))->toBeFalse()
        ->and(ReportPackage::visibleTo($other)->pluck('id')->all())->toBe([$this->package->id]);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/ReportPackages/StorageTest.php`
Expected: FAIL with `Class "App\Models\ReportPackage" not found`.

- [ ] **Step 4: Write the migration** `database/migrations/2026_12_17_000100_create_report_packages_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-17 §5, §7: report packages, their items, and schedules for a saved report OR a package.
 * Plan ruling 5: MySQL refuses a CHECK on a column a foreign-key action uses, so the CHECKed references have no
 * ON DELETE action. Both parents are soft-deleted; deleting a package removes its schedules in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('visibility', 10)->default('private');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['visibility', 'created_by']);
        });
        DB::statement("ALTER TABLE report_packages ADD CONSTRAINT report_packages_visibility_chk CHECK (visibility IN ('private', 'shared'))");

        Schema::create('report_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_package_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('kind', 10);
            $table->foreignId('saved_report_id')->nullable()->constrained();
            $table->string('report', 40)->nullable();
            $table->json('settings');
            $table->timestamps();
            $table->unique(['report_package_id', 'position']);
        });
        DB::statement("ALTER TABLE report_package_items ADD CONSTRAINT report_package_items_kind_chk CHECK (
            (kind = 'saved' AND saved_report_id IS NOT NULL AND report IS NULL)
            OR (kind = 'ready_made' AND report IS NOT NULL AND saved_report_id IS NULL))");

        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropForeign(['saved_report_id']);
        });
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('saved_report_id')->nullable()->change();
            $table->foreign('saved_report_id')->references('id')->on('saved_reports');
            $table->foreignId('report_package_id')->nullable()->after('saved_report_id')->constrained();
        });
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_target_chk CHECK ((saved_report_id IS NULL) <> (report_package_id IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE report_schedules DROP CHECK report_schedules_target_chk');
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('report_package_id');
            $table->dropForeign(['saved_report_id']);
        });
        DB::table('report_schedules')->whereNull('saved_report_id')->delete(); // package schedules cannot go back
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('saved_report_id')->nullable(false)->change();
            $table->foreign('saved_report_id')->references('id')->on('saved_reports')->cascadeOnDelete();
        });
        Schema::dropIfExists('report_package_items');
        Schema::dropIfExists('report_packages');
    }
};
```

- [ ] **Step 5: Write the models and policy.**

`app/Models/ReportPackage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Several reports bundled into one workbook (spec 2026-10-17 §3).
 *
 * @property int $id
 * @property string $name
 * @property string $visibility private | shared
 * @property int $created_by
 * @property int $updated_by
 * @property int|null $items_count
 */
class ReportPackage extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'visibility', 'created_by', 'updated_by'];

    /** @return HasMany<ReportPackageItem, $this> in package order */
    public function items(): HasMany
    {
        return $this->hasMany(ReportPackageItem::class)->orderBy('position');
    }

    /** @return HasMany<ReportSchedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user's own packages and every shared one.
     *
     * @param  Builder<ReportPackage>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('created_by', $user->id)->orWhere('visibility', 'shared'));
    }
}
```

`app/Models/ReportPackageItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report in a package (spec 2026-10-17 §3, §7): a saved report, or a ready-made report key with its settings
 * (building, period preset, the report's own options). Settings are validated again on every run.
 *
 * @property int $id
 * @property int $report_package_id
 * @property int $position
 * @property string $kind saved | ready_made
 * @property int|null $saved_report_id
 * @property string|null $report
 * @property array<string, mixed> $settings
 */
class ReportPackageItem extends Model
{
    protected $fillable = ['report_package_id', 'position', 'kind', 'saved_report_id', 'report', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'position' => 'integer'];
    }

    /** @return BelongsTo<ReportPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ReportPackage::class, 'report_package_id');
    }
}
```

`app/Policies/ReportPackagePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\ReportPackage;
use App\Models\User;

/** Spec 2026-10-17 §3: the creator or settings.manage changes a package; anyone with report access sees a shared one. */
class ReportPackagePolicy
{
    public function view(User $user, ReportPackage $package): bool
    {
        return $this->reports($user) && ($package->created_by === $user->id || $package->visibility === 'shared');
    }

    public function update(User $user, ReportPackage $package): bool
    {
        return $this->reports($user) && ($package->created_by === $user->id || $user->can(PermissionName::SettingsManage));
    }

    public function delete(User $user, ReportPackage $package): bool
    {
        return $this->update($user, $package);
    }

    private function reports(User $user): bool
    {
        return $user->can(PermissionName::ReportsOperational) || $user->can(PermissionName::ReportsFinancial);
    }
}
```

In `app/Models/ReportSchedule.php`, change the docblock line `@property int $saved_report_id` to these two lines:

```php
 * @property int|null $saved_report_id
 * @property int|null $report_package_id
```

Also change the class summary to `A recurring email of a saved report or a report package (spec §7; packages: spec 2026-10-17 §5).` Then replace `$fillable` and add `package()` after `report()`:

```php
    protected $fillable = ['saved_report_id', 'report_package_id', 'frequency', 'day', 'hour', 'next_run_at', 'consecutive_failures', 'paused_at', 'created_by'];
```

```php
    /** @return BelongsTo<ReportPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ReportPackage::class, 'report_package_id');
    }
```

- [ ] **Step 6: Run the tests, including the existing schedule tests**

Run: `php artisan test tests/Feature/ReportPackages/StorageTest.php tests/Feature/ReportBuilder`
Expected: PASS. The 9 new tests pass (the dataset counts 5), and every ReportBuilder test still passes.

- [ ] **Step 7: Migrate the dev database**

Run: `php artisan migrate --database=migrator --force`
Expected: `2026_12_17_000100_create_report_packages_tables ... DONE`.

- [ ] **Step 8: Gates and commit**

Run: `php vendor/bin/pint --dirty` and `php vendor/bin/phpstan analyse --memory-limit=2G`.

```bash
git add database/migrations/2026_12_17_000100_create_report_packages_tables.php app/Models/ReportPackage.php app/Models/ReportPackageItem.php app/Models/ReportSchedule.php app/Policies/ReportPackagePolicy.php tests/Pest.php tests/Feature/ReportPackages/StorageTest.php
git commit -m "$(cat <<'EOF'
Report packages: tables, models, policy; schedules belong to a saved report or a package (exactly-one CHECK)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Run any of the 14 ready-made reports headless

**Files:**
- Modify: `app/Livewire/Concerns/WithActor.php`
- Create: `app/Reports/Packages/PackageReport.php`, `app/Reports/Packages/ReadyMade.php`
- Modify: `app/Livewire/Reports/Concerns/ReportPage.php` (imports; replace `export()`; add methods)
- Modify: `app/Livewire/Reports/Concerns/StatementPage.php` (imports; abstract methods; replace `download()`; add methods)
- Modify: `app/Livewire/Reports/IncomeStatementReport.php`, `app/Livewire/Reports/OwnerIncomeStatementReport.php`
- Modify: the 12 `ReportPage` classes (`implements PackageReport`). The 3 with a total row also get `_kind`.
- Modify: `tests/Pest.php` (fixture and Excel helpers)
- Test: `tests/Feature/ReportPackages/HeadlessTest.php`

**Interfaces:**
- Consumes: `ReportPage` (`title()`, `permission()`, `columns()`, `rows()`, `dateMode()`, `buildingFilter()`, `options()`, `numeric()`, `filterRules()`), and `StatementPage` (`statementRules()`, `scopeRules()`, `ownerOptions()`, the `Statement` type from `IncomeStatement`).
- Produces:
  - `WithActor::asActor(User $as, Closure $run): mixed` (protected; `@template T`).
  - `interface App\Reports\Packages\PackageReport`, with `@phpstan-type Form array{title: string, dates: string, building: bool, options: array<string, array<array-key, string>>, allowed: bool}`:
    - `packageForm(User $as): array` returns a `Form`. `dates` is one of `'range'|'single'|'none'|'months'`. `options` maps each report property to its choices. A `''` choice means the option is optional.
    - `checkSettings(User $as, array $settings): void` throws `ValidationException`.
    - `sheet(User $as, array $settings): array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}` throws `AuthorizationException` or `ValidationException`.
    - `$settings` is the report's own settings. `ReportPage` takes `building`, `from`/`to` (`Y-m-d`) and its option properties. `StatementPage` takes `from`/`to` (`Y-m`), `basis`, and `building` and/or `owner`.
  - `ReadyMade::REPORTS` (`array<string, class-string<PackageReport>>`, 14 entries in Reports page order) and `ReadyMade::make(string $key): PackageReport`.
  - Test helpers: `reportPackageFixture(): array{0: Building, 1: Building, 2: User, 3: Owner}`, `xlsxRows(string $path, ?string $sheet = null): array`, `sheetFile(array $sheet): string`.

- [ ] **Step 1: Add the test helpers.** In `tests/Pest.php`, add these imports: `App\Actions\EnsureNumberSequences`, `App\Actions\Payments\RecordPayment`, `App\Models\Owner`, `App\Models\Unit`, `Spatie\SimpleExcel\SimpleExcelReader`, `Spatie\SimpleExcel\SimpleExcelWriter`. Then append:

```php
/**
 * Report-package fixture (needs roles seeded, a CompanySetting and a travelled clock): buildings AAA and BBB, Finance
 * on both, one active agreement (rent 400) and one issued invoice per building, payments of 100 (10/09/2026) and 300
 * (05/10/2026), and a leased owner contract over a vacant unit in AAA.
 *
 * @return array{0: Building, 1: Building, 2: User, 3: Owner}
 */
function reportPackageFixture(): array
{
    app(EnsureNumberSequences::class)(2026);
    $a = Building::factory()->create(['code' => 'AAA']);
    $b = Building::factory()->create(['code' => 'BBB']);
    $finance = matrixUser(RoleName::Finance, $a);
    $finance->buildings()->attach($b->id);
    $alpha = Customer::factory()->create(['name_en' => 'Alpha Tenant']);
    $beta = Customer::factory()->create(['name_en' => 'Beta Tenant']);
    $agA = activeAgreement(['customer_id' => $alpha->id, 'start_date' => '2025-01-01', 'end_date' => '2026-11-10'], [Unit::factory()->for($a)->create()]);
    $agB = activeAgreement(['customer_id' => $beta->id, 'start_date' => '2025-01-01', 'end_date' => '2027-08-31'], [Unit::factory()->for($b)->create()]);
    issuedInvoice($alpha, [['net' => '900.000', 'au' => $agA->agreementUnits->first()]], '2026-08-01', $agA);
    issuedInvoice($beta, [['net' => '100.000', 'au' => $agB->agreementUnits->first()]], '2026-09-01', $agB);
    $pay = fn (Customer $c, string $on, string $amount) => app(RecordPayment::class)->handle($finance, $c, ['received_on' => $on, 'method' => 'cash', 'amount' => $amount]);
    $pay($alpha, '2026-10-05', '300.000');
    $pay($alpha, '2026-09-10', '100.000');
    $owner = Owner::factory()->create(['name_en' => 'Leila Owner']);
    activeOwnerContract(['owner_id' => $owner->id, 'building_id' => $a->id, 'type' => 'leased', 'rent_amount' => '25.000', 'payment_frequency' => 'monthly',
        'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-03-01', 'end_date' => '2027-02-28'], [Unit::factory()->for($a)->create()]);

    return [$a, $b, $finance, $owner];
}

/** An Excel file's rows as lists (row 0 is the header), blank rows kept; one sheet by name, or the first. */
function xlsxRows(string $path, ?string $sheet = null): array
{
    $reader = SimpleExcelReader::create($path, 'xlsx')->noHeaderRow()->preserveEmptyRows();
    if ($sheet !== null) {
        $reader->fromSheetName($sheet);
    }

    return $reader->getRows()->map(fn (array $r) => array_values($r))->all();
}

/** Writes a headless sheet (header + rows) as its own file, the way a report's export does. */
function sheetFile(array $sheet): string
{
    $path = sys_get_temp_dir().'/sheet-'.Str::uuid().'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addHeader($sheet['columns']);
    foreach ($sheet['rows'] as $row) {
        $writer->addRow($row);
    }
    $writer->close();

    return $path;
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/ReportPackages/HeadlessTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\User;
use App\Reports\Packages\PackageReport;
use App\Reports\Packages\ReadyMade;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    [$this->a, $this->b, $this->finance, $this->owner] = reportPackageFixture();
    $this->hidden = Building::factory()->create();
    // Finance sees every building (buildings.view_all); this user sees only AAA, so the whitelist can be tested.
    $this->scoped = User::factory()->withTwoFactor()->create()->givePermissionTo(['reports.operational', 'reports.financial']);
    $this->scoped->buildings()->attach($this->a->id);
});

test('the registry lists the 14 reports under the keys their exports audit', function () {
    expect(ReadyMade::REPORTS)->toHaveCount(14);
    foreach (ReadyMade::REPORTS as $key => $class) {
        expect($key)->toBe(Str::snake(Str::beforeLast(class_basename($class), 'Report')))
            ->and(ReadyMade::make($key))->toBeInstanceOf(PackageReport::class);
    }

    expect(ReadyMade::make('rent_roll')->packageForm($this->finance))->toBe(['title' => 'Rent roll', 'dates' => 'single', 'building' => true, 'options' => [], 'allowed' => true])
        ->and(ReadyMade::make('expiring_agreements')->packageForm($this->finance)['options'])->toBe(['window' => ['30' => 'Next 30 days', '60' => 'Next 60 days', '90' => 'Next 90 days']])
        ->and(ReadyMade::make('id_documents')->packageForm($this->finance)['building'])->toBeFalse()
        ->and(ReadyMade::make('owner_income_statement')->packageForm($this->finance))->toBe(['title' => 'Owner income statement', 'dates' => 'months', 'building' => false,
            'options' => ['basis' => ['collected' => 'Collected', 'billed' => 'Billed'], 'owner' => [$this->owner->id => 'Leila Owner']], 'allowed' => true])
        ->and(ReadyMade::make('income_statement')->packageForm($this->finance)['options']['owner'])->toBe(['' => 'All owners', $this->owner->id => 'Leila Owner'])
        ->and(ReadyMade::make('income_statement')->packageForm($this->finance)['building'])->toBeTrue();
});

test('each ready-made report runs headless and gives exactly the header and rows its own Excel export writes', function (string $key, array $settings, bool $hasRows) {
    $settings = array_map(fn ($v) => match ($v) { ':a' => $this->a->id, ':owner' => $this->owner->id, default => $v }, $settings);
    $page = Livewire::actingAs($this->finance)->test(ReadyMade::REPORTS[$key]);
    foreach ($settings as $property => $value) {
        $page->set($property, $value);
    }
    $exported = xlsxRows($page->instance()->export()->getFile()->getPathname());

    $sheet = ReadyMade::make($key)->sheet($this->finance, $settings);

    expect(xlsxRows(sheetFile($sheet)))->toBe($exported);
    if ($hasRows) {
        expect(count($exported))->toBeGreaterThan(1);
    }
})->with([
    'rent roll, one building' => ['rent_roll', ['building' => ':a', 'to' => '2026-10-15'], true],
    'occupancy' => ['occupancy', ['to' => '2026-10-15'], true],
    'agreements expiring' => ['expiring_agreements', ['window' => '30'], true],
    'overstays' => ['overstays', [], false],
    'pending approvals' => ['pending_approvals', [], false],
    'ID documents' => ['id_documents', ['window' => '90'], false],
    'cheques' => ['cheques', ['kind' => 'bounced'], false],
    'income statement, one building' => ['income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'billed', 'building' => ':a'], true],
    'owner income statement' => ['owner_income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected', 'owner' => ':owner'], true],
    'outstanding' => ['outstanding', [], true],
    'ageing' => ['ageing', [], true],
    'collections' => ['collections', ['from' => '2026-09-01', 'to' => '2026-10-31'], true],
    'deposits held' => ['deposits_held', ['to' => '2026-10-15'], false],
    'VAT summary' => ['vat_summary', ['from' => '2026-08-01', 'to' => '2026-10-31'], true],
]);

test('a headless run acts as the given user and always puts the signed-in user back', function () {
    $leasingB = matrixUser(RoleName::Leasing, $this->b);
    $this->actingAs($this->finance);

    $sheet = ReadyMade::make('rent_roll')->sheet($leasingB, ['to' => '2026-10-15']);

    expect(auth()->id())->toBe($this->finance->id)
        ->and(collect($sheet['rows'])->pluck(0)->unique()->values()->all())->toBe(['BBB', 'Subtotal BBB', 'Total'])
        ->and($sheet['columns'])->not->toContain('Monthly rent'); // leasing has no reports.financial

    expect(fn () => ReadyMade::make('rent_roll')->sheet($leasingB, ['to' => 'yesterday']))->toThrow(ValidationException::class)
        ->and(auth()->id())->toBe($this->finance->id);

    Auth::forgetUser();
    ReadyMade::make('rent_roll')->sheet($leasingB, ['to' => '2026-10-15']);
    expect(Auth::hasUser())->toBeFalse();
});

test('settings are whitelisted for the user who runs them, and tampered values are messages, never a 500', function (string $key, array $settings, string $error) {
    $settings = array_map(fn ($v) => match ($v) { ':hidden' => $this->hidden->id, ':owner' => $this->owner->id, default => $v }, $settings);

    foreach (['checkSettings', 'sheet'] as $method) {
        try {
            ReadyMade::make($key)->{$method}($this->scoped, $settings);
            $this->fail("{$method} accepted the settings");
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($error);
        }
    }
})->with([
    'a building the user cannot see' => ['rent_roll', ['to' => '2026-10-15', 'building' => ':hidden'], 'building'],
    'a building as an array' => ['rent_roll', ['to' => '2026-10-15', 'building' => ['1']], 'building'],
    'an unknown option' => ['expiring_agreements', ['window' => '45'], 'window'],
    'an option as an array' => ['expiring_agreements', ['window' => ['30']], 'window'],
    'a missing option' => ['expiring_agreements', [], 'window'],
    'dates the wrong way round' => ['collections', ['from' => '2026-10-31', 'to' => '2026-10-01'], 'to'],
    'a date as an array' => ['rent_roll', ['to' => ['2026-10-15']], 'to'],
    'an unknown basis' => ['income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'cash'], 'basis'],
    'a statement building the user cannot see' => ['income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'billed', 'building' => ':hidden'], 'building'],
    'more than 12 months' => ['income_statement', ['from' => '2025-01', 'to' => '2026-10', 'basis' => 'billed'], 'to'],
    'an owner statement without an owner' => ['owner_income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected'], 'owner'],
    'an unknown owner' => ['owner_income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected', 'owner' => 999999], 'owner'],
]);

test('valid settings pass the check', function () {
    ReadyMade::make('owner_income_statement')->checkSettings($this->scoped, ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected', 'owner' => $this->owner->id]);
    ReadyMade::make('income_statement')->checkSettings($this->scoped, ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'collected', 'building' => '', 'owner' => '']);
    ReadyMade::make('rent_roll')->checkSettings($this->scoped, ['to' => '2026-10-15', 'building' => (string) $this->a->id]);

    expect(true)->toBeTrue();
});

test('a user without the report\'s permission is refused', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->a);

    expect(ReadyMade::make('outstanding')->packageForm($leasing)['allowed'])->toBeFalse()
        ->and(ReadyMade::make('income_statement')->packageForm($leasing)['allowed'])->toBeFalse()
        ->and(ReadyMade::make('rent_roll')->packageForm($leasing)['allowed'])->toBeTrue()
        ->and(fn () => ReadyMade::make('outstanding')->sheet($leasing, []))->toThrow(AuthorizationException::class)
        ->and(fn () => ReadyMade::make('income_statement')->sheet($leasing, ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'billed']))->toThrow(AuthorizationException::class);
});

test('the headline is the first money figure of the last total row, or none', function () {
    $headline = fn (string $key, array $settings) => ReadyMade::make($key)->sheet($this->finance, $settings)['headline'];

    expect($headline('rent_roll', ['to' => '2026-10-15']))->toBe('Monthly rent: 800.000')
        ->and($headline('collections', ['from' => '2026-09-01', 'to' => '2026-10-31']))->toBe('Amount: 400.000')
        ->and($headline('vat_summary', ['from' => '2026-08-01', 'to' => '2026-10-31']))->toBe('VAT: 0.000')
        ->and($headline('outstanding', []))->toBeNull()
        ->and($headline('income_statement', ['from' => '2026-08', 'to' => '2026-10', 'basis' => 'billed']))->toStartWith('Result: ');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/ReportPackages/HeadlessTest.php`
Expected: FAIL with `Class "App\Reports\Packages\ReadyMade" not found`.

- [ ] **Step 4: `asActor()` in `app/Livewire/Concerns/WithActor.php`.** Add `use Closure;` and `use Illuminate\Support\Facades\Auth;`, then add this method after `actor()`:

```php
    /**
     * Runs $run signed in as $as, then puts back whoever was signed in, even when $run throws. Lets a report package
     * run a ready-made report as the person it is for (spec 2026-10-17 §4).
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    protected function asActor(User $as, Closure $run): mixed
    {
        $previous = Auth::hasUser() ? Auth::user() : null;
        Auth::setUser($as);
        try {
            return $run();
        } finally {
            if ($previous === null) {
                Auth::forgetUser();
            } else {
                Auth::setUser($previous);
            }
        }
    }
```

- [ ] **Step 5: The interface and the registry.**

`app/Reports/Packages/PackageReport.php`:

```php
<?php

namespace App\Reports\Packages;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * A ready-made report a package can run headless (spec 2026-10-17 §2, §4). Implemented by the ReportPage and
 * StatementPage traits, so the sheet is exactly what the report's own Excel export writes.
 *
 * @phpstan-type Form array{title: string, dates: string, building: bool, options: array<string, array<array-key, string>>, allowed: bool}
 */
interface PackageReport
{
    /**
     * What a package item may set: dates 'range' | 'single' | 'none' | 'months', whether there is a building filter,
     * each option's choices (a '' choice = optional), and whether $as may run the report at all.
     *
     * @return Form
     */
    public function packageForm(User $as): array;

    /**
     * @param  array<array-key, mixed>  $settings  the report's own settings (dates already resolved)
     *
     * @throws ValidationException
     */
    public function checkSettings(User $as, array $settings): void;

    /**
     * @param  array<array-key, mixed>  $settings
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}
     *
     * @throws AuthorizationException when $as may not run the report
     * @throws ValidationException when the settings do not validate for $as
     */
    public function sheet(User $as, array $settings): array;
}
```

`app/Reports/Packages/ReadyMade.php`:

```php
<?php

namespace App\Reports\Packages;

use App\Livewire\Reports\AgeingReport;
use App\Livewire\Reports\ChequesReport;
use App\Livewire\Reports\CollectionsReport;
use App\Livewire\Reports\DepositsHeldReport;
use App\Livewire\Reports\ExpiringAgreementsReport;
use App\Livewire\Reports\IdDocumentsReport;
use App\Livewire\Reports\IncomeStatementReport;
use App\Livewire\Reports\OccupancyReport;
use App\Livewire\Reports\OutstandingReport;
use App\Livewire\Reports\OverstaysReport;
use App\Livewire\Reports\OwnerIncomeStatementReport;
use App\Livewire\Reports\PendingApprovalsReport;
use App\Livewire\Reports\RentRollReport;
use App\Livewire\Reports\VatSummaryReport;
use InvalidArgumentException;

/** Spec 2026-10-17 §3: the 14 reports on ReportPage / StatementPage, keyed as their exports audit them, in Reports page order. */
final class ReadyMade
{
    /** @var array<string, class-string<PackageReport>> */
    public const array REPORTS = [
        'rent_roll' => RentRollReport::class,
        'occupancy' => OccupancyReport::class,
        'expiring_agreements' => ExpiringAgreementsReport::class,
        'overstays' => OverstaysReport::class,
        'pending_approvals' => PendingApprovalsReport::class,
        'id_documents' => IdDocumentsReport::class,
        'cheques' => ChequesReport::class,
        'income_statement' => IncomeStatementReport::class,
        'owner_income_statement' => OwnerIncomeStatementReport::class,
        'outstanding' => OutstandingReport::class,
        'ageing' => AgeingReport::class,
        'collections' => CollectionsReport::class,
        'deposits_held' => DepositsHeldReport::class,
        'vat_summary' => VatSummaryReport::class,
    ];

    /** A fresh, unmounted instance (plan ruling 1): its sheet() sets everything it needs. */
    public static function make(string $key): PackageReport
    {
        $class = self::REPORTS[$key] ?? throw new InvalidArgumentException("Unknown ready-made report [{$key}].");

        return new $class;
    }
}
```

- [ ] **Step 6: `ReportPage`.** Add these imports: `App\Models\User`, `Illuminate\Auth\Access\AuthorizationException`, `Illuminate\Validation\Rule`. Replace the whole `export()` method with the version below, and add the new methods after it:

```php
    public function export(): BinaryFileResponse
    {
        abort_unless($this->permission(), 403);
        $this->validate($this->filterRules());
        $report = Str::snake(Str::beforeLast(class_basename(static::class), 'Report'));
        $dates = match ($this->dateMode()) {
            'range' => ['from' => $this->from, 'to' => $this->to],
            'single' => ['to' => $this->to],
            default => [],
        };
        Audit::log('report.exported', properties: ['report' => $report, ...($this->buildingFilter() ? ['building' => $this->building] : []), ...$dates,
            ...array_intersect_key($this->all(), $this->options())], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-'.$report.'-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        $sheet = $this->sheetData();
        $writer->addHeader($sheet['columns']);
        foreach ($sheet['rows'] as $row) {
            $writer->addRow($row);
        }
        $writer->close();

        $date = $dates === [] ? now('Asia/Bahrain')->toDateString() : $this->to;

        return response()->download($path, "{$report}-{$date}.xlsx")->deleteFileAfterSend();
    }

    /** @return array{title: string, dates: string, building: bool, options: array<string, array<array-key, string>>, allowed: bool} */
    public function packageForm(User $as): array
    {
        return $this->asActor($as, fn () => ['title' => $this->title(), 'dates' => $this->dateMode(), 'building' => $this->buildingFilter(),
            'options' => $this->options(), 'allowed' => $this->permission()]);
    }

    /** @param  array<array-key, mixed>  $settings */
    public function checkSettings(User $as, array $settings): void
    {
        $this->asActor($as, fn () => Validator::make($settings, $this->packageRules($as))->validate());
    }

    /**
     * Spec 2026-10-17 §4: what export() writes, for $settings, run as $as. Validated before anything is assigned, so a
     * tampered value never reaches a typed property.
     *
     * @param  array<array-key, mixed>  $settings
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}
     */
    public function sheet(User $as, array $settings): array
    {
        return $this->asActor($as, function () use ($as, $settings) {
            if (! $this->permission()) {
                throw new AuthorizationException(__('You do not have access to this report.'));
            }
            $data = Validator::make($settings, $this->packageRules($as))->validate();
            $today = now('Asia/Bahrain')->toDateString();
            $this->building = $this->buildingFilter() && filled($data['building'] ?? null) ? (int) $data['building'] : null;
            $this->from = (string) ($data['from'] ?? $today);
            $this->to = (string) ($data['to'] ?? $today);
            foreach (array_keys($this->options()) as $property) {
                $this->{$property} = (string) $data[$property];
            }

            return $this->sheetData();
        });
    }

    /**
     * Plan ruling 4: the screen's filter rules, every value a string, and only a building $as can see.
     *
     * @return array<string, list<mixed>>
     */
    private function packageRules(User $as): array
    {
        $rules = array_map(fn (array $r) => [...$r, 'string'], $this->filterRules());
        $rules['building'] = ['nullable', 'integer', Rule::in(Building::visibleTo($as)->pluck('id')->all())];

        return $rules;
    }

    /**
     * The export's header and rows, plus the headline (plan ruling 8): the first 3-dp amount among the numeric columns
     * of the last total row.
     *
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}
     */
    private function sheetData(): array
    {
        $columns = $this->columns();
        $rows = $this->rows();
        $headline = null;
        foreach (array_reverse($rows) as $row) {
            if (($row['_kind'] ?? null) !== 'total') {
                continue;
            }
            foreach ($this->numeric() as $key) {
                $value = $row[$key] ?? null;
                if (isset($columns[$key]) && is_string($value) && preg_match('/^-?\d+\.\d{3}$/', $value) === 1) {
                    $headline = "{$columns[$key]}: {$value}";
                    break;
                }
            }
            break;
        }

        return [
            'columns' => array_values($columns),
            'rows' => array_map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? '', array_keys($columns)), $rows),
            'headline' => $headline,
        ];
    }
```

- [ ] **Step 7: `StatementPage`.** Add these imports: `App\Models\User`, `App\Support\Fils`, `Illuminate\Auth\Access\AuthorizationException`, `Illuminate\Validation\ValidationException`. Add the abstract methods after `resetScope()`:

```php
    abstract protected function title(): string;

    /**
     * The screen's own inputs a package item may set, with their choices; a '' choice means optional.
     *
     * @return array<string, array<array-key, string>>
     */
    abstract protected function scopeOptions(): array;

    /** @return Statement|null */
    abstract protected function compute(): ?array;
```

Replace the whole `download()` method, and add the package methods after it:

```php
    /**
     * Spec §4: the same table, one row per line, months as columns. Audited like the other reports.
     *
     * @param  Statement  $statement
     * @param  array<string, int|null>  $properties
     */
    protected function download(string $report, array $statement, array $properties): BinaryFileResponse
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        Audit::log('report.exported', properties: ['report' => $report, 'from' => $this->from, 'to' => $this->to, 'basis' => $this->basis, ...$properties], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-'.$report.'-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        $sheet = $this->statementSheet($statement);
        $writer->addHeader($sheet['columns']);
        foreach ($sheet['rows'] as $row) {
            $writer->addRow($row);
        }
        $writer->close();

        return response()->download($path, Str::slug($report)."-{$this->from}-{$this->to}.xlsx")->deleteFileAfterSend();
    }

    /**
     * The Excel table. A month whose fee is not final carries a " *" on its header, so the figures stay numeric, and the
     * footnote follows the rows.
     *
     * @param  Statement  $statement
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>}
     */
    protected function statementSheet(array $statement): array
    {
        $pending = $statement['fee_pending'] ?? [];
        $columns = [__('Line'), ...array_map(fn (string $m) => CarbonImmutable::parse($m.'-01')->format('m/Y').(in_array($m, $pending, true) ? ' *' : ''), $statement['months']), __('Total')];
        $rows = [];
        foreach ($statement['rows'] as $row) {
            $rows[] = [$row['label'], ...array_map(fn (?int $v) => $v === null ? '–' : $v / 1000, array_values($row['amounts'])), $row['total'] / 1000];
        }
        if ($pending !== []) {
            $rows[] = [__('Fee not final until the owner statement for this month is finalised.')];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @return array{title: string, dates: string, building: bool, options: array<string, array<array-key, string>>, allowed: bool} */
    public function packageForm(User $as): array
    {
        return $this->asActor($as, fn () => ['title' => $this->title(), 'dates' => 'months', 'building' => array_key_exists('building', $this->scopeRules()),
            'options' => ['basis' => [IncomeStatement::COLLECTED => __('Collected'), IncomeStatement::BILLED => __('Billed')], ...$this->scopeOptions()],
            'allowed' => $as->can('reports.financial')]);
    }

    /** @param  array<array-key, mixed>  $settings */
    public function checkSettings(User $as, array $settings): void
    {
        $this->asActor($as, function () use ($settings) {
            $this->loadSettings($settings);
            $this->packageValidator()->validate();
        });
    }

    /**
     * Spec 2026-10-17 §4: what download() writes, for $settings, run as $as.
     *
     * @param  array<array-key, mixed>  $settings
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}
     */
    public function sheet(User $as, array $settings): array
    {
        return $this->asActor($as, function () use ($as, $settings) {
            if (! $as->can('reports.financial')) {
                throw new AuthorizationException(__('You do not have access to this report.'));
            }
            $this->loadSettings($settings);
            $this->packageValidator()->validate();
            $statement = $this->compute() ?? throw ValidationException::withMessages(['owner' => __('Choose an owner.')]);
            $headline = null;
            foreach ($statement['rows'] as $row) {
                if ($row['kind'] === 'total') {
                    $headline = $row['label'].': '.Fils::toDecimal($row['total']); // the last total row: Result / Net income to owner
                }
            }

            return [...$this->statementSheet($statement), 'headline' => $headline];
        });
    }

    /**
     * The props are untyped (mixed), so any value is assigned and then validated.
     *
     * @param  array<array-key, mixed>  $settings
     */
    private function loadSettings(array $settings): void
    {
        foreach (['from', 'to', 'basis', ...array_keys($this->scopeRules())] as $key) {
            $this->{$key} = $settings[$key] ?? null;
        }
    }

    /** The screen's rules; an option offered without a '' choice is required (the owner of an owner statement). */
    private function packageValidator(): \Illuminate\Validation\Validator
    {
        $rules = $this->statementRules();
        foreach ($this->scopeOptions() as $key => $choices) {
            if (! array_key_exists('', $choices)) {
                $rules[$key] = ['required', ...$rules[$key]];
            }
        }

        return Validator::make($this->all(), $rules);
    }
```

Note: `Validator::make()` returns `Illuminate\Validation\Validator`. If Pint prefers an import, add `use Illuminate\Validation\Validator as ValidationValidator;` and use that name.

- [ ] **Step 8: The two statement classes.** In `IncomeStatementReport.php`, add `use App\Reports\Packages\PackageReport;` and change the class line to `class IncomeStatementReport extends Component implements PackageReport`. Change `private function compute(): array` to `protected function compute(): array`, and add:

```php
    protected function title(): string
    {
        return __('Income statement');
    }

    /** @return array<string, array<array-key, string>> */
    protected function scopeOptions(): array
    {
        return ['owner' => ['' => __('All owners')] + $this->ownerOptions()];
    }
```

In `OwnerIncomeStatementReport.php`, add the same import and `implements PackageReport`. Change `private function compute(): ?array` to `protected function compute(): ?array`, and add:

```php
    protected function title(): string
    {
        return __('Owner income statement');
    }

    /** @return array<string, array<array-key, string>> */
    protected function scopeOptions(): array
    {
        return ['owner' => $this->ownerOptions()];
    }
```

- [ ] **Step 9: The 12 `ReportPage` classes.** In each of `RentRollReport`, `OccupancyReport`, `ExpiringAgreementsReport`, `OverstaysReport`, `PendingApprovalsReport`, `IdDocumentsReport`, `ChequesReport`, `OutstandingReport`, `AgeingReport`, `CollectionsReport`, `DepositsHeldReport` and `VatSummaryReport`, add `use App\Reports\Packages\PackageReport;` and change `extends Component` to `extends Component implements PackageReport`. Then tag the three total rows (ruling 8):

`DepositsHeldReport::rows()`:

```php
            $rows[] = ['unit' => __('Total'), 'agreement' => '', 'customer' => '', 'held' => Fils::toDecimal($total), '_kind' => 'total'];
```

`CollectionsReport::rows()`:

```php
        $rows[] = ['date' => '', 'method' => __('Total collected'), 'count' => $collected->count(), 'amount' => Fils::toDecimal($collected->sum(fn ($p) => Fils::fromDecimal($p->amount))), '_kind' => 'total'];
```

`VatSummaryReport::rows()`:

```php
        $rows[] = ['source' => __('Total output VAT'), 'category' => '', 'net' => '', 'vat' => Fils::toDecimal($total), '_kind' => 'total'];
```

- [ ] **Step 10: Run the tests**

Run: `php artisan test tests/Feature/ReportPackages/HeadlessTest.php tests/Feature/Reports tests/Feature/Payments/SplitPaymentTest.php`
Expected: PASS. The existing report tests still pass with the refactored exports and the bold total rows (`reportRowText` strips tags).

- [ ] **Step 11: Gates and commit**

Run: `php vendor/bin/pint --dirty` and `php vendor/bin/phpstan analyse --memory-limit=2G`.

```bash
git add app/Livewire/Concerns/WithActor.php app/Reports/Packages/PackageReport.php app/Reports/Packages/ReadyMade.php app/Livewire/Reports tests/Pest.php tests/Feature/ReportPackages/HeadlessTest.php
git commit -m "$(cat <<'EOF'
Ready-made reports run headless: sheet() as a given user returns exactly the export's header and rows, settings whitelisted, user always restored

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `PackageRunner` and `PackageWorkbook`

**Files:**
- Modify: `app/Reports/Builder/ExcelWriter.php` (whole file)
- Create: `app/Reports/Packages/PackageSheet.php`, `PackageResult.php`, `PackageRunner.php`, `PackageWorkbook.php`
- Test: `tests/Feature/ReportPackages/RunnerTest.php`

**Interfaces:**
- Consumes: `ReadyMade::make()`, `PackageReport::packageForm/sheet` (Task 2), `ReportPackage::items()` (Task 1), `Engine::run($user, $def, limit: null)`, `ReportDefinition::forViewer/fromArray`, `Registry::forUser`, `Period::presets/resolve/label`, `Formatter::total`.
- Produces:
  - `ExcelWriter::sheet(ReportResult $result): array{columns: list<string>, rows: list<array<array-key, mixed>>}`. `ExcelWriter::write()` is unchanged on the outside.
  - `final readonly class PackageSheet(string $title, string $periodLabel, array $columns = [], array $rows = [], int $rowCount = 0, ?string $headline = null, ?string $skipped = null, ?string $note = null)` and `PackageSheet::skip(string $title, string $reason): self`.
  - `final readonly class PackageResult(string $name, list<PackageSheet> $sheets)`, with `allSkipped(): bool`, `periodLabel(): string`, `lines(): list<string>` and `skippedLines(): list<string>`.
  - `class PackageRunner(Engine $engine)`, with `MAX_ITEMS = 15`, `run(ReportPackage $package, User $as, CarbonImmutable $now): PackageResult`, `static presets(): array<string, string>`, `static clean(array $form, array $settings): array` and `static resolve(array $form, array $settings, CarbonImmutable $now): array{settings: array<array-key, mixed>, period: string}`. `resolve()` throws `ValidationException` keyed `period`.
  - `PackageWorkbook::write(PackageResult $result, string $path): void` and `PackageWorkbook::sheetName(string $title, array $used): string`.
  - Skip reasons, verbatim: `Report deleted`, `You do not have access to this report.`, `This report is no longer available.`, `Its settings are no longer valid: <first message>`.

- [ ] **Step 1: Write the failing test** `tests/Feature/ReportPackages/RunnerTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\RentRollReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\SavedReport;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Packages\PackageResult;
use App\Reports\Packages\PackageRunner;
use App\Reports\Packages\PackageSheet;
use App\Reports\Packages\PackageWorkbook;
use App\Reports\Packages\ReadyMade;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    [$this->a, $this->b, $this->finance, $this->owner] = reportPackageFixture();
    $this->saved = SavedReport::create(['name' => 'Monthly collections', 'subject' => 'payments', 'visibility' => 'shared', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['number', 'amount'], 'period' => ['field' => 'received_on', 'preset' => 'last_month'],
            'totals' => ['amount' => 'sum'], 'sort' => ['field' => 'number', 'dir' => 'asc']]]);
    $this->package = packageWith($this->finance, [
        ['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => null, 'period' => 'today']],
        ['kind' => 'ready_made', 'report' => 'collections', 'settings' => ['building' => null, 'period' => 'last_month']],
        ['kind' => 'saved', 'saved_report_id' => $this->saved->id],
    ]);
    $this->run = fn ($user, $package = null) => app(PackageRunner::class)->run($package ?? $this->package, $user, CarbonImmutable::now('Asia/Bahrain'));
});

test('items run in package order as the given user, each with its title, real dates, rows and headline', function () {
    $result = ($this->run)($this->finance);

    expect(collect($result->sheets)->map(fn (PackageSheet $s) => [$s->title, $s->periodLabel, $s->rowCount, $s->headline, $s->skipped])->all())->toBe([
        ['Rent roll', 'As at 15/10/2026', 6, 'Monthly rent: 800.000', null],
        ['Collections by date and method', '01/09/2026 – 30/09/2026', 2, 'Amount: 100.000', null],
        ['Monthly collections', '01/09/2026 – 30/09/2026', 1, 'Amount: 100.000', null],
    ])
        ->and($result->sheets[0]->columns)->toBe(ReadyMade::make('rent_roll')->sheet($this->finance, ['to' => '2026-10-15'])['columns'])
        ->and($result->periodLabel())->toBe('As at 15/10/2026')
        ->and($result->allSkipped())->toBeFalse()
        ->and($result->lines()[0])->toBe('Rent roll, As at 15/10/2026: 6 rows · Monthly rent: 800.000')
        ->and($result->skippedLines())->toBe([]);
});

test('relative periods are worked out again on every run', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Asia/Bahrain'));

    $result = ($this->run)($this->finance);

    expect(collect($result->sheets)->pluck('periodLabel')->all())->toBe(['As at 03/11/2026', '01/10/2026 – 31/10/2026', '01/10/2026 – 31/10/2026'])
        ->and($result->sheets[1]->headline)->toBe('Amount: 300.000');
});

test('a package period resolves to the report\'s own dates', function (string $dates, string $preset, array $settings, string $label) {
    $run = PackageRunner::resolve(['title' => 'X', 'dates' => $dates, 'building' => true, 'options' => [], 'allowed' => true],
        ['building' => 4, 'period' => $preset], CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));

    expect($run)->toBe(['settings' => ['building' => 4, ...$settings], 'period' => $label]);
})->with([
    'range' => ['range', 'last_quarter', ['from' => '2026-07-01', 'to' => '2026-09-30'], '01/07/2026 – 30/09/2026'],
    'single: the period\'s last day' => ['single', 'this_month', ['to' => '2026-10-31'], 'As at 31/10/2026'],
    'single: today' => ['single', 'today', ['to' => '2026-10-15'], 'As at 15/10/2026'],
    'no dates' => ['none', 'last_month', [], 'As at 15/10/2026'],
    'months' => ['months', 'last_year', ['from' => '2025-01', 'to' => '2025-12'], '01/01/2025 – 31/12/2025'],
    'months: never past this month' => ['months', 'this_year', ['from' => '2026-01', 'to' => '2026-10'], '01/01/2026 – 31/10/2026'],
    'months: last 7 days' => ['months', 'last_7_days', ['from' => '2026-10', 'to' => '2026-10'], '01/10/2026 – 31/10/2026'],
]);

test('a period that is not a relative preset is refused', function (mixed $period) {
    expect(fn () => PackageRunner::resolve(['title' => 'X', 'dates' => 'range', 'building' => false, 'options' => [], 'allowed' => true],
        ['period' => $period], CarbonImmutable::now('Asia/Bahrain')))->toThrow(ValidationException::class);
})->with(['custom' => ['custom'], 'unknown' => ['fortnight'], 'array' => [['last_month']], 'missing' => [null]]);

test('clean keeps only what the report takes, and an empty building means all', function () {
    $form = ReadyMade::make('expiring_agreements')->packageForm($this->finance);

    expect(PackageRunner::clean($form, ['building' => '', 'period' => 'today', 'window' => '60', 'from' => '2020-01-01', 'evil' => 1]))->toBe(['building' => null, 'window' => '60'])
        ->and(PackageRunner::clean($form, ['building' => (string) $this->a->id]))->toBe(['building' => $this->a->id])
        ->and(PackageRunner::presets())->not->toHaveKey('custom')->toHaveKey('last_month');
});

test('an item that cannot run is skipped with a note, never a 500, and the rest still run', function () {
    $hidden = Building::factory()->create();
    $leasing = matrixUser(RoleName::Leasing, $this->a);
    $package = packageWith($this->finance, [
        ['kind' => 'saved', 'saved_report_id' => $this->saved->id],
        ['kind' => 'ready_made', 'report' => 'outstanding', 'settings' => ['building' => null]],
        ['kind' => 'ready_made', 'report' => 'expiring_agreements', 'settings' => ['building' => null, 'window' => '45']],
        ['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => $hidden->id, 'period' => 'today']],
        ['kind' => 'ready_made', 'report' => 'retired_report', 'settings' => []],
        ['kind' => 'ready_made', 'report' => 'occupancy', 'settings' => ['building' => null, 'period' => 'today']],
    ]);
    $this->saved->delete();

    $result = ($this->run)($leasing, $package);

    expect(collect($result->sheets)->map(fn (PackageSheet $s) => [$s->title, $s->skipped])->all())->toBe([
        ['Monthly collections', 'Report deleted'],
        ['Outstanding by tenant', 'You do not have access to this report.'],
        ['Agreements expiring', 'Its settings are no longer valid: The selected window is invalid.'],
        ['Rent roll', 'Its settings are no longer valid: The selected building is invalid.'],
        ['retired_report', 'This report is no longer available.'],
        ['Unit availability and occupancy', null],
    ])
        ->and($result->allSkipped())->toBeFalse()
        ->and($result->skippedLines()[0])->toBe('Monthly collections: Report deleted');
});

test('a saved report the user may not use is skipped; one with hidden fields runs without them and says so', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->a);
    $tenants = SavedReport::create(['name' => 'Tenant balances', 'subject' => 'tenants', 'visibility' => 'shared', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'tenants', 'columns' => ['name', 'balance'], 'totals' => ['balance' => 'sum'], 'sort' => ['field' => 'name', 'dir' => 'asc']]]);

    $result = ($this->run)($leasing, packageWith($this->finance, [['kind' => 'saved', 'saved_report_id' => $this->saved->id], ['kind' => 'saved', 'saved_report_id' => $tenants->id]]));

    expect($result->sheets[0]->skipped)->toBe('You do not have access to this report.')
        ->and($result->sheets[1]->skipped)->toBeNull()
        ->and($result->sheets[1]->columns)->toBe(['Tenant'])
        ->and($result->sheets[1]->note)->toContain('Invoice balances')
        ->and($result->sheets[1]->headline)->toBeNull();
});

test('the workbook has one sheet per item in order, each with a title, a period and a blank row above the report\'s own rows', function () {
    $path = sys_get_temp_dir().'/pkg-'.Str::uuid().'.xlsx';
    PackageWorkbook::write(($this->run)($this->finance), $path);
    $exported = xlsxRows(Livewire::actingAs($this->finance)->test(RentRollReport::class)->instance()->export()->getFile()->getPathname());
    $savedFile = sys_get_temp_dir().'/saved-'.Str::uuid().'.xlsx';
    ExcelWriter::write(app(Engine::class)->run($this->finance, ReportDefinition::fromArray($this->saved->definition, $this->finance), limit: null), $savedFile);
    $rent = xlsxRows($path, 'Rent roll');

    expect(SimpleExcelReader::create($path)->getSheetNames())->toBe(['Rent roll', 'Collections by date and method', 'Monthly collections'])
        ->and(array_slice($rent, 0, 2))->toBe([['Rent roll'], ['As at 15/10/2026']])
        ->and(array_filter($rent[2], fn ($v) => $v !== '' && $v !== null))->toBe([])
        ->and(array_slice($rent, 3))->toBe($exported)
        ->and(array_slice(xlsxRows($path, 'Monthly collections'), 3))->toBe(xlsxRows($savedFile));
});

test('sheet names lose characters Excel forbids, are cut to 31 characters and number their clashes; a skipped item shows its reason', function () {
    $titles = ['Rent roll', 'Rent roll', 'A/B: [x]*?\\', str_repeat('x', 40), str_repeat('x', 40), 'Rent Roll', "'quoted'"];
    $sheets = [...array_map(fn (string $t) => new PackageSheet($t, 'P', ['A'], [['1']], 1), $titles), PackageSheet::skip('Gone', 'Report deleted')];
    $path = sys_get_temp_dir().'/names-'.Str::uuid().'.xlsx';

    PackageWorkbook::write(new PackageResult('P', $sheets), $path);

    expect(SimpleExcelReader::create($path)->getSheetNames())->toBe(['Rent roll', 'Rent roll (2)', 'AB x', str_repeat('x', 31), str_repeat('x', 27).' (2)', 'Rent Roll (3)', 'quoted', 'Gone'])
        ->and(xlsxRows($path, 'Gone'))->toBe([['Gone'], ['Report deleted']]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ReportPackages/RunnerTest.php`
Expected: FAIL with `Class "App\Reports\Packages\PackageRunner" not found`.

- [ ] **Step 3: `ExcelWriter` returns its rows.** Replace `app/Reports/Builder/ExcelWriter.php` with the version below. The file it writes is identical; `sheet()` just collects the rows that `write()` used to send straight to the writer.

```php
<?php

namespace App\Reports\Builder;

use Spatie\SimpleExcel\SimpleExcelWriter;

/** The builder's Excel file: one header row, raw values, then the grand total (spec §5). */
final class ExcelWriter
{
    public static function write(ReportResult $result, string $path): void
    {
        $sheet = self::sheet($result);
        $writer = SimpleExcelWriter::create($path);
        $writer->addHeader($sheet['columns']);
        foreach ($sheet['rows'] as $row) {
            $writer->addRow($row);
        }
        $writer->close();
    }

    /**
     * The file's header and rows; also one sheet of a report package (spec 2026-10-17 §4).
     *
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>}
     */
    public static function sheet(ReportResult $result): array
    {
        $rows = [];
        if ($result->groups === []) {
            foreach ($result->rows as $row) {
                $rows[] = self::line($result->columns, $row);
            }
        } else {
            $deepest = collect($result->groups)->max('level');
            $byGroup = Grouping::rowsByGroup($result->rows);
            foreach ($result->groups as $g) {
                $rows[] = self::subtotal($result->columns, $g);
                if ($g['level'] === $deepest) {
                    foreach ($byGroup[Grouping::keyId($g['keys'])] ?? [] as $row) {
                        $rows[] = self::line($result->columns, $row);
                    }
                }
            }
        }
        $total = [];
        foreach ($result->columns as $i => $col) {
            $total[$col->label] = $i === 0 ? __('Total (:n rows)', ['n' => $result->rowCount]) : '';
            foreach ($result->grandTotals as $key => $value) {
                if (str_starts_with($key, $col->key.':')) {
                    $total[$col->label] = is_numeric($value) ? (float) $value : $value;
                }
            }
        }
        $rows[] = $total;
        if ($result->comparison !== null) {
            array_push($rows, ...self::comparison($result));
        }

        return ['columns' => array_map(fn (Field $f) => $f->label, $result->columns), 'rows' => $rows];
    }

    /**
     * A blank row, then Compared | per total: this period, comparison period, change, change %. Plain lists, so the main header does not apply.
     *
     * @return list<list<mixed>>
     */
    private static function comparison(ReportResult $result): array
    {
        $cmp = $result->comparison ?? [];
        $labels = collect($result->columns)->mapWithKeys(fn (Field $f) => [$f->key => $f->label]);
        $keys = array_map(strval(...), array_keys($cmp['rows'][0]['current'] ?? ['count' => 0]));
        $rows = [[], [__('Comparison')]];
        $head = [__('Compared')];
        foreach ($keys as $k) {
            $name = $k === 'count' ? __('Rows') : ($labels[explode(':', $k)[0]] ?? $k).' ('.__(explode(':', $k)[1] ?? '').')';
            array_push($head, "{$name}: {$cmp['label']}", "{$name}: {$cmp['compareLabel']}", "{$name}: ".__('Change'), "{$name}: ".__('Change %'));
        }
        $rows[] = $head;
        foreach ($cmp['rows'] as $r) {
            $line = [$r['label']];
            foreach ($keys as $k) {
                $ch = Comparison::change($r['current'][$k] ?? null, $r['previous'][$k] ?? null);
                $num = fn (mixed $v) => is_numeric($v) ? (float) $v : '';
                array_push($line, $num($r['current'][$k] ?? null), $num($r['previous'][$k] ?? null), $num($ch['diff']), $num($ch['pct']));
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * @param  list<Field>  $columns
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private static function subtotal(array $columns, array $group): array
    {
        $line = [];
        foreach ($columns as $i => $col) {
            $line[$col->label] = $i === 0 ? ($group['level'] === 2 ? '  ' : '').__('Subtotal: :label (:n)', ['label' => $group['label'], 'n' => $group['totals']['count']]) : '';
            foreach ($group['totals'] as $key => $value) {
                if (str_starts_with($key, $col->key.':')) {
                    $line[$col->label] = is_numeric($value) ? (float) $value : (string) $value;
                }
            }
        }

        return $line;
    }

    /**
     * @param  list<Field>  $columns
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function line(array $columns, array $row): array
    {
        $line = [];
        foreach ($columns as $col) {
            $raw = $row[$col->key] ?? null;
            $line[$col->label] = match (true) {
                $raw === null => '',
                $col->type->numeric() => (float) $raw,
                $col->type === FieldType::Enum, $col->type === FieldType::Date, $col->type === FieldType::Boolean => Formatter::display($col, $raw),
                default => (string) $raw,
            };
        }

        return $line;
    }
}
```

- [ ] **Step 4: The result types.**

`app/Reports/Packages/PackageSheet.php`:

```php
<?php

namespace App\Reports\Packages;

/** One item's sheet in a package (spec 2026-10-17 §4). A skipped item carries only its title and the reason. */
final readonly class PackageSheet
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<array-key, mixed>>  $rows
     */
    public function __construct(
        public string $title,
        public string $periodLabel,
        public array $columns = [],
        public array $rows = [],
        public int $rowCount = 0,
        public ?string $headline = null,
        public ?string $skipped = null,
        public ?string $note = null,
    ) {}

    public static function skip(string $title, string $reason): self
    {
        return new self($title, '', skipped: $reason);
    }
}
```

`app/Reports/Packages/PackageResult.php`:

```php
<?php

namespace App\Reports\Packages;

/** A package run for one user: its sheets in order, and the email's lines (spec 2026-10-17 §5). */
final readonly class PackageResult
{
    /** @param  list<PackageSheet>  $sheets */
    public function __construct(public string $name, public array $sheets) {}

    public function allSkipped(): bool
    {
        return array_filter($this->sheets, fn (PackageSheet $s) => $s->skipped === null) === [];
    }

    /** The email subject's period: the first item that ran, else today (plan ruling 10). */
    public function periodLabel(): string
    {
        foreach ($this->sheets as $sheet) {
            if ($sheet->skipped === null) {
                return $sheet->periodLabel;
            }
        }

        return __('As at :date', ['date' => now('Asia/Bahrain')->format('d/m/Y')]);
    }

    /** @return list<string> one line per item that ran: name, real dates, row count, headline total */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->sheets as $s) {
            if ($s->skipped === null) {
                $lines[] = __(':title, :period: :n rows', ['title' => $s->title, 'period' => $s->periodLabel, 'n' => number_format($s->rowCount)])
                    .($s->headline === null ? '' : ' · '.$s->headline);
            }
        }

        return $lines;
    }

    /** @return list<string> "title: reason" for each skipped item */
    public function skippedLines(): array
    {
        $lines = [];
        foreach ($this->sheets as $s) {
            if ($s->skipped !== null) {
                $lines[] = "{$s->title}: {$s->skipped}";
            }
        }

        return $lines;
    }
}
```

- [ ] **Step 5: `app/Reports/Packages/PackageRunner.php`:**

```php
<?php

namespace App\Reports\Packages;

use App\Models\ReportPackage;
use App\Models\ReportPackageItem;
use App\Models\SavedReport;
use App\Models\User;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\FieldType;
use App\Reports\Builder\Formatter;
use App\Reports\Builder\Period;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-17 §4: runs each item of a package, in order, as one user. Read-only. An item that cannot run is
 * skipped with a reason and never stops the others.
 *
 * @phpstan-import-type Form from PackageReport
 */
class PackageRunner
{
    public const int MAX_ITEMS = 15;

    public function __construct(private Engine $engine) {}

    public function run(ReportPackage $package, User $as, CarbonImmutable $now): PackageResult
    {
        $sheets = [];
        foreach ($package->items()->get() as $item) {
            $sheets[] = $item->kind === 'saved' ? $this->saved($item, $as, $now) : $this->readyMade($item, $as, $now);
        }

        return new PackageResult($package->name, $sheets);
    }

    /** @return array<string, string> Period::presets() without "Custom dates": a package period is always relative (plan ruling 19) */
    public static function presets(): array
    {
        return array_diff_key(Period::presets(), ['custom' => true]);
    }

    /**
     * Only the settings this report takes (spec §8 whitelist). An empty building means all; a numeric one is an int.
     *
     * @param  Form  $form
     * @param  array<array-key, mixed>  $settings
     * @return array<array-key, mixed>
     */
    public static function clean(array $form, array $settings): array
    {
        $keys = [...($form['building'] ? ['building'] : []), ...($form['dates'] === 'none' ? [] : ['period']), ...array_keys($form['options'])];
        $clean = array_intersect_key($settings, array_flip($keys));
        if (array_key_exists('building', $clean)) {
            $b = $clean['building'];
            $clean['building'] = $b === '' || $b === null ? null : (is_string($b) && ctype_digit($b) ? (int) $b : $b);
        }

        return $clean;
    }

    /**
     * Spec §3: the item's relative period as the report's own dates at $now (Asia/Bahrain), plus the sheet's period row
     * (plan rulings 6, 7).
     *
     * @param  Form  $form
     * @param  array<array-key, mixed>  $settings  cleaned item settings
     * @return array{settings: array<array-key, mixed>, period: string}
     *
     * @throws ValidationException when the period is not a relative preset
     */
    public static function resolve(array $form, array $settings, CarbonImmutable $now): array
    {
        $today = $now->timezone('Asia/Bahrain');
        $out = array_diff_key($settings, ['period' => true]);
        if ($form['dates'] === 'none') {
            return ['settings' => $out, 'period' => __('As at :date', ['date' => $today->format('d/m/Y')])];
        }
        $preset = $settings['period'] ?? null;
        if (! is_string($preset) || ! isset(self::presets()[$preset])) {
            throw ValidationException::withMessages(['period' => __('Choose a period.')]);
        }
        [$from, $to] = Period::resolve($preset, null, null, $today);

        if ($form['dates'] === 'single') {
            return ['settings' => [...$out, 'to' => $to->toDateString()], 'period' => __('As at :date', ['date' => $to->format('d/m/Y')])];
        }
        if ($form['dates'] === 'months') {
            $last = $to->startOfMonth()->min($today->startOfMonth()); // a month not yet begun has no figures
            $first = $from->startOfMonth()->max($last->subMonths(11)); // ponytail: at most 12 months, most recent kept; no preset reaches it

            return ['settings' => [...$out, 'from' => $first->format('Y-m'), 'to' => $last->format('Y-m')],
                'period' => Period::label($first, $last->endOfMonth()->startOfDay())];
        }

        return ['settings' => [...$out, 'from' => $from->toDateString(), 'to' => $to->toDateString()], 'period' => Period::label($from, $to)];
    }

    private function readyMade(ReportPackageItem $item, User $as, CarbonImmutable $now): PackageSheet
    {
        $key = (string) $item->report;
        if (! isset(ReadyMade::REPORTS[$key])) {
            return PackageSheet::skip($key, __('This report is no longer available.'));
        }
        $report = ReadyMade::make($key);
        $form = $report->packageForm($as);
        if (! $form['allowed']) {
            return PackageSheet::skip($form['title'], __('You do not have access to this report.'));
        }
        try {
            $run = self::resolve($form, self::clean($form, $item->settings), $now);
            $sheet = $report->sheet($as, $run['settings']);
        } catch (AuthorizationException) {
            return PackageSheet::skip($form['title'], __('You do not have access to this report.'));
        } catch (ValidationException $e) {
            return PackageSheet::skip($form['title'], self::invalid($e));
        }

        return new PackageSheet($form['title'], $run['period'], $sheet['columns'], $sheet['rows'], count($sheet['rows']), $sheet['headline']);
    }

    /** Plan ruling 11: subject access, as the scheduled job; fields this user may not see are dropped and noted. */
    private function saved(ReportPackageItem $item, User $as, CarbonImmutable $now): PackageSheet
    {
        $report = SavedReport::withTrashed()->find($item->saved_report_id);
        if ($report === null || $report->trashed()) {
            return PackageSheet::skip($report->name ?? __('Saved report'), __('Report deleted'));
        }
        if (! isset(Registry::forUser($as)[$report->subject])) {
            return PackageSheet::skip($report->name, __('You do not have access to this report.'));
        }
        [$data, $dropped] = ReportDefinition::forViewer($report->definition, $as);
        try {
            $result = $this->engine->run($as, ReportDefinition::fromArray($data, $as), limit: null);
        } catch (ValidationException $e) {
            return PackageSheet::skip($report->name, self::invalid($e));
        }
        $sheet = ExcelWriter::sheet($result);

        return new PackageSheet($report->name, $result->periodLabel ?? __('As at :date', ['date' => $now->timezone('Asia/Bahrain')->format('d/m/Y')]),
            $sheet['columns'], $sheet['rows'], $result->rowCount, self::headline($result),
            note: $dropped === [] ? null : __('Some fields aren\'t available to you and were left out: :list', ['list' => implode(', ', $dropped)]));
    }

    /** The first money column's grand total, e.g. "Amount: 1,250.000" (spec §5). */
    private static function headline(ReportResult $result): ?string
    {
        foreach ($result->columns as $field) {
            if ($field->type !== FieldType::Money) {
                continue;
            }
            foreach ($result->grandTotals as $key => $value) {
                if (str_starts_with((string) $key, $field->key.':')) {
                    return $field->label.': '.Formatter::total($field, explode(':', (string) $key, 2)[1], $value);
                }
            }
        }

        return null;
    }

    private static function invalid(ValidationException $e): string
    {
        return __('Its settings are no longer valid: :reason', ['reason' => (string) collect($e->errors())->flatten()->first()]);
    }
}
```

- [ ] **Step 6: `app/Reports/Packages/PackageWorkbook.php`:**

```php
<?php

namespace App\Reports\Packages;

use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Spec 2026-10-17 §4: one sheet per item in package order. Each has a title row, a period row (plus the dropped-field
 * note, plan ruling 17), a blank row, then the report's own header and rows. A skipped item has its title and reason.
 */
final class PackageWorkbook
{
    public static function write(PackageResult $result, string $path): void
    {
        $writer = SimpleExcelWriter::create($path)->noHeaderRow();
        $used = [];
        foreach ($result->sheets as $i => $sheet) {
            $name = self::sheetName($sheet->title, $used);
            $used[] = mb_strtolower($name);
            if ($i === 0) {
                $writer->nameCurrentSheet($name);
            } else {
                $writer->addNewSheetAndMakeItCurrent($name);
            }
            $writer->addRow([$sheet->title]);
            if ($sheet->skipped !== null) {
                $writer->addRow([$sheet->skipped]);

                continue;
            }
            $writer->addRow([$sheet->periodLabel]);
            if ($sheet->note !== null) {
                $writer->addRow([$sheet->note]);
            }
            $writer->addRow([]);
            $writer->addRow($sheet->columns);
            foreach ($sheet->rows as $row) {
                $writer->addRow($row);
            }
        }
        $writer->close();
    }

    /**
     * Excel's rules: no \ / ? * : [ ], no leading or trailing apostrophe, at most 31 characters, unique ignoring case.
     *
     * @param  list<string>  $used  lower-cased names already taken
     */
    public static function sheetName(string $title, array $used): string
    {
        $base = trim(str_replace(['\\', '/', '?', '*', ':', '[', ']'], '', $title), " '");
        $base = $base === '' ? __('Report') : $base;
        $name = rtrim(mb_substr($base, 0, 31), " '");
        for ($n = 2; in_array(mb_strtolower($name), $used, true); $n++) {
            $suffix = " ({$n})";
            $name = rtrim(mb_substr($base, 0, 31 - mb_strlen($suffix)), " '").$suffix;
        }

        return $name;
    }
}
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/ReportPackages tests/Feature/ReportBuilder`
Expected: PASS. The builder's Excel tests (`ExportAndListTest`, `ComparisonTest`) still pass with the refactored `ExcelWriter`.

- [ ] **Step 8: Gates and commit**

Run: `php vendor/bin/pint --dirty` and `php vendor/bin/phpstan analyse --memory-limit=2G`.

```bash
git add app/Reports/Builder/ExcelWriter.php app/Reports/Packages tests/Feature/ReportPackages/RunnerTest.php
git commit -m "$(cat <<'EOF'
Package runner and workbook: relative periods per run, skipped items noted, saved reports as the user, one named sheet per item

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Package screen, Download now, and the Packages area on the Reports page

**Files:**
- Create: `app/Livewire/Reports/Package.php`, `resources/views/livewire/reports/package.blade.php`
- Modify: `app/Livewire/Reports/Index.php` (`render()`), `resources/views/livewire/reports/index.blade.php` (Packages section after My reports)
- Modify: `routes/property.php` (after `reports.builder`)
- Modify: `tests/Feature/Permissions/ReportsPermissionMatrixTest.php` (one dataset row)
- Test: `tests/Feature/ReportPackages/PackageScreenTest.php`

**Interfaces:**
- Consumes: `ReportPackage`, `ReportPackageItem`, `ReportPackagePolicy` (Task 1), `ReadyMade` and `PackageReport` (Task 2), `PackageRunner::run/clean/resolve/presets/MAX_ITEMS` and `PackageWorkbook::write` (Task 3).
- Produces:
  - Route `reports.package` at `reports/packages/{package?}`.
  - `App\Livewire\Reports\Package` with `#[Locked] ?int $packageId`, `mixed $name`, `mixed $items` (a list of `{kind, saved_report_id, report, settings}`) and `mixed $add` (`'saved:<id>'` or `'ready:<key>'`).
  - Actions: `addItem()`, `removeItem(int)`, `moveItem(int, int)`, `save()`, `share(bool)`, `deletePackage()`, `download(): BinaryFileResponse`.
  - A private `editable(): ReportPackage`, used again by Task 5 (404 when unsaved, 403 unless the user may update).
  - Validation error keys: `name`, `items`, `items.<i>`, `items.<i>.settings.<key>` and `add`.

- [ ] **Step 1: Write the failing test** `tests/Feature/ReportPackages/PackageScreenTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Index;
use App\Livewire\Reports\Package;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportPackage;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    [$this->a, $this->b, $this->finance, $this->owner] = reportPackageFixture();
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);
    $this->saved = SavedReport::create(['name' => 'Monthly collections', 'subject' => 'payments', 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['number', 'amount'], 'period' => ['field' => 'received_on', 'preset' => 'last_month'],
            'totals' => ['amount' => 'sum'], 'sort' => ['field' => 'number', 'dir' => 'asc']]]);
    $this->overstays = [['kind' => 'ready_made', 'report' => 'overstays', 'settings' => []]];
});

test('a package is built from saved and ready-made reports, reordered, with settings, and saved in order', function () {
    Livewire::actingAs($this->finance)->test(Package::class)
        ->set('name', 'Monthly pack')
        ->set('add', 'ready:rent_roll')->call('addItem')
        ->set('add', 'saved:'.$this->saved->id)->call('addItem')
        ->set('add', 'ready:expiring_agreements')->call('addItem')
        ->set('items.2.settings.window', '60')
        ->set('items.0.settings.building', (string) $this->a->id)
        ->call('moveItem', 2, -1)
        ->call('save')->assertHasNoErrors()
        ->assertRedirect(route('reports.package', ReportPackage::sole()));

    $package = ReportPackage::sole();
    expect($package->name)->toBe('Monthly pack')->and($package->visibility)->toBe('private')->and($package->created_by)->toBe($this->finance->id)
        ->and($package->items->map(fn ($i) => [$i->position, $i->kind, $i->report, $i->saved_report_id])->all())->toBe([
            [1, 'ready_made', 'rent_roll', null], [2, 'ready_made', 'expiring_agreements', null], [3, 'saved', null, $this->saved->id]])
        ->and($package->items[0]->settings)->toEqual(['building' => $this->a->id, 'period' => 'today'])
        ->and($package->items[1]->settings)->toEqual(['building' => null, 'window' => '60'])
        ->and(Activity::where('event', 'report.saved')->count())->toBe(1);

    Livewire::actingAs($this->finance)->test(Package::class, ['package' => $package])
        ->assertSee('Rent roll')->assertSee('Agreements expiring')->assertSee('Monthly collections')
        ->call('removeItem', 0)->call('save')->assertHasNoErrors();
    expect($package->items()->pluck('position')->all())->toBe([1, 2]);
});

test('tampered name, items and settings give messages, never a 500', function (string $property, mixed $value, string $error) {
    Livewire::actingAs($this->finance)->test(Package::class)->set('name', 'P')->set('items', $this->overstays)
        ->set($property, $value)->assertOk()
        ->call('save')->assertHasErrors($error);

    expect(ReportPackage::count())->toBe(0);
})->with([
    'no name' => ['name', '', 'name'],
    'name as an array' => ['name', ['x'], 'name'],
    'name too long' => ['name', str_repeat('x', 121), 'name'],
    'items as text' => ['items', 'x', 'items'],
    'no items' => ['items', [], 'items'],
    'sixteen items' => ['items', array_fill(0, 16, ['kind' => 'ready_made', 'report' => 'overstays', 'settings' => []]), 'items'],
    'unknown kind' => ['items', [['kind' => 'pdf']], 'items.0'],
    'not an item' => ['items', ['junk'], 'items.0'],
    'unknown report' => ['items', [['kind' => 'ready_made', 'report' => 'nope', 'settings' => []]], 'items.0'],
    'report as an array' => ['items', [['kind' => 'ready_made', 'report' => ['rent_roll']]], 'items.0'],
    'unknown option' => ['items', [['kind' => 'ready_made', 'report' => 'expiring_agreements', 'settings' => ['window' => '45']]], 'items.0.settings.window'],
    'option as an array' => ['items', [['kind' => 'ready_made', 'report' => 'expiring_agreements', 'settings' => ['window' => ['30']]]], 'items.0.settings.window'],
    'fixed dates' => ['items', [['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['period' => 'custom']]], 'items.0.settings.period'],
    'settings as text' => ['items', [['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => 'x']], 'items.0.settings.period'],
    'unknown saved report' => ['items', [['kind' => 'saved', 'saved_report_id' => 999999]], 'items.0'],
    'saved report id as an array' => ['items', [['kind' => 'saved', 'saved_report_id' => ['1']]], 'items.0'],
]);

test('the page survives tampered items without a 500', function () {
    Livewire::actingAs($this->finance)->test(Package::class)
        ->set('items', [['kind' => ['x']], 'junk', ['kind' => 'ready_made', 'report' => ['x'], 'settings' => 'y'], ['kind' => 'saved', 'saved_report_id' => 'z']])
        ->assertOk()->assertSee('Unknown report')
        ->set('items', 'x')->assertOk()
        ->call('moveItem', 5, 1)->call('removeItem', 9)->assertOk();
});

test('an editor can only use buildings, saved reports and reports they may see', function () {
    $hidden = Building::factory()->create();
    $page = fn () => Livewire::actingAs($this->leasing)->test(Package::class)->set('name', 'P');

    $page()->set('items', [['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => (string) $hidden->id, 'period' => 'today']]])
        ->call('save')->assertHasErrors('items.0.settings.building');
    $page()->set('items', [['kind' => 'saved', 'saved_report_id' => $this->saved->id]])->call('save')->assertHasErrors('items.0'); // Finance's private report
    $page()->set('items', [['kind' => 'ready_made', 'report' => 'outstanding', 'settings' => []]])->call('save')->assertHasErrors('items.0'); // needs reports.financial
    $page()->set('add', 'ready:outstanding')->call('addItem')->assertHasErrors('add')->assertDontSee('Outstanding by tenant');
    $page()->set('add', 'saved:'.$this->saved->id)->call('addItem')->assertHasErrors('add');

    expect(ReportPackage::count())->toBe(0);
});

test('viewers of a shared package download it but cannot change it; the creator or an admin can', function () {
    $package = packageWith($this->finance, $this->overstays);
    $other = matrixUser(RoleName::Finance, $this->a);
    $this->actingAs($other)->get(route('reports.package', $package))->assertForbidden();

    $package->update(['visibility' => 'shared']);
    $open = fn ($as) => Livewire::actingAs($as)->test(Package::class, ['package' => $package]);
    $open($other)->assertOk()->assertDontSeeHtml('wire:click="deletePackage"')->assertDontSeeHtml('wire:click="save"');
    $open($other)->call('save')->assertForbidden();
    $open($other)->call('share', false)->assertForbidden();
    $open($other)->call('deletePackage')->assertForbidden();
    $open($other)->set('add', 'ready:rent_roll')->call('addItem')->assertForbidden();
    $open($other)->call('download')->assertFileDownloaded();

    $open(matrixUser(RoleName::Admin, $this->a))->set('name', 'Board pack')->call('save')->assertHasNoErrors();
    expect($package->fresh()->name)->toBe('Board pack')->and($package->fresh()->created_by)->toBe($this->finance->id);
});

test('the creator shares and deletes a package; deleting removes its schedules', function () {
    $package = packageWith($this->finance, $this->overstays);
    $schedule = $package->schedules()->create(['frequency' => 'daily', 'hour' => 7, 'next_run_at' => now()->addDay(), 'created_by' => $this->finance->id]);

    Livewire::actingAs($this->finance)->test(Package::class, ['package' => $package])->call('share', true);
    expect($package->fresh()->visibility)->toBe('shared')->and(Activity::where('event', 'report.shared')->count())->toBe(1);

    Livewire::actingAs($this->finance)->test(Package::class, ['package' => $package])->call('deletePackage')->assertRedirect(route('reports.index'));
    expect(ReportPackage::count())->toBe(0)->and(ReportPackage::withTrashed()->count())->toBe(1)
        ->and(ReportSchedule::find($schedule->id))->toBeNull()
        ->and(Activity::where('event', 'report.deleted')->count())->toBe(1);
});

test('Download now builds the workbook as the person clicking and is audited', function () {
    $package = packageWith($this->finance, [
        ['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => null, 'period' => 'today']],
        ['kind' => 'ready_made', 'report' => 'outstanding', 'settings' => ['building' => null]],
    ], 'Monthly pack', 'shared');
    $page = Livewire::actingAs($this->leasing)->test(Package::class, ['package' => $package]);

    $page->call('download')->assertFileDownloaded('monthly-pack-2026-10-15.xlsx');
    $path = $page->instance()->download()->getFile()->getPathname();
    $props = Activity::where('event', 'report.exported')->first()->properties;

    expect(SimpleExcelReader::create($path)->getSheetNames())->toBe(['Rent roll', 'Outstanding by tenant'])
        ->and(xlsxRows($path, 'Outstanding by tenant'))->toBe([['Outstanding by tenant'], ['You do not have access to this report.']])
        ->and(collect(xlsxRows($path, 'Rent roll'))->pluck(0)->all())->toContain('AAA')->not->toContain('BBB')
        ->and($props['report'])->toBe('package')->and($props['package'])->toBe($package->id);
});

test('the Reports page lists my packages and shared ones, and offers a new package', function () {
    $admin = matrixUser(RoleName::Admin, $this->a);
    packageWith($this->finance, $this->overstays, 'My pack');
    packageWith($admin, $this->overstays, 'Board pack', 'shared');
    packageWith($admin, $this->overstays, 'Secret pack');

    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertSee('Packages')->assertSee('New package')->assertSee('My pack')->assertSee('Board pack')->assertDontSee('Secret pack')
        ->assertSeeHtml(route('reports.package'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ReportPackages/PackageScreenTest.php`
Expected: FAIL with `Class "App\Livewire\Reports\Package" not found`.

- [ ] **Step 3: The component** `app/Livewire/Reports/Package.php`:

```php
<?php

namespace App\Livewire\Reports;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\ReportPackage;
use App\Models\ReportPackageItem;
use App\Models\SavedReport;
use App\Models\User;
use App\Reports\Packages\PackageReport;
use App\Reports\Packages\PackageRunner;
use App\Reports\Packages\PackageWorkbook;
use App\Reports\Packages\ReadyMade;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spec 2026-10-17 §3, §6: a package's items (add, remove, move, settings), Save, Share, Delete and Download now.
 * `items` is client-controlled: save() checks every item again, and render() tolerates anything.
 *
 * @phpstan-import-type Form from PackageReport
 */
class Package extends Component
{
    use WithActor;

    #[Locked]
    public ?int $packageId = null;

    // Untyped on purpose: a tampered payload must reach validation, not throw a TypeError (a 500).
    public mixed $name = '';

    public mixed $items = [];

    public mixed $add = '';

    public function mount(?ReportPackage $package = null): void
    {
        $user = $this->actor();
        abort_unless($user->can('reports.operational') || $user->can('reports.financial'), 403);
        if ($package?->exists) {
            abort_unless($user->can('view', $package), 403);
            $this->packageId = $package->id;
            $this->name = $package->name;
            $this->items = $package->items()->get()->map(fn (ReportPackageItem $i) => ['kind' => $i->kind, 'saved_report_id' => $i->saved_report_id,
                'report' => $i->report, 'settings' => $i->settings])->all();
        }
    }

    public function addItem(): void
    {
        $this->authorizeEdit();
        $user = $this->actor();
        $items = $this->itemList();
        if (count($items) >= PackageRunner::MAX_ITEMS) {
            $this->addError('add', __('A package holds at most :n reports.', ['n' => PackageRunner::MAX_ITEMS]));

            return;
        }
        [$kind, $ref] = array_pad(explode(':', is_string($this->add) ? $this->add : '', 2), 2, '');
        $form = $kind === 'ready' && isset(ReadyMade::REPORTS[$ref]) ? ReadyMade::make($ref)->packageForm($user) : null;
        if ($kind === 'saved' && ctype_digit($ref) && SavedReport::visibleTo($user)->whereKey((int) $ref)->exists()) {
            $items[] = ['kind' => 'saved', 'saved_report_id' => (int) $ref, 'report' => null, 'settings' => []];
        } elseif ($form !== null && $form['allowed']) {
            $items[] = ['kind' => 'ready_made', 'saved_report_id' => null, 'report' => $ref, 'settings' => self::defaults($form)];
        } else {
            $this->addError('add', __('Pick a report from the list.'));

            return;
        }
        $this->items = $items;
        $this->add = '';
    }

    public function removeItem(int $index): void
    {
        $this->authorizeEdit();
        $items = $this->itemList();
        unset($items[$index]);
        $this->items = array_values($items);
    }

    public function moveItem(int $index, int $by): void
    {
        $this->authorizeEdit();
        $items = $this->itemList();
        $to = $index + $by;
        if (isset($items[$index], $items[$to])) {
            [$items[$index], $items[$to]] = [$items[$to], $items[$index]];
            $this->items = $items;
        }
    }

    public function save(): void
    {
        $user = $this->actor();
        $this->authorizeEdit();
        $max = PackageRunner::MAX_ITEMS;
        $this->validate(['name' => ['required', 'string', 'max:120'], 'items' => ['required', 'array', 'min:1', "max:{$max}"]], [
            'name.required' => __('Give the package a name.'), 'items.required' => __('Add at least one report.'),
            'items.min' => __('Add at least one report.'), 'items.max' => __('A package holds at most :n reports.', ['n' => $max]),
        ]);
        $checked = [];
        foreach (array_values((array) $this->items) as $i => $item) {
            $checked[] = $this->checkedItem($user, $i, $item);
        }
        $name = (string) $this->name;
        $package = DB::transaction(function () use ($user, $checked, $name) {
            if ($this->packageId === null) {
                $package = ReportPackage::create(['name' => $name, 'visibility' => 'private', 'created_by' => $user->id, 'updated_by' => $user->id]);
            } else {
                $package = ReportPackage::findOrFail($this->packageId);
                $package->update(['name' => $name, 'updated_by' => $user->id]);
                $package->items()->delete();
            }
            foreach ($checked as $n => $item) {
                $package->items()->create([...$item, 'position' => $n + 1]);
            }

            return $package;
        });
        Audit::log('report.saved', $package, properties: ['name' => $package->name, 'package' => true, 'items' => count($checked)], causer: $user);
        Flux::toast(variant: 'success', text: __('Package saved.'));
        $this->redirectRoute('reports.package', $package, navigate: true);
    }

    public function share(bool $shared): void
    {
        $package = $this->editable();
        $package->update(['visibility' => $shared ? 'shared' : 'private']);
        Audit::log('report.shared', $package, properties: ['visibility' => $package->visibility, 'package' => true], causer: $this->actor());
        Flux::toast(text: $shared ? __('Shared with everyone who has report access.') : __('Now only you can see it.'));
    }

    /** Plan ruling 5: the schedules go first, in code, because their foreign key has no cascade. */
    public function deletePackage(): void
    {
        $package = ReportPackage::findOrFail((int) $this->packageId);
        abort_unless($this->actor()->can('delete', $package), 403);
        DB::transaction(function () use ($package) {
            $package->schedules()->delete();
            $package->delete();
        });
        Audit::log('report.deleted', $package, properties: ['name' => $package->name, 'package' => true], causer: $this->actor());
        $this->redirectRoute('reports.index', navigate: true);
    }

    /** Spec §6: the saved package, built for the person clicking (plan ruling 15). */
    public function download(): BinaryFileResponse
    {
        $user = $this->actor();
        $package = ReportPackage::findOrFail((int) $this->packageId);
        abort_unless($user->can('view', $package), 403);
        $result = app(PackageRunner::class)->run($package, $user, CarbonImmutable::now('Asia/Bahrain'));
        $path = sys_get_temp_dir().'/rms-package-'.Str::uuid().'.xlsx';
        PackageWorkbook::write($result, $path);
        Audit::log('report.exported', properties: ['report' => 'package', 'package' => $package->id], causer: $user);

        return response()->download($path, (Str::slug($package->name) ?: 'package').'-'.now('Asia/Bahrain')->toDateString().'.xlsx')->deleteFileAfterSend();
    }

    /** The saved package, if the user may change it: 404 when unsaved, 403 otherwise. */
    private function editable(): ReportPackage
    {
        $package = ReportPackage::findOrFail((int) $this->packageId);
        abort_unless($this->actor()->can('update', $package), 403);

        return $package;
    }

    private function authorizeEdit(): void
    {
        if ($this->packageId !== null) {
            $this->editable();
        }
    }

    /**
     * One item checked for $user, as stored. Errors are keyed items.<i> or items.<i>.settings.<key>.
     *
     * @return array{kind: string, saved_report_id: int|null, report: string|null, settings: array<array-key, mixed>}
     */
    private function checkedItem(User $user, int $i, mixed $item): array
    {
        $kind = is_array($item) ? ($item['kind'] ?? null) : null;
        if ($kind === 'saved') {
            $id = $item['saved_report_id'] ?? null;
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && SavedReport::visibleTo($user)->whereKey((int) $id)->exists()) {
                return ['kind' => 'saved', 'saved_report_id' => (int) $id, 'report' => null, 'settings' => []];
            }
            throw ValidationException::withMessages(["items.{$i}" => __('Pick a saved report you can see.')]);
        }
        $key = is_array($item) ? ($item['report'] ?? null) : null;
        if ($kind !== 'ready_made' || ! is_string($key) || ! isset(ReadyMade::REPORTS[$key])) {
            throw ValidationException::withMessages(["items.{$i}" => __('Pick a report from the list.')]);
        }
        $report = ReadyMade::make($key);
        $form = $report->packageForm($user);
        if (! $form['allowed']) {
            throw ValidationException::withMessages(["items.{$i}" => __('You do not have access to this report.')]);
        }
        $settings = PackageRunner::clean($form, is_array($item['settings'] ?? null) ? $item['settings'] : []);
        try {
            $report->checkSettings($user, PackageRunner::resolve($form, $settings, CarbonImmutable::now('Asia/Bahrain'))['settings']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $m, string $k) => ["items.{$i}.settings.{$k}" => $m])->all());
        }

        return ['kind' => 'ready_made', 'saved_report_id' => null, 'report' => $key, 'settings' => $settings];
    }

    /** @return list<array<array-key, mixed>> the items that are at least arrays, in order */
    private function itemList(): array
    {
        return array_values(array_filter(is_array($this->items) ? $this->items : [], 'is_array'));
    }

    /**
     * Plan ruling 16: all buildings, "Today" for an as-at report, "Last month" otherwise, each option's first choice.
     *
     * @param  Form  $form
     * @return array<string, mixed>
     */
    private static function defaults(array $form): array
    {
        $settings = $form['building'] ? ['building' => null] : [];
        if ($form['dates'] !== 'none') {
            $settings['period'] = $form['dates'] === 'single' ? 'today' : 'last_month';
        }
        foreach ($form['options'] as $property => $choices) {
            $settings[$property] = (string) array_key_first($choices);
        }

        return $settings;
    }

    /**
     * What the list shows per item. A tampered entry shows as "Unknown report", never a 500. `index` is the item's key in
     * $items (for wire:model); the list position is the view's loop index (for move / remove).
     *
     * @param  array<string, Form>  $forms
     * @param  array<array-key, mixed>  $savedNames
     * @return list<array{index: int|string, title: string, kind: string, form: Form|null, locked: bool}>
     */
    private function itemViews(array $forms, array $savedNames): array
    {
        $views = [];
        foreach (is_array($this->items) ? $this->items : [] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['kind'] ?? null) === 'saved') {
                $id = $item['saved_report_id'] ?? null;
                $name = is_int($id) || (is_string($id) && ctype_digit($id)) ? ($savedNames[(int) $id] ?? null) : null;
                $views[] = ['index' => $index, 'title' => $name === null ? __('Saved report (not available)') : (string) $name, 'kind' => __('Saved report'), 'form' => null, 'locked' => false];

                continue;
            }
            $key = $item['report'] ?? null;
            $form = is_string($key) ? ($forms[$key] ?? null) : null;
            $views[] = ['index' => $index, 'title' => $form['title'] ?? __('Unknown report'), 'kind' => __('Ready-made report'),
                'form' => $form !== null && $form['allowed'] ? $form : null, 'locked' => $form !== null && ! $form['allowed']];
        }

        return $views;
    }

    public function render(): View
    {
        $user = $this->actor();
        $package = $this->packageId === null ? null : ReportPackage::findOrFail($this->packageId);
        $forms = [];
        foreach (array_keys(ReadyMade::REPORTS) as $key) {
            $forms[$key] = ReadyMade::make($key)->packageForm($user);
        }
        $saved = SavedReport::visibleTo($user)->orderBy('name')->get(['id', 'name']);

        return view('livewire.reports.package', [
            'rows' => $this->itemViews($forms, $saved->pluck('name', 'id')->all()),
            'ready' => array_filter($forms, fn (array $f) => $f['allowed']),
            'saved' => $saved,
            'presets' => PackageRunner::presets(),
            'buildings' => Building::visibleTo($user)->orderBy('code')->get(['id', 'code', 'name']),
            'canEdit' => $package === null || $user->can('update', $package),
            'shared' => $package?->visibility === 'shared',
        ])->title(is_string($this->name) && $this->name !== '' ? $this->name : __('New package'));
    }
}
```

- [ ] **Step 4: The view** `resources/views/livewire/reports/package.blade.php`. It uses only inline `@php(...)`:

```blade
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0 flex-1">
            <flux:input wire:model.blur="name" :placeholder="__('Untitled package')" :aria-label="__('Package name')" class="max-w-md" :disabled="! $canEdit" />
            @error('name')<flux:text class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($canEdit)
                <flux:button variant="primary" wire:click="save">{{ __('Save') }}</flux:button>
            @endif
            @if ($packageId)
                <flux:button icon="arrow-down-tray" wire:click="download">{{ __('Download now') }}</flux:button>
                @if ($canEdit)
                    <flux:button :icon="$shared ? 'users' : 'lock-closed'" wire:click="share({{ $shared ? 'false' : 'true' }})">{{ $shared ? __('Shared') : __('Only me') }}</flux:button>
                    <flux:button variant="ghost" icon="trash" wire:click="deletePackage" wire:confirm="{{ __('Delete this package?') }}" :aria-label="__('Delete')" />
                @endif
            @endif
            <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
        </div>
    </div>

    <flux:text>{{ __('One Excel workbook, one sheet per report, in this order. Periods are worked out again on every run.') }}</flux:text>
    @error('items')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror

    <ol class="space-y-3">
        @forelse ($rows as $n => $row)
            <li class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="item-{{ $n }}-{{ $row['index'] }}-{{ $row['title'] }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $n + 1 }}. {{ $row['title'] }}</span>
                        <span class="block text-sm text-zinc-500">{{ $row['kind'] }}</span>
                    </div>
                    @if ($canEdit)
                        <div class="flex shrink-0 gap-1">
                            <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="moveItem({{ $n }}, -1)" :aria-label="__('Move up')" />
                            <flux:button size="sm" variant="ghost" icon="arrow-down" wire:click="moveItem({{ $n }}, 1)" :aria-label="__('Move down')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItem({{ $n }})" :aria-label="__('Remove')" />
                        </div>
                    @endif
                </div>
                @if ($row['form'] !== null)
                    <div class="mt-3 grid gap-3 sm:grid-cols-4">
                        @if ($row['form']['building'])
                            <x-building-filter :buildings="$buildings" :model="'items.'.$row['index'].'.settings.building'" :disabled="! $canEdit" />
                        @endif
                        @if ($row['form']['dates'] !== 'none')
                            <flux:select wire:model="items.{{ $row['index'] }}.settings.period" :label="__('Period')" :disabled="! $canEdit">
                                @foreach ($presets as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </flux:select>
                        @endif
                        @foreach ($row['form']['options'] as $property => $choices)
                            <flux:select wire:model="items.{{ $row['index'] }}.settings.{{ $property }}" :label="__(str($property)->headline()->toString())" :disabled="! $canEdit">
                                @foreach ($choices as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </flux:select>
                        @endforeach
                    </div>
                @elseif ($row['locked'])
                    <flux:text class="mt-2 text-sm">{{ __('You do not have access to this report.') }}</flux:text>
                @endif
                @php($itemErrors = [...$errors->get('items.'.$row['index']), ...collect($errors->get('items.'.$row['index'].'.*'))->flatten()->all()])
                @foreach ($itemErrors as $message)
                    <flux:text class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</flux:text>
                @endforeach
            </li>
        @empty
            <li><flux:text>{{ __('No reports yet. Add the first one below.') }}</flux:text></li>
        @endforelse
    </ol>

    @if ($canEdit)
        <div class="flex flex-wrap items-end gap-2">
            <flux:select wire:model="add" :label="__('Add a report')" class="sm:max-w-md">
                <option value="">{{ __('Choose a report') }}</option>
                @if ($saved->isNotEmpty())
                    <optgroup label="{{ __('Saved reports') }}">
                        @foreach ($saved as $r)<option value="saved:{{ $r->id }}">{{ $r->name }}</option>@endforeach
                    </optgroup>
                @endif
                <optgroup label="{{ __('Ready-made reports') }}">
                    @foreach ($ready as $key => $form)<option value="ready:{{ $key }}">{{ $form['title'] }}</option>@endforeach
                </optgroup>
            </flux:select>
            <flux:button icon="plus" wire:click="addItem">{{ __('Add') }}</flux:button>
        </div>
        @error('add')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror
    @endif
</div>
```

- [ ] **Step 5: Route, Reports page and permission matrix.**

In `routes/property.php`, after the `reports/builder/{report?}` line:

```php
    Route::livewire('reports/packages/{package?}', Reports\Package::class)->name('reports.package'); // reports.operational or .financial, checked in mount()
```

In `Index.php`, add `use App\Models\ReportPackage;` and, in `render()`, add these keys to the view data:

```php
            'canPackage' => $user->can('reports.operational') || $user->can('reports.financial'),
            'myPackages' => ReportPackage::visibleTo($user)->where('created_by', $user->id)->withCount('items')->latest('updated_at')->get(),
            'sharedPackages' => ReportPackage::visibleTo($user)->where('created_by', '!=', $user->id)->with('creator:id,name')->withCount('items')->latest('updated_at')->get(),
```

In `index.blade.php`, insert this section directly after the `@if ($canBuild) … @endif` My reports block:

```blade
    @if ($canPackage)
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Packages') }}</flux:heading>
                <flux:button icon="plus" :href="route('reports.package')" wire:navigate>{{ __('New package') }}</flux:button>
            </div>
            @if ($myPackages->isEmpty() && $sharedPackages->isEmpty())
                <flux:text>{{ __('Bundle several reports into one Excel workbook, and email it on a schedule.') }}</flux:text>
            @else
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ([[__('Mine'), $myPackages], [__('Shared with me'), $sharedPackages]] as [$heading, $list])
                        @foreach ($list as $p)
                            <a href="{{ route('reports.package', $p) }}" wire:navigate class="block rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:outline-none dark:border-zinc-700 dark:bg-zinc-900">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $p->name }}</span>
                                <span class="mt-0.5 block text-sm text-zinc-500">
                                    {{ __(':n reports', ['n' => $p->items_count]) }} · {{ $heading }}@if ($p->creator && $p->created_by !== auth()->id()) · {{ $p->creator->name }}@endif
                                </span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </section>
    @endif
```

In `tests/Feature/Permissions/ReportsPermissionMatrixTest.php`, add this row to the dataset after `['reports.owner-income-statement', $financial],`:

```php
    ['reports.package', $operational], // reports.operational or reports.financial (financial ⊂ operational here)
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/ReportPackages tests/Feature/Permissions/ReportsPermissionMatrixTest.php tests/Feature/ReportBuilder/ExportAndListTest.php`
Expected: PASS.

- [ ] **Step 7: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G` and `npm run build`.

```bash
git add app/Livewire/Reports/Package.php resources/views/livewire/reports/package.blade.php app/Livewire/Reports/Index.php resources/views/livewire/reports/index.blade.php routes/property.php tests/Feature/ReportPackages/PackageScreenTest.php tests/Feature/Permissions/ReportsPermissionMatrixTest.php
git commit -m "$(cat <<'EOF'
Package screen: items with settings, save, share, delete, Download now (audited); Packages area on the Reports page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Schedule email from the package screen, sharing the builder's form

**Files:**
- Create: `app/Livewire/Reports/Concerns/ManagesSchedules.php`
- Create: `resources/views/livewire/reports/partials/schedule-modal.blade.php`
- Modify: `app/Livewire/Reports/Builder.php` (remove the moved members; `use ManagesSchedules`; add `scheduleTarget()`; update `render()`)
- Modify: `resources/views/livewire/reports/builder.blade.php` (top `@php` block lines 6–9; modal block lines 34–107)
- Modify: `app/Livewire/Reports/Package.php`, `resources/views/livewire/reports/package.blade.php`
- Test: append to `tests/Feature/ReportPackages/PackageScreenTest.php`. Run the existing `tests/Feature/ReportBuilder/ScheduleScreenTest.php` unchanged.

**Interfaces:**
- Consumes: `ReportPackage::schedules()`, `SavedReport::schedules()`, `NextRun::after()`, and `Package::editable()` (Task 4).
- Produces: the trait `ManagesSchedules`. It holds `array $schedule` and `string $staffSearch`, and provides `updatedSchedule()`, `addSchedule()`, `removeSchedule(int)`, `resumeSchedule(int)` and `scheduleData(SavedReport|ReportPackage|null $target): array{schedules, lastDelivery, staff}`. It declares `abstract protected function scheduleTarget(): SavedReport|ReportPackage`. It needs `WithActor`. The partial takes `$intro` (string) and `$fixedDates` (bool), and reads `$schedules`, `$lastDelivery`, `$staff` and `$schedule` from the parent view.

- [ ] **Step 1: Write the failing tests.** Append to `tests/Feature/ReportPackages/PackageScreenTest.php`:

```php
test('the package screen schedules email to staff like the builder, audited on the package', function () {
    $package = packageWith($this->finance, $this->overstays);

    Livewire::actingAs($this->finance)->test(Package::class, ['package' => $package])->assertSee('Schedule email')
        ->set('schedule', ['frequency' => 'weekly', 'day' => 0, 'hour' => 8, 'recipients' => [$this->finance->id]])->call('addSchedule')->assertHasNoErrors();

    $schedule = ReportSchedule::sole();
    expect($schedule->report_package_id)->toBe($package->id)->and($schedule->saved_report_id)->toBeNull()
        ->and($schedule->next_run_at->format('Y-m-d H:i'))->toBe('2026-10-18 08:00')
        ->and($schedule->recipients->pluck('id')->all())->toBe([$this->finance->id])
        ->and(Activity::where('event', 'report.scheduled')->sole()->subject->is($package))->toBeTrue();

    Livewire::actingAs($this->finance)->test(Package::class, ['package' => $package])
        ->set('schedule', ['frequency' => 'daily', 'day' => null, 'hour' => 99, 'recipients' => ['x']])->call('addSchedule')
        ->assertHasErrors(['schedule.hour', 'schedule.recipients.0']);
    expect(ReportSchedule::count())->toBe(1);
});

test('only an editor schedules a package; an unsaved package has no schedule form', function () {
    $package = packageWith($this->finance, $this->overstays, 'Monthly pack', 'shared');
    $schedule = $package->schedules()->create(['frequency' => 'daily', 'hour' => 7, 'next_run_at' => now()->addDay(), 'created_by' => $this->finance->id]);
    $other = matrixUser(RoleName::Finance, $this->a);

    Livewire::actingAs($other)->test(Package::class, ['package' => $package])->assertDontSee('Schedule email')
        ->set('schedule', ['frequency' => 'daily', 'day' => null, 'hour' => 7, 'recipients' => [$other->id]])->call('addSchedule')->assertForbidden();
    Livewire::actingAs($other)->test(Package::class, ['package' => $package])->call('removeSchedule', $schedule->id)->assertForbidden();
    Livewire::actingAs($this->finance)->test(Package::class)->assertDontSee('Schedule email')
        ->set('schedule', ['frequency' => 'daily', 'day' => null, 'hour' => 7, 'recipients' => [$this->finance->id]])->call('addSchedule')->assertNotFound();
});

test('a package schedule is resumed and removed from the package screen, audited', function () {
    $package = packageWith($this->finance, $this->overstays);
    $schedule = $package->schedules()->create(['frequency' => 'daily', 'hour' => 7, 'created_by' => $this->finance->id,
        'next_run_at' => now()->addDay(), 'paused_at' => now(), 'consecutive_failures' => 3]);

    Livewire::actingAs($this->finance)->test(Package::class, ['package' => $package])->assertSee('Paused')
        ->call('resumeSchedule', $schedule->id)->call('removeSchedule', $schedule->id);

    expect(ReportSchedule::count())->toBe(0)
        ->and(Activity::where('event', 'report.scheduled')->get()->map(fn ($a) => $a->properties['action'])->all())->toBe(['resumed', 'removed']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ReportPackages/PackageScreenTest.php`
Expected: the 3 new tests FAIL. The first fails on `assertSee('Schedule email')`; the others fail with `Unable to call component method. Public method [addSchedule] not found`. The Task 4 tests still pass.

- [ ] **Step 3: The trait** `app/Livewire/Reports/Concerns/ManagesSchedules.php`. The bodies are moved from `Builder` unchanged, except that the target comes from `scheduleTarget()`:

```php
<?php

namespace App\Livewire\Reports\Concerns;

use App\Audit\Audit;
use App\Enums\RoleName;
use App\Models\ReportDelivery;
use App\Models\ReportPackage;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use App\Reports\Builder\NextRun;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The "Schedule email" form (builder spec §7), shared by the builder (a saved report) and a package (spec 2026-10-17
 * §5). The schedule belongs to scheduleTarget(); audits name it as the subject. Needs WithActor.
 */
trait ManagesSchedules
{
    /** @var array<string, mixed> client-controlled: validated in addSchedule() */
    public array $schedule = ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => []];

    public string $staffSearch = '';

    /** The saved report or package being scheduled: 404 when unsaved, 403 unless the user may change it. */
    abstract protected function scheduleTarget(): SavedReport|ReportPackage;

    /** Day follows the frequency: weekly 0-6, monthly 1-28 or 0 for the last day, daily none. */
    public function updatedSchedule(mixed $value, ?string $key = null): void
    {
        if ($key === 'frequency') {
            $this->schedule['day'] = match ($value) {
                'weekly' => 0,
                'monthly' => 1,
                default => null,
            };
        }
    }

    public function addSchedule(): void
    {
        $target = $this->scheduleTarget();
        $frequency = $this->schedule['frequency'] ?? null;
        $this->validate([
            'schedule.frequency' => ['required', 'in:daily,weekly,monthly'],
            'schedule.day' => $frequency === 'daily' ? ['nullable'] : ['required', 'integer', $frequency === 'weekly' ? 'between:0,6' : 'between:0,28'],
            'schedule.hour' => ['required', 'integer', 'between:0,23'],
            'schedule.recipients' => ['required', 'array', 'min:1', 'max:50'],
            'schedule.recipients.*' => ['integer', Rule::in($this->eligibleAmong($this->schedule['recipients'] ?? []))],
        ], ['schedule.recipients.*.in' => __('Pick staff from the list.'), 'schedule.recipients.required' => __('Pick at least one person.'),
            'schedule.day.required' => __('Pick a day.')]);
        $data = ['frequency' => (string) $frequency, 'day' => $frequency === 'daily' ? null : (int) $this->schedule['day'], 'hour' => (int) $this->schedule['hour']];
        $recipients = array_values(array_unique(array_map('intval', $this->schedule['recipients'])));
        $schedule = $target->schedules()->create([...$data, 'created_by' => $this->actor()->id, 'next_run_at' => NextRun::after($data, now()->toImmutable())]);
        $schedule->recipients()->sync($recipients);
        Audit::log('report.scheduled', $target, properties: ['schedule' => $schedule->id, ...$data, 'recipients' => $recipients], causer: $this->actor());
        $this->schedule = ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => []];
        Flux::toast(variant: 'success', text: __('Scheduled.'));
    }

    public function removeSchedule(int $id): void
    {
        $target = $this->scheduleTarget();
        $schedule = $target->schedules()->whereKey($id)->first();
        if ($schedule !== null) {
            $schedule->delete();
            Audit::log('report.scheduled', $target, properties: ['schedule' => $id, 'action' => 'removed'], causer: $this->actor());
            Flux::toast(text: __('Schedule removed.'));
        }
    }

    /** Clears the pause and failure count, then waits for the next due time. */
    public function resumeSchedule(int $id): void
    {
        $target = $this->scheduleTarget();
        $schedule = $target->schedules()->whereKey($id)->first();
        if ($schedule !== null) {
            $schedule->update(['paused_at' => null, 'consecutive_failures' => 0, 'next_run_at' => NextRun::after($schedule, now()->toImmutable())]);
            Audit::log('report.scheduled', $target, properties: ['schedule' => $id, 'action' => 'resumed'], causer: $this->actor());
            Flux::toast(variant: 'success', text: __('Schedule resumed.'));
        }
    }

    /**
     * What the schedule modal shows; nothing for an unsaved target.
     *
     * @return array{schedules: Collection<int, ReportSchedule>, lastDelivery: Collection<int, ReportDelivery>, staff: Collection<int, User>}
     */
    protected function scheduleData(SavedReport|ReportPackage|null $target): array
    {
        $schedules = $target === null ? collect() : $target->schedules()->with('recipients:id,name')->orderBy('id')->get();

        return [
            'schedules' => $schedules,
            'lastDelivery' => ReportDelivery::whereIn('report_schedule_id', $schedules->pluck('id'))->orderByDesc('due_at')->get()->unique('report_schedule_id')->keyBy('report_schedule_id'),
            'staff' => $target === null ? collect() : $this->staff(),
        ];
    }

    /** @return list<int> the submitted ids that are eligible recipients, in one query */
    private function eligibleAmong(mixed $ids): array
    {
        $ids = array_values(array_filter(is_array($ids) ? $ids : [], fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v) && strlen($v) < 10)));

        return $ids === [] ? [] : array_values($this->eligible()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Active staff who may receive reports: never Vendor Support or the system user (the job skips them too).
     *
     * @return EloquentBuilder<User>
     */
    private function eligible(): EloquentBuilder
    {
        return User::query()->where('active', true)->where('is_system', false)->withoutRole(RoleName::VendorSupport);
    }

    /** @return Collection<int, User> what the picker shows: the search matches (max 50) plus everyone already ticked */
    private function staff(): Collection
    {
        $term = trim($this->staffSearch);
        $picked = $this->eligibleAmong($this->schedule['recipients'] ?? []);
        $matches = $this->eligible()->orderBy('name')->limit(50)
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')->orWhere('email', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->get(['id', 'name', 'email']);
        $ticked = $picked === [] ? collect() : $this->eligible()->whereIn('id', $picked)->get(['id', 'name', 'email']);

        return $matches->concat($ticked)->unique('id')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
```

If phpstan objects to the `Collection` generics in `scheduleData()`, relax the `@return` to `array<string, Collection<array-key, mixed>>`. The code is the same expressions `Builder::render()` used before.

- [ ] **Step 4: `Builder` uses the trait.** In `app/Livewire/Reports/Builder.php`:
  - Change `use WithActor;` to `use ManagesSchedules, WithActor;` and add `use App\Livewire\Reports\Concerns\ManagesSchedules;`.
  - Delete the `$schedule` and `$staffSearch` properties.
  - Delete the methods `updatedSchedule`, `addSchedule`, `removeSchedule`, `resumeSchedule`, `eligibleAmong`, `eligible` and `staff`.
  - Add:

```php
    protected function scheduleTarget(): SavedReport
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('update', $report), 403);

        return $report;
    }
```

In `render()`, delete the `$schedules = …` and `$lastDelivery = …` lines. In the view data, replace the three keys `'schedules' => $schedules,`, `'lastDelivery' => $lastDelivery,` and `'staff' => $this->reportId === null ? collect() : $this->staff(),` with:

```php
            ...$this->scheduleData($this->reportId === null ? null : SavedReport::findOrFail($this->reportId)),
```

Finally, remove the imports that are now unused (`RoleName`, `ReportDelivery`, `ReportSchedule`, `User`, `NextRun`, `EloquentBuilder`, `Collection`, `Rule`), keeping any that the remaining code still uses. Pint's `no_unused_imports` will flag the rest.

- [ ] **Step 5: The partial** `resources/views/livewire/reports/partials/schedule-modal.blade.php`. This is the builder's modal moved here, with its labels in an `@php` block (the only `@php` form in this file):

```blade
{{-- The "Schedule email" modal for a saved report or a package. Needs $intro, $fixedDates, $schedules, $lastDelivery, $staff, $schedule. --}}
@php
    $days = [__('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday')];
    $statusLabels = ['sending' => __('sending'), 'sent' => __('sent'), 'failed' => __('failed'), 'skipped' => __('skipped')];
    $freq = $schedule['frequency'] ?? '';
    $freqLabels = ['daily' => __('Every day'), 'weekly' => __('Every week'), 'monthly' => __('Every month')];
@endphp
<flux:modal name="schedule-email" class="w-full max-w-xl">
    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Schedule email') }}</flux:heading>
        <flux:text>{{ $intro }}</flux:text>
        @if ($fixedDates)
            <flux:callout icon="information-circle" :heading="__('This report uses fixed dates, so a schedule will send the same dates every time.')" />
        @endif

        @forelse ($schedules as $s)
            <div class="flex items-start justify-between gap-2 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700" wire:key="schedule-{{ $s->id }}">
                <div class="min-w-0 space-y-1">
                    <div class="font-medium">
                        {{ $freqLabels[$s->frequency] ?? $s->frequency }}
                        @if ($s->frequency === 'weekly'){{ $days[$s->day] ?? '' }}@elseif ($s->frequency === 'monthly'){{ $s->day === 0 ? __('last day') : __('day :n', ['n' => $s->day]) }}@endif
                        {{ __('at') }} {{ sprintf('%02d:00', $s->hour) }}
                    </div>
                    <div>{{ __('Next run') }}: {{ $s->next_run_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}
                        @if ($s->paused_at)<flux:badge size="sm" color="amber">{{ __('Paused') }}</flux:badge>@endif
                    </div>
                    <div class="text-zinc-500">{{ $s->recipients->pluck('name')->join(', ') }}</div>
                    @if (isset($lastDelivery[$s->id]))<div class="text-zinc-500">{{ __('Last send') }}: {{ $statusLabels[$lastDelivery[$s->id]->status] ?? $lastDelivery[$s->id]->status }} · {{ $lastDelivery[$s->id]->due_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}</div>@endif
                </div>
                <div class="flex shrink-0 gap-1">
                    @if ($s->paused_at)<flux:button size="sm" wire:click="resumeSchedule({{ $s->id }})">{{ __('Resume') }}</flux:button>@endif
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeSchedule({{ $s->id }})" wire:confirm="{{ __('Remove this schedule?') }}" :aria-label="__('Remove schedule')" />
                </div>
            </div>
        @empty
            <flux:text class="text-sm">{{ __('Not scheduled yet.') }}</flux:text>
        @endforelse

        <div class="grid grid-cols-3 gap-2">
            <flux:select wire:model.live="schedule.frequency" :label="__('Repeat')">
                @foreach ($freqLabels as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
            </flux:select>
            @if ($freq === 'weekly')
                <flux:select wire:model="schedule.day" :label="__('Day')">
                    @foreach ($days as $n => $d)<option value="{{ $n }}">{{ $d }}</option>@endforeach
                </flux:select>
            @elseif ($freq === 'monthly')
                <flux:select wire:model="schedule.day" :label="__('Day')">
                    @for ($n = 1; $n <= 28; $n++)<option value="{{ $n }}">{{ $n }}</option>@endfor
                    <option value="0">{{ __('Last day') }}</option>
                </flux:select>
            @else
                <div></div>
            @endif
            <flux:select wire:model="schedule.hour" :label="__('Time')">
                @for ($h = 0; $h < 24; $h++)<option value="{{ $h }}">{{ sprintf('%02d:00', $h) }}</option>@endfor
            </flux:select>
        </div>
        @error('schedule.frequency')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror
        @error('schedule.day')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror
        @error('schedule.hour')<flux:text class="text-sm text-red-600" role="alert">{{ $message }}</flux:text>@enderror

        <fieldset class="space-y-2">
            <legend class="mb-1 text-sm font-medium">{{ __('Send to') }}</legend>
            <flux:input wire:model.live.debounce.300ms="staffSearch" :aria-label="__('Search staff')" :placeholder="__('Search staff by name or email')" icon="magnifying-glass" autocomplete="off" />
            <div class="max-h-48 space-y-1 overflow-y-auto">
                @forelse ($staff as $u)
                    <label class="flex items-center gap-2 text-sm" wire:key="staff-{{ $u->id }}">
                        <input type="checkbox" value="{{ $u->id }}" wire:model="schedule.recipients"> {{ $u->name }} <span class="text-zinc-500">{{ $u->email }}</span>
                    </label>
                @empty
                    <flux:text class="text-sm">{{ __('No staff found.') }}</flux:text>
                @endforelse
            </div>
            @foreach ($errors->get('schedule.recipients*') as $messages)@foreach ($messages as $m)<flux:text class="text-sm text-red-600" role="alert">{{ $m }}</flux:text>@endforeach @endforeach
        </fieldset>
        <div class="flex justify-end"><flux:button variant="primary" wire:click="addSchedule">{{ __('Add schedule') }}</flux:button></div>
    </div>
</flux:modal>
```

- [ ] **Step 6: The builder view uses the partial.** In `resources/views/livewire/reports/builder.blade.php`, delete the four lines in the top `@php` block that define `$days`, `$statusLabels`, `$freq` and `$freqLabels`; they now live in the partial and the rest of the file does not use them. Then replace the whole modal block (lines 34–107, from `@if ($reportId && $canEdit)` / `<flux:modal name="schedule-email" …>` through `</flux:modal>` / `@endif`) with:

```blade
    @if ($reportId && $canEdit)
        @include('livewire.reports.partials.schedule-modal', ['intro' => __('Each person gets the report with only the data they are allowed to see.'),
            'fixedDates' => ($def['period']['preset'] ?? null) === 'custom'])
    @endif
```

- [ ] **Step 7: The package screen schedules.** In `app/Livewire/Reports/Package.php`, add `use App\Livewire\Reports\Concerns\ManagesSchedules;`, change `use WithActor;` to `use ManagesSchedules, WithActor;`, and add:

```php
    protected function scheduleTarget(): ReportPackage
    {
        return $this->editable();
    }
```

In `render()`, add this to the view data:

```php
            ...$this->scheduleData($package),
```

In `package.blade.php`, inside `@if ($canEdit)` under `@if ($packageId)`, insert the trigger after the Share button:

```blade
                    <flux:modal.trigger name="schedule-email"><flux:button icon="envelope">{{ __('Schedule email') }}</flux:button></flux:modal.trigger>
```

Then, directly after the header's closing `</div>`, add:

```blade
    @if ($packageId && $canEdit)
        @include('livewire.reports.partials.schedule-modal', ['intro' => __('Each person gets the package with only the data they are allowed to see.'), 'fixedDates' => false])
    @endif
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/ReportPackages/PackageScreenTest.php tests/Feature/ReportBuilder`
Expected: PASS. `ScheduleScreenTest`, `BuilderScreenTest`, `SaveShareTest` and `FinalFixesTest` all pass unchanged.

- [ ] **Step 9: Gates and commit**

Run: `php vendor/bin/pint --dirty`, `php vendor/bin/phpstan analyse --memory-limit=2G` and `npm run build`.

```bash
git add app/Livewire/Reports/Concerns/ManagesSchedules.php resources/views/livewire/reports/partials/schedule-modal.blade.php app/Livewire/Reports/Builder.php resources/views/livewire/reports/builder.blade.php app/Livewire/Reports/Package.php resources/views/livewire/reports/package.blade.php tests/Feature/ReportPackages/PackageScreenTest.php
git commit -m "$(cat <<'EOF'
Schedule email on the package screen: the builder's schedule form moves to a shared trait and partial, audited on the package

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Scheduled package email, and the full suite

**Files:**
- Modify: `app/Console/Commands/SendScheduledReports.php` (`$description`, the `$due` query, the pause check)
- Modify: `app/Jobs/SendScheduledReport.php` (whole file)
- Create: `app/Notifications/ScheduledPackage.php`
- Test: `tests/Feature/ReportPackages/ScheduledPackageTest.php`

**Interfaces:**
- Consumes: `ReportSchedule::package()` (Task 1), `PackageRunner::run`, `PackageResult::allSkipped/periodLabel/lines/skippedLines` and `PackageWorkbook::write` (Task 3), and the `reports.package` route (Task 4).
- Produces:
  - `ScheduledPackage(string $packageName, string $periodLabel, list<string> $lines, list<string> $skipped, string $path, bool $attach, string $url)` (a mail notification).
  - `SendScheduledReport::handle(Engine $engine, PackageRunner $runner)`.
  - The delivery note `Every item skipped, nothing sent: <names>`.

- [ ] **Step 1: Write the failing test** `tests/Feature/ReportPackages/ScheduledPackageTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Jobs\SendScheduledReport;
use App\Models\CompanySetting;
use App\Models\ReportDelivery;
use App\Models\SavedReport;
use App\Models\User;
use App\Notifications\ScheduledPackage;
use App\Notifications\ScheduledReportFailed;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 07:05', 'Asia/Bahrain'));
    [$this->a, $this->b, $this->finance, $this->owner] = reportPackageFixture();
    $this->leasingB = matrixUser(RoleName::Leasing, $this->b);
    $this->package = packageWith($this->finance, [
        ['kind' => 'ready_made', 'report' => 'rent_roll', 'settings' => ['building' => null, 'period' => 'today']],
        ['kind' => 'ready_made', 'report' => 'collections', 'settings' => ['building' => null, 'period' => 'last_month']],
    ], 'Monthly pack', 'shared');
    $this->schedule = $this->package->schedules()->create(['frequency' => 'monthly', 'day' => 1, 'hour' => 7,
        'next_run_at' => CarbonImmutable::parse('2026-11-01 07:00', 'Asia/Bahrain'), 'created_by' => $this->finance->id]);
    $this->schedule->recipients()->attach([$this->finance->id, $this->leasingB->id]);
});

test('a due package schedule is claimed once; each recipient gets one workbook built as them', function () {
    $this->artisan('rms:reports:send-scheduled')->assertSuccessful();
    $this->artisan('rms:reports:send-scheduled')->assertSuccessful(); // same window: nothing more

    Notification::assertSentToTimes($this->finance, ScheduledPackage::class, 1);
    Notification::assertSentTo($this->finance, ScheduledPackage::class, fn (ScheduledPackage $n) => $n->packageName === 'Monthly pack'
        && $n->periodLabel === 'As at 01/11/2026' && $n->skipped === [] && $n->attach && $n->url === route('reports.package', $this->package)
        && $n->lines === ['Rent roll, As at 01/11/2026: 6 rows · Monthly rent: 800.000', 'Collections by date and method, 01/10/2026 – 31/10/2026: 2 rows · Amount: 300.000']);
    // Leasing on BBB only: its own building's rent roll, no money columns, and no collections (reports.financial).
    Notification::assertSentTo($this->leasingB, ScheduledPackage::class, fn (ScheduledPackage $n) => $n->lines === ['Rent roll, As at 01/11/2026: 3 rows']
        && $n->skipped === ['Collections by date and method: You do not have access to this report.']);

    expect(ReportDelivery::sole())->status->toBe('sent')->recipients_count->toBe(2)->error->toBeNull()
        ->and($this->schedule->fresh()->next_run_at->timezone('Asia/Bahrain')->format('Y-m-d H:i'))->toBe('2026-12-01 07:00')
        ->and(Activity::where('event', 'report.sent')->sole()->subject->is($this->package))->toBeTrue();
});

test('a recipient for whom every item is skipped gets nothing, and the delivery says so', function () {
    $this->package->items()->delete();
    $this->package->items()->create(['position' => 1, 'kind' => 'ready_made', 'report' => 'outstanding', 'settings' => ['building' => null]]);

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertSentTo($this->finance, ScheduledPackage::class);
    Notification::assertNotSentTo($this->leasingB, ScheduledPackage::class);
    expect(ReportDelivery::sole())->status->toBe('sent')->recipients_count->toBe(1)
        ->and(ReportDelivery::sole()->error)->toContain('Every item skipped, nothing sent: '.$this->leasingB->name);
});

test('vendor support and inactive staff never get a package', function () {
    $vendor = matrixUser(RoleName::VendorSupport, $this->a);
    $gone = matrixUser(RoleName::Finance, $this->a);
    $gone->forceFill(['active' => false])->save();
    $this->schedule->recipients()->sync([$vendor->id, $gone->id, $this->finance->id]);

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertNotSentTo($vendor, ScheduledPackage::class);
    Notification::assertNotSentTo($gone, ScheduledPackage::class);
    expect(ReportDelivery::sole()->recipients_count)->toBe(1);
});

test('a deleted package or a deactivated creator pauses the schedule', function () {
    $this->package->delete();
    $this->artisan('rms:reports:send-scheduled');

    expect($this->schedule->fresh()->paused_at)->not->toBeNull()->and(ReportDelivery::count())->toBe(0);
    Notification::assertNothingSent();
});

test('one recipient failing does not stop the others; all failing counts and tells the creator', function () {
    $saved = SavedReport::create(['name' => 'Monthly collections', 'subject' => 'payments', 'visibility' => 'shared', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['number', 'amount'], 'period' => ['field' => 'received_on', 'preset' => 'last_month']]]);
    $this->package->items()->create(['position' => 3, 'kind' => 'saved', 'saved_report_id' => $saved->id, 'settings' => []]);
    $second = matrixUser(RoleName::Finance, $this->a);
    $this->schedule->recipients()->sync([$this->finance->id, $second->id]);
    $bad = $this->finance->id;
    app()->bind(Engine::class, fn () => new class($bad) extends Engine
    {
        public function __construct(private int $bad) {}

        public function run(User $user, ReportDefinition $def, ?int $limit = self::DETAIL_LIMIT): ReportResult
        {
            throw_if($user->id === $this->bad, RuntimeException::class, 'boom');

            return parent::run($user, $def, $limit);
        }
    });

    $this->artisan('rms:reports:send-scheduled');

    Notification::assertSentTo($second, ScheduledPackage::class);
    Notification::assertNotSentTo($this->finance, ScheduledPackage::class);
    expect(ReportDelivery::sole())->status->toBe('sent')->error->toContain($this->finance->name)
        ->and($this->schedule->fresh()->consecutive_failures)->toBe(0);

    $delivery = ReportDelivery::create(['report_schedule_id' => $this->schedule->id, 'due_at' => '2026-12-01 07:00:00', 'status' => 'sending']);
    (new SendScheduledReport($delivery->id))->failed(new RuntimeException('timed out'));
    expect($delivery->fresh()->status)->toBe('failed')->and($this->schedule->fresh()->consecutive_failures)->toBe(1);
    Notification::assertSentTo($this->finance, ScheduledReportFailed::class, fn (ScheduledReportFailed $n) => $n->reportName === 'Monthly pack');
});

test('the package email: one line per item, the skipped ones, the workbook only under 10 MB, and a link to the package', function () {
    $file = tempnam(sys_get_temp_dir(), 'pkg');
    $mail = fn (bool $attach) => (new ScheduledPackage('Monthly pack', 'As at 01/11/2026', ['Rent roll, As at 01/11/2026: 6 rows · Monthly rent: 800.000'],
        ['Outstanding by tenant: You do not have access to this report.'], $file, $attach, 'https://x.test/p'))->toMail($this->finance);
    $with = $mail(true);
    $without = $mail(false);
    @unlink($file);

    expect($with->subject)->toBe('Monthly pack — As at 01/11/2026')
        ->and($with->introLines)->toBe(['Rent roll, As at 01/11/2026: 6 rows · Monthly rent: 800.000', 'Skipped:', 'Outstanding by tenant: You do not have access to this report.'])
        ->and($with->actionText)->toBe('Open in the app')->and($with->actionUrl)->toBe('https://x.test/p')
        ->and($with->attachments)->toHaveCount(1)
        ->and($without->attachments)->toBeEmpty()
        ->and($without->introLines)->toContain('The file is too large to attach; open the package to download it.')
        ->and(SendScheduledReport::ATTACH_LIMIT)->toBe(10 * 1024 * 1024);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ReportPackages/ScheduledPackageTest.php`
Expected: FAIL with `Class "App\Notifications\ScheduledPackage" not found`.

- [ ] **Step 3: The notification** `app/Notifications/ScheduledPackage.php`:

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Spec 2026-10-17 §5: one line per item, the skipped ones, the workbook attached under 10 MB, a link to the package. */
class ScheduledPackage extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines  PackageResult::lines()
     * @param  list<string>  $skipped  PackageResult::skippedLines()
     */
    public function __construct(public string $packageName, public string $periodLabel, public array $lines, public array $skipped,
        public string $path, public bool $attach, public string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__(':name — :period', ['name' => $this->packageName, 'period' => $this->periodLabel]));
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        if ($this->skipped !== []) {
            $mail->line(__('Skipped:'));
            foreach ($this->skipped as $line) {
                $mail->line($line);
            }
        }
        if ($this->attach) {
            $mail->attach($this->path, ['as' => str($this->packageName)->slug().'.xlsx']);
        } else {
            $mail->line(__('The file is too large to attach; open the package to download it.'));
        }

        return $mail->action(__('Open in the app'), $this->url);
    }
}
```

- [ ] **Step 4: The command claims package schedules too.** In `SendScheduledReports.php`, change `$description` to `'Send saved reports and report packages whose email schedule is due.'` and the class docblock to end `… (spec §7; packages: spec 2026-10-17 §5).` Then change the query and the pause check:

```php
        $due = ReportSchedule::query()->whereNull('paused_at')->where('next_run_at', '<=', now())->with(['creator', 'report', 'package'])->get();
        foreach ($due as $schedule) {
            if (($schedule->report === null && $schedule->package === null) || ! $schedule->creator?->active) {
```

- [ ] **Step 5: The job.** Replace `app/Jobs/SendScheduledReport.php`. The saved-report path is the old loop body moved into `sendReport()`; the package path is new:

```php
<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Models\ReportDelivery;
use App\Models\ReportPackage;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use App\Notifications\ScheduledPackage;
use App\Notifications\ScheduledReport;
use App\Notifications\ScheduledReportFailed;
use App\Reports\Builder\Comparison;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\Formatter;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Packages\PackageRunner;
use App\Reports\Packages\PackageWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/** Runs the saved report or package as each recipient (their permissions and buildings) and emails it (spec §7; packages: spec 2026-10-17 §5). */
class SendScheduledReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int ATTACH_LIMIT = 10 * 1024 * 1024;

    public int $timeout = 300;

    /** One attempt only: a retry would email the recipients who already got it. The worker's --tries does not override this. */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId) {}

    public function handle(Engine $engine, PackageRunner $runner): void
    {
        $delivery = ReportDelivery::find($this->deliveryId);
        $schedule = $delivery === null ? null : ReportSchedule::with(['report', 'package', 'recipients', 'creator'])->find($delivery->report_schedule_id);
        if ($delivery === null || $delivery->status !== 'sending' || $schedule === null) {
            return;
        }
        $target = $schedule->report ?? $schedule->package;
        if ($target === null) {
            $delivery->update(['status' => 'failed', 'error' => __('The report no longer exists.')]);

            return;
        }
        $sent = [];
        $skipped = [];
        $empty = [];
        $failed = [];
        $period = null;
        foreach ($schedule->recipients as $user) {
            if (! $user->active || $user->isVendorSupport() || ($target instanceof SavedReport && ! isset(Registry::forUser($user)[$target->subject]))) {
                $skipped[] = $user->name;

                continue;
            }
            $path = storage_path('app/private/report-mail/'.Str::uuid().'.xlsx');
            try {
                @mkdir(dirname($path), 0775, true);
                $label = $target instanceof SavedReport ? $this->sendReport($engine, $target, $user, $path) : $this->sendPackage($runner, $target, $user, $path);
                if ($label === null) {
                    $empty[] = $user->name;
                } else {
                    $period = $label;
                    $sent[] = $user->id;
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = $user->name.' ('.Str::limit($e->getMessage(), 80).')';
            } finally {
                @unlink($path); // the mail is built synchronously; on Windows the file can be locked, hence @
            }
        }
        $notes = array_filter([
            $skipped === [] ? null : (string) __('No access or inactive, skipped: :names', ['names' => implode(', ', $skipped)]),
            $empty === [] ? null : (string) __('Every item skipped, nothing sent: :names', ['names' => implode(', ', $empty)]),
            $failed === [] ? null : (string) __('Failed: :names', ['names' => implode('; ', $failed)]),
        ]);
        $note = $notes === [] ? null : Str::limit(implode(' | ', $notes), 490);
        if ($sent === [] && $failed !== []) {
            $this->fail($delivery, $schedule, $note ?? '');

            return;
        }
        $delivery->update(['status' => $sent !== [] ? 'sent' : 'skipped', 'recipients_count' => count($sent), 'sent_at' => now(), 'error' => $note]);
        if ($failed === []) { // a partial send neither counts as a failure nor clears the count
            $schedule->update(['consecutive_failures' => 0]);
        }
        if ($sent !== []) {
            Audit::log('report.sent', $target, properties: ['schedule' => $schedule->id, 'recipients' => $sent, 'period' => $period, 'due_at' => $delivery->due_at->toIso8601String()]);
        }
    }

    /** @return string the period sent */
    private function sendReport(Engine $engine, SavedReport $report, User $user, string $path): string
    {
        [$data, $dropped] = ReportDefinition::forViewer($report->definition, $user);
        $result = $engine->run($user, ReportDefinition::fromArray($data, $user), limit: null);
        ExcelWriter::write($result, $path);
        $totals = [];
        $byKey = collect($result->columns)->keyBy('key');
        foreach ($result->grandTotals as $key => $value) {
            if ($key === 'count') {
                $totals[__('Rows')] = Formatter::total(null, 'count', $value);

                continue;
            }
            [$field, $fn] = explode(':', $key, 2);
            $f = $byKey[$field] ?? null;
            $totals[($f->label ?? $field).' ('.__($fn).')'] = Formatter::total($f, $fn, $value);
        }
        $period = (string) $result->periodLabel;
        $user->notify(new ScheduledReport($report->name, $period, $result->rowCount, $totals, $path,
            filesize($path) <= self::ATTACH_LIMIT, route('reports.builder', $report),
            $result->comparison === null ? [] : Comparison::lines($result->comparison, $result->columns),
            $dropped === [] ? null : (string) __('Some fields aren\'t available to you and were left out: :list', ['list' => implode(', ', $dropped)])));

        return $period;
    }

    /** @return string|null the period sent; null when every item was skipped for this recipient, so nothing is sent */
    private function sendPackage(PackageRunner $runner, ReportPackage $package, User $user, string $path): ?string
    {
        $result = $runner->run($package, $user, CarbonImmutable::now('Asia/Bahrain'));
        if ($result->allSkipped()) {
            return null;
        }
        PackageWorkbook::write($result, $path);
        $user->notify(new ScheduledPackage($package->name, $result->periodLabel(), $result->lines(), $result->skippedLines(), $path,
            filesize($path) <= self::ATTACH_LIMIT, route('reports.package', $package)));

        return $result->periodLabel();
    }

    /** A killed or timed-out run must not leave the delivery stuck in `sending`. */
    public function failed(Throwable $e): void
    {
        $delivery = ReportDelivery::find($this->deliveryId);
        $schedule = $delivery === null ? null : ReportSchedule::with(['report', 'package', 'creator'])->find($delivery->report_schedule_id);
        if ($delivery !== null && $schedule !== null && $delivery->status === 'sending') {
            $this->fail($delivery, $schedule, Str::limit($e->getMessage(), 490));
        }
    }

    private function fail(ReportDelivery $delivery, ReportSchedule $schedule, string $detail): void
    {
        $failures = $schedule->consecutive_failures + 1;
        $delivery->update(['status' => 'failed', 'error' => $detail]);
        $schedule->update(['consecutive_failures' => $failures] + ($failures >= 3 ? ['paused_at' => now()] : []));
        $schedule->creator?->notify(new ScheduledReportFailed(($schedule->report ?? $schedule->package)->name ?? '', $failures >= 3
            ? __('It failed 3 times in a row and is paused. Open the report, fix it and schedule it again.')
            : __('This send failed; the details are on the delivery record.')));
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/ReportPackages tests/Feature/ReportBuilder`
Expected: PASS. `ScheduledSendTest` and `FinalFixesTest` pass unchanged.

- [ ] **Step 7: Full suite and gates.** Run these serially:
  - `php artisan test`: all tests pass, and `IntegrityCheck` still expects 61 triggers.
  - `php vendor/bin/pint --dirty`
  - `php vendor/bin/phpstan analyse --memory-limit=2G`
  - `npm run build`

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/SendScheduledReports.php app/Jobs/SendScheduledReport.php app/Notifications/ScheduledPackage.php tests/Feature/ReportPackages/ScheduledPackageTest.php
git commit -m "$(cat <<'EOF'
Scheduled package email: claimed with the saved-report schedules, built per recipient, item lines and skipped list, workbook under 10 MB

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (done while writing)

- **Spec coverage:**
  - §1 purpose: Tasks 3 and 6. The out-of-scope reports (Building profitability, Head-lease due, Owner statements) are not in `ReadyMade::REPORTS`.
  - §2 headless run: Task 2 (ruling 1). Saved reports through `Engine` and `ExcelWriter`: Task 3.
  - §3 package and items: Tasks 1 and 4.
    - 1–15 items, enforced on save and in `addItem`.
    - Saved reports must be visible to the editor.
    - Building only where the report has a filter (`form['building']`).
    - Relative period (rulings 6, 7, 19).
    - Options validated on save (`checkSettings`) and on every run (`sheet`).
    - Invalid settings are skipped and noted.
    - Packages area and screen, with add, remove, move, settings, Save, Share, Delete, Download now and Schedule email.
    - Permissions: mount check and the policy. Items without permission are skipped.
  - §4 runner and workbook: Task 3. Names, truncation, clashes, forbidden characters, title, period and blank rows, skipped sheets, dropped-field note.
  - §5 scheduled email: Tasks 1, 5 and 6.
    - Exactly-one CHECK, with the cascade replaced in code (ruling 5).
    - Claiming, per-recipient builds and recipient rules.
    - All-skipped recipients get nothing and the delivery notes it.
    - Subject, lines, skipped list, the 10 MB rule and the link.
    - Failure counting and pausing. A timeout goes through `failed()`.
  - §6 Download now with its audit: Task 4. Temp files use `deleteFileAfterSend()`.
  - §7 storage: Task 1. No triggers.
  - §8 safety: whitelists in Tasks 2 and 4, tamper datasets in Tasks 2 and 4, `__()` throughout.
  - §9 testing: each listed test maps to one above. "Statement month capping" is tested through the current-month cap. The 12-month cap cannot be reached through any preset (ruling 6).
- **Placeholder scan:** none. One conditional instruction is a named fallback: Task 5 Step 3's phpstan `@return` relaxation, which mirrors code that already passes.
- **Type consistency:**
  - Same signatures in every task:
    - `PackageReport::packageForm/checkSettings/sheet` and the `Form` shape.
    - `ReadyMade::REPORTS/make`.
    - `PackageRunner::run/resolve/clean/presets/MAX_ITEMS`.
    - `PackageSheet` (`skip()` is the static factory and `skipped` the property).
    - `PackageResult::allSkipped/periodLabel/lines/skippedLines`.
    - `PackageWorkbook::write/sheetName`.
    - `ManagesSchedules::scheduleTarget/scheduleData`.
    - `ScheduledPackage`'s constructor and public property names (`packageName`, `periodLabel`, `lines`, `skipped`, `attach`, `url`).
  - Same names in every task:
    - Route name `reports.package`.
    - Report keys (ruling 3).
    - Skip reasons, verbatim.
- **Fixture arithmetic** (2026-10-15):
  - Rent roll as Finance: AAA has 2 units (one occupied at 400, one vacant owner-contract unit) plus a subtotal. BBB has 1 unit at 400 plus a subtotal. Add the grand total, and that is 6 rows with total rent 800.000. Leasing on BBB sees 3 rows.
  - Collections: September is one day row plus the total, so 2 rows, headline 100.000. September plus October is 400.000. October alone is 300.000.
  - The saved payments report for last month is 1 row, 100.000.
  - 15/10/2026 is a Thursday, so a weekly 08:00 Sunday schedule is next due on 18/10/2026.
