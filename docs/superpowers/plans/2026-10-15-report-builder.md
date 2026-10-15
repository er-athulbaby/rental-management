# Report Builder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff build, save, share, chart, compare and email their own reports over 12 subjects, without touching the 13 ready-made reports.

**Architecture:** Each subject is a PHP class that returns a user-scoped base query (Illuminate query builder) and a whitelist of `Field`s (label, type, SQL expression). A saved `ReportDefinition` (validated JSON, never SQL) is compiled by `QueryCompiler` (building, period, filters) and run by `Engine` (detail rows, then in later stages `Aggregator` for grouped totals via MySQL `WITH ROLLUP`, and `Comparison`). A Livewire `Builder` page edits definitions; `SavedReport` stores them; stage 5 adds schedules, a runner command, a queued job and a mail notification.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL 26.7, Livewire 4 + Flux (free), Pest 5, spatie/simple-excel, spatie/laravel-permission, existing `App\Audit\Audit`, `App\Support\Fils`, `<x-report-chart>`, `<x-date-input>`, `<x-building-filter>`.

**Spec:** `docs/superpowers/specs/2026-10-15-report-builder-design.md` (read it first; this plan argues from it).

## Global Constraints

- Money is BHD to 3 decimals; sums and averages are computed in SQL on DECIMAL columns; display uses `number_format($v, 3)`; Excel gets numbers.
- Timezone Asia/Bahrain for every period; weeks start on Sunday.
- No new permissions. Builder access = `reports.operational` or `reports.financial`. Admin rights over any report = `settings.manage`.
- Every subject query applies the same visibility scope as its screens (`visibleTo($user)` / existing rules). Building filter uses the models' existing `inBuilding` scopes or `building_id`.
- Definitions are validated on save AND on run; no SQL is stored or accepted; all values are bound parameters.
- Limits: 30 columns, 20 filters, 2 group levels, 500 detail rows on screen, 1,000 groups, 30-second query hint (`/*+ MAX_EXECUTION_TIME(30000) */`).
- Field SQL may embed dates only as `'Y-m-d'` literals produced by the code (never user input).
- UI copy says "tenant", never "customer". Dates display dd/mm/yyyy.
- Tests run serially against `rms_test` (`php artisan test`, never `--parallel`); Pint and phpstan (`vendor/bin/phpstan analyse --memory-limit=2G`) must pass.
- PHP binary: `/c/Users/ababy/.config/herd/bin/php85/php.exe` (written `php` below).
- Do not run `migrate:fresh` on the dev database `rms`; migrate with `php artisan migrate --database=migrator --force`.
- Commit only when the user asks; tasks end with the commit command for the controller to run when approved.

## Rulings made while planning

- Owner statements expose stored figures only (opening, fee base, fee, fee VAT, closing); the spec was updated.
- Units "occupancy" is occupied / vacant / blocked from `Queries::occupiedUnitIds`; notice-given and reserved are not SQL-derivable cheaply. Spec updated.
- The Building **filter** is the dedicated "All buildings" control (like every report), not a field filter, so "Multiple" never needs filtering semantics; the Building **field** is for columns, grouping and sorting.
- Payments out: the Building field is filled for head-lease and owner remittances (owner contract's building) and "—" for refunds; the Building filter still uses `Disbursement::inBuilding` (all sources).

## File structure

```
app/Reports/Builder/
  FieldType.php            enum: text, enum, money, number, date, boolean
  Field.php                readonly value object + static constructors
  Subject.php              abstract base: key, label, permission, query(), fields(), periodFields(), asAt(), defaultColumns()
  Registry.php             subject list, lookup, subjects available to a user
  Sql.php                  shared SQL fragments (building code / "Multiple", unit list, today literal)
  Period.php               relative ranges, previous, last year, labels
  ReportDefinition.php     validated, versioned value object (fromArray / toArray)
  QueryCompiler.php        base query + building + period + filters
  Engine.php               run(): detail rows, count, totals, groups (stage 3), comparison (stage 4)
  Aggregator.php           (stage 3) grouped totals with ROLLUP
  Comparison.php           (stage 4) second period, merge, change
  Formatter.php            raw value -> display string per field type
  ReportResult.php         result value object
  ExcelWriter.php          xlsx export with subtotal rows
  Subjects/{Invoices,Payments,Cheques,Agreements,Units,Tenants,Owners,OwnerContracts,OwnerStatements,Expenses,PaymentsOut,DepositsHeld}.php
app/Models/SavedReport.php
app/Policies/SavedReportPolicy.php
app/Livewire/Reports/Builder.php + resources/views/livewire/reports/builder.blade.php
resources/views/livewire/reports/index.blade.php (My reports section)
database/migrations/2026_12_16_000100_create_saved_reports_table.php
-- stage 5 --
database/migrations/2026_12_16_000200_create_report_schedules_tables.php
app/Models/ReportSchedule.php, app/Models/ReportDelivery.php
app/Reports/Builder/NextRun.php
app/Console/Commands/SendScheduledReports.php
app/Jobs/SendScheduledReport.php
app/Notifications/ScheduledReport.php, app/Notifications/ScheduledReportFailed.php
tests/Feature/ReportBuilder/*.php
```

---

# Stage 1 — engine, four subjects, builder, save, share, Excel

### Task 1: Saved reports table, model and policy

**Files:**
- Create: `database/migrations/2026_12_16_000100_create_saved_reports_table.php`
- Create: `app/Models/SavedReport.php`, `app/Policies/SavedReportPolicy.php`
- Test: `tests/Feature/ReportBuilder/SavedReportPolicyTest.php`

**Interfaces:**
- Produces: `SavedReport` (fields `name`, `subject`, `definition` array cast, `visibility` 'private'|'shared', `created_by`, `updated_by`, `last_run_at`); scope `SavedReport::visibleTo(User)` (own + shared); policy abilities `view`, `update`, `delete`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\SavedReport;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->other = matrixUser(RoleName::Finance, $this->building);
    $this->admin = matrixUser(RoleName::Admin, $this->building);
});

function savedReport(App\Models\User $owner, string $visibility = 'private'): SavedReport
{
    return SavedReport::create(['name' => 'Overdue by building', 'subject' => 'invoices', 'definition' => ['version' => 1, 'subject' => 'invoices', 'columns' => ['number']],
        'visibility' => $visibility, 'created_by' => $owner->id, 'updated_by' => $owner->id]);
}

test('a private report is seen and changed only by its creator; an Admin may change any', function () {
    $mine = savedReport($this->finance);

    expect($this->finance->can('view', $mine))->toBeTrue()->and($this->finance->can('update', $mine))->toBeTrue()
        ->and($this->other->can('view', $mine))->toBeFalse()
        ->and($this->admin->can('update', $mine))->toBeTrue()->and($this->admin->can('delete', $mine))->toBeTrue()
        ->and(SavedReport::visibleTo($this->other)->count())->toBe(0);
});

test('a shared report is seen by everyone with report access but changed only by its creator or an Admin', function () {
    $shared = savedReport($this->finance, 'shared');

    expect($this->other->can('view', $shared))->toBeTrue()->and($this->other->can('update', $shared))->toBeFalse()
        ->and(SavedReport::visibleTo($this->other)->pluck('id')->all())->toBe([$shared->id]);

    $leasing = matrixUser(RoleName::Leasing, $this->building); // operational reports only: an invoices report is not theirs to see
    expect($leasing->can('view', $shared))->toBeFalse();
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test tests/Feature/ReportBuilder/SavedReportPolicyTest.php`
Expected: FAIL (class `App\Models\SavedReport` not found).

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user's own report: a validated description of what to show (spec §9). Never SQL.
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('subject', 40);
            $table->json('definition');
            $table->string('visibility', 10)->default('private');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['visibility', 'created_by']);
        });
        DB::statement("ALTER TABLE saved_reports ADD CONSTRAINT saved_reports_visibility_chk CHECK (visibility IN ('private', 'shared'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
```

- [ ] **Step 4: Model**

```php
<?php

namespace App\Models;

use App\Reports\Builder\Registry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A report a user built (spec §3, §9). `definition` is a ReportDefinition array, validated again on every run.
 *
 * @property int $id
 * @property string $name
 * @property string $subject
 * @property array<string, mixed> $definition
 * @property string $visibility private | shared
 * @property int $created_by
 * @property int $updated_by
 * @property CarbonImmutable|null $last_run_at
 */
class SavedReport extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'subject', 'definition', 'visibility', 'created_by', 'updated_by', 'last_run_at'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'last_run_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user's own reports and every shared one whose subject they may use.
     *
     * @param  Builder<SavedReport>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('created_by', $user->id)->orWhere('visibility', 'shared'))
            ->whereIn('subject', array_keys(Registry::forUser($user)));
    }
}
```

- [ ] **Step 5: Policy** (auto-discovered by name, like the other policies)

```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\SavedReport;
use App\Models\User;
use App\Reports\Builder\Registry;

/** Spec §8: the creator or an Admin changes a report; anyone who may use its subject sees a shared one. */
class SavedReportPolicy
{
    public function view(User $user, SavedReport $report): bool
    {
        return isset(Registry::forUser($user)[$report->subject])
            && ($report->created_by === $user->id || $report->visibility === 'shared');
    }

    public function update(User $user, SavedReport $report): bool
    {
        return $report->created_by === $user->id || $user->can(PermissionName::SettingsManage);
    }

    public function delete(User $user, SavedReport $report): bool
    {
        return $this->update($user, $report);
    }
}
```

`Registry` comes in Task 2; this task's test passes after Task 2. Run Task 2 next before re-running.

- [ ] **Step 6: Migrate the dev database**

Run: `php artisan migrate --database=migrator --force` → `create_saved_reports_table … DONE`.

### Task 2: Field model, Subject base, Registry, Sql helpers and the Invoices subject

**Files:**
- Create: `app/Reports/Builder/{FieldType,Field,Subject,Registry,Sql}.php`, `app/Reports/Builder/Subjects/Invoices.php`
- Test: `tests/Feature/ReportBuilder/RegistryTest.php`

**Interfaces:**
- Produces:
  - `enum FieldType: string { Text='text'; Enum='enum'; Money='money'; Number='number'; Date='date'; Boolean='boolean'; }` with `numeric(): bool`.
  - `Field` readonly: `key`, `label`, `section`, `type`, `sql`, `options` (array<string,string>), `groupable`, `filterable`, `permission` (?PermissionName). Static: `text()`, `enum()`, `money()`, `number()`, `date()`, `boolean()`; `Field::optionsFrom(class-string<BackedEnum>)`.
  - `abstract class Subject`: `key(): string`, `label(): string`, `group(): string` (Money in / Leasing / Owners / Costs), `permission(): PermissionName`, `query(User $user, ?int $buildingId, CarbonImmutable $asAt): Illuminate\Database\Query\Builder`, `fields(User $user, CarbonImmutable $asAt): array<string, Field>`, `periodFields(): list<string>`, `usesAsAt(): bool`, `defaultColumns(): list<string>`, `defaultSort(): array{field: string, dir: string}`.
  - `Registry::all(): array<string, Subject>`, `Registry::get(string $key): Subject` (throws `InvalidArgumentException`), `Registry::forUser(User $user): array<string, Subject>`.
  - `Sql::date(CarbonImmutable): string` (quoted literal), `Sql::buildingOfInvoice(string $invoiceIdExpr): string`, `Sql::unitsOfInvoice(string $invoiceIdExpr): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Reports\Builder\FieldType;
use App\Reports\Builder\Registry;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->building = Building::factory()->create();
});

test('subjects are offered by report permission', function () {
    $finance = matrixUser(RoleName::Finance, $this->building);
    $leasing = matrixUser(RoleName::Leasing, $this->building);

    expect(array_keys(Registry::forUser($finance)))->toContain('invoices')
        ->and(array_keys(Registry::forUser($leasing)))->not->toContain('invoices');
});

test('the invoices subject declares typed fields with labels and a default column set', function () {
    $finance = matrixUser(RoleName::Finance, $this->building);
    $subject = Registry::get('invoices');
    $fields = $subject->fields($finance, CarbonImmutable::parse('2026-10-15'));

    expect($fields['balance']->type)->toBe(FieldType::Money)
        ->and($fields['due_date']->type)->toBe(FieldType::Date)
        ->and($fields['status']->options)->toHaveKey('overdue')
        ->and($fields['building']->filterable)->toBeFalse()
        ->and($subject->periodFields())->toBe(['issue_date', 'due_date'])
        ->and(array_diff($subject->defaultColumns(), array_keys($fields)))->toBe([]);
    expect(fn () => Registry::get('nope'))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run to see it fail** — `php artisan test tests/Feature/ReportBuilder/RegistryTest.php` → FAIL (class not found).

- [ ] **Step 3: FieldType and Field**

```php
<?php

namespace App\Reports\Builder;

enum FieldType: string
{
    case Text = 'text';
    case Enum = 'enum';
    case Money = 'money';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';

    /** Money and numbers take sum / average / min / max (spec §4.1). */
    public function numeric(): bool
    {
        return $this === self::Money || $this === self::Number;
    }
}
```

```php
<?php

namespace App\Reports\Builder;

use App\Enums\PermissionName;
use BackedEnum;

/**
 * One reportable field of a subject (spec §4.1). `sql` is an expression over the subject's base query, written by
 * us; it may embed code-made 'Y-m-d' literals (Sql::date) and never user input.
 */
final readonly class Field
{
    /** @param  array<string, string>  $options  value => label, for enum fields */
    public function __construct(
        public string $key,
        public string $label,
        public string $section,
        public FieldType $type,
        public string $sql,
        public array $options = [],
        public bool $groupable = true,
        public bool $filterable = true,
        public ?PermissionName $permission = null,
    ) {}

    public static function text(string $key, string $label, string $section, string $sql, bool $groupable = true, bool $filterable = true, ?PermissionName $permission = null): self
    {
        return new self($key, $label, $section, FieldType::Text, $sql, [], $groupable, $filterable, $permission);
    }

    /** @param  array<string, string>  $options */
    public static function enum(string $key, string $label, string $section, string $sql, array $options, ?PermissionName $permission = null): self
    {
        return new self($key, $label, $section, FieldType::Enum, $sql, $options, true, true, $permission);
    }

    public static function money(string $key, string $label, string $section, string $sql, ?PermissionName $permission = null): self
    {
        return new self($key, $label, $section, FieldType::Money, $sql, [], false, true, $permission);
    }

    public static function number(string $key, string $label, string $section, string $sql, ?PermissionName $permission = null): self
    {
        return new self($key, $label, $section, FieldType::Number, $sql, [], false, true, $permission);
    }

    public static function date(string $key, string $label, string $section, string $sql): self
    {
        return new self($key, $label, $section, FieldType::Date, $sql);
    }

    public static function boolean(string $key, string $label, string $section, string $sql): self
    {
        return new self($key, $label, $section, FieldType::Boolean, $sql);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, string>
     */
    public static function optionsFrom(string $enum): array
    {
        $options = [];
        foreach ($enum::cases() as $case) {
            $options[(string) $case->value] = method_exists($case, 'label') ? $case->label() : str((string) $case->value)->headline()->toString();
        }

        return $options;
    }
}
```

- [ ] **Step 4: Subject base, Sql helpers and Registry**

```php
<?php

namespace App\Reports\Builder;

use App\Enums\PermissionName;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/** A reportable subject (spec §4.2): where its rows come from, scoped to the user, and its whitelisted fields. */
abstract class Subject
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** Money in, Leasing, Owners or Costs (the subject picker's groups). */
    abstract public function group(): string;

    abstract public function permission(): PermissionName;

    /** Rows the user may see, already narrowed to the building when one is chosen. */
    abstract public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder;

    /** @return list<Field> */
    abstract protected function define(CarbonImmutable $asAt): array;

    /** @return list<string> */
    abstract public function defaultColumns(): array;

    /** @return list<string> date field keys a period can use; [] when the subject has none */
    public function periodFields(): array
    {
        return [];
    }

    /** True for subjects read "as at" a date (Units, Deposits held). */
    public function usesAsAt(): bool
    {
        return false;
    }

    /** @return array{field: string, dir: string} */
    public function defaultSort(): array
    {
        return ['field' => $this->defaultColumns()[0], 'dir' => 'asc'];
    }

    /** @return array<string, Field> the fields this user may use, keyed */
    public function fields(User $user, CarbonImmutable $asAt): array
    {
        $fields = [];
        foreach ($this->define($asAt) as $field) {
            if ($field->permission === null || $user->can($field->permission)) {
                $fields[$field->key] = $field;
            }
        }

        return $fields;
    }
}
```

```php
<?php

namespace App\Reports\Builder;

use Carbon\CarbonImmutable;

/** SQL fragments several subjects share. Inputs are column expressions written by us, never user input. */
final class Sql
{
    /** A code-made date literal, safe to embed. */
    public static function date(CarbonImmutable $day): string
    {
        return "'".$day->format('Y-m-d')."'";
    }

    /** The building code of an invoice's units, "Multiple" when they span buildings (spec §4.3). */
    public static function buildingOfInvoice(string $invoiceId): string
    {
        return "(SELECT CASE WHEN COUNT(DISTINCT bu.building_id) > 1 THEN 'Multiple' ELSE MAX(bb.code) END
            FROM invoice_lines bl JOIN units bu ON bu.id = bl.unit_id JOIN buildings bb ON bb.id = bu.building_id
            WHERE bl.invoice_id = {$invoiceId})";
    }

    public static function unitsOfInvoice(string $invoiceId): string
    {
        return "(SELECT GROUP_CONCAT(DISTINCT CONCAT(ub.code, ' / ', uu.code) ORDER BY ub.code, uu.code SEPARATOR ', ')
            FROM invoice_lines ul JOIN units uu ON uu.id = ul.unit_id JOIN buildings ub ON ub.id = uu.building_id
            WHERE ul.invoice_id = {$invoiceId})";
    }

    /** The building code of an agreement's units, "Multiple" when they span buildings. */
    public static function buildingOfAgreement(string $agreementId): string
    {
        return "(SELECT CASE WHEN COUNT(DISTINCT gu.building_id) > 1 THEN 'Multiple' ELSE MAX(gb.code) END
            FROM agreement_units ga JOIN units gu ON gu.id = ga.unit_id JOIN buildings gb ON gb.id = gu.building_id
            WHERE ga.agreement_id = {$agreementId})";
    }

    public static function unitsOfAgreement(string $agreementId): string
    {
        return "(SELECT GROUP_CONCAT(DISTINCT CONCAT(vb.code, ' / ', vu.code) ORDER BY vb.code, vu.code SEPARATOR ', ')
            FROM agreement_units va JOIN units vu ON vu.id = va.unit_id JOIN buildings vb ON vb.id = vu.building_id
            WHERE va.agreement_id = {$agreementId})";
    }
}
```

```php
<?php

namespace App\Reports\Builder;

use App\Models\User;
use InvalidArgumentException;

/** The 12 subjects (spec §4.2), in picker order. */
final class Registry
{
    /** @var array<string, Subject>|null */
    private static ?array $subjects = null;

    /** @return array<string, Subject> */
    public static function all(): array
    {
        if (self::$subjects === null) {
            self::$subjects = [];
            foreach ([
                Subjects\Invoices::class, Subjects\Payments::class,
                Subjects\Agreements::class, Subjects\Units::class,
            ] as $class) {
                $subject = new $class;
                self::$subjects[$subject->key()] = $subject;
            }
        }

        return self::$subjects;
    }

    public static function get(string $key): Subject
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown report subject [{$key}].");
    }

    /** @return array<string, Subject> */
    public static function forUser(User $user): array
    {
        return array_filter(self::all(), fn (Subject $s) => $user->can($s->permission()));
    }
}
```

(Tasks 3, 9 and 10 add their subject classes to the list in `all()`. Until Task 3 lands, comment out the not-yet-written classes; the list's final order is: Invoices, Payments, Cheques, Agreements, Units, Tenants, Owners, OwnerContracts, OwnerStatements, Expenses, PaymentsOut, DepositsHeld.)

- [ ] **Step 5: The Invoices subject**

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\InvoiceType;
use App\Enums\PermissionName;
use App\Models\Invoice;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Sql;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Invoices extends Subject
{
    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return __('Invoices');
    }

    public function group(): string
    {
        return __('Money in');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsFinancial;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Invoice::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase()
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('agreements', 'agreements.id', '=', 'invoices.agreement_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $today = Sql::date($asAt);
        $owed = "invoices.status = 'issued' AND invoices.type <> 'credit_note'";

        return [
            Field::text('number', __('Invoice'), __('Invoice'), "COALESCE(invoices.number, CONCAT('#', invoices.id))", groupable: false),
            Field::enum('type', __('Type'), __('Invoice'), 'invoices.type', Field::optionsFrom(InvoiceType::class)),
            Field::enum('status', __('Status'), __('Invoice'),
                "CASE WHEN {$owed} THEN (CASE WHEN invoices.balance <= 0 THEN 'paid' WHEN invoices.grace_until < {$today} THEN 'overdue'
                    WHEN invoices.balance < invoices.total THEN 'partially_paid' ELSE 'unpaid' END) ELSE invoices.status END",
                ['unpaid' => __('Unpaid'), 'partially_paid' => __('Partially paid'), 'overdue' => __('Overdue'), 'paid' => __('Paid'),
                    'draft' => __('Draft'), 'pending_approval' => __('Pending approval'), 'scheduled' => __('Scheduled'),
                    'issued' => __('Issued'), 'cancelled' => __('Cancelled')]),
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('agreement', __('Agreement'), __('Tenant'), 'agreements.number'),
            Field::text('building', __('Building'), __('Property'), 'COALESCE('.Sql::buildingOfInvoice('invoices.id').", '—')", filterable: false),
            Field::text('units', __('Units'), __('Property'), Sql::unitsOfInvoice('invoices.id'), groupable: false),
            Field::date('issue_date', __('Issue date'), __('Dates'), 'invoices.issue_date'),
            Field::date('due_date', __('Due date'), __('Dates'), 'invoices.due_date'),
            Field::number('days_overdue', __('Days overdue'), __('Dates'),
                "CASE WHEN {$owed} AND invoices.balance > 0 AND invoices.grace_until < {$today} THEN DATEDIFF({$today}, invoices.due_date) END"),
            Field::money('net', __('Net'), __('Amounts'), 'invoices.subtotal'),
            Field::money('vat', __('VAT'), __('Amounts'), 'invoices.tax_total'),
            Field::money('total', __('Total'), __('Amounts'), 'invoices.total'),
            Field::money('paid', __('Paid'), __('Amounts'), 'invoices.allocated'),
            Field::money('credited', __('Credited'), __('Amounts'), 'invoices.credited'),
            Field::money('balance', __('Balance'), __('Amounts'), "CASE WHEN invoices.status = 'issued' THEN invoices.balance ELSE 0 END"),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'tenant', 'building', 'due_date', 'total', 'balance', 'status'];
    }

    public function periodFields(): array
    {
        return ['issue_date', 'due_date'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'due_date', 'dir' => 'asc'];
    }
}
```

