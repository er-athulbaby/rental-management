# M5b Reports, Dashboard and Scheduled Emails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each role the operational and financial reports of spec §10, a Management dashboard of 8 number tiles, and the scheduled 07:00 digest emails of §12. Also close the two M3b carry-overs.

**Architecture:** Builds on M5a (branch `m5-reports-golive`, head e23f4a8). The pieces fit together like this:
- **Report pages.** Each report is one Livewire page built on one shared trait, `ReportPage`. The trait supplies:
  - building and date filters, validated outside `render()`, which was the M4 lesson;
  - a generic table view;
  - an audited Excel export.
- **`App\Reports\Queries`.** The building-scoped counting queries live here as static query builders. Reports, dashboard tiles and digest emails all share them, so a number on the dashboard always equals the report it links to.
- **Digests.** These are three queued notifications sent by one command, each counted for the recipient's own buildings.

**Tech Stack:** as M5a; `spatie/simple-excel` for exports; Laravel notifications (mail) for digests.

**Spec:** `docs/superpowers/specs/2026-09-28-rental-management-v1-design.md`:
- §15 M5 row (the reports half);
- §10 (reports and dashboard);
- §12 (07:00 and Monday emails; recipients; scope; counts and links only);
- §8.1 and §8.2 (permissions, building scope);
- §8.4 (exports audited);
- §6.3 and §7.7 (the M3b carry-overs).

## Global Constraints

Everything in the M0–M5a Global Constraints still applies:
- Money is in integer fils (`App\Support\Fils`), with `DECIMAL(12,3)` columns. Times are Asia/Bahrain.
- Actions are the only write path. Report pages never write.
- Migrations run via `--database=migrator`. A new column on a guarded table does not change its trigger. This plan adds no triggers: `EXPECTED_TRIGGERS` stays **59**.
- Spec §10 (verbatim):
  - "Every report filters by building and date range, respects building assignment (§8.2), and exports to Excel (`spatie/simple-excel`)."
  - Operational reports need `reports.operational`. Financial reports need `reports.financial`.
  - The cheque reports "also need `cheques.manage` or `finance.view`".
- Dashboard: "8 number tiles, no charts; each tile needs its report's permission."
- Spec §12 (verbatim):
  - Daily 07:00: "Email Finance: cheques to deposit today/this week, bounces awaiting action".
  - Daily 07:00: "Email Management: pending approvals, agreements expiring in 30/60/90 days".
  - Monday 07:00: "Email: customer and owner ID documents expiring in the next 30 days".
  - "Email recipients are active users holding the relevant permission (`cheques.manage`, `approvals.decide`, `customers.manage`), excluding Vendor Support. Content is filtered by the recipient's building scope and contains counts and links only, never ID numbers."
- Every scheduled job is idempotent, uses `withoutOverlapping(120)` and pings a Forge heartbeat. Mail goes out through the queue (`ShouldQueue`).
- §8.4: exports are audited with `Audit::log('report.exported', …)`.
- English UI usable at 375 px. Free Flux only. Report tables sit in `overflow-x-auto`, and money columns are right-aligned with `tabular-nums`.
- Tests: Pest on MySQL as `rms_app`, run serially (never `--parallel`, never two runs at once). Users holding Finance/Management/Admin permissions need `->withTwoFactor()`.

## Rulings made while planning

1. **One report page pattern.** Every report is a Livewire component using `App\Livewire\Reports\Concerns\ReportPage`. A report supplies:
   - `title()`, `permission()`, `columns()` and `rows()`;
   - optionally, extra select filters (`options()`) and a date mode (`range`, `single` or `none`).

   The trait handles the shared parts: filters, validation outside `render()` (an invalid filter shows an error and an empty table, never a 500), the table view, and the audited Excel export.
2. **Shared queries.** `App\Reports\Queries` holds the scoped query builders that reports, the dashboard and the digests share. Every builder takes the viewing `User` and applies building scope.
3. **Weeks run Monday to Sunday.** "This week" (cheques to deposit, dashboard) is the Monday–Sunday week containing today (Asia/Bahrain).
4. **"Bounced cheques awaiting action"** = received cheques with status `bounced`. Replacing or returning one changes its status, so it leaves the list.
5. **"Held cheques to return" is an explicit flag** (M3b carry-over). The flag is `cheques.to_return`:
   - Re-billing sets it when it leaves a cheque with no invoice.
   - Cheques that never had an invoice (for example, imported ones) are not listed.
   - A held cheque leaves the list when it is returned (`EndCheque`).
6. **Digests are only sent when there is something to report.** A recipient whose counts are all zero gets no email that day. The heartbeat pings after every run.
7. **ID documents** are documents in the categories `id_copy` and `cr_copy` with an `expires_on`, attached to a customer or an owner.
   - Customers are scoped by building (`Customer::visibleTo`).
   - Owners need `owners.view` and are not building-scoped (`OwnerPolicy::view`).
   - Neither the report nor the email ever shows an ID number.
8. **Pending approvals report.** It lists pending approvals whose record the viewer may `view`, plus the viewer's own requests (§10).
   - An `AgreementAmendment` record is checked through its agreement, because amendments have no policy.
   - The dashboard tile counts pending items the viewer may *decide*: they hold `approvals.decide`, and with `require_different_approver` on they are neither the requester nor the record's creator.
9. **A scheduled or draft deposit invoice is cancelled when its units are settled** (M3b carry-over).
   - This happens when every line is on a unit being settled.
   - Normally such an invoice was held back by a pending owner contract.
   - If the invoice has lines on other units too, the settlement approval is refused with a message to issue the deposit invoice first.

## Working environment

```bash
export PHP="/c/Users/ababy/.config/herd/bin/php85/php.exe"
cd /c/Users/ababy/Documents/RentalManagementSystem
# MySQL stops between sessions: if "connection refused", run C:\Users\ababy\.mysql\start-mysql.cmd
```
Branch: `m5-reports-golive` (continue).

## Conventions carried over (do not re-create)

**Models and scopes**
- `Building::visibleTo`, `Unit::visibleTo`, `Customer::visibleTo`, `Agreement::visibleTo`, `Invoice::visibleTo`, `Cheque::visibleTo`, `OwnerContract::visibleTo`. Each is a query scope taking a `User`.
- `AgreementUnit::effectiveEndSql(string $alias)`: its SQL holds one `?` binding, the as-of date.
- `CustomerCredit::fils(int $customerId)`.
- `DepositMovement::heldFils(int $agreementUnitId, bool $lock = false)`.
- `invoices.balance` (generated) and `invoices.grace_until`.
- `Document` (`documentable` morph, `category`, `expires_on`).
- `Approval::query()->pending()`, `$approval->handler()` (`summary()`, `url()`, `creatorId()`).

**Enums and settings**
- Enums: `ChequeStatus`, `ChequeDirection`, `PaymentMethod` (`DepositApplied`), `PaymentStatus`, `InvoiceStatus`, `InvoiceType`, `AgreementStatus`, `DocumentCategory::{IdCopy, CrCopy}`, `OwnerChargeType::ManagementFee`, `TaxCategory`.
- `PermissionName::{ReportsOperational, ReportsFinancial, ChequesManage, FinanceView, ApprovalsDecide, CustomersManage, OwnersView, BuildingsViewAll}`.
- `CompanySetting::current()->require_different_approver`.

**Existing pages and patterns**
- `App\Livewire\Reports\BuildingProfitabilityReport`: an existing report. Leave it as it is; it's added to the reports index.
- `App\Support\Approvers::notifiable()`: the recipient pattern (active, permission, minus Vendor Support).
- `App\Notifications\ApprovalRequested`: the notification style.
- `User::system()`: inactive, so never a recipient.
- `Audit::log`.
- `RebillAgreement` (`$toReturn`).
- `Livewire\Agreements\Show` (the `chequesToReturn` query).
- `ApproveDepositSettlement` (step 0 credits unpaid issued deposit lines).
- `CancelDraftInvoice`.

**Routes, schedule and helpers**
- `routes/property.php` (Livewire routes with `can:` middleware).
- `routes/console.php` (the schedule pattern with `pingOnSuccessIf` and `config('services.forge.heartbeats.*')`).
- Test helpers: `activeAgreement()`, `activeOwnerContract()`, `issuedInvoice()`, `matrixUser()`, `scheduledEvent()`.

---

### Task 1: Report framework and the occupancy, expiring and overstay reports

**Spec:** §10:
- Operational reports, requiring `reports.operational`: unit availability and occupancy by building; agreements expiring in 30/60/90 days; expired agreements not closed (overstays).
- Every report filters by building and date range, respects building assignment and exports to Excel.

Also §8.4 (exports audited) and plan rulings 1 and 2.

**Files:**
- Create:
  - `app/Livewire/Reports/Concerns/ReportPage.php`
  - `resources/views/livewire/reports/table.blade.php`
  - `app/Reports/Queries.php`
  - `app/Livewire/Reports/{Index,OccupancyReport,ExpiringAgreementsReport,OverstaysReport}.php`
  - `resources/views/livewire/reports/index.blade.php`
  - `tests/Feature/Reports/OperationalReportsTest.php`
- Modify: `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`

**Interfaces:**
- Produces:
  - `ReportPage` (trait). The using class must implement:
    - `title(): string`
    - `permission(): bool`, true when the actor may see this report
    - `columns(): array<string, string>` (key ⇒ label)
    - `rows(): list<array<string, string|int|null>>`, where the reserved key `_url` links the row's first cell
  - Optional methods, with their defaults:
    - `dateMode(): string` (`'range'` default, `'single'`, `'none'`)
    - `options(): array<string, array<string, string>>` (property ⇒ value ⇒ label)
    - `numeric(): list<string>` (keys right-aligned)
  - Public properties: `?int $building`, `string $from`, `string $to`. For `single`, `$to` is the "as at" date.
  - `Queries::expiringAgreements(User $user, int $days, ?int $buildingId = null): Builder<Agreement>`
  - `Queries::overstays(User $user, ?int $buildingId = null): Builder<Agreement>`
  - `Queries::occupiedUnitIds(User $user, string $on): Builder` (unit ids)
  - Routes:
    - `reports.index` (`reports`, `auth`): lists the reports the viewer can open
    - `reports.occupancy`, `reports.expiring`, `reports.overstays`, each with `can:reports.operational`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reports/OperationalReportsTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\ExpiringAgreementsReport;
use App\Livewire\Reports\OccupancyReport;
use App\Livewire\Reports\OverstaysReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    [$u1, $u2, $u3] = Unit::factory()->for($this->mine)->count(3)->create()->all();
    Unit::factory()->for($this->mine)->create(['blocked' => true, 'blocked_reason' => 'Works']);
    $other = Unit::factory()->for($this->theirs)->create();
    $agreement = fn (array $units, string $end) => activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => $end], $units);
    $this->soon = $agreement([$u1], '2026-07-01');      // expires in 21 days
    $this->later = $agreement([$u2], '2026-08-25');     // in 76 days
    $this->overstay = $agreement([$u3], '2026-05-31');  // ended, not moved out
    $this->overstay->forceFill(['status' => 'expired'])->save();
    $this->hidden = $agreement([$other], '2026-06-20'); // another building
    $this->leasing = matrixUser(RoleName::Leasing, $this->mine);
});

test('occupancy counts occupied units over units that are not blocked, per building in scope', function () {
    Livewire::actingAs($this->leasing)->test(OccupancyReport::class)
        ->assertSee($this->mine->code)->assertDontSee($this->theirs->code)
        ->assertSeeInOrder(['4', '1', '3', '0', '100.0%']); // units, blocked, occupied (the overstay holds its unit), vacant, %
});

test('agreements expiring within the chosen window, in scope', function () {
    Livewire::actingAs($this->leasing)->test(ExpiringAgreementsReport::class)
        ->assertSee($this->soon->number)->assertDontSee($this->later->number)->assertDontSee($this->hidden->number)
        ->set('window', '90')->assertSee($this->later->number);
});

test('overstays are expired agreements still holding a unit', function () {
    Livewire::actingAs($this->leasing)->test(OverstaysReport::class)
        ->assertSee($this->overstay->number)->assertSee('10')->assertDontSee($this->soon->number); // 10 days past end
});

test('a report exports to Excel, audited; a bad date shows an error, not a crash', function () {
    Livewire::actingAs($this->leasing)->test(ExpiringAgreementsReport::class)->call('export')->assertFileDownloaded();
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'expiring_agreements')->count())->toBe(1);

    Livewire::actingAs($this->leasing)->test(OccupancyReport::class)->set('to', 'not-a-date')->assertHasErrors('to')->assertOk();
});

test('the reports index lists only what the viewer may open; the pages need reports.operational', function () {
    $this->actingAs($this->leasing)->get(route('reports.index'))->assertOk()->assertSee('Occupancy')->assertDontSee('Building profitability');
    $this->actingAs(matrixUser(RoleName::Finance, $this->mine))->get(route('reports.index'))->assertSee('Building profitability');

    $nobody = \App\Models\User::factory()->create(); // no role, so no report permission
    $this->actingAs($nobody)->get(route('reports.occupancy'))->assertForbidden();
});
```
(Check that the `activeAgreement` helper accepts `$units` as a list of `Unit` models, and that it leaves `move_out_date` null.)

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Reports/OperationalReportsTest.php`
Expected: FAIL with `Class "App\Livewire\Reports\OccupancyReport" not found`.

- [ ] **Step 3: The framework**

Create `app/Livewire/Reports/Concerns/ReportPage.php`:
```php
<?php

namespace App\Livewire\Reports\Concerns;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Plan ruling 1, spec §10: every report filters by building and date, respects building assignment, and exports to
 * Excel (audited, §8.4). Filters are validated outside render(): an invalid one shows an error and an empty table.
 */
trait ReportPage
{
    use WithActor;

    #[Url]
    public ?int $building = null;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    abstract protected function title(): string;

    abstract protected function permission(): bool;

    /** @return array<string, string> */
    abstract protected function columns(): array;

    /** @return list<array<string, string|int|null>> */
    abstract protected function rows(): array;

    protected function dateMode(): string
    {
        return 'range';
    }

    /** @return array<string, array<string, string>> */
    protected function options(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return [];
    }

    public function mountReportPage(): void
    {
        abort_unless($this->permission(), 403);
        $today = now('Asia/Bahrain');
        $this->to = $this->to ?: $today->toDateString();
        $this->from = $this->from ?: $today->startOfMonth()->toDateString();
    }

    public function updated(): void
    {
        $this->validate($this->filterRules());
    }

    /** @return array<string, list<string>> */
    private function filterRules(): array
    {
        $rules = ['building' => ['nullable', 'integer']];
        foreach (array_keys($this->options()) as $property) {
            $rules[$property] = ['required', 'in:'.implode(',', array_keys($this->options()[$property]))];
        }

        return match ($this->dateMode()) {
            'range' => [...$rules, 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']],
            'single' => [...$rules, 'to' => ['required', 'date_format:Y-m-d']],
            default => $rules,
        };
    }

    private function filtersValid(): bool
    {
        return Validator::make($this->all(), $this->filterRules())->passes();
    }

    public function export(): BinaryFileResponse
    {
        abort_unless($this->permission(), 403);
        $this->validate($this->filterRules());
        $report = Str::snake(Str::beforeLast(class_basename(static::class), 'Report'));
        Audit::log('report.exported', properties: ['report' => $report, 'building' => $this->building, 'from' => $this->from, 'to' => $this->to,
            ...array_intersect_key($this->all(), $this->options())], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-'.$report.'-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        $columns = $this->columns();
        foreach ($this->rows() as $row) {
            $writer->addRow(array_combine(array_values($columns), array_map(fn (string $key) => $row[$key] ?? '', array_keys($columns))));
        }
        $writer->close();

        return response()->download($path, "{$report}-{$this->to}.xlsx")->deleteFileAfterSend();
    }

    public function render(): View
    {
        return view('livewire.reports.table', [
            'title' => $this->title(),
            'columns' => $this->columns(),
            'rows' => $this->filtersValid() ? $this->rows() : [],
            'numeric' => $this->numeric(),
            'options' => $this->options(),
            'dateMode' => $this->dateMode(),
            'buildings' => Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
        ])->title($this->title());
    }
}
```
`mountReportPage` runs through Livewire's `mount{TraitName}` hook. A component that also needs its own `mount()` should keep it for its own defaults only.

Create `resources/views/livewire/reports/table.blade.php`:
```blade
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl">{{ $title }}</flux:heading>
        <flux:link :href="route('reports.index')" wire:navigate>{{ __('All reports') }}</flux:link>
    </div>

    <div class="grid gap-3 sm:grid-cols-4">
        <flux:select wire:model.live="building" :label="__('Building')">
            <flux:select.option value="">{{ __('All buildings') }}</flux:select.option>
            @foreach ($buildings as $b)
                <flux:select.option :value="$b->id">{{ $b->code }} — {{ $b->name }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($dateMode === 'range')
            <flux:input type="date" wire:model.live="from" :label="__('From')" />
            <flux:input type="date" wire:model.live="to" :label="__('To')" />
        @elseif ($dateMode === 'single')
            <flux:input type="date" wire:model.live="to" :label="__('As at')" />
        @endif
        @foreach ($options as $property => $choices)
            <flux:select wire:model.live="{{ $property }}" :label="str($property)->headline()">
                @foreach ($choices as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        @endforeach
        <div class="flex items-end"><flux:button wire:click="export">{{ __('Export to Excel') }}</flux:button></div>
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                @foreach ($columns as $key => $label)
                    <flux:table.column :class="in_array($key, $numeric, true) ? 'text-end' : ''">{{ $label }}</flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $i => $row)
                    <flux:table.row :key="'r-'.$i">
                        @foreach (array_keys($columns) as $j => $key)
                            <flux:table.cell :class="in_array($key, $numeric, true) ? 'text-end tabular-nums' : ''">
                                @if ($j === 0 && ! empty($row['_url']))
                                    <flux:link :href="$row['_url']" wire:navigate>{{ $row[$key] }}</flux:link>
                                @else
                                    {{ $row[$key] ?? '' }}
                                @endif
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell :colspan="count($columns)">{{ __('Nothing to show.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</div>
```

Create `app/Reports/Queries.php`:
```php
<?php

namespace App\Reports;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Plan ruling 2: the building-scoped queries that reports, dashboard tiles and digest emails share, so a tile always
 * equals the report it links to.
 */
final class Queries
{
    /** @return Builder<Agreement> */
    public static function expiringAgreements(User $user, int $days, ?int $buildingId = null): Builder
    {
        $today = now('Asia/Bahrain')->toDateString();

        return self::inBuilding(Agreement::query()->visibleTo($user), $buildingId)
            ->where('status', AgreementStatus::Active)
            ->whereBetween('end_date', [$today, now('Asia/Bahrain')->addDays($days)->toDateString()]);
    }

    /** Spec §10: expired agreements not closed — still holding a unit (no move-out recorded on it). @return Builder<Agreement> */
    public static function overstays(User $user, ?int $buildingId = null): Builder
    {
        return self::inBuilding(Agreement::query()->visibleTo($user), $buildingId)
            ->where('status', AgreementStatus::Expired)
            ->whereHas('agreementUnits', fn (Builder $q) => $q->whereNull('move_out_date'));
    }

    /** Units occupied on $on: an agreement unit of a non-draft agreement covers the date (effective end, §5.9). */
    public static function occupiedUnitIds(User $user, string $on): QueryBuilder
    {
        return DB::table('agreement_units as au')->join('agreements as a', 'a.id', '=', 'au.agreement_id')
            ->whereNull('a.deleted_at')
            ->whereNotIn('a.status', [AgreementStatus::Draft->value, AgreementStatus::PendingApproval->value])
            ->where('au.start_date', '<=', $on)
            ->whereRaw('('.AgreementUnit::effectiveEndSql('au').') >= ?', [$on, $on])
            ->whereIn('au.unit_id', \App\Models\Unit::query()->visibleTo($user)->select('id'))
            ->select('au.unit_id')->distinct();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function inBuilding(Builder $query, ?int $buildingId): Builder
    {
        return $query->when($buildingId, fn (Builder $q, int $b) => $q->whereHas('agreementUnits.unit', fn (Builder $u) => $u->where('building_id', $b)));
    }
}
```
(Import `App\Models\Unit` rather than fully qualifying it. `inBuilding` assumes `Agreement` has `agreementUnits()` and `AgreementUnit` has `unit()`.)

- [ ] **Step 4: The three reports and the index**

Create `app/Livewire/Reports/OccupancyReport.php`:
```php
<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Building;
use App\Reports\Queries;
use Livewire\Component;

/** Spec §10: unit availability and occupancy by building, as at a date. Occupancy % = occupied ÷ (units − blocked). */
class OccupancyReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Unit availability and occupancy');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function dateMode(): string
    {
        return 'single';
    }

    protected function columns(): array
    {
        return ['building' => __('Building'), 'units' => __('Units'), 'blocked' => __('Blocked'), 'occupied' => __('Occupied'), 'vacant' => __('Vacant'), 'occupancy' => __('Occupancy')];
    }

    protected function numeric(): array
    {
        return ['units', 'blocked', 'occupied', 'vacant', 'occupancy'];
    }

    protected function rows(): array
    {
        $occupied = Queries::occupiedUnitIds($this->actor(), $this->to)->pluck('unit_id')->all();

        return Building::visibleTo($this->actor())->when($this->building, fn ($q, $b) => $q->whereKey($b))->orderBy('code')
            ->with(['units:id,building_id,blocked'])->get()
            ->map(function (Building $b) use ($occupied) {
                $units = $b->units->count();
                $blocked = $b->units->where('blocked', true)->count();
                $taken = $b->units->whereIn('id', $occupied)->count();
                $available = $units - $blocked;

                return [
                    'building' => "{$b->code} — {$b->name}",
                    'units' => $units,
                    'blocked' => $blocked,
                    'occupied' => $taken,
                    'vacant' => max(0, $available - $taken),
                    'occupancy' => $available > 0 ? number_format(100 * $taken / $available, 1).'%' : '—',
                ];
            })->values()->all();
    }
}
```
(A blocked unit that is occupied still counts as occupied. Vacancy is `available − occupied`, floored at 0.)