- [ ] **Step 6: Run both tests** — `php artisan test tests/Feature/ReportBuilder` → both files PASS (Registry lists only Invoices until Task 3 — keep the other three class names commented out in `all()` and the test still passes because it only asks for invoices).

### Task 3: Payments, Agreements and Units subjects

**Files:**
- Create: `app/Reports/Builder/Subjects/{Payments,Agreements,Units}.php`
- Modify: `app/Reports/Builder/Registry.php` (uncomment the three classes), `app/Reports/Queries.php` (add `currentAgreementUnits`)
- Test: `tests/Feature/ReportBuilder/SubjectQueriesTest.php` (written in Task 5, after the engine exists; this task is verified by Task 5's tests)

**Interfaces:**
- Consumes: `Subject`, `Field`, `Sql` (Task 2).
- Produces: subject keys `payments`, `agreements`, `units`; `Queries::currentAgreementUnits(User $user, string $on): Illuminate\Database\Query\Builder` selecting `unit_id`, `au_id` (latest occupying agreement unit per unit).

- [ ] **Step 1: `Queries::currentAgreementUnits`** — add next to `occupiedUnitIds` in `app/Reports/Queries.php`:

```php
    /** The agreement unit occupying each unit on $on (the latest when two overlap), for "current tenant / rent". */
    public static function currentAgreementUnits(User $user, string $on): QueryBuilder
    {
        return DB::table('agreement_units as au')->join('agreements as a', 'a.id', '=', 'au.agreement_id')
            ->whereNull('a.deleted_at')
            ->whereNotIn('a.status', [AgreementStatus::Draft->value, AgreementStatus::PendingApproval->value])
            ->where('au.start_date', '<=', $on)
            ->whereRaw('('.AgreementUnit::effectiveEndSql('au').') >= ?', [$on, $on])
            ->whereIn('au.unit_id', Unit::query()->visibleTo($user)->select('id'))
            ->groupBy('au.unit_id')
            ->select('au.unit_id', DB::raw('MAX(au.id) as au_id'));
    }
```

- [ ] **Step 2: Payments**

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\PaymentMethod;
use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Payments extends Subject
{
    public function key(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return __('Payments');
    }

    public function group(): string
    {
        return __('Money in');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsFinancial;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Payment::query()->whereIn('payments.customer_id', Customer::query()->visibleTo($user)->select('id'))
            ->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase()
            ->leftJoin('customers', 'customers.id', '=', 'payments.customer_id')
            ->leftJoin('users as recorders', 'recorders.id', '=', 'payments.recorded_by');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $applied = '(SELECT COALESCE(SUM(pa.amount), 0) FROM payment_allocations pa WHERE pa.payment_id = payments.id)';
        $refunded = "(SELECT COALESCE(SUM(d.amount), 0) FROM disbursements d WHERE d.source_type = 'payment' AND d.source_id = payments.id
            AND d.purpose = 'credit_refund' AND d.status = 'paid')";
        $building = "(SELECT CASE WHEN COUNT(DISTINCT pu.building_id) > 1 THEN 'Multiple' ELSE MAX(pb.code) END
            FROM payment_allocations pa2 JOIN invoice_lines pl ON pl.id = pa2.invoice_line_id JOIN units pu ON pu.id = pl.unit_id
            JOIN buildings pb ON pb.id = pu.building_id WHERE pa2.payment_id = payments.id)";

        return [
            Field::text('number', __('Receipt'), __('Payment'), 'payments.number', groupable: false),
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('building', __('Building'), __('Property'), "COALESCE({$building}, '—')", filterable: false),
            Field::date('received_on', __('Received on'), __('Dates'), 'payments.received_on'),
            Field::enum('method', __('Method'), __('Payment'), 'payments.method', Field::optionsFrom(PaymentMethod::class)),
            Field::text('reference', __('Reference'), __('Payment'), 'payments.reference', groupable: false),
            Field::money('amount', __('Amount'), __('Amounts'), 'payments.amount'),
            Field::money('applied', __('Applied to invoices'), __('Amounts'), $applied),
            Field::money('credit_left', __('Left as credit'), __('Amounts'),
                "CASE WHEN payments.status = 'confirmed' THEN payments.amount - {$applied} - {$refunded} ELSE 0 END"),
            Field::enum('status', __('Status'), __('Payment'), 'payments.status', ['confirmed' => __('Confirmed'), 'reversed' => __('Reversed')]),
            Field::text('recorded_by', __('Recorded by'), __('Payment'), 'recorders.name'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'tenant', 'received_on', 'method', 'amount', 'status'];
    }

    public function periodFields(): array
    {
        return ['received_on'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'received_on', 'dir' => 'desc'];
    }
}
```

- [ ] **Step 3: Agreements**

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\AgreementStatus;
use App\Enums\PaymentFrequency;
use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Sql;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Agreements extends Subject
{
    public function key(): string
    {
        return 'agreements';
    }

    public function label(): string
    {
        return __('Agreements');
    }

    public function group(): string
    {
        return __('Leasing');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsOperational;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Agreement::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase()
            ->leftJoin('customers', 'customers.id', '=', 'agreements.customer_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $today = Sql::date($asAt);

        return [
            Field::text('number', __('Agreement'), __('Agreement'), "COALESCE(agreements.number, CONCAT('#', agreements.id))", groupable: false),
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('building', __('Building'), __('Property'), 'COALESCE('.Sql::buildingOfAgreement('agreements.id').", '—')", filterable: false),
            Field::text('units', __('Units'), __('Property'), Sql::unitsOfAgreement('agreements.id'), groupable: false),
            Field::date('start_date', __('Start'), __('Dates'), 'agreements.start_date'),
            Field::date('end_date', __('End'), __('Dates'), 'agreements.end_date'),
            Field::number('days_to_expiry', __('Days to expiry'), __('Dates'),
                "CASE WHEN agreements.status = 'active' THEN DATEDIFF(agreements.end_date, {$today}) END"),
            Field::date('notice_date', __('Notice date'), __('Dates'), 'agreements.notice_date'),
            Field::date('planned_exit', __('Planned exit'), __('Dates'), 'agreements.planned_exit_date'),
            Field::enum('status', __('Status'), __('Agreement'), 'agreements.status', Field::optionsFrom(AgreementStatus::class)),
            Field::enum('frequency', __('Billing'), __('Agreement'), 'agreements.frequency', Field::optionsFrom(PaymentFrequency::class)),
            Field::money('monthly_rent', __('Monthly rent'), __('Amounts'),
                "(SELECT COALESCE(SUM(c.monthly_amount), 0) FROM agreement_unit_charges c JOIN agreement_units ca ON ca.id = c.agreement_unit_id
                    WHERE ca.agreement_id = agreements.id AND c.type = 'rent')"),
            Field::money('deposit', __('Deposit'), __('Amounts'),
                '(SELECT COALESCE(SUM(da.deposit_amount), 0) FROM agreement_units da WHERE da.agreement_id = agreements.id)'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'tenant', 'building', 'start_date', 'end_date', 'monthly_rent', 'status'];
    }

    public function periodFields(): array
    {
        return ['start_date', 'end_date'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'end_date', 'dir' => 'asc'];
    }
}
```

`PaymentFrequency` has no `label()`; `Field::optionsFrom` falls back to the headline of the value. If `AgreementStatus::label()` is missing it falls back too.

- [ ] **Step 4: Units**

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\Furnishing;
use App\Enums\PermissionName;
use App\Enums\UnitUse;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Queries;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/** Read as at a date (spec §4.2): occupancy, current tenant and rent are those on the as-at date. */
final class Units extends Subject
{
    public function key(): string
    {
        return 'units';
    }

    public function label(): string
    {
        return __('Units');
    }

    public function group(): string
    {
        return __('Leasing');
    }

    public function permission(): PermissionName
    {
        return PermissionName::ReportsOperational;
    }

    public function usesAsAt(): bool
    {
        return true;
    }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Unit::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->where('units.building_id', $b))->toBase()
            ->join('buildings', 'buildings.id', '=', 'units.building_id')
            ->leftJoinSub(Queries::currentAgreementUnits($user, $asAt->toDateString()), 'cur', 'cur.unit_id', '=', 'units.id')
            ->leftJoin('agreement_units as cau', 'cau.id', '=', 'cur.au_id')
            ->leftJoin('agreements as ca', 'ca.id', '=', 'cau.agreement_id')
            ->leftJoin('customers as cc', 'cc.id', '=', 'ca.customer_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::text('code', __('Unit'), __('Property'), 'units.code', groupable: false),
            Field::enum('type', __('Type'), __('Property'), 'units.type', UnitType::query()->orderBy('name')->pluck('name', 'code')->all()),
            Field::enum('use', __('Use'), __('Property'), 'units.use', Field::optionsFrom(UnitUse::class)),
            Field::enum('furnishing', __('Furnishing'), __('Property'), 'units.furnishing', Field::optionsFrom(Furnishing::class)),
            Field::enum('occupancy', __('Occupancy'), __('Occupancy'),
                "CASE WHEN units.blocked = 1 THEN 'blocked' WHEN cur.unit_id IS NOT NULL THEN 'occupied' ELSE 'vacant' END",
                ['occupied' => __('Occupied'), 'vacant' => __('Vacant'), 'blocked' => __('Blocked')]),
            Field::money('list_rent', __('List rent'), __('Amounts'), 'units.list_rent'),
            Field::text('current_tenant', __('Current tenant'), __('Occupancy'), 'cc.name_en'),
            Field::money('current_rent', __('Current rent'), __('Amounts'),
                "(SELECT SUM(rc.monthly_amount) FROM agreement_unit_charges rc WHERE rc.agreement_unit_id = cau.id AND rc.type = 'rent')"),
            Field::date('agreement_end', __('Agreement end'), __('Occupancy'), 'cau.end_date'),
        ];
    }

    public function defaultColumns(): array
    {
        return ['building', 'code', 'type', 'occupancy', 'current_tenant', 'list_rent', 'current_rent'];
    }

    public function defaultSort(): array
    {
        return ['field' => 'building', 'dir' => 'asc'];
    }
}
```

- [ ] **Step 5: Uncomment Payments, Agreements, Units in `Registry::all()`**, run `vendor/bin/phpstan analyse app/Reports --memory-limit=2G` → no errors.

### Task 4: Periods and the report definition

**Files:**
- Create: `app/Reports/Builder/Period.php`, `app/Reports/Builder/ReportDefinition.php`
- Test: `tests/Feature/ReportBuilder/PeriodTest.php`, `tests/Feature/ReportBuilder/DefinitionTest.php`

**Interfaces:**
- Produces:
  - `Period::PRESETS` (key => label), `Period::resolve(string $preset, ?string $from, ?string $to, CarbonImmutable $today): array{0: CarbonImmutable, 1: CarbonImmutable}`, `Period::previous(CarbonImmutable $from, CarbonImmutable $to): array{0,1}`, `Period::lastYear(...)`, `Period::label(CarbonImmutable $from, CarbonImmutable $to): string`.
  - `ReportDefinition` readonly props: `subject` (string), `columns` (list<string>), `filters` (list<array{field,op,value}>), `periodField` (?string), `periodPreset` (?string), `periodFrom`/`periodTo` (?string Y-m-d), `asAt` (?string), `buildingId` (?int), `groups` (list<array{field: string, bucket: ?string}>), `totals` (array<string,string> field => sum|avg|min|max), `sort` (array{field,dir}), `summaryOnly` (bool), `compare` (?string previous|last_year), `chart` (?array{type,total}); `ReportDefinition::fromArray(array $data, User $user): self` throws `ValidationException`; `toArray(): array` (with `version` 1); `ReportDefinition::fresh(Subject $subject, User $user): self`.
  - Constants: `MAX_COLUMNS=30`, `MAX_FILTERS=20`, `MAX_GROUPS=2`, `OPERATORS` per `FieldType`.

- [ ] **Step 1: Failing tests**

```php
<?php // tests/Feature/ReportBuilder/PeriodTest.php

use App\Reports\Builder\Period;
use Carbon\CarbonImmutable;

$today = CarbonImmutable::parse('2026-10-15', 'Asia/Bahrain'); // a Thursday

test('relative presets resolve in Bahrain with Sunday weeks', function () use ($today) {
    $r = fn (string $p) => array_map(fn ($d) => $d->toDateString(), Period::resolve($p, null, null, $today));

    expect($r('this_month'))->toBe(['2026-10-01', '2026-10-31'])
        ->and($r('last_month'))->toBe(['2026-09-01', '2026-09-30'])
        ->and($r('month_to_date'))->toBe(['2026-10-01', '2026-10-15'])
        ->and($r('this_week'))->toBe(['2026-10-11', '2026-10-17'])
        ->and($r('last_week'))->toBe(['2026-10-04', '2026-10-10'])
        ->and($r('last_7_days'))->toBe(['2026-10-09', '2026-10-15'])
        ->and($r('yesterday'))->toBe(['2026-10-14', '2026-10-14'])
        ->and($r('this_quarter'))->toBe(['2026-10-01', '2026-12-31'])
        ->and($r('last_quarter'))->toBe(['2026-07-01', '2026-09-30'])
        ->and($r('year_to_date'))->toBe(['2026-01-01', '2026-10-15'])
        ->and(array_map(fn ($d) => $d->toDateString(), Period::resolve('custom', '2026-10-01', '2026-10-15', $today)))->toBe(['2026-10-01', '2026-10-15']);
});