Create `app/Livewire/Reports/ExpiringAgreementsReport.php`:
```php
<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Agreement;
use App\Reports\Queries;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec §10: active agreements expiring in the next 30/60/90 days. */
class ExpiringAgreementsReport extends Component
{
    use ReportPage;

    #[Url]
    public string $window = '30';

    protected function title(): string
    {
        return __('Agreements expiring');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function dateMode(): string
    {
        return 'none';
    }

    protected function options(): array
    {
        return ['window' => ['30' => __('Next 30 days'), '60' => __('Next 60 days'), '90' => __('Next 90 days')]];
    }

    protected function columns(): array
    {
        return ['number' => __('Agreement'), 'customer' => __('Customer'), 'units' => __('Units'), 'end' => __('Ends'), 'days' => __('Days left')];
    }

    protected function numeric(): array
    {
        return ['days'];
    }

    protected function rows(): array
    {
        $today = now('Asia/Bahrain')->startOfDay();

        return Queries::expiringAgreements($this->actor(), (int) $this->window, $this->building)
            ->with(['customer:id,name_en', 'agreementUnits.unit:id,code,building_id', 'agreementUnits.unit.building:id,code'])
            ->orderBy('end_date')->orderBy('id')->get()
            ->map(fn (Agreement $a) => [
                '_url' => route('agreements.show', $a),
                'number' => $a->number,
                'customer' => $a->customer->name_en,
                'units' => $a->agreementUnits->map(fn ($au) => $au->unit->building->code.'/'.$au->unit->code)->implode(', '),
                'end' => $a->end_date->format('d/m/Y'),
                'days' => (int) $today->diffInDays($a->end_date, true),
            ])->values()->all();
    }
}
```
Create `app/Livewire/Reports/OverstaysReport.php`. It is the same shape as the expiring report:
- no `options()`;
- title `__('Overstays: expired, not closed')`;
- columns `number`, `customer`, `units`, `end`, `days` (labelled "Days past end");
- rows from `Queries::overstays($this->actor(), $this->building)`, with `days` = days from `end_date` to today.

Write it out in full; don't subclass the expiring report.

Create `app/Livewire/Reports/Index.php`:
```php
<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\WithActor;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** The reports the viewer may open (spec §10), grouped. Later tasks add their entries here. */
class Index extends Component
{
    use WithActor;

    /** @return array<string, list<array{route: string, label: string}>> */
    public static function catalogue(): array
    {
        return [
            'operational' => [
                ['route' => 'reports.occupancy', 'label' => __('Unit availability and occupancy')],
                ['route' => 'reports.expiring', 'label' => __('Agreements expiring')],
                ['route' => 'reports.overstays', 'label' => __('Overstays: expired, not closed')],
            ],
            'financial' => [
                ['route' => 'reports.building-profitability', 'label' => __('Building profitability')],
                ['route' => 'owner-payables.index', 'label' => __('Head-lease payments due')],
                ['route' => 'owner-statements.index', 'label' => __('Owner statements')],
            ],
        ];
    }

    public function render(): View
    {
        $user = $this->actor();
        $groups = array_filter([
            __('Operational') => $user->can('reports.operational') ? self::catalogue()['operational'] : [],
            __('Financial') => $user->can('reports.financial') ? self::catalogue()['financial'] : [],
        ]);

        return view('livewire.reports.index', ['groups' => $groups])->title(__('Reports'));
    }
}
```
Create `resources/views/livewire/reports/index.blade.php`:
```blade
<div class="space-y-6">
    <flux:heading size="xl">{{ __('Reports') }}</flux:heading>
    @forelse ($groups as $group => $reports)
        <div class="space-y-2">
            <flux:heading size="lg">{{ $group }}</flux:heading>
            <ul class="space-y-1">
                @foreach ($reports as $report)
                    <li><flux:link :href="route($report['route'])" wire:navigate>{{ $report['label'] }}</flux:link></li>
                @endforeach
            </ul>
        </div>
    @empty
        <flux:text>{{ __('You have no reports.') }}</flux:text>
    @endforelse
</div>
```
Add to `routes/property.php`:
```php
    Route::livewire('reports', Reports\Index::class)->name('reports.index');
    Route::livewire('reports/occupancy', Reports\OccupancyReport::class)->middleware('can:reports.operational')->name('reports.occupancy');
    Route::livewire('reports/expiring', Reports\ExpiringAgreementsReport::class)->middleware('can:reports.operational')->name('reports.expiring');
    Route::livewire('reports/overstays', Reports\OverstaysReport::class)->middleware('can:reports.operational')->name('reports.overstays');
```
In the sidebar, change the `Reports` group added in M4:
- show it when the user holds `reports.operational` or `reports.financial`;
- its first item is "All reports" (`reports.index`, `:current="request()->routeIs('reports.*')"`);
- keep the building profitability item.

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Reports
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the report framework and three operational reports

Reports share one page pattern: building and date filters validated
outside rendering, a scoped table, and an audited Excel export. First
reports: unit availability and occupancy, agreements expiring in
30/60/90 days, and overstays. A reports index lists what each viewer
may open.
EOF
```
(End the message with your Co-Authored-By trailer.)

---
### Task 2: Pending approvals and expiring ID documents

**Spec:**
- §10 operational reports:
  - "pending approvals (only items the viewer may `view`, plus their own requests)";
  - "customer and owner ID documents expiring".
- Plan rulings 7–8.

**Files:**
- Create: `app/Livewire/Reports/{PendingApprovalsReport,IdDocumentsReport}.php`, `tests/Feature/Reports/ApprovalsAndDocumentsReportsTest.php`
- Modify: `app/Reports/Queries.php`, `app/Livewire/Reports/Index.php`, `routes/property.php`

**Interfaces:**
- Consumes: `ReportPage` and `Queries` (Task 1)
- Produces:
  - `Queries::visiblePendingApprovals(User $user): Collection<int, Approval>`: pending approvals whose record the viewer may view, plus their own requests.
  - `Queries::approvalsToDecide(User $user): Collection<int, Approval>`: pending approvals the viewer may decide.
  - `Queries::expiringIdDocuments(User $user, int $days): Collection<int, Document>`: id_copy and cr_copy documents of visible customers, plus owners when the user has `owners.view`, with `expires_on` between today and today + `$days`.
  - Routes `reports.approvals` and `reports.id-documents`, both `can:reports.operational`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reports/ApprovalsAndDocumentsReportsTest.php`:
```php
<?php

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Enums\RoleName;
use App\Livewire\Reports\IdDocumentsReport;
use App\Livewire\Reports\PendingApprovalsReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Owner;
use App\Models\Unit;
use App\Reports\Queries;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    $this->leasing = matrixUser(RoleName::Leasing, $this->mine);
    $this->otherLeasing = matrixUser(RoleName::Leasing, $this->theirs);
    $submit = function ($user, Building $building) {
        $draft = app(SaveAgreement::class)->handle($user, null, [
            'customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'frequency' => 'monthly',
            'units' => [['unit_id' => Unit::factory()->for($building)->create()->id, 'charges' => [['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'exempt']]]],
        ]);

        return app(SubmitAgreement::class)->handle($user, $draft);
    };
    $this->mineApproval = $submit($this->leasing, $this->mine);
    $this->theirApproval = $submit($this->otherLeasing, $this->theirs);
});

test('pending approvals show what the viewer may view, plus their own requests', function () {
    Livewire::actingAs($this->leasing)->test(PendingApprovalsReport::class)
        ->assertSee($this->mineApproval->handler()->summary($this->mineApproval))
        ->assertDontSee($this->theirApproval->handler()->summary($this->theirApproval));
});

test('a manager may decide neither their own request nor a record they created', function () {
    $manager = matrixUser(RoleName::Management, $this->mine);
    expect(Queries::approvalsToDecide($manager)->pluck('id')->sort()->values()->all())->toBe(collect([$this->mineApproval->id, $this->theirApproval->id])->sort()->values()->all())
        ->and(Queries::approvalsToDecide($this->leasing))->toHaveCount(0); // no approvals.decide
});

test('ID documents expiring within the window, never showing the ID number', function () {
    $customer = Customer::factory()->create(['name_en' => 'Sara Ahmed', 'id_number' => '090202345']);
    $owner = Owner::factory()->create(['name_en' => 'Ali Hassan', 'id_number' => '080101234']);
    $doc = fn ($model, string $category, string $expires) => Document::factory()->for($model, 'documentable')->create(['category' => $category, 'expires_on' => $expires]);
    $doc($customer, 'id_copy', '2026-06-30');
    $doc($owner, 'cr_copy', '2026-07-05');
    $doc($customer, 'id_copy', '2026-09-30'); // beyond 30 days
    $doc($customer, 'photo', '2026-06-20');   // not an ID document

    $finance = matrixUser(RoleName::Finance, $this->mine); // finance.view-all and owners.view
    Livewire::actingAs($finance)->test(IdDocumentsReport::class)
        ->assertSee('Sara Ahmed')->assertSee('Ali Hassan')->assertSee('30/06/2026')->assertDontSee('30/09/2026')
        ->assertDontSee('090202345')->assertDontSee('080101234');

    Livewire::actingAs($this->leasing)->test(IdDocumentsReport::class)->assertDontSee('Ali Hassan'); // no owners.view
});
```
Finance holds `buildings.view-all`, so it sees Sara without an agreement. The Leasing check only asserts that the owner is hidden. If `Document` has no factory, create the rows with `forceFill`, setting `disk`, `path`, `original_name`, `mime`, `size` and `uploaded_by`.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Reports/ApprovalsAndDocumentsReportsTest.php`
Expected: FAIL with `Class "App\Livewire\Reports\PendingApprovalsReport" not found`.

- [ ] **Step 3: Queries**

Add to `app/Reports/Queries.php`:
```php
    /** Spec §10: pending approvals whose record the viewer may view, plus the viewer's own requests (plan ruling 8). @return Collection<int, Approval> */
    public static function visiblePendingApprovals(User $user): Collection
    {
        return Approval::query()->pending()->with(['requester:id,name', 'approvable'])->orderBy('requested_at')->get()
            ->filter(fn (Approval $a) => $a->requested_by === $user->id || self::mayView($user, $a))
            ->values();
    }

    /** Pending approvals the viewer may decide (approvals.decide, and not their own when require_different_approver). @return Collection<int, Approval> */
    public static function approvalsToDecide(User $user): Collection
    {
        if (! $user->can(PermissionName::ApprovalsDecide)) {
            return collect();
        }
        $different = CompanySetting::current()->require_different_approver;

        return Approval::query()->pending()->orderBy('requested_at')->get()
            ->reject(fn (Approval $a) => $different && in_array($user->id, [$a->requested_by, $a->handler()->creatorId($a)], true))
            ->values();
    }

    private static function mayView(User $user, Approval $approval): bool
    {
        $record = $approval->approvable;
        if ($record instanceof AgreementAmendment) {
            $record = $record->agreement; // amendments have no policy: their agreement decides
        }

        return $record !== null && $user->can('view', $record);
    }

    /** Plan ruling 7: ID and CR copies expiring within $days, of visible customers and (with owners.view) owners. @return Collection<int, Document> */
    public static function expiringIdDocuments(User $user, int $days): Collection
    {
        $today = now('Asia/Bahrain')->toDateString();
        $until = now('Asia/Bahrain')->addDays($days)->toDateString();

        return Document::query()->with('documentable')
            ->whereIn('category', [DocumentCategory::IdCopy->value, DocumentCategory::CrCopy->value])
            ->whereBetween('expires_on', [$today, $until])
            ->where(function ($q) use ($user) {
                $q->where(fn ($c) => $c->where('documentable_type', (new Customer)->getMorphClass())
                    ->whereIn('documentable_id', Customer::query()->visibleTo($user)->select('id')));
                if ($user->can(PermissionName::OwnersView)) {
                    $q->orWhere('documentable_type', (new Owner)->getMorphClass());
                }
            })
            ->orderBy('expires_on')->orderBy('id')->get();
    }