test('comparison periods: the same length just before, or a year earlier (29 Feb falls back to 28 Feb)', function () {
    $p = fn (array $r) => array_map(fn ($d) => $d->toDateString(), $r);

    expect($p(Period::previous(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'))))->toBe(['2026-09-01', '2026-09-30'])
        ->and($p(Period::previous(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-15'))))->toBe(['2026-09-16', '2026-09-30'])
        ->and($p(Period::lastYear(CarbonImmutable::parse('2028-02-01'), CarbonImmutable::parse('2028-02-29'))))->toBe(['2027-02-01', '2027-02-28'])
        ->and(Period::label(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')))->toBe('01/10/2026 – 31/10/2026');
});
```

```php
<?php // tests/Feature/ReportBuilder/DefinitionTest.php

use App\Enums\RoleName;
use App\Models\Building;
use App\Reports\Builder\ReportDefinition;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->finance = matrixUser(RoleName::Finance, Building::factory()->create());
});

test('a valid definition round-trips with its version', function () {
    $def = ReportDefinition::fromArray(['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'filters' => [['field' => 'balance', 'op' => 'gt', 'value' => '100']], 'period' => ['field' => 'due_date', 'preset' => 'this_month']], $this->finance);

    expect($def->toArray())->toMatchArray(['version' => 1, 'subject' => 'invoices', 'columns' => ['number', 'balance']])
        ->and($def->filters[0])->toBe(['field' => 'balance', 'op' => 'gt', 'value' => '100']);
});

test('unknown subjects, fields and operators, bad values and too many columns are refused', function (array $bad, string $key) {
    expect(fn () => ReportDefinition::fromArray([...['subject' => 'invoices', 'columns' => ['number']], ...$bad], $this->finance))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($key));
})->with([
    'subject' => [['subject' => 'salaries'], 'subject'],
    'column' => [['columns' => ['number', 'drop table']], 'columns'],
    'operator' => [['filters' => [['field' => 'balance', 'op' => 'contains', 'value' => '1']]], 'filters.0.op'],
    'money value' => [['filters' => [['field' => 'balance', 'op' => 'gt', 'value' => '1; DROP']]], 'filters.0.value'],
    'enum value' => [['filters' => [['field' => 'status', 'op' => 'in', 'value' => ['nope']]]], 'filters.0.value'],
    'period field' => [['period' => ['field' => 'balance', 'preset' => 'this_month']], 'period.field'],
    'columns cap' => [['columns' => array_fill(0, 31, 'number')], 'columns'],
]);

test('a subject the user may not use is refused', function () {
    $leasing = matrixUser(RoleName::Leasing, Building::factory()->create());
    expect(fn () => ReportDefinition::fromArray(['subject' => 'invoices', 'columns' => ['number']], $leasing))
        ->toThrow(ValidationException::class);
});
```

- [ ] **Step 2: Run to see them fail** — `php artisan test tests/Feature/ReportBuilder/PeriodTest.php tests/Feature/ReportBuilder/DefinitionTest.php` → FAIL.

- [ ] **Step 3: Period**

```php
<?php

namespace App\Reports\Builder;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Report periods (spec §4.4, §6): Asia/Bahrain, weeks Sunday–Saturday. */
final class Period
{
    /** @return array<string, string> */
    public static function presets(): array
    {
        return [
            'today' => __('Today'), 'yesterday' => __('Yesterday'), 'this_week' => __('This week'), 'last_week' => __('Last week'),
            'last_7_days' => __('Last 7 days'), 'this_month' => __('This month'), 'last_month' => __('Last month'),
            'month_to_date' => __('Month to date'), 'this_quarter' => __('This quarter'), 'last_quarter' => __('Last quarter'),
            'this_year' => __('This year'), 'last_year' => __('Last year'), 'year_to_date' => __('Year to date'), 'custom' => __('Custom dates'),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function resolve(string $preset, ?string $from, ?string $to, CarbonImmutable $today): array
    {
        $d = $today->startOfDay();
        $week = $d->subDays($d->dayOfWeek); // dayOfWeek: 0 = Sunday

        return match ($preset) {
            'today' => [$d, $d],
            'yesterday' => [$d->subDay(), $d->subDay()],
            'this_week' => [$week, $week->addDays(6)],
            'last_week' => [$week->subWeek(), $week->subDay()],
            'last_7_days' => [$d->subDays(6), $d],
            'this_month' => [$d->startOfMonth(), $d->endOfMonth()->startOfDay()],
            'last_month' => [$d->subMonthNoOverflow()->startOfMonth(), $d->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'month_to_date' => [$d->startOfMonth(), $d],
            'this_quarter' => [$d->startOfQuarter(), $d->endOfQuarter()->startOfDay()],
            'last_quarter' => [$d->startOfQuarter()->subQuarter(), $d->startOfQuarter()->subDay()],
            'this_year' => [$d->startOfYear(), $d->endOfYear()->startOfDay()],
            'last_year' => [$d->subYear()->startOfYear(), $d->subYear()->endOfYear()->startOfDay()],
            'year_to_date' => [$d->startOfYear(), $d],
            'custom' => [CarbonImmutable::parse((string) $from, 'Asia/Bahrain'), CarbonImmutable::parse((string) $to, 'Asia/Bahrain')],
            default => throw new InvalidArgumentException("Unknown period [{$preset}]."),
        };
    }

    /**
     * Same length, immediately before. A whole calendar month compares with the whole previous month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function previous(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($from->isSameDay($from->startOfMonth()) && $to->isSameDay($to->endOfMonth()) && $from->diffInMonths($to->addDay()) >= 1) {
            $months = (int) $from->diffInMonths($to->addDay());

            return [$from->subMonthsNoOverflow($months), $from->subDay()];
        }
        $days = (int) $from->diffInDays($to) + 1;

        return [$from->subDays($days), $from->subDay()];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function lastYear(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->subYearNoOverflow(), $to->subYearNoOverflow()];
    }

    public static function label(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->format('d/m/Y').' – '.$to->format('d/m/Y');
    }
}
```

- [ ] **Step 4: ReportDefinition**

```php
<?php

namespace App\Reports\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A user's report, validated against its subject's whitelist (spec §8). Built only through fromArray(), so every
 * instance is safe to compile. Stored as toArray() in saved_reports.definition.
 */
final readonly class ReportDefinition
{
    public const int VERSION = 1;

    public const int MAX_COLUMNS = 30;

    public const int MAX_FILTERS = 20;

    public const int MAX_GROUPS = 2;

    public const array BUCKETS = ['day', 'month', 'quarter', 'year'];

    public const array TOTALS = ['sum', 'avg', 'min', 'max'];

    /** @return array<string, list<string>> operators per field type (spec §4.1) */
    public static function operators(): array
    {
        return [
            FieldType::Text->value => ['is', 'is_not', 'contains', 'empty', 'not_empty'],
            FieldType::Enum->value => ['in', 'not_in'],
            FieldType::Money->value => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'],
            FieldType::Number->value => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'],
            FieldType::Date->value => ['on', 'before', 'after', 'between'],
            FieldType::Boolean->value => ['yes', 'no'],
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array{field: string, op: string, value: mixed}>  $filters
     * @param  list<array{field: string, bucket: ?string}>  $groups
     * @param  array<string, string>  $totals
     * @param  array{field: string, dir: string}  $sort
     * @param  array{type: string, total: string}|null  $chart
     */
    private function __construct(
        public string $subject,
        public array $columns,
        public array $filters,
        public ?string $periodField,
        public ?string $periodPreset,
        public ?string $periodFrom,
        public ?string $periodTo,
        public ?string $asAt,
        public ?int $buildingId,
        public array $groups,
        public array $totals,
        public array $sort,
        public bool $summaryOnly,
        public ?string $compare,
        public ?array $chart,
    ) {}

    public static function fresh(Subject $subject, User $user): self
    {
        $period = $subject->periodFields() === [] ? [] : ['field' => $subject->periodFields()[0], 'preset' => 'this_month'];

        return self::fromArray(['subject' => $subject->key(), 'columns' => $subject->defaultColumns(), 'period' => $period, 'sort' => $subject->defaultSort()], $user);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data, User $user): self
    {
        $subjects = Registry::forUser($user);
        Validator::make($data, ['subject' => ['required', 'string', 'in:'.implode(',', array_keys($subjects))]],
            ['subject.in' => __('You cannot report on this subject.')])->validate();
        $subject = $subjects[$data['subject']];
        $asAt = filled($data['as_at'] ?? null) ? (string) $data['as_at'] : null;
        $fields = $subject->fields($user, CarbonImmutable::parse($asAt ?? 'today', 'Asia/Bahrain'));
        $keys = array_keys($fields);
        $numeric = array_keys(array_filter($fields, fn (Field $f) => $f->type->numeric()));
        $groupable = array_keys(array_filter($fields, fn (Field $f) => $f->groupable));

        $errors = [];
        $columns = array_values((array) ($data['columns'] ?? []));
        if ($columns === [] || count($columns) > self::MAX_COLUMNS || array_diff($columns, $keys) !== []) {
            $errors['columns'] = __('Choose between 1 and :n of the listed columns.', ['n' => self::MAX_COLUMNS]);
        }

        $filters = array_values((array) ($data['filters'] ?? []));
        if (count($filters) > self::MAX_FILTERS) {
            $errors['filters'] = __('At most :n filters.', ['n' => self::MAX_FILTERS]);
        }
        foreach ($filters as $i => $filter) {
            $field = $fields[$filter['field'] ?? ''] ?? null;
            if ($field === null || ! $field->filterable) {
                $errors["filters.$i.field"] = __('Choose a field to filter on.');

                continue;
            }
            if (! in_array($filter['op'] ?? '', self::operators()[$field->type->value], true)) {
                $errors["filters.$i.op"] = __('Choose how to compare.');

                continue;
            }
            if (($message = self::badValue($field, (string) $filter['op'], $filter['value'] ?? null)) !== null) {
                $errors["filters.$i.value"] = $message;
            }
        }

        $period = (array) ($data['period'] ?? []);
        if ($period !== [] && $subject->periodFields() !== []) {
            if (! in_array($period['field'] ?? null, $subject->periodFields(), true)) {
                $errors['period.field'] = __('Choose the date the period uses.');
            }
            if (! array_key_exists($period['preset'] ?? '', Period::presets())) {
                $errors['period.preset'] = __('Choose a period.');
            } elseif ($period['preset'] === 'custom' && (! self::isDate($period['from'] ?? null) || ! self::isDate($period['to'] ?? null) || $period['from'] > $period['to'])) {
                $errors['period.from'] = __('Enter a valid from and to date.');
            }
        }
        if ($asAt !== null && ! self::isDate($asAt)) {
            $errors['as_at'] = __('Enter a valid date.');
        }

        $groups = array_values((array) ($data['groups'] ?? []));
        if (count($groups) > self::MAX_GROUPS) {
            $errors['groups'] = __('At most :n group levels.', ['n' => self::MAX_GROUPS]);
        }
        foreach ($groups as $i => $group) {
            $field = $fields[$group['field'] ?? ''] ?? null;
            if ($field === null || ! in_array($field->key, $groupable, true)) {
                $errors["groups.$i.field"] = __('Choose a field to group by.');
            } elseif ($field->type === FieldType::Date && ! in_array($group['bucket'] ?? null, self::BUCKETS, true)) {
                $errors["groups.$i.bucket"] = __('Group dates by day, month, quarter or year.');
            }
        }

        $totals = (array) ($data['totals'] ?? []);
        foreach ($totals as $key => $fn) {
            if (! in_array($key, $numeric, true) || ! in_array($fn, self::TOTALS, true)) {
                $errors['totals'] = __('Totals apply to money and number columns: sum, average, minimum or maximum.');
            }
        }

        $sort = (array) ($data['sort'] ?? $subject->defaultSort());
        if (! in_array($sort['field'] ?? null, $keys, true) || ! in_array($sort['dir'] ?? null, ['asc', 'desc'], true)) {
            $errors['sort'] = __('Choose a column to sort by.');
        }

        $compare = $data['compare'] ?? null;
        if ($compare !== null && (! in_array($compare, ['previous', 'last_year'], true) || $subject->periodFields() === [] || $period === [])) {
            $errors['compare'] = __('Comparison needs a report with a period.');
        }

        $chart = $data['chart'] ?? null;
        if ($chart !== null && (! in_array($chart['type'] ?? null, ['bars', 'columns'], true) || $groups === []
            || ! self::validTotalKey((string) ($chart['total'] ?? ''), $totals))) {
            $errors['chart'] = __('A chart needs a grouped report and one of its totals.');
        }

        $building = $data['building_id'] ?? null;
        if ($building !== null && ! ctype_digit((string) $building)) {
            $errors['building_id'] = __('Choose a building.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self(
            subject: $subject->key(),
            columns: $columns,
            filters: array_map(fn (array $f) => ['field' => (string) $f['field'], 'op' => (string) $f['op'], 'value' => $f['value'] ?? null], $filters),
            periodField: $period['field'] ?? null,
            periodPreset: $period['preset'] ?? null,
            periodFrom: $period['from'] ?? null,
            periodTo: $period['to'] ?? null,
            asAt: $asAt,
            buildingId: $building === null ? null : (int) $building,
            groups: array_map(fn (array $g) => ['field' => (string) $g['field'], 'bucket' => $g['bucket'] ?? null], $groups),
            totals: $totals,
            sort: ['field' => (string) $sort['field'], 'dir' => (string) $sort['dir']],
            summaryOnly: (bool) ($data['summary_only'] ?? false) && $groups !== [],
            compare: $compare,
            chart: $chart === null ? null : ['type' => (string) $chart['type'], 'total' => (string) $chart['total']],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'subject' => $this->subject,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'period' => $this->periodField === null ? [] : array_filter(['field' => $this->periodField, 'preset' => $this->periodPreset,
                'from' => $this->periodFrom, 'to' => $this->periodTo], fn ($v) => $v !== null),
            'as_at' => $this->asAt,
            'building_id' => $this->buildingId,
            'groups' => $this->groups,
            'totals' => $this->totals,
            'sort' => $this->sort,
            'summary_only' => $this->summaryOnly,
            'compare' => $this->compare,
            'chart' => $this->chart,
        ];
    }

    /** "count" or "<field>:<fn>" naming one of the report's totals. */
    private static function validTotalKey(string $key, array $totals): bool
    {
        if ($key === 'count') {
            return true;
        }
        [$field, $fn] = array_pad(explode(':', $key, 2), 2, '');

        return ($totals[$field] ?? null) === $fn;
    }

    private static function isDate(mixed $v): bool
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false;
    }

    private static function isAmount(mixed $v): bool
    {
        return is_scalar($v) && preg_match('/^-?\d{1,12}(\.\d{1,3})?$/', (string) $v) === 1;
    }

    private static function badValue(Field $field, string $op, mixed $value): ?string
    {
        return match ($field->type) {
            FieldType::Text => in_array($op, ['empty', 'not_empty'], true) || (is_string($value) && $value !== '' && mb_strlen($value) <= 100)
                ? null : __('Enter up to 100 characters.'),
            FieldType::Enum => is_array($value) && $value !== [] && array_diff($value, array_keys($field->options)) === []
                ? null : __('Pick one or more of the listed values.'),
            FieldType::Money, FieldType::Number => $op === 'between'
                ? (is_array($value) && self::isAmount($value[0] ?? null) && self::isAmount($value[1] ?? null) ? null : __('Enter two numbers.'))
                : (self::isAmount($value) ? null : __('Enter a number.')),
            FieldType::Date => $op === 'between'
                ? (is_array($value) && self::isDate($value[0] ?? null) && self::isDate($value[1] ?? null) ? null : __('Enter two dates.'))
                : (self::isDate($value) ? null : __('Enter a date.')),
            FieldType::Boolean => null,
        };
    }
}
```

- [ ] **Step 5: Run the tests** → PASS. `vendor/bin/pint --dirty`; phpstan on `app/Reports` → clean.

### Task 5: QueryCompiler, Formatter, ReportResult, Engine (detail rows, count, totals)

**Files:**
- Create: `app/Reports/Builder/{QueryCompiler,Formatter,ReportResult,Engine}.php`
- Test: `tests/Feature/ReportBuilder/EngineTest.php`, `tests/Feature/ReportBuilder/SubjectQueriesTest.php`

**Interfaces:**
- Consumes: `Registry`, `Subject`, `Field`, `Period`, `ReportDefinition`.
- Produces:
  - `QueryCompiler::compile(User $user, ReportDefinition $def, ?array $range = null): array{0: Builder, 1: array<string, Field>}` — base query with building, period (range override for comparison), filters; returns the fields map too.
  - `QueryCompiler::periodRange(ReportDefinition $def, CarbonImmutable $today): ?array{0: CarbonImmutable, 1: CarbonImmutable}`.
  - `Formatter::display(Field $field, mixed $raw): string`.
  - `ReportResult` readonly: `columns` (list<Field>), `rows` (list<array<string, mixed>> raw values keyed by field key), `rowCount` (int), `truncated` (bool), `grandTotals` (array<string, mixed> keyed `count` and `field:fn`), `groups` (list<array> — stage 3, `[]` before), `periodLabel` (?string), `comparison` (?array — stage 4).
  - `Engine::run(User $user, ReportDefinition $def, ?int $limit = Engine::DETAIL_LIMIT): ReportResult`; `Engine::DETAIL_LIMIT = 500`.

- [ ] **Step 1: Failing tests**

```php
<?php // tests/Feature/ReportBuilder/EngineTest.php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    $this->tenantA = Customer::factory()->create(['name_en' => 'Alpha Tenant']);
    $this->tenantB = Customer::factory()->create(['name_en' => 'Beta Tenant']);
    $agA = activeAgreement(['customer_id' => $this->tenantA->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [$ua = Unit::factory()->for($this->a)->create()]);
    $agB = activeAgreement(['customer_id' => $this->tenantB->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [$ub = Unit::factory()->for($this->b)->create()]);
    $this->inv1 = issuedInvoice($this->tenantA, [['net' => '400.000', 'au' => $agA->agreementUnits->first()]], '2026-10-01', $agA);
    $this->inv2 = issuedInvoice($this->tenantA, [['net' => '150.500', 'au' => $agA->agreementUnits->first()]], '2026-09-01', $agA);
    $this->inv3 = issuedInvoice($this->tenantB, [['net' => '300.000', 'au' => $agB->agreementUnits->first()]], '2026-10-05', $agB);
});

function runReport(App\Models\User $user, array $data): App\Reports\Builder\ReportResult
{
    return app(Engine::class)->run($user, ReportDefinition::fromArray($data, $user));
}

test('detail rows carry the chosen columns, sorted, with a grand total and a row count', function () {
    $r = runReport($this->finance, ['subject' => 'invoices', 'columns' => ['number', 'tenant', 'building', 'balance'],
        'totals' => ['balance' => 'sum'], 'sort' => ['field' => 'balance', 'dir' => 'desc']]);

    expect(array_column($r->rows, 'balance'))->toBe(['400.000', '300.000', '150.500'])
        ->and(array_column($r->rows, 'building'))->toBe(['AAA', 'BBB', 'AAA'])
        ->and($r->rowCount)->toBe(3)->and($r->truncated)->toBeFalse()
        ->and($r->grandTotals)->toMatchArray(['count' => 3, 'balance:sum' => '850.500']);
});

test('filters, the period and the building narrow the rows', function () {
    $base = ['subject' => 'invoices', 'columns' => ['number', 'balance']];

    expect(runReport($this->finance, [...$base, 'filters' => [['field' => 'balance', 'op' => 'gt', 'value' => '200']]])->rowCount)->toBe(2)
        ->and(runReport($this->finance, [...$base, 'filters' => [['field' => 'status', 'op' => 'in', 'value' => ['overdue']]]])->rowCount)->toBe(1) // 1 Sep, past grace
        ->and(runReport($this->finance, [...$base, 'filters' => [['field' => 'tenant', 'op' => 'contains', 'value' => 'Beta']]])->rowCount)->toBe(1)
        ->and(runReport($this->finance, [...$base, 'period' => ['field' => 'due_date', 'preset' => 'this_month']])->rowCount)->toBe(2)
        ->and(runReport($this->finance, [...$base, 'building_id' => $this->b->id])->rowCount)->toBe(1);
});

test('the on-screen limit truncates detail rows but not the count or totals', function () {
    $r = app(Engine::class)->run($this->finance, ReportDefinition::fromArray(['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'totals' => ['balance' => 'sum']], $this->finance), limit: 2);

    expect($r->rows)->toHaveCount(2)->and($r->truncated)->toBeTrue()->and($r->rowCount)->toBe(3)->and($r->grandTotals['balance:sum'])->toBe('850.500');
});

test('a user limited to one building never sees another building, whatever the filters', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->a);
    $r = runReport($leasing, ['subject' => 'agreements', 'columns' => ['number', 'tenant', 'building']]);

    expect(array_column($r->rows, 'building'))->toBe(['AAA']);
});
```

```php
<?php // tests/Feature/ReportBuilder/SubjectQueriesTest.php — every stage-1 subject runs with every field as a column

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Reports\Builder\Engine;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create();
    $this->admin = matrixUser(RoleName::Management, $this->building);
    activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [Unit::factory()->for($this->building)->create()]);
});

test('every field of every subject compiles and runs', function (string $key) {
    $subject = Registry::get($key);
    $fields = array_keys($subject->fields($this->admin, CarbonImmutable::now('Asia/Bahrain')));
    $r = app(Engine::class)->run($this->admin, ReportDefinition::fromArray(['subject' => $key, 'columns' => array_slice($fields, 0, 30)], $this->admin));

    expect($r->columns)->not->toBeEmpty();
})->with(fn () => array_keys(Registry::all()));

test('units read occupancy as at the date, with the current tenant and rent', function () {
    $r = app(Engine::class)->run($this->admin, ReportDefinition::fromArray(['subject' => 'units', 'columns' => ['code', 'occupancy', 'current_tenant', 'current_rent']], $this->admin));

    expect($r->rows[0]['occupancy'])->toBe('occupied')->and($r->rows[0]['current_tenant'])->not->toBeNull()->and($r->rows[0]['current_rent'])->toBe('400.000');

    $before = app(Engine::class)->run($this->admin, ReportDefinition::fromArray(['subject' => 'units', 'columns' => ['code', 'occupancy'], 'as_at' => '2026-08-01'], $this->admin));
    expect($before->rows[0]['occupancy'])->toBe('vacant');
});
```

(`Management` holds both report permissions, so it sees every subject; `activeAgreement`'s default rent is `400.000`.)

- [ ] **Step 2: Run to see failures** — FAIL (classes missing).

- [ ] **Step 3: QueryCompiler**

```php
<?php

namespace App\Reports\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/** Base query + building + period + filters for one definition. Values are always bound. */
final class QueryCompiler
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    public static function periodRange(ReportDefinition $def, CarbonImmutable $today): ?array
    {
        return $def->periodField === null || $def->periodPreset === null
            ? null : Period::resolve($def->periodPreset, $def->periodFrom, $def->periodTo, $today);
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $range  overrides the definition's period (comparison)
     * @return array{0: Builder, 1: array<string, Field>}
     */
    public static function compile(User $user, ReportDefinition $def, ?array $range = null): array
    {
        $subject = Registry::get($def->subject);
        $asAt = CarbonImmutable::parse($def->asAt ?? 'today', 'Asia/Bahrain')->startOfDay();
        $fields = $subject->fields($user, $asAt);
        $query = $subject->query($user, $def->buildingId, $asAt);

        $range ??= self::periodRange($def, CarbonImmutable::now('Asia/Bahrain'));
        if ($range !== null && $def->periodField !== null) {
            $query->whereRaw("{$fields[$def->periodField]->sql} BETWEEN ? AND ?", [$range[0]->toDateString(), $range[1]->toDateString()]);
        }

        foreach ($def->filters as $filter) {
            self::applyFilter($query, $fields[$filter['field']], $filter['op'], $filter['value']);
        }

        return [$query, $fields];
    }

    private static function applyFilter(Builder $query, Field $field, string $op, mixed $value): void
    {
        $sql = $field->sql;
        match ($op) {
            'is' => $query->whereRaw("{$sql} = ?", [$value]),
            'is_not' => $query->whereRaw("({$sql} <> ? OR {$sql} IS NULL)", [$value]),
            'contains' => $query->whereRaw("{$sql} LIKE ?", ['%'.addcslashes((string) $value, '%_\\').'%']),
            'empty' => $query->whereRaw("({$sql} IS NULL OR {$sql} = '')"),
            'not_empty' => $query->whereRaw("({$sql} IS NOT NULL AND {$sql} <> '')"),
            'in' => $query->whereRaw("{$sql} IN (".implode(',', array_fill(0, count($value), '?')).')', array_values($value)),
            'not_in' => $query->whereRaw("({$sql} NOT IN (".implode(',', array_fill(0, count($value), '?')).") OR {$sql} IS NULL)", array_values($value)),
            'eq', 'on' => $query->whereRaw("{$sql} = ?", [$value]),
            'ne' => $query->whereRaw("{$sql} <> ?", [$value]),
            'gt', 'after' => $query->whereRaw("{$sql} > ?", [$value]),
            'gte' => $query->whereRaw("{$sql} >= ?", [$value]),
            'lt', 'before' => $query->whereRaw("{$sql} < ?", [$value]),
            'lte' => $query->whereRaw("{$sql} <= ?", [$value]),
            'between' => $query->whereRaw("{$sql} BETWEEN ? AND ?", [$value[0], $value[1]]),
            'yes' => $query->whereRaw("{$sql} = 1"),
            'no' => $query->whereRaw("({$sql} = 0 OR {$sql} IS NULL)"),
        };
    }
}
```

- [ ] **Step 4: Formatter and ReportResult**

```php
<?php

namespace App\Reports\Builder;

use Carbon\CarbonImmutable;

/** Raw SQL values to display strings (spec: money 3 dp with separators, dates dd/mm/yyyy). Excel uses raw values. */
final class Formatter
{
    public static function display(Field $field, mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }

        return match ($field->type) {
            FieldType::Money => number_format((float) $raw, 3),
            FieldType::Number => is_numeric($raw) && (float) $raw == (int) $raw ? number_format((int) $raw) : number_format((float) $raw, 3),
            FieldType::Date => CarbonImmutable::parse((string) $raw)->format('d/m/Y'),
            FieldType::Enum => $field->options[(string) $raw] ?? (string) $raw,
            FieldType::Boolean => $raw ? __('Yes') : __('No'),
            FieldType::Text => (string) $raw,
        };
    }

    /** A total's display; counts are whole numbers, everything else follows the field. */
    public static function total(?Field $field, string $fn, mixed $raw): string
    {
        if ($fn === 'count' || $field === null) {
            return number_format((int) $raw);
        }

        return $raw === null ? '—' : ($field->type === FieldType::Money || $fn === 'avg' ? number_format((float) $raw, 3) : self::display($field, $raw));
    }
}
```

```php
<?php

namespace App\Reports\Builder;

/** What a run produced. Raw values; the view and Excel format them. */
final readonly class ReportResult
{
    /**
     * @param  list<Field>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $grandTotals  keyed "count" and "field:fn"
     * @param  list<array<string, mixed>>  $groups  stage 3
     * @param  array<string, mixed>|null  $comparison  stage 4
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public int $rowCount,
        public bool $truncated,
        public array $grandTotals,
        public array $groups = [],
        public ?string $periodLabel = null,
        public ?array $comparison = null,
    ) {}
}
```

- [ ] **Step 5: Engine (stage 1: rows, count, grand totals)**

```php
<?php

namespace App\Reports\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Runs a validated definition (spec §5). Read-only; the database does all filtering, sorting and totals. */
final class Engine
{
    public const int DETAIL_LIMIT = 500;

    public function run(User $user, ReportDefinition $def, ?int $limit = self::DETAIL_LIMIT): ReportResult
    {
        [$query, $fields] = QueryCompiler::compile($user, $def);
        $range = QueryCompiler::periodRange($def, CarbonImmutable::now('Asia/Bahrain'));

        $grand = $this->totals(clone $query, $fields, $def->totals);
        $rows = [];
        $truncated = false;
        if (! $def->summaryOnly) {
            $detail = (clone $query)->select(array_map(fn (string $k) => DB::raw("{$fields[$k]->sql} as `{$k}`"), $def->columns));
            $this->order($detail, $def, $fields);
            if ($limit !== null) {
                $detail->limit($limit + 1);
            }
            $rows = array_map(fn ($r) => (array) $r, $detail->get()->all());
            if ($limit !== null && count($rows) > $limit) {
                $truncated = true;
                array_pop($rows);
            }
        }

        return new ReportResult(
            columns: array_values(array_map(fn (string $k) => $fields[$k], $def->columns)),
            rows: $rows,
            rowCount: (int) $grand['count'],
            truncated: $truncated,
            grandTotals: $grand,
            periodLabel: $range === null ? null : Period::label(...$range),
        );
    }

    /**
     * Count plus each chosen total over the whole filtered set.
     *
     * @param  array<string, Field>  $fields
     * @param  array<string, string>  $totals
     * @return array<string, mixed>
     */
    public function totals(Builder $query, array $fields, array $totals): array
    {
        $inner = $query->select(DB::raw('1 as `_one`'));
        foreach (array_keys($totals) as $key) {
            $inner->addSelect(DB::raw("{$fields[$key]->sql} as `{$key}`"));
        }
        $select = ['COUNT(*) as `count`'];
        foreach ($totals as $key => $fn) {
            $select[] = self::aggregate($fields[$key], $fn)." as `{$key}:{$fn}`";
        }

        return (array) DB::query()->fromSub($inner, 't')->selectRaw('/*+ MAX_EXECUTION_TIME(30000) */ '.implode(', ', $select))->first();
    }

    /** SQL for one total of a column already selected as `key` in a derived table. */
    public static function aggregate(Field $field, string $fn): string
    {
        $col = "`{$field->key}`";

        return match ($fn) {
            'sum' => "COALESCE(SUM({$col}), 0)",
            'avg' => "ROUND(AVG({$col}), 3)",
            'min' => "MIN({$col})",
            'max' => "MAX({$col})",
        };
    }

    /** @param  array<string, Field>  $fields */
    private function order(Builder $query, ReportDefinition $def, array $fields): void
    {
        foreach ($def->groups as $group) {
            $query->orderByRaw(Grouping::expression($fields[$group['field']], $group['bucket']).' ASC');
        }
        $query->orderByRaw("{$fields[$def->sort['field']]->sql} ".($def->sort['dir'] === 'desc' ? 'DESC' : 'ASC'));
    }
}
```

`totals()` selects each totalled column into a derived table `t` aliased by its key, so `aggregate()` always aggregates the alias.

`Grouping::expression()` is created in Task 11. Until then add this minimal class (Task 11 extends it):

```php
<?php

namespace App\Reports\Builder;

/** The SQL a group level groups by: the field, or its date bucket. */
final class Grouping
{
    public static function expression(Field $field, ?string $bucket): string
    {
        return match ($bucket) {
            'day' => "DATE({$field->sql})",
            'month' => "DATE_FORMAT({$field->sql}, '%Y-%m')",
            'quarter' => "CONCAT(YEAR({$field->sql}), '-Q', QUARTER({$field->sql}))",
            'year' => "CAST(YEAR({$field->sql}) AS CHAR)",
            default => $field->sql,
        };
    }
}
```

- [ ] **Step 6: Run** `php artisan test tests/Feature/ReportBuilder` → PASS. Fix any subject SQL the "every field" test exposes (it runs each subject with up to 30 columns). Pint; phpstan `app/Reports`.

### Task 6: The builder page — subject, columns, filters, period, sort, live results

**Files:**
- Create: `app/Livewire/Reports/Builder.php`, `resources/views/livewire/reports/builder.blade.php`
- Modify: `routes/property.php` (route), `resources/views/livewire/reports/index.blade.php` (link added in Task 8)
- Test: `tests/Feature/ReportBuilder/BuilderScreenTest.php`

**Interfaces:**
- Consumes: `Registry`, `ReportDefinition`, `Engine`, `Formatter`, `Period`, `SavedReport` (Task 1).
- Produces: route `reports.builder` → `/reports/builder/{report?}`; component state `public array $def` (a definition array), `public ?int $reportId`, `public string $name`; actions `setSubject(string)`, `toggleColumn(string)`, `moveColumn(string $key, int $by)`, `addFilter()`, `removeFilter(int)`; computed result via `render()`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create(['code' => 'AAA']);
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create(['name_en' => 'Alpha Tenant'])->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'],
        [Unit::factory()->for($this->building)->create()]);
    issuedInvoice($agreement->customer, [['net' => '400.000', 'au' => $agreement->agreementUnits->first()]], '2026-10-01', $agreement);
});

test('a new report starts on invoices with its default columns and shows live results', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->assertSet('def.subject', 'invoices')
        ->assertSee('Alpha Tenant')->assertSee('400.000')
        ->call('toggleColumn', 'vat')->assertSet('def.columns', fn (array $c) => in_array('vat', $c, true))
        ->call('setSubject', 'agreements')->assertSet('def.columns', ['number', 'tenant', 'building', 'start_date', 'end_date', 'monthly_rent', 'status']);
});

test('filters change the results and bad filters show an error instead of results', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->call('addFilter')->set('def.filters.0.field', 'balance')->set('def.filters.0.op', 'gt')->set('def.filters.0.value', '500')
        ->assertDontSee('Alpha Tenant')
        ->set('def.filters.0.value', 'abc')->assertSee('Enter a number.');
});

test('only users with report access may open the builder', function () {
    $this->actingAs(App\Models\User::factory()->withTwoFactor()->create())->get(route('reports.builder'))->assertForbidden();
});
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Route** — in `routes/property.php`, next to `reports.index`:

```php
    Route::livewire('reports/builder/{report?}', Reports\Builder::class)->name('reports.builder');
```

- [ ] **Step 4: Component**

```php
<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\SavedReport;
use App\Reports\Builder\Engine;
use App\Reports\Builder\Field;
use App\Reports\Builder\Period;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The report builder (spec §3.2). `def` is the definition being edited; every render validates and runs it. */
class Builder extends Component
{
    use WithActor;

    /** @var array<string, mixed> */
    public array $def = [];

    #[Locked]
    public ?int $reportId = null;

    public string $name = '';

    public function mount(?SavedReport $report = null): void
    {
        abort_unless(Registry::forUser($this->actor()) !== [], 403);

        if ($report?->exists) {
            abort_unless($this->actor()->can('view', $report), 403);
            $this->reportId = $report->id;
            $this->name = $report->name;
            $this->def = $report->definition;

            return;
        }

        $first = array_key_first(Registry::forUser($this->actor()));
        $this->def = ReportDefinition::fresh(Registry::get((string) $first), $this->actor())->toArray();
    }

    public function setSubject(string $key): void
    {
        abort_unless(isset(Registry::forUser($this->actor())[$key]), 403);
        $this->def = ReportDefinition::fresh(Registry::get($key), $this->actor())->toArray();
    }

    public function toggleColumn(string $key): void
    {
        $columns = $this->def['columns'] ?? [];
        $this->def['columns'] = in_array($key, $columns, true)
            ? array_values(array_diff($columns, [$key]))
            : [...$columns, $key];
    }

    public function moveColumn(string $key, int $by): void
    {
        $columns = $this->def['columns'] ?? [];
        $i = array_search($key, $columns, true);
        $j = $i === false ? false : $i + $by;
        if ($i !== false && $j >= 0 && $j < count($columns)) {
            [$columns[$i], $columns[$j]] = [$columns[$j], $columns[$i]];
            $this->def['columns'] = $columns;
        }
    }

    public function addFilter(): void
    {
        $this->def['filters'][] = ['field' => '', 'op' => '', 'value' => null];
    }

    public function removeFilter(int $index): void
    {
        unset($this->def['filters'][$index]);
        $this->def['filters'] = array_values($this->def['filters']);
    }

    /** Changing a filter's field resets its operator and value. $key is null when Livewire replaces the whole array. */
    public function updatedDef(mixed $value, ?string $key = null): void
    {
        if ($key !== null && preg_match('/^filters\.(\d+)\.field$/', $key, $m) === 1) {
            $this->def['filters'][(int) $m[1]]['op'] = '';
            $this->def['filters'][(int) $m[1]]['value'] = null;
        }
    }

    public function render(): View
    {
        $user = $this->actor();
        $subject = Registry::get((string) ($this->def['subject'] ?? 'invoices'));
        $fields = $subject->fields($user, CarbonImmutable::parse($this->def['as_at'] ?? 'today', 'Asia/Bahrain'));
        $result = null;
        $errors = [];
        try {
            $result = app(Engine::class)->run($user, ReportDefinition::fromArray($this->def, $user));
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        return view('livewire.reports.builder', [
            'subjects' => Registry::forUser($user),
            'subject' => $subject,
            'fields' => $fields,
            'sections' => collect($fields)->groupBy(fn (Field $f) => $f->section),
            'operators' => ReportDefinition::operators(),
            'presets' => Period::presets(),
            'buildings' => Building::visibleTo($user)->orderBy('code')->get(['id', 'code', 'name']),
            'result' => $result,
            'defErrors' => $errors,
            'canEdit' => $this->reportId === null || $user->can('update', SavedReport::findOrFail($this->reportId)),
        ])->title($this->name !== '' ? $this->name : __('New report'));
    }
}
```

- [ ] **Step 5: View** (`resources/views/livewire/reports/builder.blade.php`)

```blade
@php
    use App\Reports\Builder\Formatter;
    $opLabels = ['is' => __('is'), 'is_not' => __('is not'), 'contains' => __('contains'), 'empty' => __('is empty'), 'not_empty' => __('is not empty'),
        'in' => __('is any of'), 'not_in' => __('is none of'), 'eq' => '=', 'ne' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤',
        'between' => __('between'), 'on' => __('on'), 'before' => __('before'), 'after' => __('after'), 'yes' => __('is yes'), 'no' => __('is no')];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0 flex-1">
            <flux:input wire:model.blur="name" :placeholder="__('Untitled report')" :aria-label="__('Report name')" class="max-w-md" />
        </div>
        <div class="flex flex-wrap gap-2">
            {{-- Save, Share, Export, Schedule, Delete: Tasks 7, 8 and 18 --}}
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <aside class="space-y-4">
            <flux:select wire:change="setSubject($event.target.value)" :label="__('Subject')">
                @foreach (collect($subjects)->groupBy(fn ($s) => $s->group()) as $group => $items)
                    <optgroup label="{{ $group }}">
                        @foreach ($items as $s)<option value="{{ $s->key() }}" @selected($s->key() === $def['subject'])>{{ $s->label() }}</option>@endforeach
                    </optgroup>
                @endforeach
            </flux:select>

            <x-building-filter :buildings="$buildings" wire:model.live="def.building_id" />

            @if ($subject->periodFields() !== [])
                <div class="grid grid-cols-2 gap-2">
                    <flux:select wire:model.live="def.period.field" :label="__('Period by')">
                        @foreach ($subject->periodFields() as $p)<option value="{{ $p }}">{{ $fields[$p]->label }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model.live="def.period.preset" :label="__('Period')">
                        @foreach ($presets as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </flux:select>
                    @if (($def['period']['preset'] ?? '') === 'custom')
                        <x-date-input wire:model.live="def.period.from" :label="__('From')" />
                        <x-date-input wire:model.live="def.period.to" :label="__('To')" />
                    @endif
                </div>
            @elseif ($subject->usesAsAt())
                <x-date-input wire:model.live="def.as_at" :label="__('As at')" />
            @endif

            <flux:fieldset>
                <flux:legend>{{ __('Columns') }}</flux:legend>
                <div class="max-h-72 space-y-3 overflow-y-auto pe-1">
                    @foreach ($sections as $section => $sectionFields)
                        <div>
                            <div class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ $section }}</div>
                            @foreach ($sectionFields as $f)
                                <label class="flex items-center gap-2 py-0.5 text-sm">
                                    <input type="checkbox" wire:click="toggleColumn('{{ $f->key }}')" @checked(in_array($f->key, $def['columns'] ?? [], true))>
                                    {{ $f->label }}
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @error('columns')<flux:text class="text-sm text-red-600">{{ $message }}</flux:text>@enderror
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Filters') }}</flux:legend>
                <div class="space-y-2">
                    @foreach ($def['filters'] ?? [] as $i => $filter)
                        @php($ff = $fields[$filter['field']] ?? null)
                        <div class="space-y-1 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700" wire:key="filter-{{ $i }}">
                            <div class="flex gap-1">
                                <flux:select wire:model.live="def.filters.{{ $i }}.field" size="sm" :aria-label="__('Field')">
                                    <option value="">{{ __('Field…') }}</option>
                                    @foreach ($fields as $f)@if ($f->filterable)<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach
                                </flux:select>
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeFilter({{ $i }})" :aria-label="__('Remove filter')" />
                            </div>
                            @if ($ff)
                                <flux:select wire:model.live="def.filters.{{ $i }}.op" size="sm" :aria-label="__('Comparison')">
                                    <option value="">{{ __('How…') }}</option>
                                    @foreach ($operators[$ff->type->value] as $op)<option value="{{ $op }}">{{ $opLabels[$op] }}</option>@endforeach
                                </flux:select>
                                @if ($ff->type->value === 'enum')
                                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                                        @foreach ($ff->options as $value => $label)
                                            <label class="flex items-center gap-1 text-sm"><input type="checkbox" value="{{ $value }}" wire:model.live="def.filters.{{ $i }}.value"> {{ $label }}</label>
                                        @endforeach
                                    </div>
                                @elseif (($filter['op'] ?? '') === 'between')
                                    <div class="grid grid-cols-2 gap-1">
                                        @if ($ff->type->value === 'date')
                                            <x-date-input wire:model.live="def.filters.{{ $i }}.value.0" />
                                            <x-date-input wire:model.live="def.filters.{{ $i }}.value.1" />
                                        @else
                                            <flux:input wire:model.live.debounce.500ms="def.filters.{{ $i }}.value.0" size="sm" inputmode="decimal" />
                                            <flux:input wire:model.live.debounce.500ms="def.filters.{{ $i }}.value.1" size="sm" inputmode="decimal" />
                                        @endif
                                    </div>
                                @elseif (! in_array($filter['op'] ?? '', ['empty', 'not_empty', 'yes', 'no', ''], true))
                                    @if ($ff->type->value === 'date')
                                        <x-date-input wire:model.live="def.filters.{{ $i }}.value" />
                                    @else
                                        <flux:input wire:model.live.debounce.500ms="def.filters.{{ $i }}.value" size="sm" :inputmode="$ff->type->numeric() ? 'decimal' : 'text'" />
                                    @endif
                                @endif
                            @endif
                            @foreach (['field', 'op', 'value'] as $part)
                                @isset($defErrors["filters.$i.$part"])<flux:text class="text-xs text-red-600">{{ $defErrors["filters.$i.$part"][0] }}</flux:text>@endisset
                            @endforeach
                        </div>
                    @endforeach
                    <flux:button size="sm" icon="plus" wire:click="addFilter">{{ __('Add filter') }}</flux:button>
                </div>
            </flux:fieldset>

            <div class="grid grid-cols-2 gap-2">
                <flux:select wire:model.live="def.sort.field" :label="__('Sort by')">
                    @foreach ($fields as $f)<option value="{{ $f->key }}">{{ $f->label }}</option>@endforeach
                </flux:select>
                <flux:select wire:model.live="def.sort.dir" :label="__('Order')">
                    <option value="asc">{{ __('Ascending') }}</option>
                    <option value="desc">{{ __('Descending') }}</option>
                </flux:select>
            </div>
            {{-- Group by, totals, compare, chart: Tasks 11–14 --}}
        </aside>

        <section class="min-w-0 space-y-3">
            @if ($defErrors !== [])
                <flux:callout icon="exclamation-triangle" color="amber" :heading="__('Finish the settings to see results')">
                    <flux:callout.text>{{ collect($defErrors)->flatten()->first() }}</flux:callout.text>
                </flux:callout>
            @elseif ($result)
                <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm text-zinc-500">
                    <span>{{ trans_choice(':n row|:n rows', $result->rowCount, ['n' => number_format($result->rowCount)]) }}@if ($result->periodLabel) · {{ $result->periodLabel }}@endif</span>
                    @if ($result->truncated)<span>{{ __('Showing the first :n — export to Excel for all.', ['n' => App\Reports\Builder\Engine::DETAIL_LIMIT]) }}</span>@endif
                </div>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            @foreach ($result->columns as $col)
                                <flux:table.column :align="$col->type->numeric() ? 'end' : 'start'">{{ $col->label }}</flux:table.column>
                            @endforeach
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($result->rows as $i => $row)
                                <flux:table.row :key="'r'.$i">
                                    @foreach ($result->columns as $col)
                                        <flux:table.cell @class(['text-end tabular-nums' => $col->type->numeric()])>{{ Formatter::display($col, $row[$col->key] ?? null) }}</flux:table.cell>
                                    @endforeach
                                </flux:table.row>
                            @empty
                                <flux:table.row><flux:table.cell :colspan="count($result->columns)">{{ __('No rows match.') }}</flux:table.cell></flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </section>
    </div>
</div>
```

`<x-building-filter>` binds `wire:model.live="building"` today; give it a `model` prop (default `'building'`) so the builder can pass `model="def.building_id"`: in `resources/views/components/building-filter.blade.php` change `@props(['buildings', 'label' => __('Building')])` to `@props(['buildings', 'label' => __('Building'), 'model' => 'building'])` and `wire:model.live="building"` to `wire:model.live="{{ $model }}"`; in the builder use `<x-building-filter :buildings="$buildings" model="def.building_id" />`. An empty select value arrives as `""` — `ReportDefinition` treats `""` as no building: change its check to `$building = filled($data['building_id'] ?? null) ? $data['building_id'] : null;`.

- [ ] **Step 6: Run** the builder test → PASS. Build CSS (`npm run build`).

### Task 7: Save, Save as copy, Share, Delete

**Files:**
- Modify: `app/Livewire/Reports/Builder.php`, `resources/views/livewire/reports/builder.blade.php` (top bar)
- Test: `tests/Feature/ReportBuilder/SaveShareTest.php`

**Interfaces:**
- Consumes: `SavedReport`, `SavedReportPolicy`, `Audit::log` (`App\Audit\Audit::log(string $event, ?Model $subject = null, array $old = [], array $new = [], array $properties = [], ?Model $causer = null)`).
- Produces: component actions `save()`, `saveAsCopy()`, `share(bool $shared)`, `deleteReport()`; audit events `report.saved`, `report.shared`, `report.deleted`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\SavedReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->other = matrixUser(RoleName::Finance, $this->building);
});

test('saving stores a validated definition under a name, then reopens it', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('name', 'Big balances')->call('toggleColumn', 'vat')->call('save')
        ->assertHasNoErrors()->assertRedirect();

    $report = SavedReport::sole();
    expect($report)->name->toBe('Big balances')->visibility->toBe('private')->subject->toBe('invoices')
        ->and($report->definition['columns'])->toContain('vat')->and($report->definition['version'])->toBe(1)
        ->and(Activity::where('event', 'report.saved')->count())->toBe(1);

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])->assertSet('name', 'Big balances');
});

test('a name is required and a broken definition is not saved', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', '')->call('save')->assertHasErrors('name');
    Livewire::actingAs($this->finance)->test(Builder::class)->set('name', 'X')->set('def.columns', [])->call('save')->assertHasErrors('columns');
    expect(SavedReport::count())->toBe(0);
});

test('sharing, copying and who may change a report', function () {
    $page = Livewire::actingAs($this->finance)->test(Builder::class)->set('name', 'Mine')->call('save');
    $report = SavedReport::sole();

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])->call('share', true);
    expect($report->fresh()->visibility)->toBe('shared');

    Livewire::actingAs($this->other)->test(Builder::class, ['report' => $report])
        ->call('save')->assertForbidden();
    Livewire::actingAs($this->other)->test(Builder::class, ['report' => $report])
        ->call('saveAsCopy')->assertRedirect();
    expect(SavedReport::where('created_by', $this->other->id)->sole())->name->toBe('Mine (copy)')->visibility->toBe('private');

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])->call('deleteReport')->assertRedirect(route('reports.index'));
    expect(SavedReport::find($report->id))->toBeNull();
});
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Actions** — add to `Builder`:

```php
    public function save(): void
    {
        $user = $this->actor();
        $this->validate(['name' => ['required', 'string', 'max:120']], ['name.required' => __('Give the report a name.')]);
        $definition = ReportDefinition::fromArray($this->def, $user)->toArray(); // ValidationException shows on the page

        if ($this->reportId === null) {
            $report = SavedReport::create(['name' => $this->name, 'subject' => $definition['subject'], 'definition' => $definition,
                'visibility' => 'private', 'created_by' => $user->id, 'updated_by' => $user->id]);
        } else {
            $report = SavedReport::findOrFail($this->reportId);
            abort_unless($user->can('update', $report), 403);
            $report->update(['name' => $this->name, 'subject' => $definition['subject'], 'definition' => $definition, 'updated_by' => $user->id]);
        }
        Audit::log('report.saved', $report, properties: ['name' => $report->name, 'subject' => $report->subject], causer: $user);
        Flux::toast(variant: 'success', text: __('Report saved.'));
        $this->redirectRoute('reports.builder', $report, navigate: true);
    }

    public function saveAsCopy(): void
    {
        $user = $this->actor();
        $definition = ReportDefinition::fromArray($this->def, $user)->toArray();
        $report = SavedReport::create(['name' => mb_substr(($this->name !== '' ? $this->name : __('Untitled report')).' '.__('(copy)'), 0, 120),
            'subject' => $definition['subject'], 'definition' => $definition, 'visibility' => 'private', 'created_by' => $user->id, 'updated_by' => $user->id]);
        Audit::log('report.saved', $report, properties: ['name' => $report->name, 'copied_from' => $this->reportId], causer: $user);
        $this->redirectRoute('reports.builder', $report, navigate: true);
    }

    public function share(bool $shared): void
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('update', $report), 403);
        $report->update(['visibility' => $shared ? 'shared' : 'private']);
        Audit::log('report.shared', $report, properties: ['visibility' => $report->visibility], causer: $this->actor());
        Flux::toast(text: $shared ? __('Shared with everyone who has report access.') : __('Now only you can see it.'));
    }

    public function deleteReport(): void
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('delete', $report), 403);
        $report->delete();
        Audit::log('report.deleted', $report, properties: ['name' => $report->name], causer: $this->actor());
        $this->redirectRoute('reports.index', navigate: true);
    }
```

Add imports `App\Audit\Audit`, `Flux\Flux`. `ValidationException` thrown by `fromArray` inside an action lands in the component's error bag automatically (keys like `columns`).

- [ ] **Step 4: Top bar buttons** — replace the placeholder comment in the view:

```blade
            @if ($canEdit)
                <flux:button variant="primary" wire:click="save">{{ __('Save') }}</flux:button>
            @endif
            @if ($reportId)
                <flux:button wire:click="saveAsCopy">{{ __('Save as copy') }}</flux:button>
                @if ($canEdit)
                    @php($shared = App\Models\SavedReport::findOrFail($reportId)->visibility === 'shared')
                    <flux:button :icon="$shared ? 'users' : 'lock-closed'" wire:click="share({{ $shared ? 'false' : 'true' }})">{{ $shared ? __('Shared') : __('Only me') }}</flux:button>
                    <flux:button variant="ghost" icon="trash" wire:click="deleteReport" wire:confirm="{{ __('Delete this report?') }}" :aria-label="__('Delete')" />
                @endif
            @endif
            @error('name')<flux:text class="w-full text-sm text-red-600">{{ $message }}</flux:text>@enderror
```

- [ ] **Step 5: Run** → PASS.

### Task 8: Excel export, and "My reports" on the Reports page

**Files:**
- Create: `app/Reports/Builder/ExcelWriter.php`
- Modify: `app/Livewire/Reports/Builder.php` (`export()`), `app/Livewire/Reports/Index.php`, `resources/views/livewire/reports/index.blade.php`
- Test: `tests/Feature/ReportBuilder/ExportAndListTest.php`

**Interfaces:**
- Produces: `ExcelWriter::write(ReportResult $result, string $path): void` (columns header, raw values, then a "Total" row; group subtotal rows added in Task 12); `Builder::export(): BinaryFileResponse` (runs with `limit: null`, audits `report.exported` with `report: 'builder:<subject>'`); Reports index data `myReports`, `sharedReports`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Livewire\Reports\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\SavedReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, Building::factory()->create());
});

test('export downloads every row as xlsx and is audited', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->call('export')->assertFileDownloaded();
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'builder:invoices')->exists())->toBeTrue();
});

test('the Reports page lists my reports and shared ones, with a New report button', function () {
    $mine = SavedReport::create(['name' => 'Mine', 'subject' => 'invoices', 'definition' => ['version' => 1], 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id]);
    $other = matrixUser(RoleName::Finance, Building::factory()->create());
    SavedReport::create(['name' => 'Theirs shared', 'subject' => 'payments', 'definition' => ['version' => 1], 'visibility' => 'shared', 'created_by' => $other->id, 'updated_by' => $other->id]);
    SavedReport::create(['name' => 'Theirs private', 'subject' => 'payments', 'definition' => ['version' => 1], 'visibility' => 'private', 'created_by' => $other->id, 'updated_by' => $other->id]);

    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertSee('New report')->assertSee('Mine')->assertSee('Theirs shared')->assertDontSee('Theirs private')
        ->assertSeeHtml(e(route('reports.builder', $mine)));
});
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: ExcelWriter**

```php
<?php

namespace App\Reports\Builder;

use Spatie\SimpleExcel\SimpleExcelWriter;

/** The builder's Excel file: one header row, raw values, then the grand total (spec §5). */
final class ExcelWriter
{
    public static function write(ReportResult $result, string $path): void
    {
        $writer = SimpleExcelWriter::create($path);
        $labels = array_map(fn (Field $f) => $f->label, $result->columns);
        $writer->addHeader($labels);
        foreach ($result->rows as $row) {
            $writer->addRow(self::line($result->columns, $row));
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
        $writer->addRow($total);
        $writer->close();
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

- [ ] **Step 4: `Builder::export()`**

```php
    public function export(): BinaryFileResponse
    {
        $user = $this->actor();
        $def = ReportDefinition::fromArray($this->def, $user);
        $result = app(Engine::class)->run($user, $def, limit: null);
        Audit::log('report.exported', properties: ['report' => 'builder:'.$def->subject, 'saved_report_id' => $this->reportId,
            'building' => $def->buildingId, 'period' => $result->periodLabel], causer: $user);
        if ($this->reportId !== null) {
            SavedReport::whereKey($this->reportId)->update(['last_run_at' => now()]);
        }
        $path = sys_get_temp_dir().'/rms-report-'.Str::uuid().'.xlsx';
        ExcelWriter::write($result, $path);

        return response()->download($path, Str::slug($this->name !== '' ? $this->name : $def->subject).'-'.now('Asia/Bahrain')->toDateString().'.xlsx')->deleteFileAfterSend();
    }
```

Imports: `App\Reports\Builder\ExcelWriter`, `Illuminate\Support\Str`, `Symfony\Component\HttpFoundation\BinaryFileResponse`. Add the button beside Save: `<flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export to Excel') }}</flux:button>`.

- [ ] **Step 5: Reports index** — in `App\Livewire\Reports\Index::render()` pass:

```php
            'myReports' => SavedReport::visibleTo($user)->where('created_by', $user->id)->latest('updated_at')->get(),
            'sharedReports' => SavedReport::visibleTo($user)->where('created_by', '!=', $user->id)->with('creator:id,name')->latest('updated_at')->get(),
            'canBuild' => Registry::forUser($user) !== [],
```

and at the top of `resources/views/livewire/reports/index.blade.php`, before `@forelse ($groups …)`:

```blade
    @if ($canBuild)
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('My reports') }}</flux:heading>
                <flux:button variant="primary" icon="plus" :href="route('reports.builder')" wire:navigate>{{ __('New report') }}</flux:button>
            </div>
            @if ($myReports->isEmpty() && $sharedReports->isEmpty())
                <flux:text>{{ __('Build your own report: pick a subject, columns and filters, then save it here.') }}</flux:text>
            @else
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ([[__('Mine'), $myReports], [__('Shared with me'), $sharedReports]] as [$heading, $list])
                        @foreach ($list as $r)
                            <a href="{{ route('reports.builder', $r) }}" wire:navigate class="block rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:outline-none dark:border-zinc-700 dark:bg-zinc-900">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $r->name }}</span>
                                <span class="mt-0.5 block text-sm text-zinc-500">
                                    {{ App\Reports\Builder\Registry::get($r->subject)->label() }} · {{ $heading }}@if ($r->creator && $r->created_by !== auth()->id()) · {{ $r->creator->name }}@endif
                                    @if ($r->last_run_at) · {{ __('run :d', ['d' => $r->last_run_at->timezone('Asia/Bahrain')->format('d/m/Y')]) }}@endif
                                </span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </section>
    @endif
```

Also update `last_run_at` in `Builder::mount()` when opening a saved report: `SavedReport::whereKey($report->id)->update(['last_run_at' => now()]);` (a plain update; no audit).

- [ ] **Step 6: Run** stage-1 tests: `php artisan test tests/Feature/ReportBuilder` → PASS. Full suite → PASS. Pint, phpstan, `npm run build`.
- [ ] **Step 7: Commit (when the user asks)**

```bash
git add app/Reports app/Models/SavedReport.php app/Policies/SavedReportPolicy.php app/Livewire/Reports database/migrations resources/views tests/Feature/ReportBuilder routes/property.php
git commit -m "Add the report builder: four subjects, columns, filters, periods, save, share, Excel"
```

---

# Stage 2 — the other eight subjects

### Task 9: Cheques, Tenants, Owners, Owner contracts

**Files:**
- Create: `app/Reports/Builder/Subjects/{Cheques,Tenants,Owners,OwnerContracts}.php`
- Modify: `app/Reports/Builder/Registry.php` (add to `all()` in the final order)
- Test: `tests/Feature/ReportBuilder/SubjectFiguresTest.php` (new) — and `SubjectQueriesTest` already runs every field of every registered subject.

**Interfaces:** Consumes `Subject`, `Field`, `Sql`, `Customer::inBuilding`, `Cheque::inBuilding`, `OwnerContract::visibleTo`. Produces subject keys `cheques`, `tenants`, `owners`, `owner_contracts`.

- [ ] **Step 1: Failing figures test**

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['code' => 'AAA']);
    $this->management = matrixUser(RoleName::Management, $this->building);
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->tenant = Customer::factory()->create(['name_en' => 'Alpha Tenant']);
    $ag = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [Unit::factory()->for($this->building)->create()]);
    issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $ag->agreementUnits->first()]], '2026-10-01', $ag);
    app(RecordPayment::class)->handle($this->finance, $this->tenant, ['received_on' => '2026-10-10', 'method' => 'cash', 'amount' => '450', 'allocations' => [['invoice_id' => App\Models\Invoice::where('customer_id', $this->tenant->id)->value('id'), 'amount' => '400']]]);
});

test('tenant outstanding and credit match the app\'s own figures', function () {
    $r = app(Engine::class)->run($this->management, ReportDefinition::fromArray(['subject' => 'tenants', 'columns' => ['name', 'outstanding', 'credit', 'buildings']], $this->management));
    $row = collect($r->rows)->firstWhere('name', 'Alpha Tenant');

    expect($row['credit'])->toBe(App\Support\Fils::toDecimal(CustomerCredit::fils($this->tenant->id)))->and($row['credit'])->toBe('50.000')
        ->and($row['outstanding'])->toBe('-50.000')->and($row['buildings'])->toBe('AAA');
});

test('money fields on leasing subjects need financial report access', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->building);
    expect(fn () => ReportDefinition::fromArray(['subject' => 'tenants', 'columns' => ['name', 'outstanding']], $leasing))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Run** → FAIL (unknown subject).

- [ ] **Step 3: Cheques**

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\ChequeStatus;
use App\Enums\PermissionName;
use App\Models\Cheque;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Sql;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Cheques extends Subject
{
    public function key(): string { return 'cheques'; }

    public function label(): string { return __('Cheques'); }

    public function group(): string { return __('Money in'); }

    public function permission(): PermissionName { return PermissionName::ReportsFinancial; }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Cheque::query()->visibleTo($user)->where('cheques.direction', 'received')
            ->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase()
            ->leftJoin('customers', 'customers.id', '=', 'cheques.customer_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('cheque_no', __('Cheque no.'), __('Cheque'), 'cheques.cheque_no', groupable: false),
            Field::text('bank', __('Bank'), __('Cheque'), 'cheques.bank_name'),
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('building', __('Building'), __('Property'), 'COALESCE('.Sql::buildingOfAgreement('cheques.agreement_id').", '—')", filterable: false),
            Field::date('cheque_date', __('Cheque date'), __('Dates'), 'cheques.cheque_date'),
            Field::money('amount', __('Amount'), __('Amounts'), 'cheques.amount'),
            Field::enum('status', __('Status'), __('Cheque'), 'cheques.status', Field::optionsFrom(ChequeStatus::class)),
            Field::date('deposited_on', __('Deposited on'), __('Dates'), 'cheques.deposited_on'),
            Field::date('cleared_on', __('Cleared on'), __('Dates'), 'cheques.cleared_on'),
            Field::date('bounced_on', __('Bounced on'), __('Dates'), 'cheques.bounced_on'),
            Field::text('bounce_reason', __('Bounce reason'), __('Cheque'), 'cheques.bounce_reason'),
        ];
    }

    public function defaultColumns(): array { return ['cheque_no', 'bank', 'tenant', 'cheque_date', 'amount', 'status']; }

    public function periodFields(): array { return ['cheque_date', 'cleared_on']; }

    public function defaultSort(): array { return ['field' => 'cheque_date', 'dir' => 'asc']; }
}
```

(Run Pint after writing: it expands the one-line methods to the project's style.)

- [ ] **Step 4: Tenants** — credit follows `IntegrityCheck`'s formula (confirmed payments − their allocations − paid credit refunds):

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Tenants extends Subject
{
    public function key(): string { return 'tenants'; }

    public function label(): string { return __('Tenants'); }

    public function group(): string { return __('Leasing'); }

    public function permission(): PermissionName { return PermissionName::ReportsOperational; }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Customer::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase();
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $balance = "(SELECT COALESCE(SUM(oi.balance), 0) FROM invoices oi WHERE oi.customer_id = customers.id AND oi.status = 'issued'
            AND oi.type <> 'credit_note' AND oi.balance > 0)";
        $credit = "((SELECT COALESCE(SUM(cp.amount), 0) FROM payments cp WHERE cp.customer_id = customers.id AND cp.status = 'confirmed')
            - (SELECT COALESCE(SUM(ca.amount), 0) FROM payment_allocations ca JOIN payments cp2 ON cp2.id = ca.payment_id
                WHERE cp2.customer_id = customers.id AND cp2.status = 'confirmed')
            - (SELECT COALESCE(SUM(cd.amount), 0) FROM disbursements cd JOIN payments cp3 ON cd.source_type = 'payment' AND cd.source_id = cp3.id
                WHERE cp3.customer_id = customers.id AND cd.purpose = 'credit_refund' AND cd.status = 'paid'))";

        return [
            Field::text('name', __('Tenant'), __('Tenant'), 'customers.name_en', groupable: false),
            Field::enum('type', __('Type'), __('Tenant'), 'customers.type', Field::optionsFrom(CustomerType::class)),
            Field::enum('id_type', __('ID type'), __('Tenant'), 'customers.id_type', Field::optionsFrom(IdType::class)),
            Field::text('nationality', __('Nationality'), __('Tenant'), 'customers.nationality'),
            Field::text('mobile', __('Mobile'), __('Tenant'), 'customers.mobile', groupable: false),
            Field::text('buildings', __('Buildings'), __('Property'),
                "(SELECT GROUP_CONCAT(DISTINCT tb.code ORDER BY tb.code SEPARATOR ', ') FROM agreements ta JOIN agreement_units tau ON tau.agreement_id = ta.id
                    JOIN units tu ON tu.id = tau.unit_id JOIN buildings tb ON tb.id = tu.building_id WHERE ta.customer_id = customers.id AND ta.deleted_at IS NULL)",
                filterable: false),
            Field::number('active_agreements', __('Active agreements'), __('Tenant'),
                "(SELECT COUNT(*) FROM agreements aa WHERE aa.customer_id = customers.id AND aa.status = 'active' AND aa.deleted_at IS NULL)"),
            Field::money('balance', __('Invoice balances'), __('Amounts'), $balance, PermissionName::ReportsFinancial),
            Field::money('credit', __('Credit'), __('Amounts'), $credit, PermissionName::ReportsFinancial),
            Field::money('outstanding', __('Outstanding'), __('Amounts'), "({$balance} - {$credit})", PermissionName::ReportsFinancial),
        ];
    }

    public function defaultColumns(): array { return ['name', 'type', 'mobile', 'buildings', 'active_agreements']; }
}
```

- [ ] **Step 5: Owners and Owner contracts**

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\IdType;
use App\Enums\PartyType;
use App\Enums\PermissionName;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/** Owners are not building-scoped (spec §8.2); the building filter keeps owners with a contract on it. */
final class Owners extends Subject
{
    public function key(): string { return 'owners'; }

    public function label(): string { return __('Owners'); }

    public function group(): string { return __('Owners'); }

    public function permission(): PermissionName { return PermissionName::ReportsFinancial; }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Owner::query()->when($buildingId, fn ($q, $b) => $q->whereIn('owners.id',
            OwnerContract::query()->visibleTo($user)->where('building_id', $b)->select('owner_id')))->toBase();
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('name', __('Owner'), __('Owner'), 'owners.name_en', groupable: false),
            Field::enum('type', __('Type'), __('Owner'), 'owners.type', Field::optionsFrom(PartyType::class)),
            Field::enum('id_type', __('ID type'), __('Owner'), 'owners.id_type', Field::optionsFrom(IdType::class)),
            Field::number('active_contracts', __('Active contracts'), __('Owner'),
                "(SELECT COUNT(*) FROM owner_contracts oc WHERE oc.owner_id = owners.id AND oc.status = 'active')"),
            Field::text('buildings', __('Buildings'), __('Property'),
                "(SELECT GROUP_CONCAT(DISTINCT ob.code ORDER BY ob.code SEPARATOR ', ') FROM owner_contracts oc2 JOIN buildings ob ON ob.id = oc2.building_id
                    WHERE oc2.owner_id = owners.id)", filterable: false),
        ];
    }

    public function defaultColumns(): array { return ['name', 'type', 'active_contracts', 'buildings']; }
}
```

Check `App\Enums\PartyType` is the owner type enum (`app/Enums/PartyType.php`); if owners use a different enum, use that one.

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\FeeType;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\PaymentFrequency;
use App\Enums\PermissionName;
use App\Models\OwnerContract;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class OwnerContracts extends Subject
{
    public function key(): string { return 'owner_contracts'; }

    public function label(): string { return __('Owner contracts'); }

    public function group(): string { return __('Owners'); }

    public function permission(): PermissionName { return PermissionName::ReportsFinancial; }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return OwnerContract::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->where('owner_contracts.building_id', $b))->toBase()
            ->join('owners', 'owners.id', '=', 'owner_contracts.owner_id')
            ->join('buildings', 'buildings.id', '=', 'owner_contracts.building_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('number', __('Contract'), __('Contract'), "COALESCE(owner_contracts.number, CONCAT('#', owner_contracts.id))", groupable: false),
            Field::text('owner', __('Owner'), __('Owner'), 'owners.name_en'),
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::enum('type', __('Type'), __('Contract'), 'owner_contracts.type', Field::optionsFrom(OwnerContractType::class)),
            Field::enum('status', __('Status'), __('Contract'), 'owner_contracts.status', Field::optionsFrom(OwnerContractStatus::class)),
            Field::date('start_date', __('Start'), __('Dates'), 'owner_contracts.start_date'),
            Field::date('end_date', __('End'), __('Dates'), 'owner_contracts.end_date'),
            Field::money('rent', __('Head-lease rent'), __('Amounts'), 'owner_contracts.rent_amount'),
            Field::enum('frequency', __('Paid'), __('Contract'), 'owner_contracts.payment_frequency', Field::optionsFrom(PaymentFrequency::class)),
            Field::enum('fee_type', __('Fee type'), __('Contract'), 'owner_contracts.fee_type', Field::optionsFrom(FeeType::class)),
            Field::number('fee_value', __('Fee value'), __('Amounts'), 'owner_contracts.fee_value'),
            Field::number('units', __('Units'), __('Property'), '(SELECT COUNT(*) FROM owner_contract_units ocu WHERE ocu.owner_contract_id = owner_contracts.id)'),
        ];
    }

    public function defaultColumns(): array { return ['number', 'owner', 'building', 'type', 'status', 'start_date', 'end_date']; }

    public function periodFields(): array { return ['start_date', 'end_date']; }
}
```

- [ ] **Step 6: Register, run** `php artisan test tests/Feature/ReportBuilder` → PASS (the every-field test now covers these four).

### Task 10: Owner statements, Expenses, Payments out, Deposits held

**Files:** Create `app/Reports/Builder/Subjects/{OwnerStatements,Expenses,PaymentsOut,DepositsHeld}.php`; modify `Registry`; extend `SubjectFiguresTest.php`.

- [ ] **Step 1: Failing test** — append to `SubjectFiguresTest.php`:

```php
test('deposits held equal the deposit movements as at the date', function () {
    $r = app(Engine::class)->run($this->management, ReportDefinition::fromArray(['subject' => 'deposits_held', 'columns' => ['tenant', 'held', 'agreed']], $this->management));
    $row = collect($r->rows)->firstWhere('tenant', 'Alpha Tenant');

    expect($row['agreed'])->toBe('400.000')
        ->and($row['held'])->toBe(App\Support\Fils::toDecimal(App\Models\DepositMovement::query()->sum('amount') ? App\Support\Fils::fromDecimal((string) App\Models\DepositMovement::query()->sum('amount')) : 0));
});

test('expenses, payments out and owner statements run for every field', function () {
    foreach (['expenses', 'payments_out', 'owner_statements'] as $key) {
        expect(app(Engine::class)->run($this->management, ReportDefinition::fresh(App\Reports\Builder\Registry::get($key), $this->management))->columns)->not->toBeEmpty();
    }
});
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: OwnerStatements**

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\OwnerStatementStatus;
use App\Enums\PermissionName;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/** Stored figures only (planning ruling): collections, expenses and remittances need the owner ledger. */
final class OwnerStatements extends Subject
{
    public function key(): string { return 'owner_statements'; }

    public function label(): string { return __('Owner statements'); }

    public function group(): string { return __('Owners'); }

    public function permission(): PermissionName { return PermissionName::ReportsFinancial; }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return OwnerStatement::query()->whereIn('owner_statements.owner_contract_id', OwnerContract::query()->visibleTo($user)
            ->when($buildingId, fn ($q, $b) => $q->where('building_id', $b))->select('id'))->toBase()
            ->join('owner_contracts', 'owner_contracts.id', '=', 'owner_statements.owner_contract_id')
            ->join('owners', 'owners.id', '=', 'owner_contracts.owner_id')
            ->join('buildings', 'buildings.id', '=', 'owner_contracts.building_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('number', __('Statement'), __('Statement'), "COALESCE(owner_statements.number, CONCAT('#', owner_statements.id))", groupable: false),
            Field::text('owner', __('Owner'), __('Owner'), 'owners.name_en'),
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::text('contract', __('Contract'), __('Owner'), 'owner_contracts.number'),
            Field::date('period_start', __('Period start'), __('Dates'), 'owner_statements.period_start'),
            Field::date('period_end', __('Period end'), __('Dates'), 'owner_statements.period_end'),
            Field::money('opening', __('Opening'), __('Amounts'), 'owner_statements.opening_balance'),
            Field::money('fee_base', __('Fee base'), __('Amounts'), 'owner_statements.fee_base'),
            Field::money('fee', __('Fee'), __('Amounts'), 'owner_statements.fee_amount'),
            Field::money('fee_vat', __('VAT on fee'), __('Amounts'), 'owner_statements.fee_tax'),
            Field::money('closing', __('Closing'), __('Amounts'), 'owner_statements.closing_balance'),
            Field::enum('status', __('Status'), __('Statement'), 'owner_statements.status', Field::optionsFrom(OwnerStatementStatus::class)),
        ];
    }

    public function defaultColumns(): array { return ['number', 'owner', 'building', 'period_end', 'opening', 'fee', 'closing', 'status']; }

    public function periodFields(): array { return ['period_end']; }

    public function defaultSort(): array { return ['field' => 'period_end', 'dir' => 'desc']; }
}
```

- [ ] **Step 4: Expenses**

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\ChargeTo;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PermissionName;
use App\Models\Expense;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class Expenses extends Subject
{
    public function key(): string { return 'expenses'; }

    public function label(): string { return __('Expenses'); }

    public function group(): string { return __('Costs'); }

    public function permission(): PermissionName { return PermissionName::ReportsFinancial; }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Expense::query()->visibleTo($user)->when($buildingId, fn ($q, $b) => $q->where('expenses.building_id', $b))->toBase()
            ->join('buildings', 'buildings.id', '=', 'expenses.building_id')
            ->leftJoin('units', 'units.id', '=', 'expenses.unit_id');
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::text('unit', __('Unit'), __('Property'), 'units.code'),
            Field::enum('category', __('Category'), __('Expense'), 'expenses.category', Field::optionsFrom(ExpenseCategory::class)),
            Field::date('expense_date', __('Date'), __('Dates'), 'expenses.expense_date'),
            Field::money('net', __('Net'), __('Amounts'), 'expenses.net'),
            Field::money('vat', __('VAT'), __('Amounts'), 'expenses.tax_amount'),
            Field::money('total', __('Total'), __('Amounts'), 'expenses.total'),
            Field::enum('charge_to', __('Charged to'), __('Expense'), 'expenses.charge_to', Field::optionsFrom(ChargeTo::class)),
            Field::enum('status', __('Status'), __('Expense'), 'expenses.status', Field::optionsFrom(ExpenseStatus::class)),
        ];
    }

    public function defaultColumns(): array { return ['expense_date', 'building', 'unit', 'category', 'total', 'charge_to', 'status']; }

    public function periodFields(): array { return ['expense_date']; }

    public function defaultSort(): array { return ['field' => 'expense_date', 'dir' => 'desc']; }
}
```

- [ ] **Step 5: PaymentsOut** (Building filled for head-lease and owner remittances; ruling above)

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\DisbursementMethod;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final class PaymentsOut extends Subject
{
    public function key(): string { return 'payments_out'; }

    public function label(): string { return __('Payments out'); }

    public function group(): string { return __('Costs'); }

    public function permission(): PermissionName { return PermissionName::ReportsFinancial; }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        return Disbursement::query()->where(fn ($q) => $q->where('disbursements.payee_type', 'owner')
            ->orWhereIn('disbursements.payee_id', Customer::query()->visibleTo($user)->select('id')))
            ->when($buildingId, fn ($q, $b) => $q->inBuilding($b))->toBase();
    }

    protected function define(CarbonImmutable $asAt): array
    {
        $building = "(CASE disbursements.source_type
            WHEN 'owner_payable' THEN (SELECT xb.code FROM owner_payables xp JOIN owner_contracts xc ON xc.id = xp.owner_contract_id JOIN buildings xb ON xb.id = xc.building_id WHERE xp.id = disbursements.source_id)
            WHEN 'owner_statement' THEN (SELECT yb.code FROM owner_statements ys JOIN owner_contracts yc ON yc.id = ys.owner_contract_id JOIN buildings yb ON yb.id = yc.building_id WHERE ys.id = disbursements.source_id)
            END)";

        return [
            Field::text('number', __('Number'), __('Payment out'), "COALESCE(disbursements.number, CONCAT('#', disbursements.id))", groupable: false),
            Field::enum('payee_type', __('Payee type'), __('Payee'), 'disbursements.payee_type', ['owner' => __('Owner'), 'customer' => __('Tenant')]),
            Field::text('payee', __('Payee'), __('Payee'), "(CASE disbursements.payee_type WHEN 'owner' THEN (SELECT po.name_en FROM owners po WHERE po.id = disbursements.payee_id)
                ELSE (SELECT pc.name_en FROM customers pc WHERE pc.id = disbursements.payee_id) END)"),
            Field::enum('purpose', __('Purpose'), __('Payment out'), 'disbursements.purpose', Field::optionsFrom(DisbursementPurpose::class)),
            Field::text('building', __('Building'), __('Property'), "COALESCE({$building}, '—')", filterable: false),
            Field::money('amount', __('Amount'), __('Amounts'), 'disbursements.amount'),
            Field::enum('method', __('Method'), __('Payment out'), 'disbursements.method', Field::optionsFrom(DisbursementMethod::class)),
            Field::date('paid_on', __('Paid on'), __('Dates'), 'disbursements.paid_on'),
            Field::enum('status', __('Status'), __('Payment out'), 'disbursements.status', Field::optionsFrom(DisbursementStatus::class)),
        ];
    }

    public function defaultColumns(): array { return ['number', 'payee', 'purpose', 'amount', 'paid_on', 'status']; }

    public function periodFields(): array { return ['paid_on']; }

    public function defaultSort(): array { return ['field' => 'paid_on', 'dir' => 'desc']; }
}
```