```
The imports are:
- `App\Enums\{DocumentCategory, PermissionName}`
- `App\Models\{AgreementAmendment, Approval, CompanySetting, Customer, Document, Owner}`
- `Illuminate\Support\Collection`

Check `Approval::$requested_by` and the `pending()` scope name in `app/Models/Approval.php`, and match them.

- [ ] **Step 4: The two reports**

Create `app/Livewire/Reports/PendingApprovalsReport.php`:
```php
<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Approval;
use App\Reports\Queries;
use Livewire\Component;

/** Spec §10: pending approvals the viewer may see, plus their own requests. */
class PendingApprovalsReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Pending approvals');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function dateMode(): string
    {
        return 'none';
    }

    protected function columns(): array
    {
        return ['action' => __('Action'), 'summary' => __('Summary'), 'requested' => __('Requested'), 'by' => __('By')];
    }

    protected function rows(): array
    {
        return Queries::visiblePendingApprovals($this->actor())
            ->map(fn (Approval $a) => [
                '_url' => $a->handler()->url($a),
                'action' => $a->action->label(),
                'summary' => $a->handler()->summary($a),
                'requested' => $a->requested_at->timezone('Asia/Bahrain')->format('d/m/Y H:i'),
                'by' => $a->requester->name ?? '—',
            ])->values()->all();
    }
}
```
The building filter is not applied here. Approvals span record types, and scope already comes from `view`. Keep the filter visible anyway; it is harmless.

Create `app/Livewire/Reports/IdDocumentsReport.php`, built the same way:
- `dateMode` `'none'`;
- options `['window' => ['30' => …, '60' => …, '90' => …]]` with `#[Url] public string $window = '30';`;
- title `__('ID documents expiring')`;
- columns `name`, `kind` (Customer or Owner), `document` (the category's `label()`), `expires` (d/m/Y) and `days` (days left, numeric);
- rows from `Queries::expiringIdDocuments($this->actor(), (int) $this->window)`;
- `_url` is the customer or owner edit route (`customers.edit` or `owners.edit`).

Write it out in full. It must never output `id_number`.

In `Reports\Index::catalogue()` add these two `operational` entries:
- `['route' => 'reports.approvals', 'label' => __('Pending approvals')]`
- `['route' => 'reports.id-documents', 'label' => __('ID documents expiring')]`

Add both routes to `routes/property.php` with `can:reports.operational`.

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Reports
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Report pending approvals and expiring ID documents

Pending approvals lists what the viewer may view plus their own
requests. ID documents lists customer and owner ID or CR copies
expiring in 30/60/90 days, by name only, never the ID number.
EOF
```

---

### Task 3: Cheque reports, and an explicit "to return" flag

**Spec:**
- §10: "Cheque reports — cheques to deposit (today / this week), bounced cheques awaiting action, held cheques to return — also need `cheques.manage` or `finance.view`."
- §6.3 (re-billing leaves cheques to return).
- Plan rulings 3–5. M3b carry-over: "Cheques to return" also listed unmatched cheques.

**Files:**
- Create: `database/migrations/2026_12_05_000100_add_to_return_to_cheques.php`, `app/Livewire/Reports/ChequesReport.php`, `tests/Feature/Reports/ChequeReportsTest.php`
- Modify: `app/Actions/Billing/RebillAgreement.php`, `app/Livewire/Agreements/Show.php`, `app/Models/Cheque.php`, `app/Reports/Queries.php`, `app/Livewire/Reports/Index.php`, `routes/property.php`

**Interfaces:**
- Produces:
  - `cheques.to_return` (boolean, default false)
  - `Queries::chequesToDeposit(User $user, string $until): Builder<Cheque>` (held, received, `cheque_date ≤ $until`)
  - `Queries::bouncedCheques(User $user): Builder<Cheque>`
  - `Queries::chequesToReturn(User $user): Builder<Cheque>`
  - `Queries::weekEnd(): string` (the Sunday of this week)
  - route `reports.cheques` (`can:reports.operational`, plus the component checks `cheques.manage` or `finance.view`)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reports/ChequeReportsTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\ChequesReport;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain')); // a Wednesday; this week ends Sunday 14 June
    $this->building = Building::factory()->create();
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($this->building)->create()]);
    $cheque = fn (string $no, string $date, string $status, bool $toReturn = false) => tap((new Cheque)->forceFill([
        'direction' => 'received', 'customer_id' => $this->customer->id, 'agreement_id' => $this->agreement->id, 'cheque_no' => $no, 'bank_name' => 'NBB',
        'cheque_date' => $date, 'amount' => '400.000', 'status' => $status, 'to_return' => $toReturn, 'created_by' => User::factory()->create()->id,
        'bounced_on' => $status === 'bounced' ? '2026-06-01' : null, 'bounce_reason' => $status === 'bounced' ? 'Funds' : null,
    ]))->save();
    $cheque('T-TODAY', '2026-06-10', 'held');
    $cheque('T-WEEK', '2026-06-14', 'held');
    $cheque('T-LATER', '2026-07-01', 'held');
    $cheque('T-BOUNCED', '2026-05-01', 'bounced');
    $cheque('T-RETURN', '2026-08-01', 'held', toReturn: true);
    $cheque('T-UNMATCHED', '2026-09-01', 'held'); // never had an invoice: not "to return"
    $this->finance = matrixUser(RoleName::Finance, $this->building);
});

test('cheques to deposit today and this week (Monday to Sunday)', function () {
    $page = Livewire::actingAs($this->finance)->test(ChequesReport::class);
    $page->assertSee('T-TODAY')->assertDontSee('T-WEEK')->assertDontSee('T-LATER'); // default: today
    $page->set('kind', 'week')->assertSee('T-TODAY')->assertSee('T-WEEK')->assertDontSee('T-LATER');
});

test('bounced cheques awaiting action, and held cheques flagged to return', function () {
    Livewire::actingAs($this->finance)->test(ChequesReport::class)->set('kind', 'bounced')->assertSee('T-BOUNCED')->assertDontSee('T-TODAY');
    Livewire::actingAs($this->finance)->test(ChequesReport::class)->set('kind', 'return')->assertSee('T-RETURN')->assertDontSee('T-UNMATCHED');
});

test('the agreement page lists only cheques re-billing left to return', function () {
    $this->actingAs($this->finance)->get(route('agreements.show', $this->agreement))->assertSee('T-RETURN')->assertDontSee('T-UNMATCHED');
});

test('the cheque reports also need cheques.manage or finance.view', function () {
    $this->actingAs(matrixUser(RoleName::Leasing, $this->building))->get(route('reports.cheques'))->assertForbidden(); // operational only
    $this->actingAs($this->finance)->get(route('reports.cheques'))->assertOk();
});
```
Add one more case to the existing re-billing test file. Find the test that asserts `RebillResult::$chequesToReturn` in `tests/Feature/Agreements` or `tests/Feature/Billing`, and add: `expect(Cheque::find($id)->to_return)->toBeTrue()` for each returned id.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Reports/ChequeReportsTest.php`
Expected: FAIL. The `to_return` column doesn't exist yet.

- [ ] **Step 3: The flag**

Create `database/migrations/2026_12_05_000100_add_to_return_to_cheques.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->boolean('to_return')->default(false)->after('status'); // re-billing left it without an invoice (spec §6.3)
        });
    }

    public function down(): void
    {
        Schema::table('cheques', fn (Blueprint $table) => $table->dropColumn('to_return'));
    }
};
```
The cheques guard trigger compares a fixed list of columns, so leave it unchanged. Add `'to_return' => 'boolean'` and `@property bool $to_return` to `app/Models/Cheque.php`.

In `RebillAgreement`, change the cheque loop so a cheque left without an invoice is flagged:
```php
            foreach ($cheques->where('invoice_id', $old->id) as $cheque) {
                $cheque->forceFill(['invoice_id' => $new?->id, 'to_return' => $new === null])->save();
                if ($new === null) {
                    $toReturn[] = $cheque->id;
                }
            }
```
In `Livewire\Agreements\Show`, the `chequesToReturn` query becomes `Queries::chequesToReturn($this->actor())->where('agreement_id', $agreement->id)->orderBy('cheque_date')->orderBy('id')->get()`.

- [ ] **Step 4: Queries and the report**

Add to `app/Reports/Queries.php`:
```php
    /** Plan ruling 3: the Sunday ending the Monday–Sunday week that contains today. */
    public static function weekEnd(): string
    {
        return now('Asia/Bahrain')->endOfWeek(CarbonInterface::SUNDAY)->toDateString();
    }

    /** Held received cheques due for deposit by $until. @return Builder<Cheque> */
    public static function chequesToDeposit(User $user, string $until): Builder
    {
        return Cheque::query()->visibleTo($user)->where('direction', ChequeDirection::Received)
            ->where('status', ChequeStatus::Held)->where('cheque_date', '<=', $until);
    }

    /** Plan ruling 4. @return Builder<Cheque> */
    public static function bouncedCheques(User $user): Builder
    {
        return Cheque::query()->visibleTo($user)->where('direction', ChequeDirection::Received)->where('status', ChequeStatus::Bounced);
    }

    /** Plan ruling 5: held cheques re-billing left without an invoice. @return Builder<Cheque> */
    public static function chequesToReturn(User $user): Builder
    {
        return Cheque::query()->visibleTo($user)->where('direction', ChequeDirection::Received)
            ->where('status', ChequeStatus::Held)->where('to_return', true);
    }
```
Use the imports `App\Enums\{ChequeDirection, ChequeStatus}`, `App\Models\Cheque` and `Carbon\CarbonInterface`.

Previously the Agreements page also listed deposited cheques. A deposited cheque has left the company, so it cannot be handed back, and the report lists only held cheques. Check the old agreement-page test still passes. If it expects a deposited cheque on the page, that expectation is the M3b defect this ruling closes: update it and say so in the report.

Create `app/Livewire/Reports/ChequesReport.php`:
```php
<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Cheque;
use App\Reports\Queries;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec §10: the cheque reports, which also need cheques.manage or finance.view. */
class ChequesReport extends Component
{
    use ReportPage;

    #[Url]
    public string $kind = 'today';

    protected function title(): string
    {
        return __('Cheques');
    }

    protected function permission(): bool
    {
        $user = $this->actor();

        return $user->can('reports.operational') && ($user->can('cheques.manage') || $user->can('finance.view'));
    }

    protected function dateMode(): string
    {
        return 'none';
    }

    protected function options(): array
    {
        return ['kind' => [
            'today' => __('To deposit today'),
            'week' => __('To deposit this week'),
            'bounced' => __('Bounced, awaiting action'),
            'return' => __('Held cheques to return'),
        ]];
    }

    protected function columns(): array
    {
        return ['cheque' => __('Cheque'), 'customer' => __('Customer'), 'bank' => __('Bank'), 'date' => __('Cheque date'), 'amount' => __('Amount')];
    }

    protected function numeric(): array
    {
        return ['amount'];
    }