- [ ] **Step 6: DepositsHeld** (as at a date; one row per agreement unit with a deposit agreed or held)

```php
<?php

namespace App\Reports\Builder\Subjects;

use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\User;
use App\Reports\Builder\Field;
use App\Reports\Builder\Sql;
use App\Reports\Builder\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DepositsHeld extends Subject
{
    public function key(): string { return 'deposits_held'; }

    public function label(): string { return __('Deposits held'); }

    public function group(): string { return __('Costs'); }

    public function permission(): PermissionName { return PermissionName::ReportsFinancial; }

    public function usesAsAt(): bool { return true; }

    public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder
    {
        $end = $asAt->endOfDay()->toDateTimeString();

        return DB::table('agreement_units')
            ->whereIn('agreement_units.agreement_id', Agreement::query()->visibleTo($user)->select('id'))
            ->join('agreements', 'agreements.id', '=', 'agreement_units.agreement_id')
            ->join('units', 'units.id', '=', 'agreement_units.unit_id')
            ->join('buildings', 'buildings.id', '=', 'units.building_id')
            ->join('customers', 'customers.id', '=', 'agreements.customer_id')
            ->leftJoinSub(DB::table('deposit_movements')->where('posted_at', '<=', $end)
                ->groupBy('agreement_unit_id')->select('agreement_unit_id', DB::raw('SUM(amount) as held')), 'dm', 'dm.agreement_unit_id', '=', 'agreement_units.id')
            ->when($buildingId, fn ($q, $b) => $q->where('units.building_id', $b))
            ->where(fn ($q) => $q->where('agreement_units.deposit_amount', '>', 0)->orWhereNotNull('dm.held'));
    }

    protected function define(CarbonImmutable $asAt): array
    {
        return [
            Field::text('tenant', __('Tenant'), __('Tenant'), 'customers.name_en'),
            Field::text('agreement', __('Agreement'), __('Tenant'), 'agreements.number'),
            Field::text('building', __('Building'), __('Property'), 'buildings.code', filterable: false),
            Field::text('unit', __('Unit'), __('Property'), 'units.code', groupable: false),
            Field::money('agreed', __('Deposit agreed'), __('Amounts'), 'agreement_units.deposit_amount'),
            Field::money('held', __('Held'), __('Amounts'), 'COALESCE(dm.held, 0)'),
            Field::boolean('settled', __('Settled'), __('Tenant'),
                "EXISTS (SELECT 1 FROM deposit_settlement_units su JOIN deposit_settlements ss ON ss.id = su.deposit_settlement_id
                    WHERE su.agreement_unit_id = agreement_units.id AND ss.status = 'completed')"),
        ];
    }

    public function defaultColumns(): array { return ['tenant', 'agreement', 'building', 'unit', 'agreed', 'held']; }
}
```