    protected function rows(): array
    {
        $user = $this->actor();
        $query = match ($this->kind) {
            'week' => Queries::chequesToDeposit($user, Queries::weekEnd()),
            'bounced' => Queries::bouncedCheques($user),
            'return' => Queries::chequesToReturn($user),
            default => Queries::chequesToDeposit($user, now('Asia/Bahrain')->toDateString()),
        };

        return $query->when($this->building, fn ($q, $b) => $q->whereHas('agreement.agreementUnits.unit', fn ($u) => $u->where('building_id', $b)))
            ->with('customer:id,name_en')->orderBy('cheque_date')->orderBy('id')->get()
            ->map(fn (Cheque $c) => [
                '_url' => route('cheques.show', $c),
                'cheque' => $c->cheque_no,
                'customer' => $c->customer?->name_en,
                'bank' => $c->bank_name,
                'date' => $c->cheque_date->format('d/m/Y'),
                'amount' => $c->amount,
            ])->values()->all();
    }
}
```
Add `['route' => 'reports.cheques', 'label' => __('Cheques')]` to the `operational` group in `Reports\Index`. Show it only when the viewer also holds `cheques.manage` or `finance.view`: filter the operational list in `render()`. Add the route with `can:reports.operational`.

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Reports tests/Feature/Cheques tests/Feature/Agreements tests/Feature/Billing
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Report cheques to deposit, bounced and to return

Cheques to deposit today or this week, bounced cheques awaiting action,
and held cheques to return. Re-billing now flags the cheques it leaves
without an invoice, so a cheque that never had one is no longer listed
as one to return.
EOF
```

---

### Task 4: Outstanding by customer and overdue ageing

**Spec:**
- §10 financial reports (`reports.financial`):
  - "outstanding by customer (Σ invoice balances − customer credit)";
  - "overdue ageing by days past `grace_until`: 1–30 / 31–60 / 61–90 / 91+".
- §7.10 (balances are issued invoices only).

**Files:**
- Create: `app/Livewire/Reports/{OutstandingReport,AgeingReport}.php`, `tests/Feature/Reports/ReceivablesReportsTest.php`
- Modify: `app/Reports/Queries.php`, `app/Livewire/Reports/Index.php`, `routes/property.php`

**Interfaces:**
- Produces:
  - `Queries::openInvoices(User $user, ?int $buildingId = null): Builder<Invoice>`: issued, non-credit-note invoices with balance > 0, scoped, optionally to one building through their lines' units.
  - `Queries::overdueInvoices(User $user, ?int $buildingId = null): Builder<Invoice>`: open invoices with `grace_until < today`.
  - Routes `reports.outstanding` and `reports.ageing`, both `can:reports.financial`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reports/ReceivablesReportsTest.php`:
```php
<?php

use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Reports\AgeingReport;
use App\Livewire\Reports\OutstandingReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['default_grace_days' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    app(\App\Actions\EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->sara = Customer::factory()->create(['name_en' => 'Sara Ahmed']);
    $this->omar = Customer::factory()->create(['name_en' => 'Omar Ali']);
    $invoice = fn (Customer $c, string $net, string $due) => issuedInvoice($c, [['net' => $net]], $due);
    $invoice($this->sara, '100.000', '2026-06-01'); // grace to 06-06: 4 days past → 1–30
    $invoice($this->sara, '200.000', '2026-04-01'); // grace to 04-06: 65 days past → 61–90
    $invoice($this->omar, '50.000', '2026-06-20');  // not yet due
    app(RecordPayment::class)->handle($this->finance, $this->omar, ['received_on' => '2026-06-10', 'method' => 'cash', 'amount' => '80.000']); // 50 allocated, 30 credit
});

test('outstanding by customer is invoice balances less customer credit', function () {
    Livewire::actingAs($this->finance)->test(OutstandingReport::class)
        ->assertSeeInOrder(['Sara Ahmed', '300.000', '0.000', '300.000'])
        ->assertSeeInOrder(['Omar Ali', '0.000', '30.000', '-30.000']);
});

test('ageing buckets overdue balances by days past grace', function () {
    Livewire::actingAs($this->finance)->test(AgeingReport::class)
        ->assertSeeInOrder(['Sara Ahmed', '100.000', '0.000', '200.000', '0.000', '300.000'])
        ->assertDontSee('Omar Ali');
});

test('the receivables reports need reports.financial', function () {
    $this->actingAs(matrixUser(RoleName::Leasing, $this->building))->get(route('reports.outstanding'))->assertForbidden();
});
```
Check how `issuedInvoice` sets `grace_until` (via `IssueInvoice`, from the default grace days), and keep the bucket arithmetic right if it differs. A customer with no open invoice and no credit is left out of the outstanding report.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Reports/ReceivablesReportsTest.php`
Expected: FAIL with `Class "App\Livewire\Reports\OutstandingReport" not found`.

- [ ] **Step 3: Queries and reports**

Add to `app/Reports/Queries.php`:
```php
    /** Issued invoices with a balance (credit notes never carry one, spec §7.10). @return Builder<Invoice> */
    public static function openInvoices(User $user, ?int $buildingId = null): Builder
    {
        return Invoice::query()->visibleTo($user)->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote)
            ->where('balance', '>', 0)
            ->when($buildingId, fn (Builder $q, int $b) => $q->whereHas('lines', fn (Builder $l) => $l->whereIn('unit_id', Unit::query()->where('building_id', $b)->select('id'))));
    }

    /** Open invoices past their grace date (spec §10 ageing; §2 overdue = due date + grace days). @return Builder<Invoice> */
    public static function overdueInvoices(User $user, ?int $buildingId = null): Builder
    {
        return self::openInvoices($user, $buildingId)->where('grace_until', '<', now('Asia/Bahrain')->toDateString());
    }
```
(The imports are `App\Enums\{InvoiceStatus, InvoiceType}` and `App\Models\Invoice`.)

Create `app/Livewire/Reports/OutstandingReport.php`:
```php
<?php

namespace App\Livewire\Reports;

use App\Billing\CustomerCredit;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Customer;
use App\Reports\Queries;
use App\Support\Fils;
use Livewire\Component;

/** Spec §10: outstanding by customer = Σ invoice balances − customer credit. */
class OutstandingReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Outstanding by customer');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.financial');
    }

    protected function dateMode(): string
    {
        return 'none'; // live balances
    }

    protected function columns(): array
    {
        return ['customer' => __('Customer'), 'balance' => __('Invoice balances'), 'credit' => __('Credit'), 'outstanding' => __('Outstanding')];
    }

    protected function numeric(): array
    {
        return ['balance', 'credit', 'outstanding'];
    }

    protected function rows(): array
    {
        $balances = Queries::openInvoices($this->actor(), $this->building)->selectRaw('customer_id, SUM(balance) AS total')->groupBy('customer_id')->pluck('total', 'customer_id');
        $customers = Customer::query()->visibleTo($this->actor())->orderBy('name_en')->get(['id', 'name_en']);

        return $customers->map(function (Customer $c) use ($balances) {
            $balance = Fils::fromDecimal((string) ($balances[$c->id] ?? '0'));
            $credit = CustomerCredit::fils($c->id);

            return $balance === 0 && $credit === 0 ? null : [
                '_url' => route('customers.statement', $c),
                'customer' => $c->name_en,
                'balance' => Fils::toDecimal($balance),
                'credit' => Fils::toDecimal($credit),
                'outstanding' => Fils::toDecimal($balance - $credit),
            ];
        })->filter()->values()->all();
    }
}
```
This makes one `CustomerCredit::fils` call per customer. Add `// ponytail: one credit query per customer; batch it if the customer list passes a few thousand.`

When a building is chosen, list only customers that have an open invoice in that building: filter `$customers` to the keys of `$balances`.

Create `app/Livewire/Reports/AgeingReport.php` in the same shape:
- title `__('Overdue ageing')`;
- `dateMode` `'none'`;
- columns `customer`, `d30` (`1–30`), `d60` (`31–60`), `d90` (`61–90`), `d91` (`91+`) and `total`, all numeric except `customer`;
- rows: for each customer with any overdue invoice in `Queries::overdueInvoices($this->actor(), $this->building)`, sum `balance` into a bucket by `days past = grace_until → today`. 1–30 goes in `d30`, 31–60 in `d60`, 61–90 in `d90`, more in `d91`. Do the sums in fils and output the decimals.

Write the bucket code out in full.

Add both routes, with `can:reports.financial`, and two `financial` entries to `Reports\Index`.

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Reports
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Report outstanding balances and overdue ageing

Outstanding by customer nets open invoice balances against customer
credit; overdue ageing buckets each customer's overdue balance by days
past grace into 1–30, 31–60, 61–90 and 91+.
EOF
```

---
### Task 5: Collections and deposits held

**Spec:** §10 financial reports: "collections by date and method — `deposit_applied` excluded and shown separately"; "deposits held". §7.6: the deposit held per agreement unit is Σ movements.

**Files:**
- Create: `app/Livewire/Reports/{CollectionsReport,DepositsHeldReport}.php`, `tests/Feature/Reports/CollectionsAndDepositsReportsTest.php`
- Modify: `app/Reports/Queries.php`, `app/Livewire/Reports/Index.php`, `routes/property.php`

**Interfaces:**
- Produces:
  - `Queries::collections(User $user, string $from, string $to, ?int $buildingId = null): Builder<Payment>` returns confirmed payments of visible customers received in the range. When a building is chosen, it keeps customers with an agreement unit in that building.
  - Routes `reports.collections` and `reports.deposits`, both `can:reports.financial`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reports/CollectionsAndDepositsReportsTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\CollectionsReport;
use App\Livewire\Reports\DepositsHeldReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($this->building)->create(['code' => 'A-1'])]);
    $pay = fn (string $on, string $method, string $amount, string $status = 'confirmed') => tap((new Payment)->forceFill([
        'number' => 'RCP-T-'.uniqid(), 'customer_id' => $this->customer->id, 'received_on' => $on, 'method' => $method, 'amount' => $amount,
        'status' => $status, 'recorded_by' => User::factory()->create()->id, 'posted_at' => now(),
    ]))->save();
    $pay('2026-06-01', 'cash', '100.000');
    $pay('2026-06-01', 'bank_transfer', '50.000');
    $pay('2026-06-03', 'cash', '20.000');
    $pay('2026-06-04', 'deposit_applied', '30.000');
    $pay('2026-06-05', 'cash', '999.000', 'reversed');
});

test('collections by date and method, deposit applied shown separately and not counted', function () {
    Livewire::actingAs($this->finance)->test(CollectionsReport::class)->set('from', '2026-06-01')->set('to', '2026-06-30')
        ->assertSeeInOrder(['01/06/2026', 'Bank transfer', '50.000', '01/06/2026', 'Cash', '100.000', '03/06/2026', 'Cash', '20.000', 'Total collected', '170.000', 'Deposit applied', '30.000'])
        ->assertDontSee('999.000');
});

test('deposits held per agreement unit as at a date', function () {
    $au = $this->agreement->agreementUnits()->sole();
    DepositMovement::create(['agreement_unit_id' => $au->id, 'type' => 'opening', 'amount' => '400.000', 'source_type' => 'import', 'source_id' => $this->agreement->id, 'posted_at' => '2026-05-01 10:00:00']);
    DepositMovement::create(['agreement_unit_id' => $au->id, 'type' => 'refunded', 'amount' => '-100.000', 'source_type' => 'disbursement', 'source_id' => 1, 'posted_at' => '2026-06-08 10:00:00']);

    Livewire::actingAs($this->finance)->test(DepositsHeldReport::class)->set('to', '2026-06-01')->assertSee('A-1')->assertSee('400.000');
    Livewire::actingAs($this->finance)->test(DepositsHeldReport::class)->set('to', '2026-06-10')->assertSee('300.000');
});
```
If `payments` has CHECKs that this `forceFill` breaks (for example, a reversal must carry `reversed_at`), add the required columns. Don't drop the reversed row.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Reports/CollectionsAndDepositsReportsTest.php`
Expected: FAIL with `Class "App\Livewire\Reports\CollectionsReport" not found`.