`Sql` import in DepositsHeld is unused — drop it while transcribing. Fix the first figures test's `held` expectation to the plain form: `expect($row['held'])->toBe(App\Support\Fils::toDecimal(App\Support\Fils::fromDecimal((string) (App\Models\DepositMovement::query()->sum('amount') ?: '0'))))` (the deposit invoice is not paid in this fixture, so held is `0.000` — which is what the assertion checks).

- [ ] **Step 7: Register all twelve in final order; run** `php artisan test tests/Feature/ReportBuilder` → PASS. Full suite, Pint, phpstan.
- [ ] **Step 8: Commit (when asked)** — `git commit -m "Report builder: the other eight subjects"`.

---

# Stage 3 — grouping, totals and charts

### Task 11: Aggregator — grouped totals with ROLLUP

**Files:**
- Create: `app/Reports/Builder/Aggregator.php`
- Modify: `app/Reports/Builder/Grouping.php` (add `label()`), `app/Reports/Builder/Engine.php` (fill `groups`)
- Test: `tests/Feature/ReportBuilder/GroupingTest.php`

**Interfaces:**
- Produces: `Aggregator::groups(Builder $query, array $fields, ReportDefinition $def): list<array{level: int, keys: list<string|null>, label: string, totals: array<string, mixed>}>` — level 1 and level 2 rows in display order (level-1 subtotal first, then its level-2 rows), capped at 1,000; `Grouping::label(Field $field, ?string $bucket, ?string $raw): string`; `ReportResult::$groups` populated.

- [ ] **Step 1: Failing test** (fixture as in `EngineTest` beforeEach: AAA invoices 400.000 due 2026-10-01 and 150.500 due 2026-09-01; BBB 300.000 due 2026-10-05) — copy that `beforeEach` into this file.

```php
test('one level: subtotals per building, sorted by key, with the grand total unchanged', function () {
    $r = runReport($this->finance, ['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'groups' => [['field' => 'building']], 'totals' => ['balance' => 'sum']]);

    expect(array_map(fn ($g) => [$g['label'], $g['totals']['count'], $g['totals']['balance:sum']], $r->groups))
        ->toBe([['AAA', 2, '550.500'], ['BBB', 1, '300.000']])
        ->and($r->grandTotals['balance:sum'])->toBe('850.500');
});

test('two levels: building then month of due date, level-2 rows under each subtotal', function () {
    $r = runReport($this->finance, ['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'groups' => [['field' => 'building'], ['field' => 'due_date', 'bucket' => 'month']], 'totals' => ['balance' => 'sum'], 'summary_only' => true]);

    expect(array_map(fn ($g) => [$g['level'], $g['label'], $g['totals']['balance:sum']], $r->groups))->toBe([
        [1, 'AAA', '550.500'], [2, 'Sep 2026', '150.500'], [2, 'Oct 2026', '400.000'],
        [1, 'BBB', '300.000'], [2, 'Oct 2026', '300.000'],
    ])->and($r->rows)->toBe([]);
});

test('average, min and max; a "Multiple" building is counted once', function () {
    $r = runReport($this->finance, ['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'totals' => ['balance' => 'avg'], 'groups' => [['field' => 'tenant']]]);
    expect(collect($r->groups)->firstWhere('label', 'Alpha Tenant')['totals']['balance:avg'])->toBe('275.250');
});
```

- [ ] **Step 2: Run** → FAIL (`groups` empty).

- [ ] **Step 3: Grouping labels** — add to `Grouping`:

```php
    /** How a group key reads: month 2026-10 → "Oct 2026", quarter → "Q4 2026", enum values → their labels. */
    public static function label(Field $field, ?string $bucket, ?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }

        return match ($bucket) {
            'day' => \Carbon\CarbonImmutable::parse($raw)->format('d/m/Y'),
            'month' => \Carbon\CarbonImmutable::createFromFormat('Y-m', $raw)?->format('M Y') ?? $raw,
            'quarter' => preg_replace('/^(\d{4})-Q(\d)$/', 'Q$2 $1', $raw) ?? $raw,
            'year' => $raw,
            default => Formatter::display($field, $raw),
        };
    }
```

- [ ] **Step 4: Aggregator**

```php
<?php

namespace App\Reports\Builder;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Group subtotals in one query with ROLLUP (spec §5); GROUPING() tells a subtotal row from a real NULL key. */
final class Aggregator
{
    public const int GROUP_LIMIT = 1000;

    /**
     * @param  array<string, Field>  $fields
     * @return list<array{level: int, keys: list<string|null>, label: string, totals: array<string, mixed>}>
     */
    public static function groups(Builder $query, array $fields, ReportDefinition $def): array
    {
        if ($def->groups === []) {
            return [];
        }
        $inner = $query->select(DB::raw('1 as `_one`'));
        foreach ($def->groups as $i => $g) {
            $inner->addSelect(DB::raw(Grouping::expression($fields[$g['field']], $g['bucket'])." as `g{$i}`"));
        }
        foreach (array_keys($def->totals) as $key) {
            $inner->addSelect(DB::raw("{$fields[$key]->sql} as `{$key}`"));
        }

        $cols = array_map(fn ($i) => "`g{$i}`", array_keys($def->groups));
        $select = [...$cols, 'COUNT(*) as `count`'];
        foreach ($cols as $i => $col) {
            $select[] = "GROUPING({$col}) as `r{$i}`";
        }
        foreach ($def->totals as $key => $fn) {
            $select[] = Engine::aggregate($fields[$key], $fn)." as `{$key}:{$fn}`";
        }

        $rows = DB::query()->fromSub($inner, 't')
            ->selectRaw('/*+ MAX_EXECUTION_TIME(30000) */ '.implode(', ', $select))
            ->groupByRaw(implode(', ', $cols).' WITH ROLLUP')
            ->limit(self::GROUP_LIMIT * 3)
            ->get();

        $out = [];
        $depth = count($cols);
        foreach ($rows as $row) {
            $row = (array) $row;
            $level = $depth;
            for ($i = $depth - 1; $i >= 0; $i--) {
                if ((int) $row["r{$i}"] === 1) {
                    $level = $i;
                }
            }
            if ($level === 0) {
                continue; // the grand total: the Engine already has it
            }
            $keyIndex = $level - 1;
            $g = $def->groups[$keyIndex];
            $totals = ['count' => (int) $row['count']];
            foreach ($def->totals as $key => $fn) {
                $totals["{$key}:{$fn}"] = $row["{$key}:{$fn}"];
            }
            $out[] = [
                'level' => $level,
                'keys' => array_map(fn ($i) => $row["g{$i}"] === null ? null : (string) $row["g{$i}"], array_slice(array_keys($def->groups), 0, $level)),
                'label' => Grouping::label($fields[$g['field']], $g['bucket'], $row["g{$keyIndex}"] === null ? null : (string) $row["g{$keyIndex}"]),
                'totals' => $totals,
            ];
        }

        // ROLLUP emits level-2 rows before their level-1 subtotal; show each subtotal first.
        return self::subtotalsFirst($out, $depth);
    }

    /**
     * @param  list<array{level: int, keys: list<string|null>, label: string, totals: array<string, mixed>}>  $rows
     * @return list<array{level: int, keys: list<string|null>, label: string, totals: array<string, mixed>}>
     */
    private static function subtotalsFirst(array $rows, int $depth): array
    {
        if ($depth === 1) {
            return array_slice($rows, 0, self::GROUP_LIMIT);
        }
        $result = [];
        $pending = [];
        foreach ($rows as $row) {
            if ($row['level'] === 2) {
                $pending[] = $row;

                continue;
            }
            $result[] = $row;
            array_push($result, ...$pending);
            $pending = [];
        }

        return array_slice($result, 0, self::GROUP_LIMIT);
    }
}
```

- [ ] **Step 5: Engine fills groups** — in `Engine::run()`, after computing `$grand`:

```php
        $groups = Aggregator::groups(clone $query, $fields, $def);
```

and pass `groups: $groups` to `ReportResult`.

- [ ] **Step 6: Run** → PASS. If MySQL orders ROLLUP rows differently, sort `$out` by `keys` before `subtotalsFirst` (string compare, NULL last).

### Task 12: Builder UI for grouping and totals; group rows in the table and in Excel

**Files:** Modify `Builder.php` view section, `ExcelWriter.php`; Test: extend `BuilderScreenTest.php` and `ExportAndListTest.php`.

- [ ] **Step 1: Failing tests**

```php
test('grouping shows subtotal rows and Summary only hides detail rows', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('def.groups', [['field' => 'building', 'bucket' => null]])->set('def.totals', ['balance' => 'sum'])
        ->assertSee('AAA')->assertSee('400.000')
        ->set('def.summary_only', true)->assertDontSee('Alpha Tenant');
});
```

```php
test('the Excel file carries group subtotal rows', function () {
    $result = app(App\Reports\Builder\Engine::class)->run($this->finance, App\Reports\Builder\ReportDefinition::fromArray(['subject' => 'invoices',
        'columns' => ['number', 'balance'], 'groups' => [['field' => 'status']], 'totals' => ['balance' => 'sum']], $this->finance), limit: null);
    $path = sys_get_temp_dir().'/t-'.uniqid().'.xlsx';
    App\Reports\Builder\ExcelWriter::write($result, $path);
    $rows = Spatie\SimpleExcel\SimpleExcelReader::create($path)->getRows()->all();

    expect(collect($rows)->pluck('Invoice')->filter(fn ($v) => str_starts_with((string) $v, 'Subtotal'))->count())->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Builder panel controls** — add after Sort in the view:

```blade
            <flux:fieldset>
                <flux:legend>{{ __('Group by') }}</flux:legend>
                @foreach ([0, 1] as $level)
                    @php($g = $def['groups'][$level] ?? null)
                    @if ($level === 0 || isset($def['groups'][0]))
                        <div class="flex gap-1" wire:key="group-{{ $level }}">
                            <flux:select wire:model.live="def.groups.{{ $level }}.field" size="sm" :aria-label="__('Group level :n', ['n' => $level + 1])">
                                <option value="">{{ $level === 0 ? __('No grouping') : __('No second level') }}</option>
                                @foreach ($fields as $f)@if ($f->groupable)<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach
                            </flux:select>
                            @if ($g && ($fields[$g['field']] ?? null)?->type->value === 'date')
                                <flux:select wire:model.live="def.groups.{{ $level }}.bucket" size="sm" :aria-label="__('Date grouping')">
                                    @foreach (['day' => __('Day'), 'month' => __('Month'), 'quarter' => __('Quarter'), 'year' => __('Year')] as $b => $l)<option value="{{ $b }}">{{ $l }}</option>@endforeach
                                </flux:select>
                            @endif
                        </div>
                    @endif
                @endforeach
                <flux:checkbox wire:model.live="def.summary_only" :label="__('Summary only (hide detail rows)')" />
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Totals') }}</flux:legend>
                @foreach ($def['columns'] ?? [] as $key)
                    @if (($fields[$key] ?? null)?->type->numeric())
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <span>{{ $fields[$key]->label }}</span>
                            <flux:select wire:model.live="def.totals.{{ $key }}" size="sm" class="max-w-32" :aria-label="__('Total for :c', ['c' => $fields[$key]->label])">
                                <option value="">{{ __('None') }}</option>
                                <option value="sum">{{ __('Sum') }}</option><option value="avg">{{ __('Average') }}</option>
                                <option value="min">{{ __('Minimum') }}</option><option value="max">{{ __('Maximum') }}</option>
                            </flux:select>
                        </div>
                    @endif
                @endforeach
            </flux:fieldset>