- [ ] **Step 3: Implement**

Add to `app/Reports/Queries.php`:
```php
    /** @return Builder<Payment> */
    public static function collections(User $user, string $from, string $to, ?int $buildingId = null): Builder
    {
        return Payment::query()->where('status', PaymentStatus::Confirmed)->whereBetween('received_on', [$from, $to])
            ->whereIn('customer_id', Customer::query()->visibleTo($user)->select('id'))
            ->when($buildingId, fn (Builder $q, int $b) => $q->whereIn('customer_id', Agreement::query()
                ->whereHas('agreementUnits.unit', fn (Builder $u) => $u->where('building_id', $b))->select('customer_id')));
    }
```
(Use the imports `App\Enums\PaymentStatus` and `App\Models\Payment`.)

Create `app/Livewire/Reports/CollectionsReport.php`:
- title `__('Collections by date and method')`, permission `reports.financial`, `dateMode` `'range'`;
- columns `date` (Date), `method` (Method), `count` (Payments), `amount` (Amount); numeric `count` and `amount`;
- rows:
  1. Group `Queries::collections(...)` excluding `PaymentMethod::DepositApplied` by `received_on` then method. Order by date, then by the method's label. Give each group its count and its Σ amount in fils.
  2. Add a row `['date' => '', 'method' => __('Total collected'), 'count' => <n>, 'amount' => <Σ>]`.
  3. Add a row `['date' => '', 'method' => __('Deposit applied (not a collection)'), 'count' => <n>, 'amount' => <Σ deposit_applied>]` when there is any.

  Use `PaymentMethod::label()` for the method text. Check that it exists. If it doesn't, use `str($m->value)->headline()`, which gives "Bank transfer".

Write the grouping out in full:
```php
    protected function rows(): array
    {
        $payments = Queries::collections($this->actor(), $this->from, $this->to, $this->building)->get(['received_on', 'method', 'amount']);
        [$applied, $collected] = $payments->partition(fn ($p) => $p->method === PaymentMethod::DepositApplied);

        $rows = $collected->groupBy(fn ($p) => $p->received_on->toDateString().'|'.$p->method->label())->sortKeys()
            ->map(fn ($group) => [
                'date' => $group->first()->received_on->format('d/m/Y'),
                'method' => $group->first()->method->label(),
                'count' => $group->count(),
                'amount' => Fils::toDecimal($group->sum(fn ($p) => Fils::fromDecimal($p->amount))),
            ])->values()->all();

        $rows[] = ['date' => '', 'method' => __('Total collected'), 'count' => $collected->count(), 'amount' => Fils::toDecimal($collected->sum(fn ($p) => Fils::fromDecimal($p->amount)))];
        if ($applied->isNotEmpty()) {
            $rows[] = ['date' => '', 'method' => __('Deposit applied (not a collection)'), 'count' => $applied->count(), 'amount' => Fils::toDecimal($applied->sum(fn ($p) => Fils::fromDecimal($p->amount)))];
        }

        return $rows;
    }
```

Create `app/Livewire/Reports/DepositsHeldReport.php`:
- title `__('Deposits held')`, permission `reports.financial`, `dateMode` `'single'` (the "as at" date);
- columns `unit` (Building / unit), `agreement`, `customer`, `held`; numeric `held`;
- rows: `deposit_movements` summed by `agreement_unit_id` where `posted_at ≤ <as at> 23:59:59`, joined to `agreement_units` → `units` (in `Unit::visibleTo`, and in the chosen building when set) → `agreements` → `customers`, keeping `held > 0`, ordered by building code then unit code;
- then a final `Total` row.

Write it with the query builder in full.

Add both routes (`can:reports.financial`) and both entries to the `financial` group in `Reports\Index`.

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Reports
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Report collections by date and method, and deposits held

Collections group confirmed payments by day and method, with deposits
applied listed separately and left out of the total. Deposits held
shows each agreement unit's balance as at a date.
EOF
```

---

### Task 6: VAT summary

**Spec:** §10: "VAT summary by period: output VAT by category from issued invoices and credit notes, plus VAT on management fees from `owner_charges` by `posted_at`".

**Files:**
- Create: `app/Livewire/Reports/VatSummaryReport.php`, `tests/Feature/Reports/VatSummaryReportTest.php`
- Modify: `app/Livewire/Reports/Index.php`, `routes/property.php`

**Interfaces:**
- Produces: route `reports.vat` (`can:reports.financial`)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reports/VatSummaryReportTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reports\VatSummaryReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerCharge;
use App\Models\OwnerStatement;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $customer = Customer::factory()->create();
    $standard = issuedInvoice($customer, [['net' => '100.000', 'tax' => 'standard'], ['net' => '40.000', 'tax' => 'exempt']], '2026-06-05');
    $cn = app(SaveCreditNote::class)->handle($this->finance, $standard, null, ['reason' => 'x', 'lines' => [['credited_line_id' => $standard->lines->firstWhere('tax_category', 'standard')->id, 'amount' => '11.000']]]);
    app(DecideApproval::class)->handle(matrixUser(RoleName::Management, $this->building), app(SubmitCreditNote::class)->handle($this->finance, $cn), true);

    $contract = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'fixed', 'fee_value' => '50.000'], []);
    $statement = (new OwnerStatement)->forceFill(['owner_contract_id' => $contract->id, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'cutoff_at' => '2026-05-31 23:59:59', 'status' => 'draft', 'created_by' => $contract->created_by]);
    $statement->save();
    OwnerCharge::create(['owner_contract_id' => $contract->id, 'owner_statement_id' => $statement->id, 'type' => 'management_fee', 'net' => '50.000', 'tax_amount' => '5.000', 'amount' => '-55.000', 'posted_at' => '2026-06-01 00:00:00', 'created_by' => $contract->created_by]);
});

test('output VAT by category from invoices less credit notes, plus VAT on management fees', function () {
    Livewire::actingAs($this->finance)->test(VatSummaryReport::class)->set('from', '2026-06-01')->set('to', '2026-06-30')
        ->assertSeeInOrder(['Invoices', 'Standard', '100.000', '10.000'])
        ->assertSeeInOrder(['Invoices', 'Exempt', '40.000', '0.000'])
        ->assertSeeInOrder(['Credit notes', 'Standard', '-10.000', '-1.000'])
        ->assertSeeInOrder(['Management fees', 'Standard', '50.000', '5.000'])
        ->assertSeeInOrder(['Total output VAT', '14.000']);
});
```
Before running, check two things and correct the test to match, recording the change in your report:
- **Credit-note sign and split.** The credit note on the standard line credits 11.000 gross, which `CreditNoteSplit` splits as 10.000 net and 1.000 VAT.
- **Category label.** The label comes from `TaxCategory::label()`, or `headline()` if that doesn't exist.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Reports/VatSummaryReportTest.php`
Expected: FAIL with `Class "App\Livewire\Reports\VatSummaryReport" not found`.

- [ ] **Step 3: Implement**

Create `app/Livewire/Reports/VatSummaryReport.php`:
```php
<?php

namespace App\Livewire\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OwnerChargeType;
use App\Enums\TaxCategory;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Customer;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Spec §10: output VAT by category from issued invoices and credit notes (by issue date), plus VAT on management fees (by posted_at). */
class VatSummaryReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('VAT summary');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.financial');
    }

    protected function columns(): array
    {
        return ['source' => __('Source'), 'category' => __('Tax category'), 'net' => __('Net'), 'vat' => __('VAT')];
    }

    protected function numeric(): array
    {
        return ['net', 'vat'];
    }

    protected function rows(): array
    {
        $lines = fn (bool $credit) => DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.status', InvoiceStatus::Issued->value)->where('i.type', $credit ? '=' : '<>', InvoiceType::CreditNote->value)
            ->whereBetween('i.issue_date', [$this->from, $this->to])
            ->whereIn('i.customer_id', Customer::query()->visibleTo($this->actor())->select('id'))
            ->when($this->building, fn ($q, $b) => $q->whereIn('l.unit_id', Unit::query()->where('building_id', $b)->select('id')))
            ->groupBy('l.tax_category')->selectRaw('l.tax_category AS category, SUM(l.net) AS net, SUM(l.tax_amount) AS vat')->get();

        $fees = DB::table('owner_charges as c')->where('c.type', OwnerChargeType::ManagementFee->value)
            ->whereBetween('c.posted_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->whereIn('c.owner_contract_id', OwnerContract::visibleTo($this->actor())->when($this->building, fn ($q, $b) => $q->where('building_id', $b))->select('id'))
            ->selectRaw('SUM(c.net) AS net, SUM(c.tax_amount) AS vat')->first();

        $rows = [];
        $total = 0;
        foreach ([[__('Invoices'), $lines(false), 1], [__('Credit notes'), $lines(true), -1]] as [$source, $groups, $sign]) {
            foreach ($groups as $g) {
                $vat = $sign * Fils::fromDecimal((string) $g->vat);
                $total += $vat;
                $rows[] = ['source' => $source, 'category' => TaxCategory::from($g->category)->label(),
                    'net' => Fils::toDecimal($sign * Fils::fromDecimal((string) $g->net)), 'vat' => Fils::toDecimal($vat)];
            }
        }
        if ($fees !== null && $fees->net !== null) {
            $total += Fils::fromDecimal((string) $fees->vat);
            $rows[] = ['source' => __('Management fees'), 'category' => TaxCategory::Standard->label(),
                'net' => Fils::toDecimal(Fils::fromDecimal((string) $fees->net)), 'vat' => Fils::toDecimal(Fils::fromDecimal((string) $fees->vat))];
        }
        $rows[] = ['source' => __('Total output VAT'), 'category' => '', 'net' => '', 'vat' => Fils::toDecimal($total)];

        return $rows;
    }
}
```
- **Label fallback.** If `TaxCategory` has no `label()`, add one that returns the value in headline case: "Standard", "Zero rated", "Exempt", "Out of scope".
- **Line sign.** Credit-note lines are stored with positive amounts (BuildCreditNote), so the report subtracts them. Check this and report what you find.
- **Index and route.** Add the `financial` entry to `Reports\Index` and the route with `can:reports.financial`.

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Reports
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Report the VAT summary by period