```

Normalise the arrays the selects produce before validating: in `Builder::render()` (and `save`/`export`), call a private `normalised(): array` that drops `groups` entries with an empty field, drops `totals` with `''`, and turns a date group's missing bucket into `'month'`:

```php
    /** @return array<string, mixed> the edited definition with empty picker rows removed */
    private function normalised(): array
    {
        $def = $this->def;
        $def['groups'] = array_values(array_filter((array) ($def['groups'] ?? []), fn ($g) => filled($g['field'] ?? null)));
        foreach ($def['groups'] as $i => $g) {
            $def['groups'][$i]['bucket'] = $g['bucket'] ?? null;
        }
        $def['totals'] = array_filter((array) ($def['totals'] ?? []), fn ($fn, $key) => filled($fn) && in_array($key, $def['columns'] ?? [], true), ARRAY_FILTER_USE_BOTH);
        $def['filters'] = array_values((array) ($def['filters'] ?? []));

        return $def;
    }
```

Replace every `ReportDefinition::fromArray($this->def, …)` in the component with `ReportDefinition::fromArray($this->normalised(), …)`. When a date field is chosen as a group, `updatedDef` sets its bucket: add to `updatedDef`:

```php
        if ($key !== null && preg_match('/^groups\.(\d+)\.field$/', $key, $m) === 1) {
            $this->def['groups'][(int) $m[1]]['bucket'] = null;
        }
```

and `ReportDefinition` treats a date group without bucket as an error — so `normalised()` defaults it: `$def['groups'][$i]['bucket'] = $g['bucket'] ?? (($subjectFields[$g['field']] ?? null)?->type === FieldType::Date ? 'month' : null);` (load `$subjectFields` from the current subject at the top of `normalised()`).

- [ ] **Step 3: Grouped table** — when `$result->groups !== []`, render group rows interleaved: before the detail `@forelse`, add a grouped path:

```blade
                            @if ($result->groups !== [])
                                @foreach ($result->groups as $gi => $g)
                                    <flux:table.row :key="'g'.$gi" class="bg-zinc-50 font-medium dark:bg-zinc-800/60">
                                        @foreach ($result->columns as $ci => $col)
                                            <flux:table.cell @class(['text-end tabular-nums' => $col->type->numeric(), 'ps-8' => $ci === 0 && $g['level'] === 2])>
                                                @if ($ci === 0)
                                                    {{ $g['label'] }} <span class="text-xs font-normal text-zinc-500">({{ number_format($g['totals']['count']) }})</span>
                                                @else
                                                    @foreach ($g['totals'] as $tk => $tv)@if (str_starts_with($tk, $col->key.':')){{ App\Reports\Builder\Formatter::total($col, explode(':', $tk)[1], $tv) }}@endif @endforeach
                                                @endif
                                            </flux:table.cell>
                                        @endforeach
                                    </flux:table.row>
                                    @unless ($def['summary_only'] ?? false)
                                        @foreach (collect($result->rows)->filter(fn ($row) => App\Reports\Builder\Grouping::rowKeys($row, $result) === $g['keys'] && $g['level'] === count($def['groups'])) as $ri => $row)
                                            <flux:table.row :key="'g'.$gi.'r'.$ri">
                                                @foreach ($result->columns as $col)
                                                    <flux:table.cell @class(['text-end tabular-nums' => $col->type->numeric()])>{{ Formatter::display($col, $row[$col->key] ?? null) }}</flux:table.cell>
                                                @endforeach
                                            </flux:table.row>
                                        @endforeach
                                    @endunless
                                @endforeach
                            @else
                                {{-- the existing @forelse detail rows --}}
                            @endif
```

To match detail rows to their deepest group, the Engine selects the group keys with the detail rows: in `Engine::run()` add to the detail select `DB::raw(Grouping::expression(...)." as `__g{$i}`")` for each group, and add to `Grouping`:

```php
    /**
     * The group keys a detail row belongs to (the `__g0`, `__g1` columns the Engine adds).
     *
     * @param  array<string, mixed>  $row
     * @return list<string|null>
     */
    public static function rowKeys(array $row, ReportResult $result): array
    {
        $keys = [];
        for ($i = 0; array_key_exists("__g{$i}", $row); $i++) {
            $keys[] = $row["__g{$i}"] === null ? null : (string) $row["__g{$i}"];
        }

        return $keys;
    }
```

Close the table with a grand-total row in both paths:

```blade
                            <flux:table.row class="font-semibold">
                                @foreach ($result->columns as $ci => $col)
                                    <flux:table.cell @class(['text-end tabular-nums' => $col->type->numeric()])>
                                        @if ($ci === 0){{ __('Total') }}@else @foreach ($result->grandTotals as $tk => $tv)@if (str_starts_with($tk, $col->key.':')){{ Formatter::total($col, explode(':', $tk)[1], $tv) }}@endif @endforeach @endif
                                    </flux:table.cell>
                                @endforeach
                            </flux:table.row>
```

- [ ] **Step 4: ExcelWriter subtotal rows** — in `write()`, when `$result->groups !== []`, write each group row before its detail rows using the same matching (`Grouping::rowKeys`), with the first column `Subtotal: <label> (<count>)` (level-2 labels prefixed with two spaces) and totals in their columns as floats. Grand total row last (existing code).

- [ ] **Step 5: Run** stage tests → PASS.

### Task 13: Charts from grouped reports

**Files:** Modify `Builder` view (chart controls + `<x-report-chart>`), add `Builder::chartSpec()`; Test: extend `BuilderScreenTest.php`.

**Interfaces:** Consumes `<x-report-chart :chart="…">` (`type` bars|columns, `title`, `items` [label, value, display, short?], optional `caption`).

- [ ] **Step 1: Failing test**

```php
test('a grouped report draws the chosen total as a chart', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('def.groups', [['field' => 'building', 'bucket' => null]])->set('def.totals', ['balance' => 'sum'])
        ->set('def.chart', ['type' => 'bars', 'total' => 'balance:sum'])
        ->assertSeeHtml('<figure class="viz')->assertSee('Balance (sum) by Building');
});
```

- [ ] **Step 2: chartSpec** — private method building the component spec from level-1 groups:

```php
    /** @return array<string, mixed>|null */
    private function chartSpec(?ReportResult $result, array $fields): ?array
    {
        $chart = $this->def['chart'] ?? null;
        if ($result === null || $chart === null || $result->groups === [] || ! isset($chart['total'])) {
            return null;
        }
        [$key, $fn] = array_pad(explode(':', $chart['total'], 2), 2, 'count');
        $field = $fields[$key] ?? null;
        $level1 = array_values(array_filter($result->groups, fn ($g) => $g['level'] === 1));
        $groupField = $fields[$this->def['groups'][0]['field']] ?? null;

        return [
            'type' => $chart['type'] === 'columns' ? 'columns' : 'bars',
            'title' => ($chart['total'] === 'count' ? __('Count') : $field?->label.' ('.__($fn).')').' '.__('by').' '.$groupField?->label,
            'items' => array_map(fn ($g) => ['label' => $g['label'], 'short' => $g['label'], 'value' => (float) $g['totals'][$chart['total']],
                'display' => Formatter::total($field, $chart['total'] === 'count' ? 'count' : $fn, $g['totals'][$chart['total']])], $level1),
        ];
    }
```

Pass `'chart' => $this->chartSpec($result, $fields)` to the view; render `@if ($chart)<x-report-chart :chart="$chart" />@endif` above the table. Panel control (only when grouped):

```blade
            @if (! empty($def['groups']))
                <div class="grid grid-cols-2 gap-2">
                    <flux:select wire:model.live="def.chart.type" :label="__('Chart')">
                        <option value="">{{ __('None') }}</option><option value="bars">{{ __('Bars') }}</option><option value="columns">{{ __('Columns') }}</option>
                    </flux:select>
                    <flux:select wire:model.live="def.chart.total" :label="__('Chart value')">
                        <option value="count">{{ __('Count of rows') }}</option>
                        @foreach (array_filter($def['totals'] ?? []) as $k => $fn)<option value="{{ $k }}:{{ $fn }}">{{ $fields[$k]->label }} ({{ __($fn) }})</option>@endforeach
                    </flux:select>
                </div>
            @endif
```

In `normalised()`: `$def['chart'] = filled($def['chart']['type'] ?? null) && $def['groups'] !== [] ? ['type' => $def['chart']['type'], 'total' => $def['chart']['total'] ?? 'count'] : null;`.

- [ ] **Step 3: Run** → PASS. Build CSS; render the builder in the browser preview with dev data (the preview script approach used for the reports: a tinker script that `Auth::login`s an Admin and saves `app()->handle(Request::create('http://127.0.0.1:8123/reports/builder'))` to `public/zz-*.html`) and look at it, light and dark; delete the preview file.
- [ ] **Step 4: Full suite, Pint, phpstan; commit (when asked)** — `git commit -m "Report builder: grouping, totals and charts"`.

---

# Stage 4 — period comparison

### Task 14: Comparison engine and UI

**Files:**
- Create: `app/Reports/Builder/Comparison.php`
- Modify: `Engine.php` (fill `comparison`), Builder view and `chartSpec()`, `ExcelWriter.php`
- Test: `tests/Feature/ReportBuilder/ComparisonTest.php`

**Interfaces:**
- Produces: `Comparison::run(User $user, ReportDefinition $def, CarbonImmutable $today): ?array{label: string, compareLabel: string, rows: list<array{label: string, level: int, keys: list<string|null>, current: array<string, mixed>, previous: array<string, mixed>}>}` — rows = grand total (ungrouped) or level-1 groups (grouped; level 2 is not compared); `Comparison::change(mixed $current, mixed $previous): array{diff: string, pct: ?string}`.

- [ ] **Step 1: Failing test** (payments fixture: 300 received 2026-10-05 in AAA, 100 received 2026-09-10 in AAA, 50 received 2025-10-07 in BBB; today 2026-10-15; build with `RecordPayment` on issued invoices like `EngineTest`)

```php
test('previous period: this month against last month, per building, with change and change %', function () {
    $r = runReport($this->finance, ['subject' => 'payments', 'columns' => ['number', 'amount'], 'totals' => ['amount' => 'sum'],
        'period' => ['field' => 'received_on', 'preset' => 'this_month'], 'groups' => [['field' => 'tenant']], 'compare' => 'previous']);

    expect($r->comparison['label'])->toBe('01/10/2026 – 31/10/2026')->and($r->comparison['compareLabel'])->toBe('01/09/2026 – 30/09/2026');
    $row = collect($r->comparison['rows'])->firstWhere('label', 'Alpha Tenant');
    expect($row['current']['amount:sum'])->toBe('300.000')->and($row['previous']['amount:sum'])->toBe('100.000')
        ->and(App\Reports\Builder\Comparison::change('300.000', '100.000'))->toBe(['diff' => '200.000', 'pct' => '200.0']);
});

test('a group in only one period shows 0 for the other; change % is blank against 0', function () {
    $r = runReport($this->finance, ['subject' => 'payments', 'columns' => ['number', 'amount'], 'totals' => ['amount' => 'sum'],
        'period' => ['field' => 'received_on', 'preset' => 'this_month'], 'groups' => [['field' => 'tenant']], 'compare' => 'last_year']);
    $beta = collect($r->comparison['rows'])->firstWhere('label', 'Beta Tenant');

    expect($beta['current']['amount:sum'])->toBe('0.000')->and($beta['previous']['amount:sum'])->toBe('50.000')
        ->and(App\Reports\Builder\Comparison::change('10.000', '0.000')['pct'])->toBeNull();
});
```

- [ ] **Step 2: Comparison**

```php
<?php

namespace App\Reports\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;

/** Spec §6: the same totals for a second period, matched by group key. */
final class Comparison
{
    /** @return array{label: string, compareLabel: string, rows: list<array<string, mixed>>}|null */
    public static function run(User $user, ReportDefinition $def, CarbonImmutable $today): ?array
    {
        $range = QueryCompiler::periodRange($def, $today);
        if ($def->compare === null || $range === null) {
            return null;
        }
        $other = $def->compare === 'previous' ? Period::previous(...$range) : Period::lastYear(...$range);
        $levelOne = $def->groups === [] ? null : [$def->groups[0]];

        $collect = function (array $r) use ($user, $def, $levelOne): array {
            [$query, $fields] = QueryCompiler::compile($user, $def, $r);
            if ($levelOne === null) {
                return ['' => ['label' => __('Total'), 'keys' => [], 'totals' => app(Engine::class)->totals($query, $fields, $def->totals)]];
            }
            $single = ReportDefinition::fromArray([...$def->toArray(), 'groups' => $levelOne, 'chart' => null, 'compare' => null], $user);
            $out = [];
            foreach (Aggregator::groups($query, $fields, $single) as $g) {
                $out[(string) ($g['keys'][0] ?? '')] = $g;
            }

            return $out;
        };

        $current = $collect($range);
        $previous = $collect($other);
        $zero = ['count' => 0];
        foreach ($def->totals as $key => $fn) {
            $zero["{$key}:{$fn}"] = $fn === 'sum' ? '0.000' : null;
        }

        $rows = [];
        foreach (array_unique([...array_keys($current), ...array_keys($previous)]) as $k) {
            $rows[] = [
                'label' => ($current[$k] ?? $previous[$k])['label'],
                'keys' => ($current[$k] ?? $previous[$k])['keys'],
                'current' => array_replace($zero, (array) ($current[$k]['totals'] ?? [])),
                'previous' => array_replace($zero, (array) ($previous[$k]['totals'] ?? [])),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp((string) ($a['keys'][0] ?? ''), (string) ($b['keys'][0] ?? '')));

        return ['label' => Period::label(...$range), 'compareLabel' => Period::label(...$other), 'rows' => $rows];
    }

    /** @return array{diff: string, pct: ?string} */
    public static function change(mixed $current, mixed $previous): array
    {
        $c = (float) ($current ?? 0);
        $p = (float) ($previous ?? 0);

        return ['diff' => number_format($c - $p, 3, '.', ''), 'pct' => $p == 0.0 ? null : number_format(($c - $p) / abs($p) * 100, 1, '.', '')];
    }
}
```

`Aggregator::groups()` returns the level-1 row with `keys[0]` and `label`; `totals` keyed `count` and `field:fn`. Sum totals from the aggregator come back as `'300.000'` strings; when a sum is missing in one period the zero row supplies `'0.000'`. Fix the zero row to also format `count` as 0 (it does).

- [ ] **Step 3: Engine** — `comparison: Comparison::run($user, $def, CarbonImmutable::now('Asia/Bahrain'))` in the result.

- [ ] **Step 4: UI** — panel control (only when the subject has periods and a period is set):

```blade
            @if ($subject->periodFields() !== [])
                <flux:select wire:model.live="def.compare" :label="__('Compare with')">
                    <option value="">{{ __('Nothing') }}</option>
                    <option value="previous">{{ __('Previous period') }}</option>
                    <option value="last_year">{{ __('Same period last year') }}</option>
                </flux:select>
            @endif
```

`normalised()`: `$def['compare'] = filled($def['compare'] ?? null) ? $def['compare'] : null;` and when comparing with no totals chosen, default to `count` only (allowed: the comparison table then shows Count). Results area, above the table when `$result->comparison`:

```blade
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Compared') }}</flux:table.column>
                                @foreach (['count' => __('Rows'), ...collect($def['totals'] ?? [])->mapWithKeys(fn ($fn, $k) => ["{$k}:{$fn}" => $fields[$k]->label.' ('.__($fn).')'])->all()] as $tk => $tl)
                                    <flux:table.column align="end">{{ $tl }}<br><span class="text-xs font-normal">{{ $result->comparison['label'] }} · {{ $result->comparison['compareLabel'] }} · {{ __('Change') }}</span></flux:table.column>
                                @endforeach
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($result->comparison['rows'] as $ri => $cr)
                                    <flux:table.row :key="'c'.$ri">
                                        <flux:table.cell>{{ $cr['label'] }}</flux:table.cell>
                                        @foreach (array_keys(['count' => 1, ...collect($def['totals'] ?? [])->mapWithKeys(fn ($fn, $k) => ["{$k}:{$fn}" => 1])->all()]) as $tk)
                                            @php($ch = App\Reports\Builder\Comparison::change($cr['current'][$tk] ?? 0, $cr['previous'][$tk] ?? 0))
                                            <flux:table.cell class="text-end tabular-nums whitespace-nowrap">
                                                {{ $tk === 'count' ? number_format((int) $cr['current'][$tk]) : number_format((float) $cr['current'][$tk], 3) }}
                                                · {{ $tk === 'count' ? number_format((int) $cr['previous'][$tk]) : number_format((float) $cr['previous'][$tk], 3) }}
                                                · {{ (float) $ch['diff'] >= 0 ? '▲' : '▼' }} {{ number_format(abs((float) $ch['diff']), $tk === 'count' ? 0 : 3) }}@if ($ch['pct'] !== null) ({{ $ch['pct'] }}%)@endif
                                            </flux:table.cell>
                                        @endforeach
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
```

Chart with comparison: in `chartSpec()`, when `$result->comparison` is set use `type` `bars` items in pairs — `[label, value current]` then `[label.' ('.__('previous').')', value previous, 'color' => '--viz-age-1']` — so the two periods sit side by side in two shades; add a caption naming both period labels.

Excel: after the grand total, a blank row then a "Comparison" block: header (Compared, then per total: this period, comparison period, change, change %), one row per comparison row.

- [ ] **Step 5: Run** → PASS; full suite; Pint; phpstan; commit (when asked) — `git commit -m "Report builder: period comparison"`.

---

# Stage 5 — scheduled email

### Task 15: Schedules tables, models and the next-run calculation

**Files:**
- Create: `database/migrations/2026_12_16_000200_create_report_schedules_tables.php`, `app/Models/ReportSchedule.php`, `app/Models/ReportDelivery.php`, `app/Reports/Builder/NextRun.php`
- Test: `tests/Feature/ReportBuilder/NextRunTest.php`

**Interfaces:**
- Produces: `ReportSchedule` (`saved_report_id`, `frequency` daily|weekly|monthly, `day` ?int (weekly 0–6 Sunday=0; monthly 1–28 or 0 = last day), `hour` 0–23, `next_run_at`, `consecutive_failures`, `paused_at`, `created_by`; relation `recipients()` BelongsToMany User via `report_schedule_user`; `report()`); `ReportDelivery` (`report_schedule_id`, `due_at`, `status` sent|failed|skipped, `recipients_count`, `error`, `sent_at`); `NextRun::after(ReportSchedule|array $schedule, CarbonImmutable $from): CarbonImmutable` (Asia/Bahrain wall time, returned in UTC).

- [ ] **Step 1: Failing test**

```php
<?php

use App\Reports\Builder\NextRun;
use Carbon\CarbonImmutable;

$at = fn (string $s) => CarbonImmutable::parse($s, 'Asia/Bahrain');
$bh = fn (CarbonImmutable $c) => $c->timezone('Asia/Bahrain')->format('Y-m-d H:i');

test('daily, weekly and monthly runs in Bahrain time', function () use ($at, $bh) {
    expect($bh(NextRun::after(['frequency' => 'daily', 'day' => null, 'hour' => 7], $at('2026-10-15 06:00'))))->toBe('2026-10-15 07:00')
        ->and($bh(NextRun::after(['frequency' => 'daily', 'day' => null, 'hour' => 7], $at('2026-10-15 07:00'))))->toBe('2026-10-16 07:00')
        ->and($bh(NextRun::after(['frequency' => 'weekly', 'day' => 0, 'hour' => 8], $at('2026-10-15 10:00'))))->toBe('2026-10-18 08:00') // next Sunday
        ->and($bh(NextRun::after(['frequency' => 'monthly', 'day' => 1, 'hour' => 7], $at('2026-10-15 10:00'))))->toBe('2026-11-01 07:00')
        ->and($bh(NextRun::after(['frequency' => 'monthly', 'day' => 0, 'hour' => 18], $at('2027-02-10 10:00'))))->toBe('2027-02-28 18:00'); // last day
});
```

- [ ] **Step 2: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_report_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 10);
            $table->unsignedTinyInteger('day')->nullable();
            $table->unsignedTinyInteger('hour');
            $table->timestamp('next_run_at');
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['paused_at', 'next_run_at']);
        });
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_frequency_chk CHECK (frequency IN ('daily', 'weekly', 'monthly'))");
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_hour_chk CHECK (hour <= 23)');

        Schema::create('report_schedule_user', function (Blueprint $table) {
            $table->foreignId('report_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['report_schedule_id', 'user_id']);
        });

        // One row per schedule per due time: the guarantee against sending twice (spec §7).
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_schedule_id')->constrained()->cascadeOnDelete();
            $table->timestamp('due_at');
            $table->string('status', 10);
            $table->unsignedSmallInteger('recipients_count')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['report_schedule_id', 'due_at']);
        });
        DB::statement("ALTER TABLE report_deliveries ADD CONSTRAINT report_deliveries_status_chk CHECK (status IN ('sending', 'sent', 'failed', 'skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_schedule_user');
        Schema::dropIfExists('report_schedules');
    }
};
```

(`sending` is the claimed-but-not-finished state the runner writes first.)

- [ ] **Step 3: Models**

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $saved_report_id
 * @property string $frequency
 * @property int|null $day
 * @property int $hour
 * @property CarbonImmutable $next_run_at
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $paused_at
 * @property int $created_by
 */
class ReportSchedule extends Model
{
    protected $fillable = ['saved_report_id', 'frequency', 'day', 'hour', 'next_run_at', 'consecutive_failures', 'paused_at', 'created_by'];