Output VAT by tax category from issued invoices less credit notes, by
issue date, plus VAT on management fees by posting date, with the
period's total.
EOF
```

---

### Task 7: The Management dashboard

**Spec:**
- §10: "Management dashboard: 8 number tiles, no charts; each tile needs its report's permission."
- The tiles are listed in the spec's table: Occupancy %, Rent due this month, Collected this month, Overdue total, Cheques to deposit this week, Open bounced cheques, Expiring in 60 days, Pending approvals.
- Plan rulings 2–4 and 8 also apply.

**Files:**
- Create: `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`, `tests/Feature/Reports/DashboardTilesTest.php`
- Modify: `routes/web.php`, `tests/Feature/DashboardTest.php` (only if it relies on the old view)
- Delete: `resources/views/dashboard.blade.php`

**Interfaces:**
- Consumes: the `Queries` methods from Tasks 1–5
- Produces: `Dashboard::tiles(User $user): list<array{label: string, value: string, url: string}>`, which returns only the tiles the user may see

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reports/DashboardTilesTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\Dashboard;
use App\Models\Building;
use App\Models\Cheque;
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
    CompanySetting::factory()->create(['default_grace_days' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    app(\App\Actions\EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    [$u1, $u2] = Unit::factory()->for($this->building)->count(2)->create()->all();
    $this->customer = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-07-15'], [$u1]); // expires in 35 days
    issuedInvoice($this->customer, [['net' => '400.000']], '2026-06-01', $agreement);  // a rent invoice due this month, overdue (grace 06-06)
    (new Cheque)->forceFill(['direction' => 'received', 'customer_id' => $this->customer->id, 'cheque_no' => 'C1', 'bank_name' => 'NBB', 'cheque_date' => '2026-06-12',
        'amount' => '400.000', 'status' => 'held', 'created_by' => User::factory()->create()->id])->save();
});

test('Management sees all 8 tiles with this company\'s figures', function () {
    $tiles = collect(Dashboard::tiles(matrixUser(RoleName::Management, $this->building)))->pluck('value', 'label');

    expect($tiles->keys()->all())->toBe(['Occupancy', 'Rent due this month', 'Collected this month', 'Overdue total', 'Cheques to deposit this week', 'Open bounced cheques', 'Expiring in 60 days', 'Pending approvals'])
        ->and($tiles['Occupancy'])->toBe('50.0%')
        ->and($tiles['Rent due this month'])->toBe('400.000')
        ->and($tiles['Overdue total'])->toBe('400.000')
        ->and($tiles['Cheques to deposit this week'])->toBe('1')
        ->and($tiles['Expiring in 60 days'])->toBe('1');
});

test('each tile needs its report\'s permission', function () {
    $leasing = collect(Dashboard::tiles(matrixUser(RoleName::Leasing, $this->building)))->pluck('label')->all();
    expect($leasing)->toBe(['Occupancy', 'Expiring in 60 days']); // operational only: no financial, cheque or approval tiles

    Livewire::actingAs(matrixUser(RoleName::Management, $this->building))->test(Dashboard::class)->assertSee('Pending approvals')->assertSee('50.0%');
});
```
`issuedInvoice` makes a `rent` invoice only when an agreement is passed. Check this. If `activeAgreement` already generated a scheduled rent invoice due this month, add its total to the expected "Rent due this month".

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Reports/DashboardTilesTest.php`
Expected: FAIL with `Class "App\Livewire\Dashboard" not found`.

- [ ] **Step 3: Implement**

Create `app/Livewire/Dashboard.php`:
```php
<?php

namespace App\Livewire;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use App\Reports\Queries;
use App\Support\Fils;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Spec §10: 8 number tiles, no charts; each tile needs its report's permission and links to it. */
class Dashboard extends Component
{
    use WithActor;

    /** @return list<array{label: string, value: string, url: string}> */
    public static function tiles(User $user): array
    {
        $today = now('Asia/Bahrain');
        $monthStart = $today->startOfMonth()->toDateString();
        $monthEnd = $today->endOfMonth()->toDateString();
        $operational = $user->can('reports.operational');
        $financial = $user->can('reports.financial');
        $cheques = $operational && ($user->can('cheques.manage') || $user->can('finance.view'));
        $money = fn (string $decimal) => Fils::toDecimal(Fils::fromDecimal($decimal));
        $tiles = [];

        if ($operational) {
            $units = Unit::query()->visibleTo($user)->where('blocked', false)->count();
            $occupied = Unit::query()->visibleTo($user)->where('blocked', false)->whereIn('id', Queries::occupiedUnitIds($user, $today->toDateString()))->count();
            $tiles[] = ['label' => __('Occupancy'), 'value' => $units > 0 ? number_format(100 * $occupied / $units, 1).'%' : '—', 'url' => route('reports.occupancy')];
        }
        if ($financial) {
            $tiles[] = ['label' => __('Rent due this month'), 'url' => route('reports.outstanding'), 'value' => $money((string) (Invoice::query()->visibleTo($user)
                ->where('type', InvoiceType::Rent)->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Scheduled])
                ->whereBetween('due_date', [$monthStart, $monthEnd])->sum('total') ?: '0'))];
            $tiles[] = ['label' => __('Collected this month'), 'url' => route('reports.collections', ['from' => $monthStart, 'to' => $today->toDateString()]),
                'value' => $money((string) (Queries::collections($user, $monthStart, $monthEnd)->where('method', '!=', PaymentMethod::DepositApplied)->sum('amount') ?: '0'))];
            $tiles[] = ['label' => __('Overdue total'), 'url' => route('reports.ageing'), 'value' => $money((string) (Queries::overdueInvoices($user)->sum('balance') ?: '0'))];
        }
        if ($cheques) {
            $tiles[] = ['label' => __('Cheques to deposit this week'), 'url' => route('reports.cheques', ['kind' => 'week']), 'value' => (string) Queries::chequesToDeposit($user, Queries::weekEnd())->count()];
            $tiles[] = ['label' => __('Open bounced cheques'), 'url' => route('reports.cheques', ['kind' => 'bounced']), 'value' => (string) Queries::bouncedCheques($user)->count()];
        }
        if ($operational) {
            $tiles[] = ['label' => __('Expiring in 60 days'), 'url' => route('reports.expiring', ['window' => '60']), 'value' => (string) Queries::expiringAgreements($user, 60)->count()];
        }
        if ($user->can('approvals.decide')) {
            $tiles[] = ['label' => __('Pending approvals'), 'url' => route('approvals.index'), 'value' => (string) Queries::approvalsToDecide($user)->count()];
        }

        return $tiles;
    }

    public function render(): View
    {
        return view('livewire.dashboard', ['tiles' => self::tiles($this->actor())])->title(__('Dashboard'));
    }
}
```
**Dashboard tile order.** The spec table lists occupancy first and expiring seventh. The two operational tiles stay in that order: occupancy first, expiring after the cheque tiles. A viewer with only `reports.operational` therefore sees `['Occupancy', 'Expiring in 60 days']`.

Create `resources/views/livewire/dashboard.blade.php`:
```blade
<div class="space-y-4">
    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
    @if ($tiles === [])
        <flux:text>{{ __('Welcome. Use the menu to get started.') }}</flux:text>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($tiles as $tile)
                <a href="{{ $tile['url'] }}" wire:navigate class="block">
                    <flux:card class="space-y-1 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <flux:text>{{ $tile['label'] }}</flux:text>
                        <flux:heading size="xl" class="tabular-nums">{{ $tile['value'] }}</flux:heading>
                    </flux:card>
                </a>
            @endforeach
        </div>
    @endif
</div>
```
**Wiring.**
- In `routes/web.php`, replace `Route::view('dashboard', 'dashboard')->name('dashboard');` with `Route::livewire('dashboard', \App\Livewire\Dashboard::class)->name('dashboard');`. Import the class rather than fully qualifying it.
- Delete `resources/views/dashboard.blade.php`.
- Keep `tests/Feature/DashboardTest.php` passing. A user with no permissions still gets a 200 and the welcome text.

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Reports tests/Feature/DashboardTest.php
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the Management dashboard

Eight number tiles: occupancy, rent due and collected this month,
overdue total, cheques to deposit this week, open bounced cheques,
agreements expiring in 60 days and pending approvals. Each tile needs
its report's permission and links to that report, and each counts only
the viewer's buildings.
EOF
```

---

### Task 8: Scheduled digest emails

**Spec:** §12:
- Daily 07:00: Finance gets cheques to deposit today and this week, and bounces awaiting action.
- Daily 07:00: Management gets pending approvals and agreements expiring in 30/60/90 days.
- Monday 07:00: customer and owner ID documents expiring in the next 30 days.
- Recipients are active users with `cheques.manage`, `approvals.decide` or `customers.manage` respectively, excluding Vendor Support.
- Each email counts only the recipient's buildings, and contains counts and links only, never ID numbers.
- Plan ruling 6 also applies.

**Files:**
- Create: `app/Notifications/Digest.php`, `app/Actions/Digests/SendDigests.php`, `app/Console/Commands/SendDigestsCommand.php`, `tests/Feature/Jobs/DigestsTest.php`
- Modify: `routes/console.php`, `config/services.php`, `.env.example`

**Interfaces:**
- Consumes: the `Queries` methods from Tasks 1–3
- Produces:
  - `Digest(string $subject, list<array{label: string, count: int, url: string}> $items)`, a mail notification, queued
  - `SendDigests::handle(string $kind): int`, where `$kind` is `finance`, `management` or `documents`, returning the number of emails sent
  - command `rms:digests {kind}`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/DigestsTest.php`:
```php
<?php

use App\Actions\Digests\SendDigests;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Digest;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-06-08 07:00', 'Asia/Bahrain')); // a Monday
    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    $held = function (Building $b, string $date) {
        $customer = Customer::factory()->create();
        $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($b)->create()]);
        (new Cheque)->forceFill(['direction' => 'received', 'customer_id' => $customer->id, 'agreement_id' => $agreement->id, 'cheque_no' => uniqid(), 'bank_name' => 'NBB',
            'cheque_date' => $date, 'amount' => '100.000', 'status' => 'held', 'created_by' => User::factory()->create()->id])->save();
    };
    $held($this->mine, '2026-06-08');   // today
    $held($this->mine, '2026-06-12');   // this week
    $held($this->theirs, '2026-06-08'); // another building

    $role = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
    $role->givePermissionTo('cheques.manage', 'finance.view');
    $this->cashier = User::factory()->create()->assignRole($role);
    $this->cashier->buildings()->attach($this->mine->id);
});

test('the finance digest goes to cheques.manage holders, counted for their buildings, and not to Vendor Support or inactive users', function () {
    $finance = matrixUser(RoleName::Finance, $this->mine); // all buildings
    $vendor = matrixUser(RoleName::VendorSupport, $this->mine);
    $inactive = matrixUser(RoleName::Finance, $this->mine);
    $inactive->forceFill(['active' => false])->save();

    expect(app(SendDigests::class)->handle('finance'))->toBe(2);

    Notification::assertSentTo($this->cashier, Digest::class, fn (Digest $d) => collect($d->items)->pluck('count', 'label')->all() === [
        'Cheques to deposit today' => 1, 'Cheques to deposit this week' => 2, 'Bounced cheques awaiting action' => 0,
    ]);
    Notification::assertSentTo($finance, Digest::class, fn (Digest $d) => $d->items[0]['count'] === 2);
    Notification::assertNotSentTo([$vendor, $inactive, User::system()], Digest::class);
});

test('nothing to report means no email (plan ruling 6)', function () {
    Cheque::query()->update(['cheque_date' => '2026-12-31']);

    expect(app(SendDigests::class)->handle('finance'))->toBe(0);
    Notification::assertNothingSent();
});

test('the documents digest counts customer and owner ID documents and never shows an ID number', function () {
    $owner = \App\Models\Owner::factory()->create(['id_number' => '080101234']);
    $customer = Customer::factory()->create(['id_number' => '090202345']);
    foreach ([$owner, $customer] as $model) {
        Document::factory()->for($model, 'documentable')->create(['category' => 'id_copy', 'expires_on' => '2026-06-30']);
    }
    $manager = matrixUser(RoleName::PropertyManager, $this->mine); // customers.manage, owners.view, all buildings

    app(SendDigests::class)->handle('documents');

    Notification::assertSentTo($manager, Digest::class, function (Digest $d) use ($manager) {
        $html = (string) $d->toMail($manager)->render();

        return collect($d->items)->sum('count') === 2 && ! str_contains($html, '080101234') && ! str_contains($html, '090202345');
    });
});