    protected function casts(): array
    {
        return ['next_run_at' => 'immutable_datetime', 'paused_at' => 'immutable_datetime', 'day' => 'integer', 'hour' => 'integer'];
    }

    /** @return BelongsTo<SavedReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class, 'saved_report_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'report_schedule_user');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $report_schedule_id
 * @property CarbonImmutable $due_at
 * @property string $status sending | sent | failed | skipped
 * @property int $recipients_count
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 */
class ReportDelivery extends Model
{
    protected $fillable = ['report_schedule_id', 'due_at', 'status', 'recipients_count', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
```

Add to `SavedReport`: `public function schedules(): HasMany { return $this->hasMany(ReportSchedule::class); }` (soft-deleting a report must also remove schedules — in `Builder::deleteReport()` call `$report->schedules()->delete()` before `$report->delete()`).

- [ ] **Step 4: NextRun**

```php
<?php

namespace App\Reports\Builder;

use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;

/** The next due time strictly after $from, at the schedule's hour in Asia/Bahrain (spec §7). */
final class NextRun
{
    /** @param  ReportSchedule|array{frequency: string, day: ?int, hour: int}  $s */
    public static function after(ReportSchedule|array $s, CarbonImmutable $from): CarbonImmutable
    {
        $frequency = is_array($s) ? $s['frequency'] : $s->frequency;
        $day = is_array($s) ? $s['day'] : $s->day;
        $hour = is_array($s) ? $s['hour'] : $s->hour;
        $local = $from->timezone('Asia/Bahrain');
        $candidate = $local->setTime($hour, 0);

        $next = match ($frequency) {
            'daily' => $candidate->greaterThan($local) ? $candidate : $candidate->addDay(),
            'weekly' => self::weekly($candidate, $local, (int) $day),
            'monthly' => self::monthly($local, (int) $day, $hour),
        };

        return $next->utc();
    }

    private static function weekly(CarbonImmutable $candidate, CarbonImmutable $local, int $day): CarbonImmutable
    {
        $shift = ($day - $candidate->dayOfWeek + 7) % 7;
        $at = $candidate->addDays($shift);

        return $at->greaterThan($local) ? $at : $at->addWeek();
    }

    private static function monthly(CarbonImmutable $local, int $day, int $hour): CarbonImmutable
    {
        $in = fn (CarbonImmutable $month) => ($day === 0 ? $month->endOfMonth() : $month->startOfMonth()->addDays($day - 1))->setTime($hour, 0);
        $at = $in($local->startOfMonth());

        return $at->greaterThan($local) ? $at : $in($local->startOfMonth()->addMonthNoOverflow());
    }
}
```

- [ ] **Step 5: Migrate dev DB; run test** → PASS.

### Task 16: The runner command, the job and the email

**Files:**
- Create: `app/Console/Commands/SendScheduledReports.php`, `app/Jobs/SendScheduledReport.php`, `app/Notifications/ScheduledReport.php`, `app/Notifications/ScheduledReportFailed.php`
- Modify: `routes/console.php`, `config/services.php` (heartbeat), `.env.example` (`HEARTBEAT_REPORT_SCHEDULES=`), `docs/new-company-setup.md` (heartbeat count 13)
- Test: `tests/Feature/ReportBuilder/ScheduledSendTest.php`

**Interfaces:**
- Consumes: `ReportSchedule`, `ReportDelivery`, `NextRun`, `Engine`, `ReportDefinition`, `ExcelWriter`, `Audit`.
- Produces: command `rms:reports:send-scheduled`; job `SendScheduledReport(int $deliveryId)`; notifications `ScheduledReport(string $reportName, string $periodLabel, int $rows, array $totals, string $path, bool $attach, string $url)`, `ScheduledReportFailed(string $reportName, string $reason)`; audit `report.sent`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Notifications\ScheduledReport;
use App\Notifications\ScheduledReportFailed;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 07:05', 'Asia/Bahrain'));
    $this->a = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);
    $this->report = SavedReport::create(['name' => 'Monthly collections', 'subject' => 'payments', 'visibility' => 'shared',
        'definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['number', 'amount'], 'period' => ['field' => 'received_on', 'preset' => 'last_month'],
            'totals' => ['amount' => 'sum'], 'sort' => ['field' => 'number', 'dir' => 'asc']], 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id]);
    $this->schedule = ReportSchedule::create(['saved_report_id' => $this->report->id, 'frequency' => 'monthly', 'day' => 1, 'hour' => 7,
        'next_run_at' => CarbonImmutable::parse('2026-11-01 07:00', 'Asia/Bahrain')->utc(), 'created_by' => $this->finance->id]);
    $this->schedule->recipients()->attach([$this->finance->id, $this->leasing->id]);
});

test('a due schedule sends once to each recipient who may see the subject, then moves to the next run', function () {
    $this->artisan('rms:reports:send-scheduled')->assertSuccessful();
    $this->artisan('rms:reports:send-scheduled')->assertSuccessful(); // a second run in the same window sends nothing

    Notification::assertSentTo($this->finance, ScheduledReport::class, fn ($n) => $n->periodLabel === '01/10/2026 – 31/10/2026');
    Notification::assertNotSentTo($this->leasing, ScheduledReport::class); // payments need financial reports
    expect(ReportDelivery::sole())->status->toBe('sent')->recipients_count->toBe(1)
        ->and($this->schedule->fresh()->next_run_at->timezone('Asia/Bahrain')->format('Y-m-d H:i'))->toBe('2026-12-01 07:00')
        ->and(Spatie\Activitylog\Models\Activity::where('event', 'report.sent')->count())->toBe(1);
});

test('three failures in a row pause the schedule and tell its creator; a deactivated creator pauses it', function () {
    $this->report->update(['definition' => ['version' => 1, 'subject' => 'payments', 'columns' => ['nope']]]);
    foreach (range(1, 3) as $i) {
        $this->artisan('rms:reports:send-scheduled');
        $this->travel(1)->months();
        $this->schedule->refresh();
    }

    Notification::assertSentTo($this->finance, ScheduledReportFailed::class);
    expect($this->schedule->fresh()->paused_at)->not->toBeNull();

    $other = ReportSchedule::create(['saved_report_id' => $this->report->id, 'frequency' => 'daily', 'hour' => 7, 'next_run_at' => now()->subMinute(), 'created_by' => $this->finance->id]);
    $this->finance->update(['active' => false]);
    $this->artisan('rms:reports:send-scheduled');
    expect($other->fresh()->paused_at)->not->toBeNull();
});
```

- [ ] **Step 2: Command**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledReport;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Reports\Builder\NextRun;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Spec §7: claim every due, unpaused schedule once (unique schedule + due time), queue its send, move it on. */
class SendScheduledReports extends Command
{
    protected $signature = 'rms:reports:send-scheduled';

    protected $description = 'Send saved reports whose email schedule is due.';

    public function handle(): int
    {
        $due = ReportSchedule::query()->whereNull('paused_at')->where('next_run_at', '<=', now())->with(['creator', 'report'])->get();
        foreach ($due as $schedule) {
            if ($schedule->report === null || ! $schedule->creator?->active) {
                $schedule->update(['paused_at' => now()]);

                continue;
            }
            $dueAt = $schedule->next_run_at;
            try {
                $delivery = DB::transaction(function () use ($schedule, $dueAt) {
                    $delivery = ReportDelivery::create(['report_schedule_id' => $schedule->id, 'due_at' => $dueAt, 'status' => 'sending']);
                    $schedule->update(['next_run_at' => NextRun::after($schedule, now()->toImmutable())]);

                    return $delivery;
                });
            } catch (QueryException) {
                continue; // already claimed for this due time: never send twice
            }
            SendScheduledReport::dispatch($delivery->id);
        }
        $this->info(sprintf('%d schedule(s) due.', $due->count()));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Job**

```php
<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Notifications\ScheduledReport;
use App\Notifications\ScheduledReportFailed;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Builder\Formatter;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/** Runs the report as each recipient (their permissions and buildings) and emails it (spec §7). */
class SendScheduledReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int ATTACH_LIMIT = 10 * 1024 * 1024;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(Engine $engine): void
    {
        $delivery = ReportDelivery::findOrFail($this->deliveryId);
        if ($delivery->status !== 'sending') {
            return;
        }
        $schedule = ReportSchedule::with(['report', 'recipients', 'creator'])->findOrFail($delivery->report_schedule_id);
        $report = $schedule->report;
        $sent = 0;
        try {
            foreach ($schedule->recipients as $user) {
                if (! $user->active || $user->isVendorSupport() || ! isset(Registry::forUser($user)[$report->subject])) {
                    continue;
                }
                $def = ReportDefinition::fromArray($report->definition, $user);
                $result = $engine->run($user, $def, limit: null);
                $path = storage_path('app/private/report-mail/'.Str::uuid().'.xlsx');
                @mkdir(dirname($path), 0775, true);
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
                    $totals[($f?->label ?? $field).' ('.__($fn).')'] = Formatter::total($f, $fn, $value);
                }
                $user->notify(new ScheduledReport($report->name, (string) $result->periodLabel, $result->rowCount, $totals, $path,
                    filesize($path) <= self::ATTACH_LIMIT, route('reports.builder', $report)));
                $sent++;
            }
            $delivery->update(['status' => $sent > 0 ? 'sent' : 'skipped', 'recipients_count' => $sent, 'sent_at' => now()]);
            $schedule->update(['consecutive_failures' => 0]);
            Audit::log('report.sent', $report, properties: ['schedule' => $schedule->id, 'recipients' => $sent, 'due_at' => $delivery->due_at->toIso8601String()]);
        } catch (Throwable $e) {
            $failures = $schedule->consecutive_failures + 1;
            $delivery->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 490)]);
            $schedule->update(['consecutive_failures' => $failures, 'paused_at' => $failures >= 3 ? now() : null]);
            $schedule->creator?->notify(new ScheduledReportFailed($report->name, $failures >= 3
                ? __('It failed 3 times in a row and is paused. Open the report, fix it and schedule it again.')
                : __('This send failed: :e', ['e' => Str::limit($e->getMessage(), 200)])));
        }
    }
}
```

- [ ] **Step 4: Notifications**

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A scheduled report: totals in the body, Excel attached under 10 MB, a link to open it (spec §7). */
class ScheduledReport extends Notification
{
    use Queueable;

    /** @param  array<string, string>  $totals */
    public function __construct(public string $reportName, public string $periodLabel, public int $rows, public array $totals,
        public string $path, public bool $attach, public string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__(':name — :period', ['name' => $this->reportName, 'period' => $this->periodLabel]))
            ->line(__(':n rows.', ['n' => number_format($this->rows)]));
        foreach ($this->totals as $label => $value) {
            $mail->line("{$label}: {$value}");
        }
        if ($this->attach) {
            $mail->attach($this->path, ['as' => str($this->reportName)->slug().'.xlsx']);
        } else {
            $mail->line(__('The file is too large to attach; open the report to download it.'));
        }

        return $mail->action(__('Open the report'), $this->url);
    }
}
```

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScheduledReportFailed extends Notification
{
    use Queueable;

    public function __construct(public string $reportName, public string $reason) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('Scheduled report not sent: :name', ['name' => $this->reportName]))->line($this->reason);
    }
}
```

Clean up attachments: in the job, after notifying, schedule deletion is not possible with a sync file path — instead delete files older than one day in `rms:reports:send-scheduled` at its start: `collect(glob(storage_path('app/private/report-mail/*.xlsx')) ?: [])->filter(fn ($f) => filemtime($f) < now()->subDay()->getTimestamp())->each(fn ($f) => @unlink($f));`.

- [ ] **Step 5: Schedule and heartbeat** — `routes/console.php`:

```php
Schedule::command('rms:reports:send-scheduled')->everyFifteenMinutes()->withoutOverlapping(30)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.report_schedules')), (string) $url);
```

`config/services.php` heartbeats: `'report_schedules' => env('HEARTBEAT_REPORT_SCHEDULES'),`; add `HEARTBEAT_REPORT_SCHEDULES=` to `.env.example`; in `docs/new-company-setup.md` Appendix B change "`HEARTBEAT_*` (12 keys)" to 13.

- [ ] **Step 6: Run** → PASS.

### Task 17: Schedule email UI on the builder

**Files:** Modify `Builder.php` + view; Test: `tests/Feature/ReportBuilder/ScheduleScreenTest.php`.

**Interfaces:** Produces component state `public array $schedule = ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => []]`, actions `addSchedule()`, `removeSchedule(int $id)`; audit `report.scheduled`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, Building::factory()->create());
    $this->report = SavedReport::create(['name' => 'R', 'subject' => 'invoices', 'visibility' => 'private', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id,
        'definition' => ['version' => 1, 'subject' => 'invoices', 'columns' => ['number'], 'period' => ['field' => 'due_date', 'preset' => 'last_month'], 'sort' => ['field' => 'number', 'dir' => 'asc']]]);
});

test('the creator schedules a saved report to staff; vendor support and outsiders cannot be picked', function () {
    $vendor = User::factory()->withTwoFactor()->create()->assignRole(RoleName::VendorSupport);
    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $this->report])
        ->assertDontSee($vendor->name)
        ->set('schedule', ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => [$this->finance->id]])->call('addSchedule')->assertHasNoErrors();

    expect(ReportSchedule::sole())->frequency->toBe('monthly')->and(ReportSchedule::sole()->recipients->pluck('id')->all())->toBe([$this->finance->id]);

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $this->report])
        ->set('schedule', ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => [$vendor->id]])->call('addSchedule')->assertHasErrors('schedule.recipients');
});

test('a fixed date range warns that it will send the same dates every time', function () {
    $this->report->update(['definition' => [...$this->report->definition, 'period' => ['field' => 'due_date', 'preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']]]);
    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $this->report])->assertSee('will send the same dates every time');
});
```

- [ ] **Step 2: Actions**

```php
    /** @var array{frequency: string, day: int|null, hour: int, recipients: list<int>} */
    public array $schedule = ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'recipients' => []];

    public function addSchedule(): void
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('update', $report), 403);
        $staff = $this->staffIds();
        $this->validate([
            'schedule.frequency' => ['required', 'in:daily,weekly,monthly'],
            'schedule.day' => ['nullable', 'integer', 'between:0,28'],
            'schedule.hour' => ['required', 'integer', 'between:0,23'],
            'schedule.recipients' => ['required', 'array', 'min:1'],
            'schedule.recipients.*' => ['integer', 'in:'.implode(',', $staff)],
        ], ['schedule.recipients.*.in' => __('Pick staff from the list.'), 'schedule.recipients.required' => __('Pick at least one person.')]);
        $data = ['frequency' => $this->schedule['frequency'], 'day' => $this->schedule['frequency'] === 'daily' ? null : (int) $this->schedule['day'], 'hour' => (int) $this->schedule['hour']];
        $schedule = ReportSchedule::create([...$data, 'saved_report_id' => $report->id, 'created_by' => $this->actor()->id,
            'next_run_at' => NextRun::after($data, now()->toImmutable())]);
        $schedule->recipients()->sync($this->schedule['recipients']);
        Audit::log('report.scheduled', $report, properties: ['schedule' => $schedule->id, ...$data, 'recipients' => $this->schedule['recipients']], causer: $this->actor());
        Flux::toast(variant: 'success', text: __('Scheduled.'));
    }

    public function removeSchedule(int $id): void
    {
        $report = SavedReport::findOrFail((int) $this->reportId);
        abort_unless($this->actor()->can('update', $report), 403);
        $report->schedules()->whereKey($id)->delete();
    }

    /** @return list<int> active staff who may receive reports: never Vendor Support or the system user */
    private function staffIds(): array
    {
        return User::query()->where('active', true)->where('is_system', false)->get()
            ->reject(fn (User $u) => $u->isVendorSupport())->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
```

- [ ] **Step 3: View** — a "Schedule email" `flux:modal` opened from the top bar (saved reports only, `$canEdit`): frequency select; day select (weekly: Sunday–Saturday values 0–6; monthly: 1–28 and "Last day" value 0); hour select 00:00–23:00; recipient checkboxes from `User::whereIn('id', staffIds)->orderBy('name')`; Add button `wire:click="addSchedule"`; a list of existing schedules with recipients, next run (`next_run_at` shown in Asia/Bahrain dd/mm/yyyy HH:mm), "Paused" badge when `paused_at`, and a remove button. Show the fixed-range warning when `($def['period']['preset'] ?? null) === 'custom'`: `<flux:callout icon="information-circle" :heading="__('This report uses fixed dates, so a schedule will send the same dates every time.')" />`. Pass `schedules` (`$report->schedules()->with('recipients:id,name')->get()`) and `staff` to the view.

- [ ] **Step 4: Run** stage tests → PASS. Full suite, Pint, phpstan, `npm run build`, browser preview check, delete preview file.
- [ ] **Step 5: Commit (when asked)** — `git commit -m "Report builder: scheduled email"`.

### Task 18: Speed check and the user guide line

**Files:** Test: `tests/Feature/ReportBuilder/PerformanceTest.php`; Modify: `docs/new-company-setup.md` Appendix C (add `rms:reports:send-scheduled`).

- [ ] **Step 1: Test** — seeds 10,000 invoices with raw inserts (fast) and checks a grouped run time:

```php
<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

test('10,000 invoices grouped by building and month run in under 2 seconds', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $building = Building::factory()->create();
    $management = matrixUser(RoleName::Management, $building);
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit = Unit::factory()->for($building)->create()]);
    $rows = [];
    for ($i = 0; $i < 10000; $i++) {
        $rows[] = ['type' => 'manual', 'customer_id' => $agreement->customer_id, 'agreement_id' => $agreement->id, 'issue_date' => '2026-01-01',
            'due_date' => '2026-'.str_pad((string) (1 + $i % 12), 2, '0', STR_PAD_LEFT).'-01', 'status' => 'draft',
            'subtotal' => '10.000', 'tax_total' => '0.000', 'total' => '10.000', 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('invoices')->insert($chunk);
    }

    $start = microtime(true);
    $r = app(Engine::class)->run($management, ReportDefinition::fromArray(['subject' => 'invoices', 'columns' => ['number', 'total'],
        'groups' => [['field' => 'building'], ['field' => 'due_date', 'bucket' => 'month']], 'totals' => ['total' => 'sum'], 'summary_only' => true], $management));

    expect(microtime(true) - $start)->toBeLessThan(2.0)->and($r->grandTotals['total:sum'])->toBe('100000.000');
});
```

If the `invoices` table has more NOT NULL columns, add them to the insert (read `database/migrations/*create_payments*`/`*invoices*` for the list); draft invoices are allowed by the triggers.

- [ ] **Step 2: Run** → PASS (if slow, add an index on `invoice_lines(invoice_id, unit_id)` in a migration and re-run).
- [ ] **Step 3: Appendix C row** — `| php artisan rms:reports:send-scheduled | Emails saved reports whose schedule is due (also runs every 15 minutes). |`
- [ ] **Step 4: Full suite, Pint, phpstan; commit (when asked).**

---

## Self-review notes (done while writing)

- Spec coverage: §3 screens → Tasks 6–8, 12–14, 17; §4 subjects → Tasks 2, 3, 9, 10 (rulings above for statements, units, payments out); §4.3 Multiple → `Sql` helpers + Task 11 test; §4.4 periods → Task 4; §5 → Tasks 11–13; §6 → Task 14; §7 → Tasks 15–17; §8 → Tasks 1, 4, 5 (scope tests), 9 (field permission); §9 → Tasks 1, 15; §11 tests → each task + Task 18.
- Names are consistent across tasks: `ReportDefinition::fromArray/toArray/fresh`, `Engine::run/totals/aggregate`, `Aggregator::groups`, `Grouping::expression/label/rowKeys`, `Comparison::run/change`, `QueryCompiler::compile/periodRange`, `Formatter::display/total`, `NextRun::after`.
- One transcription note remains on purpose: DepositsHeld's unused `Sql` import and its figures-test expectation (Task 10 Step 6).
- The test assertion for the sent test in Task 16 uses the assertion `assertNotSentTo($this->leasing, …)`: Leasing has no financial reports, so the payments report is skipped for them.