test('scheduled at 07:00 daily, and Monday 07:00 for documents', function () {
    expect(scheduledEvent('rms:digests finance')->expression)->toBe('0 7 * * *')
        ->and(scheduledEvent('rms:digests management')->expression)->toBe('0 7 * * *')
        ->and(scheduledEvent('rms:digests documents')->expression)->toBe('0 7 * * 1');
});
```
The documents test makes PropertyManager the recipient. PropertyManager holds `customers.manage` and `owners.view`, and has all buildings in the seeder. Check that against `RolesAndPermissionsSeeder`, and adapt the test's recipient if it differs. Leasing also holds `customers.manage`, so it would receive a customers-only count. If `Document` has no factory, build the rows with `forceFill` as Task 2 does.

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Jobs/DigestsTest.php`
Expected: FAIL with `Class "App\Actions\Digests\SendDigests" not found`.

- [ ] **Step 3: Implement**

Create `app/Notifications/Digest.php`:
```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Spec §12: a morning digest — counts and links only, never ID numbers. */
class Digest extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<array{label: string, count: int, url: string}>  $items */
    public function __construct(public string $subject, public array $items) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);
        foreach ($this->items as $item) {
            $mail->line(__(':label: :count', ['label' => $item['label'], 'count' => $item['count']]).' — '.$item['url']);
        }

        return $mail->action(__('Open the system'), route('dashboard'));
    }
}
```
Create `app/Actions/Digests/SendDigests.php`:
```php
<?php

namespace App\Actions\Digests;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Notifications\Digest;
use App\Reports\Queries;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** Spec §12: the 07:00 digests, each counted for its recipient's own buildings (plan ruling 6: none when all counts are zero). */
final class SendDigests
{
    public function handle(string $kind): int
    {
        [$permission, $subject] = match ($kind) {
            'finance' => [PermissionName::ChequesManage, __('Cheques today')],
            'management' => [PermissionName::ApprovalsDecide, __('Approvals and expiring agreements')],
            'documents' => [PermissionName::CustomersManage, __('ID documents expiring in the next 30 days')],
            default => throw new InvalidArgumentException("Unknown digest: {$kind}"),
        };

        $sent = 0;
        foreach (self::recipients($permission) as $user) {
            $items = $this->items($kind, $user);
            if (collect($items)->sum('count') === 0) {
                continue;
            }
            $user->notify(new Digest($subject, $items));
            $sent++;
        }

        return $sent;
    }

    /** Active holders of the permission, never Vendor Support (spec §12) and never the System user (inactive). @return Collection<int, User> */
    private static function recipients(PermissionName $permission): Collection
    {
        return User::query()->where('active', true)->where('is_system', false)->permission($permission->value)->get()
            ->reject(fn (User $user) => $user->hasRole(RoleName::VendorSupport))->values();
    }

    /** @return list<array{label: string, count: int, url: string}> */
    private function items(string $kind, User $user): array
    {
        $today = now('Asia/Bahrain')->toDateString();

        return match ($kind) {
            'finance' => [
                ['label' => __('Cheques to deposit today'), 'count' => Queries::chequesToDeposit($user, $today)->count(), 'url' => route('reports.cheques', ['kind' => 'today'])],
                ['label' => __('Cheques to deposit this week'), 'count' => Queries::chequesToDeposit($user, Queries::weekEnd())->count(), 'url' => route('reports.cheques', ['kind' => 'week'])],
                ['label' => __('Bounced cheques awaiting action'), 'count' => Queries::bouncedCheques($user)->count(), 'url' => route('reports.cheques', ['kind' => 'bounced'])],
            ],
            'management' => [
                ['label' => __('Pending approvals'), 'count' => Queries::approvalsToDecide($user)->count(), 'url' => route('approvals.index')],
                ...array_map(fn (int $days) => ['label' => __('Agreements expiring in :d days', ['d' => $days]), 'count' => Queries::expiringAgreements($user, $days)->count(),
                    'url' => route('reports.expiring', ['window' => (string) $days])], [30, 60, 90]),
            ],
            default => (function () use ($user) {
                $documents = Queries::expiringIdDocuments($user, 30);
                $customers = $documents->where('documentable_type', (new Customer)->getMorphClass())->count();

                return [
                    ['label' => __('Customer ID documents'), 'count' => $customers, 'url' => route('reports.id-documents')],
                    ['label' => __('Owner ID documents'), 'count' => $documents->count() - $customers, 'url' => route('reports.id-documents')],
                ];
            })(),
        };
    }
}
```
**URLs in queued mail.** `route()` builds absolute URLs from `APP_URL`, which is correct for mail sent from the queue worker.

**The test asserts on the digest's items.** That is the data the mail is built from. If the `PermissionName` and `RoleName` imports differ from the M0 enums, match the enums.

Create `app/Console/Commands/SendDigestsCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Actions\Digests\SendDigests;
use Illuminate\Console\Command;

class SendDigestsCommand extends Command
{
    protected $signature = 'rms:digests {kind : finance | management | documents}';

    protected $description = 'Send a morning digest email (spec §12)';

    public function handle(SendDigests $send): int
    {
        $this->info(sprintf('%d digest(s) sent.', $send->handle((string) $this->argument('kind'))));

        return self::SUCCESS;
    }
}
```
In `routes/console.php`:
```php
Schedule::command('rms:digests finance')->dailyAt('07:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.digests_finance')), (string) $url);
Schedule::command('rms:digests management')->dailyAt('07:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.digests_management')), (string) $url);
Schedule::command('rms:digests documents')->weeklyOn(1, '07:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.digests_documents')), (string) $url);
```
Add the three heartbeat keys to `config/services.php` (`env('HEARTBEAT_DIGESTS_FINANCE')`, …) and to `.env.example`.

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Jobs
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Send the morning digest emails

At 07:00 Finance gets cheques to deposit today and this week and the
bounces awaiting action. Management gets pending approvals and
agreements expiring in 30/60/90 days. On Mondays, customer managers get
expiring ID documents. Each count covers only the recipient's own
buildings and links to its report. Nothing is sent when there is
nothing to report, and nothing goes to Vendor Support.
EOF
```

---

### Task 9: A held-back deposit invoice at settlement, and the reports permission matrix

**Spec:**
- §7.7: a settlement closes the unit's deposit.
- §4.6: hold-back.
- §14 flow 11: the permission matrix.
- §10: who may open which report.
- Plan ruling 9. M3b carry-over: "a settlement doesn't credit a scheduled (unissued) deposit invoice".

**Files:**
- Create: `tests/Feature/Permissions/ReportsPermissionMatrixTest.php`
- Modify: `app/Actions/Deposits/ApproveDepositSettlement.php`, `tests/Feature/Deposits/` (the existing settlement-approval test file)

**Interfaces:**
- Consumes: every report route from Tasks 1–6.

- [ ] **Step 1: Write the failing tests**

Add two tests to the existing deposit-settlement approval test file in `tests/Feature/Deposits`. Use its setup and helpers.
- **The held-back invoice is cancelled.** Give the settled unit a deposit invoice that is still `scheduled`. Create it with `forceFill` (`type` deposit, `status` draft), add one deposit line on that agreement unit, then set it to `scheduled`. Approving the settlement cancels the invoice: `status` is `cancelled`, the settlement is approved, and no credit note is raised for it.
- **A mixed invoice blocks the approval.** Give a scheduled deposit invoice two lines: one on the settled unit, one on a unit that stays. Approving is then refused with a `ValidationException` whose message contains `Issue deposit invoice`.

Create `tests/Feature/Permissions/ReportsPermissionMatrixTest.php`:
```php
<?php

use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use Database\Seeders\RolesAndPermissionsSeeder;

// Spec §14 flow 11 for M5b, §10: who may open which report.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
});

$operational = [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport];
$financial = [R::Admin, R::Management, R::Finance, R::VendorSupport];

test('report pages open for exactly the roles the spec allows', function (string $route, array $allowed) {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route($route))->getStatusCode();

        expect($status === 200)->toBe(in_array($role, $allowed, true), "{$route} as {$role->value} gave {$status}");
    }
})->with([
    ['reports.occupancy', $operational],
    ['reports.expiring', $operational],
    ['reports.overstays', $operational],
    ['reports.approvals', $operational],
    ['reports.id-documents', $operational],
    ['reports.cheques', [R::Admin, R::Management, R::Finance, R::VendorSupport]], // operational + (cheques.manage | finance.view)
    ['reports.outstanding', $financial],
    ['reports.ageing', $financial],
    ['reports.collections', $financial],
    ['reports.deposits', $financial],
    ['reports.vat', $financial],
    ['reports.index', R::cases()],
    ['dashboard', R::cases()],
]);
```
Check every role's permissions in `RolesAndPermissionsSeeder` against these lists. A mismatch is either a policy bug, which you fix, or a list error in this test. Report which it was.

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Deposits tests/Feature/Permissions/ReportsPermissionMatrixTest.php`
Expected: the two settlement tests FAIL, because the scheduled invoice stays scheduled. The matrix passes, or names a real permission gap.

- [ ] **Step 3: Implement**

In `ApproveDepositSettlement`, at the start of step 0, before crediting unpaid issued deposit lines, add:
```php
        // Plan ruling 9: a deposit invoice not yet issued (normally held back by a pending owner contract, §4.6) must
        // not bill a settled unit later. One wholly on settled units is cancelled; a mixed one is refused.
        $settled = $settlement->units->pluck('agreement_unit_id')->all();
        $pending = Invoice::query()->where('type', InvoiceType::Deposit)->whereIn('status', [InvoiceStatus::Draft, InvoiceStatus::Scheduled])
            ->whereHas('lines', fn ($q) => $q->whereIn('agreement_unit_id', $settled))->orderBy('id')->lockForUpdate()->with('lines')->get();
        foreach ($pending as $invoice) {
            if ($invoice->lines->contains(fn ($l) => ! in_array($l->agreement_unit_id, $settled, true))) {
                throw ValidationException::withMessages(['approval' => __('Issue deposit invoice :n for the other units first (it is held back), then approve this settlement.', ['n' => $invoice->label()])]);
            }
            $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();
        }
```
**Before writing this, check two things:**
- **Lock order.** It follows §7.2 (customer → … → invoice lines → invoices) relative to the locks this Action already takes. If the Action locks invoice lines first, lock these invoices after those lines. Say where you placed it.
- **Invoices guard trigger.** Check that it allows `draft → cancelled` and `scheduled → cancelled`. If it allows only one of them, keep to the allowed transition. For a draft, use the path `CancelDraftInvoice` takes internally.

- [ ] **Step 4: Run the whole suite and the static checks**

```bash
"$PHP" artisan test
"$PHP" vendor/bin/pint --test
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: every test passes; Pint and Larastan report nothing (fix what they report).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Close a held-back deposit invoice at settlement; check report access

Approving a deposit settlement now cancels a deposit invoice that was
never issued, so a settled unit can't be billed its deposit later; a
held-back invoice that also covers other units must be issued first.
Every report page is checked against the roles that may open it.
EOF
```

---

## Not in M5b

| Item | Where |
|---|---|
| Customer-facing reminders (SMS or email) | v2/v3 (§12) |
| Charts on the dashboard | not in v1 (§10: "no charts") |
| Customer statement, owner statement, head-lease payments due, building profitability | already built (M3a, M4); listed in the reports index |
| UAT sign-off, security review, training, cutover | `docs/go-live-runbook.md`, run with the client |
| A separate parking tax column in the agreements import; reserving the System user's email | carried from M5a, after go-live if the client needs them |
