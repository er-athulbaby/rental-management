# M2 Customers and Agreements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record customers, draft multi-unit agreements (across buildings and owners), submit them for Management approval, and on approval freeze a bilingual EN/AR contract with a QR verification page, generate the prorated rent schedule, issue the deposit invoice, and issue rent invoices with tax and owner attribution on time.

**Architecture:** Builds on M0 + M1 (branch `m1-property-owners`). Same pattern: thin Livewire screens → one Action per operation in one DB transaction → MySQL triggers guard each new table. Agreement activation is the second user of the M1 approvals engine (`ApprovalAction` case + `app/Approvals` handler). Billing maths is pure PHP in `app/Billing` (integer fils, unit-tested); `IssueInvoice` is the single place tax, owner stamping, numbers and grace dates are written.

**Tech Stack:** as M1 (Laravel 13.33, Livewire 4.4, Flux 2 free, MySQL 26.7, Pest 5, mPDF 8.3 + mpdf/qrcode, spatie/simple-excel). No new packages.

**Spec:** `docs/superpowers/specs/2026-09-28-rental-management-v1-design.md` — §15 M2 row; §4.3, §4.6, §5.1–§5.6, §5.4 notice, §6.1–§6.4, §6.8, §8.1–§8.5, §9.1–§9.4, §12, §14.

## Global Constraints

Everything in the M0 and M1 plans' Global Constraints still applies:
- Latest stable versions (D16); no Redis (D14); timezone Asia/Bahrain.
- Every monetary column `DECIMAL(12,3)`; PHP money maths in **integer fils** via `App\Support\Fils`; rounding half-up to the fil, once per line (§2, §6.2).
- Two DB users: migrations as `rms_migrate` (`migrate:fresh --database=migrator --force`); app and tests as `rms_app` (no DDL, no TRUNCATE). One statement per `DB::unprepared()`; triggers raise `SIGNAL SQLSTATE '45000'`.
- Actions are the only write path, one transaction each; Livewire model ids are `#[Locked]`; policies on every route and Livewire action.
- Building scope is decided by `Building::visibleTo()`; a user without `buildings.view-all` sees and writes only inside assigned buildings (§8.2).
- No secrets in the audit log; uploads private and allow-listed.
- English UI usable at 375 px; free Flux only; native `<input type=date>`/`<select>`; customer lookup is a text input with a results list (§2).
- Contract PDFs EN + AR side by side, IBM Plex Sans Arabic, one table row per clause paragraph, no raw HTML from users (§9.2).
- Tests: Pest function style, `$this->seed(RolesAndPermissionsSeeder::class)`. Users holding a sensitive permission (users/roles/settings/audit, finance.view, any finance `*.manage` — **Property Manager holds `expenses.manage`** — or approvals.decide) need `->withTwoFactor()` for HTTP tests; `Livewire::actingAs()` tests bypass that middleware.

## Deliberately NOT in M2 (and where it lands)

| Item | Spec | Lands in |
|---|---|---|
| Amendments (add/release unit, terminate), renewal, move-out, and the `renewed`/`closed`/`terminated` transitions | §5.7–§5.9, §5.4 | M3 (with their financial effects) — M2's 02:00 job only moves `active` → `expired` |
| Payments, allocations, customer credit and its auto-allocation, cheques | §7.1–§7.4, §6.3 | M3 |
| Manual invoices, credit notes, cancel-and-replace | §6.3, §6.5 | M3 — M2 invoices are only generated and issued |
| Deposit movements, settlements | §7.6–§7.7 | M3 |
| Invoice / tax invoice PDF, customer statement | §9.3 | M3 (M2 shows invoices on screen) |
| Import of customers and agreements | §11 | M5 |
| Scheduled emails (pending approvals, expiring agreements, ID expiry) | §12 | M5 |
| Per-record-type document categories and proper category labels | — | Deferred by the product owner (2026-09-29) |

## Rulings made while planning (spec gaps)

- **`{agreement_number}` in clause bodies:** the number is assigned on approval (§6.8) but clauses are rendered on submit (§5.6). Submit stores `{agreement_number}` literally; the PDF renderer replaces it with the number (or `DRAFT`). The number never changes after approval, so the frozen contract is still frozen.
- **`{units_table}`:** a clause whose body is exactly `{units_table}` renders as the bilingual schedule-of-units table in the PDF (built from the frozen agreement units). Clause text is never HTML.
- **Scheduled invoices are created as `draft`, their lines inserted, then flipped to `scheduled`** — so the §8.5 rule "invoice_lines: no INSERT once the parent is not draft" holds literally.
- **`notice_period_days` defaults to 30** (no setting exists for it).
- **Frequency reuses `App\Enums\PaymentFrequency`** (same four values as owner contracts).

## Working environment

```bash
export PHP="/c/Users/ababy/.config/herd/bin/php85/php.exe"
cd /c/Users/ababy/Documents/RentalManagementSystem
# composer: "$PHP" /c/Users/ababy/.config/herd/bin/composer.phar <cmd>   (a $COMPOSER var with a space breaks in Git Bash)
```
Branch: `m2-customers-agreements` (from `m1-property-owners`). Run `"$PHP" artisan migrate:fresh --database=migrator --force` after adding any migration.

## Conventions carried over (do not re-create)

M0: `App\Audit\Audit::log(event, subject, old, new, properties, causer)`, `App\Actions\NextDocumentNumber` (invokable; `NumberSequenceKey::Agreement` → `AGR-2026-000001`, `::Invoice` → `INV-…`; must run inside a transaction), `App\Actions\EnsureNumberSequences` (invokable with a year), `App\Support\Approvers`, `WithActor` (`$this->actor()`), `CompanySetting::current()` (`vat_registered`, `vat_rate` decimal:2, `residential_tax_category`, `commercial_tax_category`, `default_grace_days`, `invoice_lead_days`, `proration_basis` → `ProrationBasis::Actual365|Days30`, `name_en`, `name_ar`, `logo_path`), `App\Pdf\PdfRenderer::render(view, data, ['header','footer','watermark'])`, `App\Enums\{TaxCategory, DocumentCategory, NumberSequenceKey, PermissionName, RoleName, IdType}`.
M1: `App\Support\Fils` (`fromDecimal`, `toDecimal`, `rule`), `Building::visibleTo`, `Unit` (`visibleTo`, `effectiveTaxCategory()`, `status()`, `list_rent`, `blocked`), `OwnerContract` (`effectiveOn($date)` scope, statuses), `App\Approvals\ApprovalHandler`, `App\Enums\ApprovalAction`, `App\Actions\Approvals\{RequestApproval, DecideApproval}`, `App\Models\Approval`, `App\Livewire\Documents\Panel::ALLOWED`, `routes/property.php`, the sidebar groups in `resources/views/layouts/app/sidebar.blade.php`, test helper `activeOwnerContract()` in `tests/Pest.php`, and the validation-key remapping pattern (`form.*`) in `app/Livewire/Units/Form.php`.

---

### Task 1: Customers

**Spec:** §5.1 (fields, one master record, ID copies with `expires_on`), §8.1 (customers.view / customers.manage: Admin ✔, Management view, Finance view, Property Mgr ✔, Leasing ✔), §9.1

**Files:**
- Create: `app/Enums/CustomerType.php`, `database/migrations/2026_10_05_000100_create_customers_table.php`, `app/Models/Customer.php`, `database/factories/CustomerFactory.php`, `app/Policies/CustomerPolicy.php`, `app/Actions/Customers/SaveCustomer.php`, `app/Livewire/Customers/{Index,Form}.php`, `resources/views/livewire/customers/{index,form}.blade.php`, `tests/Feature/Customers/CustomersTest.php`
- Modify: `app/Livewire/Documents/Panel.php`, `resources/views/livewire/documents/panel.blade.php`, `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`

**Interfaces:**
- Consumes: `IdType`, `StoreDocument::handle(..., ?CarbonInterface $expiresOn)`, `WithActor`
- Produces: `CustomerType` (`Individual`, `Company`); `Customer` (`visibleTo($user)` scope — Task 3 narrows it, `maskedId(): string`, `displayName(string $lang): string`); `CustomerPolicy::viewAny/view/create/update`; `SaveCustomer::handle(User $actor, ?Customer $customer, array $data): Customer`; routes `customers.index`, `customers.create`, `customers.edit`; sidebar group **Leasing** (Tasks 4 and 10 add items); `Documents\Panel` gains an optional expiry date

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Customers/CustomersTest.php`:
```php
<?php

use App\Actions\Customers\SaveCustomer;
use App\Enums\RoleName;
use App\Livewire\Customers\Form;
use App\Livewire\Documents\Panel;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->person = [
        'type' => 'individual', 'name_en' => 'Mohammed Ali', 'name_ar' => 'محمد علي', 'id_type' => 'cpr', 'id_number' => '900101234',
        'nationality' => 'Bahraini', 'mobile' => '+97333112233', 'email' => 'm.ali@example.test',
    ];
});

test('Leasing records an individual customer', function () {
    $customer = app(SaveCustomer::class)->handle($this->leasing, null, $this->person);

    expect($customer->name_en)->toBe('Mohammed Ali')
        ->and($customer->maskedId())->toBe('••••1234')
        ->and($customer->displayName('ar'))->toBe('محمد علي');
});

test('companies use a CR and people a CPR or passport', function () {
    $company = app(SaveCustomer::class)->handle($this->leasing, null, [
        'type' => 'company', 'name_en' => 'Gulf Trading W.L.L.', 'id_type' => 'cr', 'id_number' => '12345-1', 'contact_person' => 'Sara', 'mobile' => '+97317000000',
    ]);
    expect($company->displayName('ar'))->toBe('Gulf Trading W.L.L.'); // falls back to English

    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'id_type' => 'cr', 'id_number' => '5']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, ['type' => 'company', 'name_en' => 'X', 'id_type' => 'cpr', 'id_number' => '6', 'mobile' => '+9731']))
        ->toThrow(ValidationException::class);
});

test('a duplicate ID names the existing customer with a masked ID only', function () {
    app(SaveCustomer::class)->handle($this->leasing, null, $this->person);

    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'name_en' => 'Someone Else']))
        ->toThrow(ValidationException::class, 'Already exists: Mohammed Ali, ID ••••1234');
});

test('Management views customers but cannot create them', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $customer = Customer::factory()->create();

    expect($management->can('view', $customer))->toBeTrue()
        ->and(fn () => app(SaveCustomer::class)->handle($management, null, $this->person))->toThrow(AuthorizationException::class);

    $this->actingAs($management)->get(route('customers.create'))->assertForbidden();
    $this->actingAs($this->leasing)->get(route('customers.create'))->assertOk();
});

test('the form saves and an ID copy keeps its expiry date', function () {
    Storage::fake('local');

    Livewire::actingAs($this->leasing)->test(Form::class)
        ->set('form.type', 'individual')
        ->set('form.name_en', 'Aisha Noor')
        ->set('form.id_type', 'passport')
        ->set('form.id_number', 'P1234567')
        ->set('form.mobile', '+97339998877')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $customer = Customer::where('id_number', 'P1234567')->sole();

    Livewire::actingAs($this->leasing)->test(Panel::class, ['documentable' => $customer])
        ->set('upload', UploadedFile::fake()->create('passport.pdf', 20, 'application/pdf'))
        ->set('category', 'id_copy')
        ->set('expiresOn', '2028-05-31')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('31/05/2028');

    expect($customer->documents()->sole()->expires_on->toDateString())->toBe('2028-05-31');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Customers`
Expected: FAIL — `Class "App\Actions\Customers\SaveCustomer" not found`.

- [ ] **Step 3: Enum, migration, model, factory, policy**

Create `app/Enums/CustomerType.php`:
```php
<?php

namespace App\Enums;

enum CustomerType: string
{
    case Individual = 'individual';
    case Company = 'company';
}
```
Create `database/migrations/2026_10_05_000100_create_customers_table.php`:
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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name_en', 150);
            $table->string('name_ar', 150)->nullable();
            $table->string('id_type', 20);
            $table->string('id_number', 30);
            $table->string('nationality', 60)->nullable();
            $table->string('mobile', 30);
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['id_type', 'id_number']);
            $table->index('mobile');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_type_chk CHECK (type IN ('individual', 'company')),
                ADD CONSTRAINT customers_id_type_chk CHECK ((type = 'company' AND id_type = 'cr') OR (type = 'individual' AND id_type IN ('cpr', 'passport')))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
```
Create `app/Models/Customer.php`:
```php
<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\IdType;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property CustomerType $type
 * @property string $name_en
 * @property string|null $name_ar
 * @property IdType $id_type
 * @property string $id_number
 * @property string $mobile
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'type', 'name_en', 'name_ar', 'id_type', 'id_number', 'nationality', 'mobile', 'email', 'address',
        'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes',
    ];

    protected function casts(): array
    {
        return ['type' => CustomerType::class, 'id_type' => IdType::class];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Spec §8.2. ponytail: every customer is visible until agreements exist; Task 3 narrows this to
     * "has an agreement with a unit in an assigned building, or no agreement yet".
     *
     * @param  Builder<Customer>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void {}

    public function maskedId(): string
    {
        return '••••'.substr($this->id_number, -4);
    }

    /** Contracts use name_ar in Arabic text, falling back to name_en (spec §5.6). */
    public function displayName(string $lang): string
    {
        return $lang === 'ar' && filled($this->name_ar) ? (string) $this->name_ar : $this->name_en;
    }
}
```
Create `database/factories/CustomerFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'type' => 'individual',
            'name_en' => fake()->name(),
            'id_type' => 'cpr',
            'id_number' => (string) fake()->unique()->numerify('#########'),
            'mobile' => '+973'.fake()->numerify('3#######'),
        ];
    }
}
```
Create `app/Policies/CustomerPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::CustomersView);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can(PermissionName::CustomersView) && Customer::visibleTo($user)->whereKey($customer->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::CustomersManage);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can(PermissionName::CustomersManage) && Customer::visibleTo($user)->whereKey($customer->getKey())->exists();
    }
}
```

- [ ] **Step 4: The Action**

Create `app/Actions/Customers/SaveCustomer.php`:
```php
<?php

namespace App\Actions\Customers;

use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Spec §5.1: one master record per customer. */
final class SaveCustomer
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Customer $customer, array $data): Customer
    {
        if (! ($customer ? $actor->can('update', $customer) : $actor->can('create', Customer::class))) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $data);

        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(CustomerType::class)],
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['required', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:60'],
            'mobile' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->after(function (ValidatorInstance $validator) use ($data, $customer) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyIdOk = $data['type'] === CustomerType::Company->value
                ? $data['id_type'] === IdType::Cr->value
                : in_array($data['id_type'], [IdType::Cpr->value, IdType::Passport->value], true);
            if (! $companyIdOk) {
                $validator->errors()->add('id_type', __('Companies are identified by CR; individuals by CPR or passport.'));
            }

            // Deliberately unscoped: a scoped user must learn the customer exists, but only its name and masked ID (spec §8.2).
            $existing = Customer::query()->where('id_type', $data['id_type'])->where('id_number', $data['id_number'])
                ->when($customer, fn ($q) => $q->whereKeyNot($customer->getKey()))->first();
            if ($existing) {
                $validator->errors()->add('id_number', __('Already exists: :name, ID :id', ['name' => $existing->name_en, 'id' => $existing->maskedId()]));
            }
        })->validate();

        return DB::transaction(function () use ($customer, $validated) {
            $customer ??= new Customer;
            $customer->fill($validated)->save();

            return $customer;
        });
    }
}
```

- [ ] **Step 5: Expiry date on the documents panel**

In `app/Livewire/Documents/Panel.php`:
- add `use App\Models\Customer;` and `use Carbon\CarbonImmutable;`, and add `Customer::class` to `ALLOWED`;
- add the property `public ?string $expiresOn = null;`;
- in `save()`, extend the validate call with `'expiresOn' => ['nullable', 'date_format:Y-m-d'],` and pass the date to the Action:
```php
            $store->handle($this->actor(), $this->documentable(), $this->upload, DocumentCategory::from($this->category),
                $this->expiresOn ? CarbonImmutable::parse($this->expiresOn) : null);
```
- change `$this->reset('upload');` to `$this->reset('upload', 'expiresOn');`.

In `resources/views/livewire/documents/panel.blade.php`:
- after the category badge in each list row add:
```blade
                    @if ($document->expires_on)
                        <flux:badge size="sm" :color="$document->expires_on->isPast() ? 'red' : 'zinc'">{{ __('Expires :date', ['date' => $document->expires_on->format('d/m/Y')]) }}</flux:badge>
                    @endif
```
- change `wire:model="category"` on the category select to `wire:model.live="category"`, and after the select add:
```blade
            @if (in_array($category, ['id_copy', 'cr_copy'], true))
                <flux:input wire:model="expiresOn" type="date" :label="__('Expires on')" class="sm:max-w-44" />
            @endif
```
(`Document` already casts `expires_on` to a date in M0; if not, add `'expires_on' => 'date'` to its casts.)

- [ ] **Step 6: Screens, routes, navigation**

Create `app/Livewire/Customers/Index.php`:
```php
<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Customers')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = trim($this->search);

        $customers = Customer::query()
            ->visibleTo($this->actor())
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name_en', 'like', '%'.$term.'%')
                ->orWhere('id_number', $term)
                ->orWhere('mobile', $term)))
            ->orderBy('name_en')
            ->paginate(25);

        return view('livewire.customers.index', ['customers' => $customers, 'hint' => null]);
    }
}
```
Create `resources/views/livewire/customers/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Customers') }}</flux:heading>
        @can('create', \App\Models\Customer::class)
            <flux:button variant="primary" :href="route('customers.create')" wire:navigate>{{ __('New customer') }}</flux:button>
        @endcan
    </div>

    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name, exact ID or mobile')" icon="magnifying-glass" class="sm:max-w-xs" />

    @if ($hint)
        <flux:callout icon="information-circle" :heading="$hint" />
    @endif

    <div class="overflow-x-auto">
        <flux:table :paginate="$customers">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('ID') }}</flux:table.column>
                <flux:table.column>{{ __('Mobile') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($customers as $customer)
                    <flux:table.row :key="$customer->id">
                        <flux:table.cell><flux:link :href="route('customers.edit', $customer)" wire:navigate>{{ $customer->name_en }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $customer->id_type->label() }} {{ $customer->id_number }}</flux:table.cell>
                        <flux:table.cell>{{ $customer->mobile }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/Customers/Form.php`:
```php
<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\SaveCustomer;
use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    use WithActor;

    #[Locked]
    public ?int $customerId = null;

    /** @var array<string, mixed> */
    public array $form = ['type' => 'individual', 'id_type' => 'cpr'];

    public function mount(?Customer $customer = null): void
    {
        if ($customer?->exists) {
            abort_unless($this->actor()->can('view', $customer), 403);
            $this->customerId = $customer->id;
            $this->form = [
                ...$customer->only(['name_en', 'name_ar', 'id_number', 'nationality', 'mobile', 'email', 'address', 'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes']),
                'type' => $customer->type->value,
                'id_type' => $customer->id_type->value,
            ];
        } else {
            abort_unless($this->actor()->can('create', Customer::class), 403);
        }
    }

    public function save(SaveCustomer $save): void
    {
        try {
            $customer = $save->handle($this->actor(), $this->customerId ? Customer::findOrFail($this->customerId) : null, $this->form);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Customer saved.'));
        $this->redirectRoute('customers.edit', $customer, navigate: true);
    }

    public function render(): View
    {
        $customer = $this->customerId ? Customer::findOrFail($this->customerId) : null;

        return view('livewire.customers.form', [
            'customer' => $customer,
            'types' => CustomerType::cases(),
            'idTypes' => IdType::cases(),
            'canEdit' => $customer ? $this->actor()->can('update', $customer) : true,
        ])->title($customer ? $customer->name_en : __('New customer'));
    }
}
```
Create `resources/views/livewire/customers/form.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $customer?->name_en ?? __('New customer') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:radio.group wire:model.live="form.type" :label="__('Customer type')" variant="segmented">
                <flux:radio value="individual" :label="__('Individual')" />
                <flux:radio value="company" :label="__('Company')" />
            </flux:radio.group>
            <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
            <flux:input wire:model="form.name_ar" :label="__('Name (Arabic, used in contracts)')" dir="rtl" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.id_type" :label="__('ID type')">
                    @foreach ($idTypes as $idType)<option value="{{ $idType->value }}">{{ $idType->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.id_number" :label="__('ID number')" required />
                <flux:input wire:model="form.mobile" :label="__('Mobile')" type="tel" required />
                <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                <flux:input wire:model="form.nationality" :label="__('Nationality')" />
                @if (($form['type'] ?? '') === 'company')
                    <flux:input wire:model="form.contact_person" :label="__('Contact person')" />
                @endif
                <flux:input wire:model="form.emergency_contact_name" :label="__('Emergency contact')" />
                <flux:input wire:model="form.emergency_contact_phone" :label="__('Emergency phone')" type="tel" />
            </div>
            <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" />
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($customer)
        <livewire:documents.panel :documentable="$customer" :key="'docs-customer-'.$customer->id" />
    @endif
</section>
```
In `routes/property.php` add `use App\Livewire\Customers;` and inside the group:
```php
    Route::livewire('customers', Customers\Index::class)->middleware('can:customers.view')->name('customers.index');
    Route::livewire('customers/create', Customers\Form::class)->middleware('can:customers.manage')->name('customers.create');
    Route::livewire('customers/{customer}/edit', Customers\Form::class)->middleware('can:customers.view')->name('customers.edit');
```
In `resources/views/layouts/app/sidebar.blade.php`, insert a new group directly before the Property group (Tasks 4 and 10 add items to it):
```blade
                <flux:sidebar.group :heading="__('Leasing')" class="grid">
                    @can('customers.view')
                        <flux:sidebar.item icon="identification" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate>{{ __('Customers') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Customers tests/Feature/Documents
"$PHP" artisan test
```
Expected: 5 new tests pass; full suite passes.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add customers, with ID copies that carry an expiry date

One master record per customer. A duplicate ID is reported by name and
masked ID only; companies use a CR, individuals a CPR or passport.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 2: Contract templates and the default bilingual template

**Spec:** §5.6 (templates with ordered bilingual clauses, Admin edits clauses, no raw HTML, merge fields), §9.2 (maximum paragraph length), §8.1 (templates.manage: Admin), §8.4 (templates and clauses audited)

**Files:**
- Create: `database/migrations/2026_10_05_000200_create_contract_templates_tables.php`, `app/Models/{ContractTemplate,ContractTemplateClause}.php`, `app/Actions/ContractTemplates/{SaveContractTemplate,EnsureDefaultContractTemplate}.php`, `app/Livewire/Admin/ContractTemplates/{Index,Edit}.php`, `resources/views/livewire/admin/contract-templates/{index,edit}.blade.php`, `tests/Feature/ContractTemplates/ContractTemplatesTest.php`
- Modify: `app/Console/Commands/InstallCommand.php`, `routes/admin.php`, `resources/views/layouts/app/sidebar.blade.php`, `tests/Feature/Install/InstallCommandTest.php`

**Interfaces:**
- Consumes: `Audit`, `PermissionName::TemplatesManage`
- Produces: `ContractTemplate` (`clauses()` ordered by position, `static defaultTemplate(): ?self`, consts `MERGE_FIELDS` (list of field names without braces) and `MAX_PARAGRAPH` = 1200); `ContractTemplateClause` (`position`, `heading_en`, `heading_ar`, `body_en`, `body_ar`); `ContractTemplate::paragraphs(string $body): list<string>` (split on blank lines — the PDF renders one row per paragraph); `SaveContractTemplate::handle(User $actor, ?ContractTemplate $template, array $data): ContractTemplate` with `$data['clauses']` a list of `{heading_en, heading_ar, body_en, body_ar}` in order; `EnsureDefaultContractTemplate::__invoke(): ContractTemplate`; routes `admin.templates.index`, `admin.templates.create`, `admin.templates.edit`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ContractTemplates/ContractTemplatesTest.php`:
```php
<?php

use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\ContractTemplates\SaveContractTemplate;
use App\Enums\RoleName;
use App\Livewire\Admin\ContractTemplates\Edit;
use App\Models\ContractTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->clause = ['heading_en' => 'Rent', 'heading_ar' => 'الإيجار', 'body_en' => 'Rent is BHD {total_monthly_rent}.', 'body_ar' => 'الإيجار {total_monthly_rent} دينار.'];
});

test('the default template is bilingual, uses only known merge fields and is created once', function () {
    $template = app(EnsureDefaultContractTemplate::class)();
    app(EnsureDefaultContractTemplate::class)();

    expect(ContractTemplate::count())->toBe(1)
        ->and(ContractTemplate::defaultTemplate()?->id)->toBe($template->id)
        ->and($template->clauses()->count())->toBeGreaterThanOrEqual(8);

    foreach ($template->clauses as $clause) {
        expect($clause->heading_ar)->not->toBe('')
            ->and($clause->body_ar)->toMatch('/\p{Arabic}|\{units_table\}/u');
        preg_match_all('/\{(\w+)\}/', $clause->body_en.$clause->body_ar, $m);
        expect(array_diff($m[1], ContractTemplate::MERGE_FIELDS))->toBe([]);
    }
});

test('Admin saves a template with ordered clauses; the change is audited', function () {
    $template = app(SaveContractTemplate::class)->handle($this->admin, null, [
        'name' => 'Commercial lease', 'active' => true, 'is_default' => false,
        'clauses' => [$this->clause, [...$this->clause, 'heading_en' => 'Deposit', 'body_en' => 'Deposit {total_deposit}.', 'body_ar' => 'التأمين {total_deposit}.']],
    ]);

    expect($template->clauses->pluck('heading_en')->all())->toBe(['Rent', 'Deposit'])
        ->and($template->clauses->pluck('position')->all())->toBe([1, 2])
        ->and(Activity::query()->where('event', 'contract_template.clauses_saved')->exists())->toBeTrue();
});

test('one default template at a time', function () {
    $first = app(EnsureDefaultContractTemplate::class)();
    $second = app(SaveContractTemplate::class)->handle($this->admin, null, ['name' => 'New default', 'active' => true, 'is_default' => true, 'clauses' => [$this->clause]]);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and(ContractTemplate::defaultTemplate()?->id)->toBe($second->id);
});

test('clauses are validated: known merge fields, matching paragraphs, paragraph length', function (array $clause) {
    expect(fn () => app(SaveContractTemplate::class)->handle($this->admin, null, ['name' => 'Bad', 'active' => true, 'is_default' => false, 'clauses' => [$clause]]))
        ->toThrow(ValidationException::class);
})->with([
    'unknown field' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => 'Pay {bank_iban}.', 'body_ar' => 'ادفع.']],
    'paragraphs differ' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => "One.\n\nTwo.", 'body_ar' => 'واحد.']],
    'too long' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => str_repeat('x', 1201), 'body_ar' => str_repeat('س', 10)]],
    'units table mixed' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => 'See {units_table} below', 'body_ar' => '{units_table}']],
]);

test('only templates.manage holders edit templates', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    expect(fn () => app(SaveContractTemplate::class)->handle($pm, null, ['name' => 'X', 'active' => true, 'is_default' => false, 'clauses' => [$this->clause]]))
        ->toThrow(AuthorizationException::class);
    $this->actingAs($pm)->get(route('admin.templates.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.templates.index'))->assertOk();
});

test('the editor adds, reorders and removes clauses', function () {
    $template = app(EnsureDefaultContractTemplate::class)();
    $count = $template->clauses()->count();

    Livewire::actingAs($this->admin)->test(Edit::class, ['template' => $template])
        ->call('addClause')
        ->set("clauses.{$count}.heading_en", 'Parking')
        ->set("clauses.{$count}.heading_ar", 'المواقف')
        ->set("clauses.{$count}.body_en", 'One parking space is included.')
        ->set("clauses.{$count}.body_ar", 'يشمل العقد موقفاً واحداً.')
        ->call('moveUp', $count)
        ->call('remove', 0)
        ->call('save')
        ->assertHasNoErrors();

    $headings = $template->fresh()->clauses->pluck('heading_en');
    expect($headings)->toHaveCount($count)
        ->and($headings[$count - 2])->toBe('Parking');
});
```
In `tests/Feature/Install/InstallCommandTest.php`, in the test that checks a successful install, add:
```php
    expect(\App\Models\ContractTemplate::defaultTemplate())->not->toBeNull();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/ContractTemplates`
Expected: FAIL — `Class "App\Actions\ContractTemplates\EnsureDefaultContractTemplate" not found`.

- [ ] **Step 3: Migration and models**

Create `database/migrations/2026_10_05_000200_create_contract_templates_tables.php`:
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
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // At most one default: NULLs never collide in a UNIQUE index.
        DB::statement(<<<'SQL'
            ALTER TABLE contract_templates
                ADD COLUMN default_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(is_default, 1, NULL)) STORED,
                ADD UNIQUE KEY contract_templates_one_default (default_key),
                ADD CONSTRAINT contract_templates_default_active_chk CHECK (NOT is_default OR active)
            SQL);

        Schema::create('contract_template_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_template_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('heading_en', 150);
            $table->string('heading_ar', 150);
            $table->text('body_en');
            $table->text('body_ar');
            $table->timestamps();
            $table->unique(['contract_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_template_clauses');
        Schema::dropIfExists('contract_templates');
    }
};
```
Create `app/Models/ContractTemplate.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_default
 * @property bool $active
 */
class ContractTemplate extends Model
{
    use LogsActivity;

    /** Spec §5.6. `units_table` must be a clause's whole body. `agreement_number` is filled in when the PDF renders. */
    public const array MERGE_FIELDS = [
        'company_name', 'customer_name', 'customer_id_number', 'agreement_number', 'start_date', 'end_date', 'frequency',
        'total_monthly_rent', 'total_deposit', 'notice_period_days', 'grace_days', 'units_table',
    ];

    /** Characters per paragraph: mPDF never splits a table row, so a paragraph must fit on a page in a half-width column (spec §9.2). */
    public const int MAX_PARAGRAPH = 1200;

    protected $fillable = ['name', 'is_default', 'active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'is_default', 'active'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return HasMany<ContractTemplateClause, $this> */
    public function clauses(): HasMany
    {
        return $this->hasMany(ContractTemplateClause::class)->orderBy('position');
    }

    public static function defaultTemplate(): ?self
    {
        return self::query()->where('is_default', true)->where('active', true)->first();
    }

    /** @return list<string> paragraphs separated by a blank line; one PDF row each */
    public static function paragraphs(string $body): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R\s*\R/u', $body) ?: []), fn (string $p) => $p !== ''));
    }
}
```
Create `app/Models/ContractTemplateClause.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $position
 * @property string $heading_en
 * @property string $heading_ar
 * @property string $body_en
 * @property string $body_ar
 */
class ContractTemplateClause extends Model
{
    protected $fillable = ['position', 'heading_en', 'heading_ar', 'body_en', 'body_ar'];

    /** @return BelongsTo<ContractTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }
}
```

- [ ] **Step 4: The Actions**

Create `app/Actions/ContractTemplates/SaveContractTemplate.php`:
```php
<?php

namespace App\Actions\ContractTemplates;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\ContractTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Spec §5.6: Admin edits clauses; no raw HTML. Agreements snapshot clauses on submit, so edits never reach them. */
final class SaveContractTemplate
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?ContractTemplate $template, array $data): ContractTemplate
    {
        if (! $actor->can(PermissionName::TemplatesManage)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100', Rule::unique('contract_templates', 'name')->ignore($template?->id)],
            'active' => ['boolean'],
            'is_default' => ['boolean'],
            'clauses' => ['required', 'array', 'min:1'],
            'clauses.*.heading_en' => ['required', 'string', 'max:150'],
            'clauses.*.heading_ar' => ['required', 'string', 'max:150'],
            'clauses.*.body_en' => ['required', 'string'],
            'clauses.*.body_ar' => ['required', 'string'],
        ])->after(fn (ValidatorInstance $v) => $this->checkClauses($v, $data))->validate();

        return DB::transaction(function () use ($actor, $template, $validated) {
            $template ??= new ContractTemplate;
            $old = $template->exists ? $template->clauses->map(fn ($c) => $c->heading_en)->all() : [];

            if (($validated['is_default'] ?? false) === true) {
                ContractTemplate::query()->where('is_default', true)->whereKeyNot($template->id ?? 0)->get()
                    ->each(fn (ContractTemplate $other) => $other->update(['is_default' => false]));
            }

            $template->fill([
                'name' => $validated['name'],
                'active' => (bool) ($validated['active'] ?? true),
                'is_default' => (bool) ($validated['is_default'] ?? false),
            ])->save();

            $template->clauses()->delete();
            foreach (array_values($validated['clauses']) as $i => $clause) {
                $template->clauses()->create([
                    'position' => $i + 1,
                    'heading_en' => trim($clause['heading_en']),
                    'heading_ar' => trim($clause['heading_ar']),
                    'body_en' => trim($clause['body_en']),
                    'body_ar' => trim($clause['body_ar']),
                ]);
            }

            $template->load('clauses');
            Audit::log('contract_template.clauses_saved', $template,
                ['clauses' => $old],
                ['clauses' => $template->clauses->map(fn ($c) => $c->heading_en)->all()],
                causer: $actor,
            );

            return $template;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function checkClauses(ValidatorInstance $validator, array $data): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        foreach (array_values((array) $data['clauses']) as $i => $clause) {
            foreach (['body_en', 'body_ar'] as $body) {
                $text = (string) $clause[$body];
                preg_match_all('/\{(\w+)\}/', $text, $m);
                if ($unknown = array_diff($m[1], ContractTemplate::MERGE_FIELDS)) {
                    $validator->errors()->add("clauses.$i.$body", __('Unknown merge field: :f', ['f' => '{'.implode('}, {', $unknown).'}']));
                }
                if (str_contains($text, '{units_table}') && trim($text) !== '{units_table}') {
                    $validator->errors()->add("clauses.$i.$body", __('{units_table} must be the whole clause body.'));
                }
                foreach (ContractTemplate::paragraphs($text) as $paragraph) {
                    if (mb_strlen($paragraph) > ContractTemplate::MAX_PARAGRAPH) {
                        $validator->errors()->add("clauses.$i.$body", __('Each paragraph can have at most :n characters; split it with a blank line.', ['n' => ContractTemplate::MAX_PARAGRAPH]));
                    }
                }
            }

            // EN and AR sit side by side, one row per paragraph pair (spec §9.2).
            if (count(ContractTemplate::paragraphs((string) $clause['body_en'])) !== count(ContractTemplate::paragraphs((string) $clause['body_ar']))) {
                $validator->errors()->add("clauses.$i.body_ar", __('The English and Arabic texts must have the same number of paragraphs.'));
            }
        }
    }
}
```
Create `app/Actions/ContractTemplates/EnsureDefaultContractTemplate.php`:
```php
<?php

namespace App\Actions\ContractTemplates;

use App\Models\ContractTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Creates the starter bilingual lease on a fresh install (rms:install). The client's lawyer reviews and
 * Admin edits it before go-live (M2 exit criterion: the client signs off the EN/AR contract PDF).
 */
final class EnsureDefaultContractTemplate
{
    public function __invoke(): ContractTemplate
    {
        if ($existing = ContractTemplate::query()->orderBy('id')->first()) {
            return $existing;
        }

        return DB::transaction(function () {
            $template = ContractTemplate::create(['name' => 'Standard lease', 'is_default' => true, 'active' => true]);

            foreach (self::clauses() as $i => [$headingEn, $headingAr, $bodyEn, $bodyAr]) {
                $template->clauses()->create([
                    'position' => $i + 1, 'heading_en' => $headingEn, 'heading_ar' => $headingAr, 'body_en' => $bodyEn, 'body_ar' => $bodyAr,
                ]);
            }

            return $template->load('clauses');
        });
    }

    /** @return list<array{string, string, string, string}> */
    private static function clauses(): array
    {
        return [
            ['Parties', 'الأطراف',
                'This lease agreement No. {agreement_number} is made between {company_name} (the Landlord) and {customer_name}, ID {customer_id_number} (the Tenant).',
                'أُبرم عقد الإيجار هذا رقم {agreement_number} بين {company_name} (المؤجر) و{customer_name}، رقم الهوية {customer_id_number} (المستأجر).'],
            ['Premises', 'العين المؤجرة', '{units_table}', '{units_table}'],
            ['Term', 'مدة العقد',
                'The lease starts on {start_date} and ends on {end_date}.',
                'تبدأ مدة الإيجار في {start_date} وتنتهي في {end_date}.'],
            ['Rent', 'الإيجار',
                "The total monthly rent is BHD {total_monthly_rent}, payable {frequency} in advance.\n\nEach payment is due on the first day of its period. A grace period of {grace_days} days applies.",
                "إجمالي الإيجار الشهري {total_monthly_rent} دينار بحريني، يُدفع {frequency} مقدماً.\n\nيستحق كل دفعة في أول يوم من فترتها، مع مهلة سماح مدتها {grace_days} يوماً."],
            ['Security deposit', 'مبلغ التأمين',
                'The Tenant pays a security deposit of BHD {total_deposit}. It is refunded at the end of the lease after deducting any amounts due to the Landlord.',
                'يدفع المستأجر مبلغ تأمين قدره {total_deposit} دينار بحريني، ويُسترد عند انتهاء العقد بعد خصم أي مبالغ مستحقة للمؤجر.'],
            ['Use and care', 'الاستعمال والمحافظة',
                "The Tenant shall use the premises only for the agreed purpose and keep them in good condition.\n\nThe Tenant shall not sublet or assign the premises without the Landlord's written consent.",
                "يلتزم المستأجر باستعمال العين المؤجرة للغرض المتفق عليه فقط والمحافظة عليها بحالة جيدة.\n\nلا يجوز للمستأجر تأجير العين المؤجرة من الباطن أو التنازل عنها دون موافقة كتابية من المؤجر."],
            ['Utilities', 'الخدمات',
                'Electricity and water (EWA) charges are paid by the Tenant unless agreed otherwise in writing.',
                'يتحمل المستأجر رسوم الكهرباء والماء ما لم يُتفق على خلاف ذلك كتابياً.'],
            ['Notice', 'الإخطار',
                'A party that does not wish to renew must give the other party {notice_period_days} days\' written notice before the end date.',
                'على الطرف الذي لا يرغب في التجديد إخطار الطرف الآخر كتابياً قبل {notice_period_days} يوماً من تاريخ انتهاء العقد.'],
            ['Governing law', 'القانون الواجب التطبيق',
                'This agreement is governed by the laws of the Kingdom of Bahrain.',
                'يخضع هذا العقد لقوانين مملكة البحرين.'],
        ];
    }
}
```
In `app/Console/Commands/InstallCommand.php`, add `use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;` and inside the install transaction, directly after `$sequences($year + 1);`, add:
```php
            app(EnsureDefaultContractTemplate::class)();
```

- [ ] **Step 5: Admin screens, routes, navigation**

Create `app/Livewire/Admin/ContractTemplates/Index.php`:
```php
<?php

namespace App\Livewire\Admin\ContractTemplates;

use App\Models\ContractTemplate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Contract templates')]
class Index extends Component
{
    public function render(): View
    {
        return view('livewire.admin.contract-templates.index', [
            'templates' => ContractTemplate::query()->withCount('clauses')->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }
}
```
Create `resources/views/livewire/admin/contract-templates/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Contract templates') }}</flux:heading>
        <flux:button variant="primary" :href="route('admin.templates.create')" wire:navigate>{{ __('New template') }}</flux:button>
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Clauses') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($templates as $template)
                    <flux:table.row :key="$template->id">
                        <flux:table.cell><flux:link :href="route('admin.templates.edit', $template)" wire:navigate>{{ $template->name }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $template->clauses_count }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($template->is_default)<flux:badge size="sm" color="green">{{ __('Default') }}</flux:badge>@endif
                            @unless ($template->active)<flux:badge size="sm">{{ __('Inactive') }}</flux:badge>@endunless
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/Admin/ContractTemplates/Edit.php`:
```php
<?php

namespace App\Livewire\Admin\ContractTemplates;

use App\Actions\ContractTemplates\SaveContractTemplate;
use App\Livewire\Concerns\WithActor;
use App\Models\ContractTemplate;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Edit extends Component
{
    use WithActor;

    #[Locked]
    public ?int $templateId = null;

    public string $name = '';

    public bool $active = true;

    public bool $isDefault = false;

    /** @var list<array{heading_en: string, heading_ar: string, body_en: string, body_ar: string}> */
    public array $clauses = [];

    public function mount(?ContractTemplate $template = null): void
    {
        if ($template?->exists) {
            $this->templateId = $template->id;
            $this->name = $template->name;
            $this->active = $template->active;
            $this->isDefault = $template->is_default;
            $this->clauses = $template->clauses->map(fn ($c) => $c->only(['heading_en', 'heading_ar', 'body_en', 'body_ar']))->values()->all();
        } else {
            $this->addClause();
        }
    }

    public function addClause(): void
    {
        $this->clauses[] = ['heading_en' => '', 'heading_ar' => '', 'body_en' => '', 'body_ar' => ''];
    }

    public function moveUp(int $index): void
    {
        if ($index > 0 && isset($this->clauses[$index])) {
            [$this->clauses[$index - 1], $this->clauses[$index]] = [$this->clauses[$index], $this->clauses[$index - 1]];
        }
    }

    public function remove(int $index): void
    {
        unset($this->clauses[$index]);
        $this->clauses = array_values($this->clauses);
    }

    public function save(SaveContractTemplate $save): void
    {
        try {
            $template = $save->handle($this->actor(), $this->templateId ? ContractTemplate::findOrFail($this->templateId) : null, [
                'name' => $this->name, 'active' => $this->active, 'is_default' => $this->isDefault, 'clauses' => $this->clauses,
            ]);
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: __('Template saved.'));
        $this->redirectRoute('admin.templates.edit', $template, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.contract-templates.edit', ['fields' => ContractTemplate::MERGE_FIELDS])
            ->title($this->templateId ? $this->name : __('New template'));
    }
}
```
Create `resources/views/livewire/admin/contract-templates/edit.blade.php`:
```blade
<section class="w-full max-w-4xl space-y-6">
    <flux:heading size="xl" level="1">{{ $templateId ? $name : __('New template') }}</flux:heading>
    <flux:text>{{ __('Merge fields:') }} @foreach ($fields as $f)<code class="text-xs">{{ '{'.$f.'}' }}</code> @endforeach</flux:text>
    <flux:text size="sm">{{ __('Separate paragraphs with a blank line. English and Arabic need the same number of paragraphs. A clause whose body is just {units_table} prints the schedule of units.') }}</flux:text>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="name" :label="__('Name')" class="sm:col-span-3" />
            <flux:checkbox wire:model="active" :label="__('Active')" />
            <flux:checkbox wire:model="isDefault" :label="__('Default for new agreements')" />
        </div>
        <flux:error name="name" />
        <flux:error name="clauses" />

        @foreach ($clauses as $i => $clause)
            <flux:card class="space-y-3" wire:key="clause-{{ $i }}">
                <div class="flex items-center justify-between gap-2">
                    <flux:heading>{{ $i + 1 }}.</flux:heading>
                    <div class="flex gap-1">
                        <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="moveUp({{ $i }})" :disabled="$i === 0" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove({{ $i }})" />
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="clauses.{{ $i }}.heading_en" :label="__('Heading (English)')" />
                    <flux:input wire:model="clauses.{{ $i }}.heading_ar" :label="__('Heading (Arabic)')" dir="rtl" />
                    <flux:textarea wire:model="clauses.{{ $i }}.body_en" :label="__('Text (English)')" rows="5" />
                    <flux:textarea wire:model="clauses.{{ $i }}.body_ar" :label="__('Text (Arabic)')" rows="5" dir="rtl" />
                </div>
                <flux:error name="clauses.{{ $i }}.heading_en" />
                <flux:error name="clauses.{{ $i }}.heading_ar" />
                <flux:error name="clauses.{{ $i }}.body_en" />
                <flux:error name="clauses.{{ $i }}.body_ar" />
            </flux:card>
        @endforeach

        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="addClause" icon="plus">{{ __('Add clause') }}</flux:button>
            <flux:button variant="primary" type="submit">{{ __('Save template') }}</flux:button>
        </div>
    </form>
</section>
```
In `routes/admin.php`, inside its existing `admin.` group, add `use App\Livewire\Admin\ContractTemplates;` at the top and:
```php
    Route::livewire('templates', ContractTemplates\Index::class)->middleware('can:templates.manage')->name('templates.index');
    Route::livewire('templates/create', ContractTemplates\Edit::class)->middleware('can:templates.manage')->name('templates.create');
    Route::livewire('templates/{template}/edit', ContractTemplates\Edit::class)->middleware('can:templates.manage')->name('templates.edit');
```
(check the file first: the group prefixes names with `admin.` and paths with `admin/`; match how `admin.settings` is declared.)
In the sidebar, change the Administration group's `@canany([...])` list to include `'templates.manage'` and add inside the group:
```blade
                        @can('templates.manage')
                            <flux:sidebar.item icon="document-duplicate" :href="route('admin.templates.index')" :current="request()->routeIs('admin.templates.*')" wire:navigate>{{ __('Contract templates') }}</flux:sidebar.item>
                        @endcan
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/ContractTemplates tests/Feature/Install
"$PHP" artisan test
```
Expected: 9 new tests pass (the dataset counts 4); full suite passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add bilingual contract templates and the default lease

Admin edits ordered EN/AR clauses with merge fields; paragraphs are
length-checked and paired so the PDF can print them side by side.
rms:install creates a starter lease for the client to review.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 3: Agreements — schema, triggers, drafts and scope

**Spec:** §5.2–§5.4 (header, units, charges, statuses), §5.5 (`effective_end`), §8.2 (agreement and customer scope; unit-targeted writes need the unit in scope), §8.4, §8.5 (no DELETE; one-way status; units/charges/clauses frozen once not draft, except `end_date`, `planned_exit_date` and move-out fields; soft delete drafts only)

**Files:**
- Create: `app/Enums/{AgreementStatus,ChargeType}.php`, `database/migrations/2026_10_05_000300_create_agreements_tables.php`, `app/Models/{Agreement,AgreementUnit,AgreementUnitCharge,AgreementClause}.php`, `database/factories/AgreementFactory.php`, `app/Policies/AgreementPolicy.php`, `app/Actions/Agreements/{SaveAgreement,DeleteDraftAgreement}.php`, `tests/Feature/Agreements/AgreementDraftsTest.php`, `tests/Feature/Agreements/AgreementTriggersTest.php`, `tests/Feature/Agreements/AgreementScopeTest.php`
- Modify: `app/Enums/PaymentFrequency.php`, `app/Models/Customer.php`, `app/Models/Unit.php`, `app/Livewire/Customers/Index.php`, `tests/Pest.php`

**Interfaces:**
- Consumes: `Customer`, `Unit` (`visibleTo`, `list_rent`, `list_deposit`, `list_service_charge`, `effectiveTaxCategory()`), `ContractTemplate::defaultTemplate()`, `CompanySetting::current()->default_grace_days`, `Fils`
- Produces:
  - `AgreementStatus` (`Draft`, `PendingApproval`, `Active`, `Expired`, `Renewed`, `Closed`, `Terminated`; `label()`); `ChargeType` (`Rent`, `ServiceCharge`, `Parking`, `Other`); `PaymentFrequency::months(): int` and `::arabic(): string`
  - `Agreement` (relations `customer`, `agreementUnits`, `clauses`, `creator`, `previous`, `contractTemplate`, `approvals`; scope `visibleTo($user)`; `label()`, `monthlyRentFils()`, `listRentFils()`, `depositFils()`, `discountFils()` — the totals need `agreementUnits.charges` loaded)
  - `AgreementUnit` (relations `agreement`, `unit`, `charges`; `static effectiveEndSql(string $alias = 'agreement_units'): string` — spec §5.5 as SQL with ONE `?` binding for today's date; open-ended = `'9999-12-31'`)
  - `AgreementUnitCharge` (`type`, `description`, `monthly_amount`, `tax_category`); `AgreementClause` (`position`, `heading_en`, `heading_ar`, `body_en`, `body_ar`)
  - `AgreementPolicy::viewAny/view/create/update`; `SaveAgreement::handle(User $actor, ?Agreement $agreement, array $data): Agreement`; `DeleteDraftAgreement::handle(User $actor, Agreement $agreement): void`
  - `Customer::agreements()` and the real customer scope; `Unit::agreementUnits()`
  - test helper `activeAgreement(array $attributes, iterable $units, string $rent = '400.000'): Agreement` in `tests/Pest.php`

The `$data` shape for `SaveAgreement`:
```php
[
    'customer_id' => 1, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'frequency' => 'monthly',
    'billing_day' => null, 'grace_days' => null, 'notice_period_days' => null, 'contract_template_id' => null,
    'units' => [
        ['unit_id' => 7, 'deposit_amount' => '450', 'start_date' => null, 'end_date' => null, 'charges' => [
            ['type' => 'rent', 'description' => null, 'monthly_amount' => '450', 'tax_category' => 'exempt'],
        ]],
    ],
]
```

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Agreements/AgreementDraftsTest.php`:
```php
<?php

use App\Actions\Agreements\DeleteDraftAgreement;
use App\Actions\Agreements\SaveAgreement;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Enums\AgreementStatus;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->template = app(EnsureDefaultContractTemplate::class)();
    $this->pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->flat = Unit::factory()->for($this->a)->create(['list_rent' => '500.000']);
    $this->shop = Unit::factory()->for($this->b)->create(['use' => 'commercial', 'type' => 'shop', 'list_rent' => '900.000']);
    $this->customer = Customer::factory()->create();
    $this->terms = [
        'customer_id' => $this->customer->id, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'frequency' => 'monthly',
        'units' => [
            ['unit_id' => $this->flat->id, 'deposit_amount' => '450', 'charges' => [
                ['type' => 'rent', 'monthly_amount' => '450', 'tax_category' => 'exempt'],
                ['type' => 'service_charge', 'monthly_amount' => '20', 'tax_category' => 'exempt'],
            ]],
            ['unit_id' => $this->shop->id, 'deposit_amount' => '900', 'charges' => [
                ['type' => 'rent', 'monthly_amount' => '850', 'tax_category' => 'standard'],
            ]],
        ],
    ];
});

test('a property manager drafts one agreement over units in two buildings', function () {
    $agreement = app(SaveAgreement::class)->handle($this->pm, null, $this->terms);
    $agreement->load('agreementUnits.charges');

    expect($agreement->status)->toBe(AgreementStatus::Draft)
        ->and($agreement->number)->toBeNull()
        ->and($agreement->grace_days)->toBe(5)
        ->and($agreement->notice_period_days)->toBe(30)
        ->and($agreement->contract_template_id)->toBe($this->template->id)
        ->and($agreement->agreementUnits)->toHaveCount(2)
        ->and($agreement->agreementUnits->firstWhere('unit_id', $this->flat->id)->list_rent)->toBe('500.000')
        ->and($agreement->agreementUnits->firstWhere('unit_id', $this->flat->id)->end_date->toDateString())->toBe('2027-10-31')
        ->and($agreement->monthlyRentFils())->toBe(1_300_000)       // 450 + 850, rent only
        ->and($agreement->listRentFils())->toBe(1_400_000)          // 500 + 900
        ->and($agreement->discountFils())->toBe(100_000)
        ->and($agreement->depositFils())->toBe(1_350_000);
});

test('editing a draft replaces units and charges but keeps the list rent copied when a unit was added', function () {
    $agreement = app(SaveAgreement::class)->handle($this->pm, null, $this->terms);
    $this->flat->update(['list_rent' => '550.000']);

    app(SaveAgreement::class)->handle($this->pm, $agreement, [...$this->terms, 'units' => [
        [...$this->terms['units'][0], 'charges' => [['type' => 'rent', 'monthly_amount' => '470', 'tax_category' => 'exempt']]],
    ]]);

    $agreement->refresh()->load('agreementUnits.charges');
    expect($agreement->agreementUnits)->toHaveCount(1)
        ->and($agreement->agreementUnits[0]->list_rent)->toBe('500.000')
        ->and($agreement->agreementUnits[0]->charges)->toHaveCount(1)
        ->and($agreement->monthlyRentFils())->toBe(470_000);
});

test('each unit needs exactly one positive rent charge and dates inside the agreement', function (array $unit) {
    expect(fn () => app(SaveAgreement::class)->handle($this->pm, null, [...$this->terms, 'units' => [[...$this->terms['units'][0], ...$unit]]]))
        ->toThrow(ValidationException::class);
})->with([
    'no rent' => [['charges' => [['type' => 'service_charge', 'monthly_amount' => '20', 'tax_category' => 'exempt']]]],
    'two rents' => [['charges' => [['type' => 'rent', 'monthly_amount' => '1', 'tax_category' => 'exempt'], ['type' => 'rent', 'monthly_amount' => '2', 'tax_category' => 'exempt']]]],
    'zero rent' => [['charges' => [['type' => 'rent', 'monthly_amount' => '0', 'tax_category' => 'exempt']]]],
    'starts early' => [['start_date' => '2026-10-01']],
    'ends late' => [['end_date' => '2027-11-30']],
]);

test('a unit outside the actor\'s buildings cannot be added', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->a->id);

    expect(fn () => app(SaveAgreement::class)->handle($leasing, null, $this->terms))->toThrow(AuthorizationException::class);

    $own = app(SaveAgreement::class)->handle($leasing, null, [...$this->terms, 'units' => [$this->terms['units'][0]]]);
    expect($own->exists)->toBeTrue();
});

test('only drafts are edited or deleted, and deleting is a soft delete', function () {
    $draft = app(SaveAgreement::class)->handle($this->pm, null, $this->terms);
    app(DeleteDraftAgreement::class)->handle($this->pm, $draft);
    expect(Agreement::withTrashed()->find($draft->id)->trashed())->toBeTrue();

    $active = activeAgreement(['customer_id' => $this->customer->id], [$this->flat]);
    expect(fn () => app(SaveAgreement::class)->handle($this->pm, $active, $this->terms))->toThrow(ValidationException::class, 'Only draft agreements can be edited.');
    expect(fn () => app(DeleteDraftAgreement::class)->handle($this->pm, $active))->toThrow(ValidationException::class);
});

test('Finance and Management view agreements but cannot draft them', function () {
    foreach ([RoleName::Finance, RoleName::Management] as $role) {
        $user = User::factory()->withTwoFactor()->create()->assignRole($role);
        expect(fn () => app(SaveAgreement::class)->handle($user, null, $this->terms))->toThrow(AuthorizationException::class)
            ->and($user->can('view', activeAgreement([], [Unit::factory()->create()])))->toBeTrue();
    }
});
```
Create `tests/Feature/Agreements/AgreementTriggersTest.php`:
```php
<?php

use App\Models\Agreement;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Spec §8.5 — enforced by MySQL, not only by the Actions.

beforeEach(function () {
    $this->draft = Agreement::factory()->create();
    $this->unit = Unit::factory()->create();
    $this->au = $this->draft->agreementUnits()->create([
        'unit_id' => $this->unit->id, 'list_rent' => '400.000', 'deposit_amount' => '400.000',
        'start_date' => $this->draft->start_date, 'end_date' => $this->draft->end_date,
    ]);
    $this->charge = $this->au->charges()->create(['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'exempt']);
    $this->draft->clauses()->create(['position' => 1, 'heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => 'x', 'body_ar' => 'س']);
});

function agRow(Agreement $a): Illuminate\Database\Query\Builder
{
    return DB::table('agreements')->where('id', $a->id);
}

function submitAndActivate(Agreement $a): void
{
    agRow($a)->update(['status' => 'pending_approval']);
    agRow($a)->update(['status' => 'active', 'number' => 'AGR-2026-000001', 'verify_token' => str_repeat('a', 32)]);
}

test('agreements are never hard-deleted, and only drafts are soft-deleted', function () {
    expect(fn () => agRow($this->draft)->delete())->toThrow(QueryException::class, 'agreements cannot be deleted');

    submitAndActivate($this->draft);
    expect(fn () => agRow($this->draft)->update(['deleted_at' => now()]))->toThrow(QueryException::class, 'terms are frozen');
});

test('status moves only along the spec diagram', function () {
    agRow($this->draft)->update(['status' => 'pending_approval']);
    agRow($this->draft)->update(['status' => 'draft']);
    expect(fn () => agRow($this->draft)->update(['status' => 'active', 'number' => 'AGR-X', 'verify_token' => str_repeat('b', 32)]))
        ->toThrow(QueryException::class, 'status change not allowed');

    submitAndActivate($this->draft);
    expect(fn () => agRow($this->draft)->update(['status' => 'draft']))->toThrow(QueryException::class, 'status change not allowed');

    agRow($this->draft)->update(['status' => 'expired']);
    expect(fn () => agRow($this->draft)->update(['status' => 'active']))->toThrow(QueryException::class, 'status change not allowed');
    agRow($this->draft)->update(['status' => 'closed']);
});

test('a pending agreement and its units, charges and clauses are locked', function () {
    agRow($this->draft)->update(['status' => 'pending_approval']);

    expect(fn () => agRow($this->draft)->update(['start_date' => '2020-01-01']))->toThrow(QueryException::class, 'terms are frozen');
    expect(fn () => agRow($this->draft)->update(['planned_exit_date' => '2027-01-01']))->toThrow(QueryException::class, 'only notice dates');
    expect(fn () => DB::table('agreement_units')->where('id', $this->au->id)->update(['deposit_amount' => '1.000']))->toThrow(QueryException::class, 'agreement_units');
    expect(fn () => DB::table('agreement_units')->where('id', $this->au->id)->update(['planned_exit_date' => '2027-01-01']))->toThrow(QueryException::class, 'locked while pending');
    expect(fn () => DB::table('agreement_unit_charges')->where('id', $this->charge->id)->update(['monthly_amount' => '1.000']))->toThrow(QueryException::class, 'agreement_unit_charges');
    expect(fn () => DB::table('agreement_units')->insert(['agreement_id' => $this->draft->id, 'unit_id' => Unit::factory()->create()->id, 'list_rent' => 1, 'deposit_amount' => 1, 'start_date' => '2026-11-01', 'end_date' => '2026-12-01']))
        ->toThrow(QueryException::class, 'agreement_units');
    expect(fn () => DB::table('agreement_clauses')->where('agreement_id', $this->draft->id)->update(['body_en' => 'changed']))->toThrow(QueryException::class, 'agreement_clauses');
    expect(fn () => DB::table('agreement_clauses')->where('agreement_id', $this->draft->id)->delete())->toThrow(QueryException::class, 'agreement_clauses');
});

test('an active agreement records notice and may only shorten its end date', function () {
    submitAndActivate($this->draft);
    $end = agRow($this->draft)->value('end_date');

    agRow($this->draft)->update(['notice_date' => '2027-01-15', 'planned_exit_date' => '2027-03-31']);
    DB::table('agreement_units')->where('id', $this->au->id)->update(['planned_exit_date' => '2027-03-31']);

    expect(fn () => agRow($this->draft)->update(['end_date' => now()->parse($end)->addYear()->toDateString()]))->toThrow(QueryException::class, 'only notice dates');
    expect(fn () => DB::table('agreement_units')->where('id', $this->au->id)->update(['end_date' => now()->parse($end)->addDay()->toDateString()]))->toThrow(QueryException::class, 'agreement_units');
    expect(fn () => DB::table('agreement_units')->where('id', $this->au->id)->delete())->toThrow(QueryException::class, 'agreement_units');
});

test('one rent charge per agreement unit is a database rule', function () {
    expect(fn () => $this->au->charges()->create(['type' => 'rent', 'monthly_amount' => '1.000', 'tax_category' => 'exempt']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a number and verify token exist exactly when the agreement is past approval', function () {
    agRow($this->draft)->update(['status' => 'pending_approval']);
    expect(fn () => agRow($this->draft)->update(['status' => 'active']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});
```
Create `tests/Feature/Agreements/AgreementScopeTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mine = Building::factory()->create();
    $this->theirs = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($this->mine->id);

    $this->ownCustomer = Customer::factory()->create(['name_en' => 'Own Tenant']);
    $this->otherCustomer = Customer::factory()->create(['name_en' => 'Other Tenant', 'id_number' => '770707777', 'mobile' => '+97336660000']);
    $this->newCustomer = Customer::factory()->create(['name_en' => 'Walk In']);

    $this->ownAgreement = activeAgreement(['customer_id' => $this->ownCustomer->id], [Unit::factory()->for($this->mine)->create()]);
    $this->otherAgreement = activeAgreement(['customer_id' => $this->otherCustomer->id], [Unit::factory()->for($this->theirs)->create()]);
});

test('a scoped user sees agreements with a unit in an assigned building', function () {
    expect(Agreement::visibleTo($this->leasing)->pluck('id')->all())->toBe([$this->ownAgreement->id])
        ->and($this->leasing->can('view', $this->otherAgreement))->toBeFalse();

    // An agreement spanning both buildings is visible (any unit in scope) but not writable (not every unit in scope).
    $spanning = activeAgreement([], [Unit::factory()->for($this->mine)->create(), Unit::factory()->for($this->theirs)->create()]);
    expect($this->leasing->can('view', $spanning))->toBeTrue()
        ->and($this->leasing->can('update', $spanning))->toBeFalse();
});

test('customers are visible when they have an agreement in scope or none yet', function () {
    expect(Customer::visibleTo($this->leasing)->pluck('name_en')->sort()->values()->all())->toBe(['Own Tenant', 'Walk In']);

    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    expect(Customer::visibleTo($admin)->count())->toBe(3);
});

test('an exact ID or mobile match outside the scope shows only that the customer exists', function (string $term) {
    Livewire::actingAs($this->leasing)->test(CustomersIndex::class)
        ->set('search', $term)
        ->assertDontSee('770707777')
        ->assertSee('Already exists: Other Tenant, ID ••••7777');
})->with(['770707777', '+97336660000']);
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Agreements`
Expected: FAIL — `Class "App\Models\Agreement" not found`.

- [ ] **Step 3: Enums**

Create `app/Enums/AgreementStatus.php`:
```php
<?php

namespace App\Enums;

/** Spec §5.4. */
enum AgreementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Expired = 'expired';
    case Renewed = 'renewed';
    case Closed = 'closed';
    case Terminated = 'terminated';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `app/Enums/ChargeType.php`:
```php
<?php

namespace App\Enums;

/** Recurring agreement charges (spec §5.3). */
enum ChargeType: string
{
    case Rent = 'rent';
    case ServiceCharge = 'service_charge';
    case Parking = 'parking';
    case Other = 'other';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
In `app/Enums/PaymentFrequency.php` add:
```php
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
        };
    }

    /** {frequency} in Arabic contract text (spec §5.6). */
    public function arabic(): string
    {
        return match ($this) {
            self::Monthly => 'شهرياً',
            self::Quarterly => 'كل ثلاثة أشهر',
            self::HalfYearly => 'كل ستة أشهر',
            self::Yearly => 'سنوياً',
        };
    }

    /** {frequency} in English contract text. */
    public function english(): string
    {
        return match ($this) {
            self::Monthly => 'monthly',
            self::Quarterly => 'quarterly',
            self::HalfYearly => 'every six months',
            self::Yearly => 'yearly',
        };
    }
```

- [ ] **Step 4: Migration with CHECKs and triggers**

Create `database/migrations/2026_10_05_000300_create_agreements_tables.php`:
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
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('frequency', 20);
            $table->unsignedTinyInteger('billing_day')->nullable();
            $table->unsignedSmallInteger('grace_days');
            $table->unsignedSmallInteger('notice_period_days')->default(30);
            $table->date('notice_date')->nullable();
            $table->date('planned_exit_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('previous_agreement_id')->nullable()->constrained('agreements')->restrictOnDelete();
            $table->foreignId('contract_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('verify_token', 32)->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'end_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE agreements
                ADD CONSTRAINT agreements_status_chk CHECK (status IN ('draft', 'pending_approval', 'active', 'expired', 'renewed', 'closed', 'terminated')),
                ADD CONSTRAINT agreements_frequency_chk CHECK (frequency IN ('monthly', 'quarterly', 'half_yearly', 'yearly')),
                ADD CONSTRAINT agreements_billing_day_chk CHECK (billing_day IS NULL OR billing_day BETWEEN 1 AND 28),
                ADD CONSTRAINT agreements_dates_chk CHECK (end_date >= start_date),
                ADD CONSTRAINT agreements_days_chk CHECK (grace_days <= 60 AND notice_period_days <= 365),
                ADD CONSTRAINT agreements_number_chk CHECK ((status IN ('draft', 'pending_approval')) = (number IS NULL)),
                ADD CONSTRAINT agreements_token_chk CHECK ((status IN ('draft', 'pending_approval')) = (verify_token IS NULL))
            SQL);

        Schema::create('agreement_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('list_rent', 12, 3);
            $table->decimal('deposit_amount', 12, 3)->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->date('planned_exit_date')->nullable();
            $table->date('move_out_date')->nullable();
            $table->text('move_out_readings')->nullable();
            $table->text('move_out_notes')->nullable();
            $table->foreignId('move_out_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['agreement_id', 'unit_id']);
            $table->index(['unit_id', 'start_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE agreement_units
                ADD CONSTRAINT agreement_units_dates_chk CHECK (end_date >= start_date),
                ADD CONSTRAINT agreement_units_money_chk CHECK (list_rent >= 0 AND deposit_amount >= 0)
            SQL);

        Schema::create('agreement_unit_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_unit_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('description', 150)->nullable();
            $table->decimal('monthly_amount', 12, 3);
            $table->string('tax_category', 20);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE agreement_unit_charges
                ADD COLUMN rent_key BIGINT UNSIGNED GENERATED ALWAYS AS (IF(type = 'rent', agreement_unit_id, NULL)) STORED,
                ADD UNIQUE KEY agreement_unit_charges_one_rent (rent_key),
                ADD CONSTRAINT agreement_unit_charges_type_chk CHECK (type IN ('rent', 'service_charge', 'parking', 'other')),
                ADD CONSTRAINT agreement_unit_charges_tax_chk CHECK (tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT agreement_unit_charges_amount_chk CHECK (monthly_amount >= 0)
            SQL);

        Schema::create('agreement_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('heading_en', 150);
            $table->string('heading_ar', 150);
            $table->text('body_en');
            $table->text('body_ar');
            $table->timestamps();
            $table->unique(['agreement_id', 'position']);
        });

        // Spec §8.5. One statement per unprepared() call.
        DB::unprepared("CREATE TRIGGER agreements_no_delete BEFORE DELETE ON agreements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreements cannot be deleted'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agreements_guard BEFORE UPDATE ON agreements FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'active'))
                    OR (OLD.status = 'active' AND NEW.status IN ('expired', 'renewed', 'closed', 'terminated'))
                    OR (OLD.status = 'expired' AND NEW.status IN ('renewed', 'closed', 'terminated'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreements: status change not allowed';
                END IF;

                IF OLD.status <> 'draft' AND NOT (
                    NEW.customer_id <=> OLD.customer_id AND NEW.start_date <=> OLD.start_date AND NEW.frequency <=> OLD.frequency
                    AND NEW.billing_day <=> OLD.billing_day AND NEW.grace_days <=> OLD.grace_days
                    AND NEW.notice_period_days <=> OLD.notice_period_days AND NEW.previous_agreement_id <=> OLD.previous_agreement_id
                    AND NEW.contract_template_id <=> OLD.contract_template_id AND NEW.created_by <=> OLD.created_by
                    AND NEW.deleted_at <=> OLD.deleted_at
                    AND (OLD.number IS NULL OR NEW.number <=> OLD.number)
                    AND (OLD.verify_token IS NULL OR NEW.verify_token <=> OLD.verify_token)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreements: terms are frozen once submitted';
                END IF;

                IF (OLD.status IN ('pending_approval', 'renewed', 'closed', 'terminated')
                        AND NOT (NEW.end_date <=> OLD.end_date AND NEW.notice_date <=> OLD.notice_date AND NEW.planned_exit_date <=> OLD.planned_exit_date))
                    OR (OLD.status IN ('active', 'expired') AND NEW.end_date > OLD.end_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreements: only notice dates and an earlier end_date may change';
                END IF;
            END
            SQL);

        // ponytail: M3's add_unit amendment inserts units into an active agreement; it widens this trigger then.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agreement_units_insert BEFORE INSERT ON agreement_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM agreements WHERE id = NEW.agreement_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units are frozen once the agreement is submitted';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agreement_units_guard BEFORE UPDATE ON agreement_units FOR EACH ROW
            BEGIN
                DECLARE s VARCHAR(20);
                SELECT status INTO s FROM agreements WHERE id = OLD.agreement_id;

                IF s <> 'draft' AND NOT (NEW.agreement_id <=> OLD.agreement_id AND NEW.unit_id <=> OLD.unit_id
                    AND NEW.list_rent <=> OLD.list_rent AND NEW.deposit_amount <=> OLD.deposit_amount
                    AND NEW.start_date <=> OLD.start_date AND NEW.end_date <= OLD.end_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units: terms are frozen once submitted';
                END IF;

                IF s = 'pending_approval' AND NOT (NEW.end_date <=> OLD.end_date AND NEW.planned_exit_date <=> OLD.planned_exit_date
                    AND NEW.move_out_date <=> OLD.move_out_date AND NEW.move_out_readings <=> OLD.move_out_readings
                    AND NEW.move_out_notes <=> OLD.move_out_notes AND NEW.move_out_recorded_by <=> OLD.move_out_recorded_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units: locked while pending approval';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agreement_units_delete BEFORE DELETE ON agreement_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM agreements WHERE id = OLD.agreement_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units are frozen once the agreement is submitted';
                END IF;
            END
            SQL);

        foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $event => $row) {
            $name = 'agreement_unit_charges_'.strtolower($event);
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name} BEFORE {$event} ON agreement_unit_charges FOR EACH ROW
                BEGIN
                    IF (SELECT a.status FROM agreement_units au JOIN agreements a ON a.id = au.agreement_id WHERE au.id = {$row}.agreement_unit_id) <> 'draft' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_unit_charges are frozen once the agreement is submitted';
                    END IF;
                END
                SQL);

            $name = 'agreement_clauses_'.strtolower($event);
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name} BEFORE {$event} ON agreement_clauses FOR EACH ROW
                BEGIN
                    IF (SELECT status FROM agreements WHERE id = {$row}.agreement_id) <> 'draft' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_clauses are frozen once the agreement is submitted';
                    END IF;
                END
                SQL);
        }
    }

    public function down(): void
    {
        foreach (['agreement_clauses_insert', 'agreement_clauses_update', 'agreement_clauses_delete',
            'agreement_unit_charges_insert', 'agreement_unit_charges_update', 'agreement_unit_charges_delete',
            'agreement_units_delete', 'agreement_units_guard', 'agreement_units_insert', 'agreements_guard', 'agreements_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('agreement_clauses');
        Schema::dropIfExists('agreement_unit_charges');
        Schema::dropIfExists('agreement_units');
        Schema::dropIfExists('agreements');
    }
};
```
The clause triggers mean `SubmitAgreement` (Task 7) must insert the rendered clauses **before** it flips the status to `pending_approval`, and a rejection must flip back to `draft` **before** deleting them.

- [ ] **Step 5: Models, factory, policy, test helper**

Create `app/Models/Agreement.php`:
```php
<?php

namespace App\Models;

use App\Enums\AgreementStatus;
use App\Enums\ChargeType;
use App\Enums\PaymentFrequency;
use App\Enums\PermissionName;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Factories\AgreementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string|null $number
 * @property int $customer_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property PaymentFrequency $frequency
 * @property int|null $billing_day
 * @property int $grace_days
 * @property int $notice_period_days
 * @property CarbonImmutable|null $notice_date
 * @property CarbonImmutable|null $planned_exit_date
 * @property AgreementStatus $status
 * @property int|null $previous_agreement_id
 * @property int|null $contract_template_id
 * @property string|null $verify_token
 * @property int $created_by
 */
class Agreement extends Model
{
    /** @use HasFactory<AgreementFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'customer_id', 'start_date', 'end_date', 'frequency', 'billing_day', 'grace_days', 'notice_period_days',
        'previous_agreement_id', 'contract_template_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'notice_date' => 'immutable_date',
            'planned_exit_date' => 'immutable_date',
            'frequency' => PaymentFrequency::class,
            'status' => AgreementStatus::class,
            'billing_day' => 'integer',
            'grace_days' => 'integer',
            'notice_period_days' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['verify_token'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<AgreementUnit, $this> */
    public function agreementUnits(): HasMany
    {
        return $this->hasMany(AgreementUnit::class);
    }

    /** @return HasMany<AgreementClause, $this> */
    public function clauses(): HasMany
    {
        return $this->hasMany(AgreementClause::class)->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Agreement, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_agreement_id');
    }

    /** @return BelongsTo<ContractTemplate, $this> */
    public function contractTemplate(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class);
    }

    /** @return MorphMany<Approval, $this> */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Spec §8.2: visible when any unit is in an assigned building. A scoped user's own draft with no unit yet stays visible to them.
     *
     * @param  Builder<Agreement>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereHas('agreementUnits.unit', fn (Builder $unit) => $unit->visibleTo($user))
            ->orWhere(fn (Builder $own) => $own->where('created_by', $user->getKey())->whereDoesntHave('agreementUnits')));
    }

    public function label(): string
    {
        return $this->number ?? __('Draft #:id', ['id' => $this->id]);
    }

    /** Σ rent charges, monthly (spec §5.6 {total_monthly_rent}). Needs agreementUnits.charges loaded. */
    public function monthlyRentFils(): int
    {
        return $this->agreementUnits->sum(fn (AgreementUnit $au) => $au->charges
            ->where('type', ChargeType::Rent)->sum(fn (AgreementUnitCharge $c) => Fils::fromDecimal($c->monthly_amount)));
    }

    public function listRentFils(): int
    {
        return $this->agreementUnits->sum(fn (AgreementUnit $au) => Fils::fromDecimal($au->list_rent));
    }

    /** List rent − agreed rent (spec §5.3: shown on the approval screen). */
    public function discountFils(): int
    {
        return $this->listRentFils() - $this->monthlyRentFils();
    }

    public function depositFils(): int
    {
        return $this->agreementUnits->sum(fn (AgreementUnit $au) => Fils::fromDecimal($au->deposit_amount));
    }
}
```
Create `app/Models/AgreementUnit.php`:
```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $agreement_id
 * @property int $unit_id
 * @property string $list_rent
 * @property string $deposit_amount
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable|null $planned_exit_date
 * @property CarbonImmutable|null $move_out_date
 */
class AgreementUnit extends Model
{
    use LogsActivity;

    protected $fillable = ['agreement_id', 'unit_id', 'list_rent', 'deposit_amount', 'start_date', 'end_date', 'planned_exit_date'];

    protected function casts(): array
    {
        return [
            'list_rent' => 'decimal:3',
            'deposit_amount' => 'decimal:3',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'planned_exit_date' => 'immutable_date',
            'move_out_date' => 'immutable_date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<AgreementUnitCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(AgreementUnitCharge::class);
    }

    /**
     * Spec §5.5 effective_end, as SQL so the overlap check (a locking read) and unit status share one definition.
     * One binding: today as Y-m-d. An overstay (past end_date, no move-out, not carried into a renewal) is open-ended.
     */
    public static function effectiveEndSql(string $alias = 'agreement_units'): string
    {
        return "CASE WHEN {$alias}.move_out_date IS NOT NULL THEN GREATEST({$alias}.move_out_date, {$alias}.end_date)
            WHEN {$alias}.end_date >= ? THEN {$alias}.end_date
            WHEN EXISTS (SELECT 1 FROM agreements r JOIN agreement_units ru ON ru.agreement_id = r.id
                WHERE r.previous_agreement_id = {$alias}.agreement_id AND ru.unit_id = {$alias}.unit_id
                  AND r.status IN ('pending_approval', 'active') AND r.deleted_at IS NULL) THEN {$alias}.end_date
            ELSE '9999-12-31' END";
    }
}
```
Create `app/Models/AgreementUnitCharge.php`:
```php
<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Enums\TaxCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $agreement_unit_id
 * @property ChargeType $type
 * @property string|null $description
 * @property string $monthly_amount
 * @property TaxCategory $tax_category
 */
class AgreementUnitCharge extends Model
{
    use LogsActivity;

    protected $fillable = ['type', 'description', 'monthly_amount', 'tax_category'];

    protected function casts(): array
    {
        return ['type' => ChargeType::class, 'tax_category' => TaxCategory::class, 'monthly_amount' => 'decimal:3'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['type', 'description', 'monthly_amount', 'tax_category'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }
}
```
Create `app/Models/AgreementClause.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The contract text frozen at submit (spec §5.6). Written only by SubmitAgreement and the rejection handler.
 *
 * @property int $position
 * @property string $heading_en
 * @property string $heading_ar
 * @property string $body_en
 * @property string $body_ar
 */
class AgreementClause extends Model
{
    protected $fillable = ['position', 'heading_en', 'heading_ar', 'body_en', 'body_ar'];
}
```
In `app/Models/Customer.php` add the relation and replace the placeholder scope:
```php
    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Agreement, $this> */
    public function agreements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Agreement::class);
    }

    /**
     * Spec §8.2: in scope if it has any agreement with a unit in an assigned building, or no agreement yet.
     *
     * @param  Builder<Customer>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(\App\Enums\PermissionName::BuildingsViewAll)) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('agreements')
            ->orWhereHas('agreements', fn (Builder $a) => $a->visibleTo($user)));
    }
```
(import `HasMany` and `PermissionName` properly rather than fully qualified names.)
In `app/Models/Unit.php` add:
```php
    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<AgreementUnit, $this> */
    public function agreementUnits(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AgreementUnit::class);
    }
```
Create `database/factories/AgreementFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Agreement;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Always a draft with no units; activeAgreement() in tests/Pest.php walks the allowed status steps.
 *
 * @extends Factory<Agreement>
 */
class AgreementFactory extends Factory
{
    protected $model = Agreement::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->startOfMonth()->addYear()->subDay()->toDateString(),
            'frequency' => 'monthly',
            'grace_days' => 5,
            'notice_period_days' => 30,
            'created_by' => User::factory(),
        ];
    }
}
```
Create `app/Policies/AgreementPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\Unit;
use App\Models\User;

/** Spec §8.1 agreements.view / agreements.manage within the building scope of §8.2. */
class AgreementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::AgreementsView);
    }

    public function view(User $user, Agreement $agreement): bool
    {
        return $user->can(PermissionName::AgreementsView) && Agreement::visibleTo($user)->whereKey($agreement->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::AgreementsManage);
    }

    /** Writes need every unit of the agreement in scope (spec §8.2). */
    public function update(User $user, Agreement $agreement): bool
    {
        return $user->can(PermissionName::AgreementsManage) && self::allUnitsInScope($user, $agreement);
    }

    public static function allUnitsInScope(User $user, Agreement $agreement): bool
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return true;
        }

        $unitIds = $agreement->agreementUnits()->pluck('unit_id')->unique()->values()->all();

        if ($unitIds === []) {
            return $agreement->created_by === $user->getKey();
        }

        return Unit::query()->visibleTo($user)->whereKey($unitIds)->count() === count($unitIds);
    }
}
```
In `tests/Pest.php` add below `activeOwnerContract()`:
```php
/** An active agreement with one rent charge per unit, walked through the status steps the triggers allow. No invoices. */
function activeAgreement(array $attributes, iterable $units, string $rent = '400.000'): App\Models\Agreement
{
    $agreement = App\Models\Agreement::factory()->create($attributes);

    foreach ($units as $unit) {
        $au = $agreement->agreementUnits()->create([
            'unit_id' => $unit->id, 'list_rent' => $unit->list_rent, 'deposit_amount' => $rent,
            'start_date' => $agreement->start_date, 'end_date' => $agreement->end_date,
        ]);
        $au->charges()->create(['type' => 'rent', 'monthly_amount' => $rent, 'tax_category' => 'exempt']);
    }

    $agreement->forceFill(['status' => 'pending_approval'])->save();
    $agreement->forceFill(['status' => 'active', 'number' => 'AGR-T-'.$agreement->id, 'verify_token' => Illuminate\Support\Str::random(32)])->save();

    return $agreement->fresh();
}
```

- [ ] **Step 6: The Actions**

Create `app/Actions/Agreements/SaveAgreement.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\ChargeType;
use App\Enums\PaymentFrequency;
use App\Enums\TaxCategory;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Creates or edits a DRAFT agreement with its units and charges (spec §5.2, §5.3). */
final class SaveAgreement
{
    /** @param  array<string, mixed>  $data  shape: see the M2 plan, Task 3 */
    public function handle(User $actor, ?Agreement $agreement, array $data): Agreement
    {
        if (! ($agreement ? $actor->can('update', $agreement) : $actor->can('create', Agreement::class))) {
            throw new AuthorizationException;
        }

        if ($agreement && $agreement->status !== AgreementStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('Only draft agreements can be edited.')]);
        }

        $data = self::blankToNull($data);

        $validated = Validator::make($data, [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'frequency' => ['required', Rule::enum(PaymentFrequency::class)],
            'billing_day' => ['nullable', 'integer', 'between:1,28'],
            'grace_days' => ['nullable', 'integer', 'between:0,60'],
            'notice_period_days' => ['nullable', 'integer', 'between:0,365'],
            'contract_template_id' => ['nullable', 'integer', Rule::exists('contract_templates', 'id')->where('active', true)],
            'units' => ['required', 'array', 'min:1'],
            'units.*.unit_id' => ['required', 'integer', 'distinct', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'units.*.deposit_amount' => ['nullable', Fils::rule()],
            'units.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'units.*.end_date' => ['nullable', 'date_format:Y-m-d'],
            'units.*.charges' => ['required', 'array', 'min:1'],
            'units.*.charges.*.type' => ['required', Rule::enum(ChargeType::class)],
            'units.*.charges.*.description' => ['nullable', 'string', 'max:150'],
            'units.*.charges.*.monthly_amount' => ['required', Fils::rule()],
            'units.*.charges.*.tax_category' => ['required', Rule::enum(TaxCategory::class)],
        ])->after(fn (ValidatorInstance $v) => $this->check($v, $data, $actor))->validate();

        // Every unit a write targets must be in the actor's buildings (spec §8.2).
        $unitIds = array_map(fn (array $u) => (int) $u['unit_id'], $validated['units']);
        if (Unit::query()->visibleTo($actor)->whereKey($unitIds)->count() !== count($unitIds)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $agreement, $validated) {
            $settings = CompanySetting::current();

            $agreement ??= (new Agreement)->forceFill(['created_by' => $actor->id]);
            $agreement->fill([
                'customer_id' => $validated['customer_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'frequency' => $validated['frequency'],
                'billing_day' => $validated['billing_day'] ?? null,
                'grace_days' => $validated['grace_days'] ?? $settings->default_grace_days,
                'notice_period_days' => $validated['notice_period_days'] ?? 30,
                'contract_template_id' => $validated['contract_template_id'] ?? $agreement->contract_template_id ?? ContractTemplate::defaultTemplate()?->id,
            ])->save();

            $existing = $agreement->agreementUnits()->with('charges')->get()->keyBy('unit_id');
            $keep = array_map(fn (array $u) => (int) $u['unit_id'], $validated['units']);

            foreach ($existing as $unitId => $au) {
                if (! in_array((int) $unitId, $keep, true)) {
                    $au->charges->each->delete();
                    $au->delete();
                }
            }

            foreach ($validated['units'] as $line) {
                $unitId = (int) $line['unit_id'];
                // list_rent is copied from the unit when it is first added (spec §5.3).
                $au = $existing->get($unitId) ?? new AgreementUnit(['unit_id' => $unitId, 'list_rent' => Unit::findOrFail($unitId)->list_rent]);
                $au->fill([
                    'deposit_amount' => self::money($line['deposit_amount'] ?? '0'),
                    'start_date' => $line['start_date'] ?? $validated['start_date'],
                    'end_date' => $line['end_date'] ?? $validated['end_date'],
                ]);
                $agreement->agreementUnits()->save($au);

                $au->charges()->get()->each->delete();
                foreach ($line['charges'] as $charge) {
                    $au->charges()->create([
                        'type' => $charge['type'],
                        'description' => $charge['description'] ?? null,
                        'monthly_amount' => self::money($charge['monthly_amount']),
                        'tax_category' => $charge['tax_category'],
                    ]);
                }
            }

            return $agreement->load('agreementUnits.charges');
        });
    }

    /** @param  array<string, mixed>  $data */
    private function check(ValidatorInstance $validator, array $data, User $actor): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if (! Customer::query()->visibleTo($actor)->whereKey($data['customer_id'])->exists()) {
            $validator->errors()->add('customer_id', __('Choose a customer you can see.'));
        }

        foreach (array_values((array) $data['units']) as $i => $line) {
            $from = $line['start_date'] ?? $data['start_date'];
            $to = $line['end_date'] ?? $data['end_date'];
            if ($from < $data['start_date'] || $to > $data['end_date'] || $from > $to) {
                $validator->errors()->add("units.$i.start_date", __('A unit\'s dates must fall within the agreement\'s dates.'));
            }

            $rents = array_values(array_filter((array) $line['charges'], fn (array $c) => $c['type'] === ChargeType::Rent->value));
            if (count($rents) !== 1) {
                $validator->errors()->add("units.$i.charges", __('Each unit needs exactly one rent charge.'));
            } elseif (Fils::fromDecimal((string) $rents[0]['monthly_amount']) === 0) {
                $validator->errors()->add("units.$i.charges", __('Rent must be more than zero.'));
            }
        }
    }

    private static function money(mixed $value): string
    {
        return Fils::toDecimal(Fils::fromDecimal((string) $value));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function blankToNull(array $data): array
    {
        return array_map(fn (mixed $v) => is_array($v) ? self::blankToNull($v) : (is_string($v) && trim($v) === '' ? null : $v), $data);
    }
}
```
Create `app/Actions/Agreements/DeleteDraftAgreement.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §5.2: soft deletes, drafts only. */
final class DeleteDraftAgreement
{
    public function handle(User $actor, Agreement $agreement): void
    {
        if (! $actor->can('update', $agreement)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($agreement) {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if ($agreement->status !== AgreementStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('Only a draft can be deleted.')]);
            }

            $agreement->delete();
        });
    }
}
```

- [ ] **Step 7: The customer search hint**

In `app/Livewire/Customers/Index.php`, add `use App\Enums\PermissionName;` and replace the `return view(...)` line in `render()` with:
```php
        // Spec §8.2: a scoped user's exact ID or mobile match outside their scope shows only "Already exists".
        $hint = null;
        if ($term !== '' && ! $this->actor()->can(PermissionName::BuildingsViewAll)) {
            $hidden = Customer::query()
                ->where(fn ($q) => $q->where('id_number', $term)->orWhere('mobile', $term))
                ->whereNotIn('id', Customer::query()->visibleTo($this->actor())->select('id'))
                ->first();
            if ($hidden) {
                $hint = __('Already exists: :name, ID :id', ['name' => $hidden->name_en, 'id' => $hidden->maskedId()]);
            }
        }

        return view('livewire.customers.index', ['customers' => $customers, 'hint' => $hint]);
```

- [ ] **Step 8: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Agreements tests/Feature/Customers
"$PHP" artisan test
```
Expected: every test in `tests/Feature/Agreements` passes; full suite passes.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add multi-unit agreement drafts, guarded by triggers

Agreements span units in any building, with one rent charge per unit
and optional service, parking and other charges. MySQL refuses deletes,
backward status moves and edits to anything submitted. Agreements and
customers follow the building scope.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 4: Agreement draft screens

**Spec:** §5.2–§5.4 ("expiring soon" is a filter), §5.3 (discount shown), §2 (customer lookup is a text input with a results list; native date/select; 375 px), §8.2

**Files:**
- Create: `app/Livewire/Agreements/{Index,Form,Show}.php`, `resources/views/livewire/agreements/{index,form,show}.blade.php`, `tests/Feature/Agreements/AgreementScreensTest.php`
- Modify: `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`, `app/Livewire/Documents/Panel.php`

**Interfaces:**
- Consumes: `SaveAgreement`, `DeleteDraftAgreement`, `AgreementPolicy`, `Agreement::visibleTo`, `Customer::visibleTo`, `Unit::visibleTo`, `Unit::effectiveTaxCategory()`, `Fils`
- Produces: routes `agreements.index`, `agreements.create`, `agreements.edit`, `agreements.show`; `Agreements\Show` with `protected function agreement(): Agreement` and the placeholder comment `{{-- Task 7: submit + approvals. Task 8: contract PDF. Task 9: notice. Task 10: invoices. --}}` in its view, which later tasks replace piece by piece

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Agreements/AgreementScreensTest.php`:
```php
<?php

use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Enums\RoleName;
use App\Livewire\Agreements\Form;
use App\Livewire\Agreements\Index;
use App\Livewire\Agreements\Show;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureDefaultContractTemplate::class)();
    $this->building = Building::factory()->create(['name' => 'Juffair Heights']);
    $this->flat = Unit::factory()->for($this->building)->create(['code' => 'J-101', 'list_rent' => '500.000', 'list_deposit' => '500.000', 'list_service_charge' => '25.000']);
    $this->customer = Customer::factory()->create(['name_en' => 'Hassan Qasim']);
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($this->building->id);
});

test('Leasing drafts an agreement: finds the customer, adds a unit with its list terms, and saves', function () {
    Livewire::actingAs($this->leasing)->test(Form::class)
        ->set('customerSearch', 'Hassan')
        ->assertSee('Hassan Qasim')
        ->call('selectCustomer', $this->customer->id)
        ->set('form.start_date', '2026-11-01')
        ->set('form.end_date', '2027-10-31')
        ->set('pickBuilding', $this->building->id)
        ->set('pickUnit', $this->flat->id)
        ->call('addUnit')
        ->assertSet('units.0.deposit_amount', '500.000')
        ->assertSet('units.0.charges.0.monthly_amount', '500.000')
        ->assertSet('units.0.charges.1.type', 'service_charge')
        ->set('units.0.charges.0.monthly_amount', '460')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $agreement = Agreement::sole()->load('agreementUnits.charges');
    expect($agreement->monthlyRentFils())->toBe(460_000)
        ->and($agreement->discountFils())->toBe(40_000);
});

test('the unit picker lists only buildings in the user\'s scope', function () {
    Building::factory()->create(['name' => 'Seef Tower']);

    Livewire::actingAs($this->leasing)->test(Form::class)
        ->assertSee('Juffair Heights')
        ->assertDontSee('Seef Tower');
});

test('errors land on the header fields and on the unit lines', function () {
    Livewire::actingAs($this->leasing)->test(Form::class)
        ->set('pickBuilding', $this->building->id)
        ->set('pickUnit', $this->flat->id)
        ->call('addUnit')
        ->set('units.0.charges.0.monthly_amount', '0')
        ->call('save')
        ->assertHasErrors(['form.customer_id', 'form.start_date'])
        // Cross-field checks run once the basic rules pass.
        ->call('selectCustomer', $this->customer->id)
        ->set('form.start_date', '2026-11-01')
        ->set('form.end_date', '2027-10-31')
        ->call('save')
        ->assertHasErrors(['units.0.charges']);
});

test('the show page shows the discount; drafts can be edited and deleted', function () {
    $draft = Agreement::factory()->create(['customer_id' => $this->customer->id, 'created_by' => $this->leasing->id]);
    $au = $draft->agreementUnits()->create(['unit_id' => $this->flat->id, 'list_rent' => '500.000', 'deposit_amount' => '500.000', 'start_date' => $draft->start_date, 'end_date' => $draft->end_date]);
    $au->charges()->create(['type' => 'rent', 'monthly_amount' => '450.000', 'tax_category' => 'exempt']);

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $draft])
        ->assertSee('Hassan Qasim')
        ->assertSee('50.000')       // discount in BHD
        ->assertSee('10.0%')
        ->assertSee(route('agreements.edit', $draft))
        ->call('deleteDraft')
        ->assertRedirect(route('agreements.index'));

    expect($draft->fresh())->toBeNull();
});

test('the list follows the scope and filters agreements expiring soon', function () {
    $soon = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => now()->subMonths(11)->toDateString(), 'end_date' => now()->addDays(20)->toDateString()], [$this->flat]);
    $later = activeAgreement(['start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString()], [Unit::factory()->for($this->building)->create()]);
    $hidden = activeAgreement([], [Unit::factory()->create()]);

    Livewire::actingAs($this->leasing)->test(Index::class)
        ->assertSee($soon->number)->assertSee($later->number)->assertDontSee($hidden->number)
        ->set('expiring', 30)
        ->assertSee($soon->number)->assertDontSee($later->number);

    $this->actingAs($this->leasing)->get(route('agreements.show', $hidden))->assertForbidden();
    $this->actingAs($this->leasing)->get(route('agreements.show', $soon))->assertOk();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Agreements/AgreementScreensTest.php`
Expected: FAIL — `Class "App\Livewire\Agreements\Form" not found`.

- [ ] **Step 3: The list**

Create `app/Livewire/Agreements/Index.php`:
```php
<?php

namespace App\Livewire\Agreements;

use App\Enums\AgreementStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Agreements')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = '';

    /** "Expiring soon" is a filter, not a status (spec §5.4): 30, 60 or 90 days. */
    #[Url]
    public ?int $expiring = null;

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $today = now('Asia/Bahrain')->toDateString();

        $agreements = Agreement::query()
            ->visibleTo($this->actor())
            ->with('customer:id,name_en')
            ->withCount('agreementUnits')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when(in_array($this->expiring, [30, 60, 90], true), fn ($q) => $q
                ->where('status', AgreementStatus::Active)
                ->whereBetween('end_date', [$today, now('Asia/Bahrain')->addDays((int) $this->expiring)->toDateString()]))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', '%'.$this->search.'%'))))
            ->latest('id')
            ->paginate(25);

        return view('livewire.agreements.index', ['agreements' => $agreements, 'statuses' => AgreementStatus::cases()]);
    }
}
```
Create `resources/views/livewire/agreements/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Agreements') }}</flux:heading>
        @can('create', \App\Models\Agreement::class)
            <flux:button variant="primary" :href="route('agreements.create')" wire:navigate>{{ __('New agreement') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="status" class="sm:max-w-48">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="expiring" class="sm:max-w-48">
            <option value="">{{ __('Any end date') }}</option>
            <option value="30">{{ __('Expiring in 30 days') }}</option>
            <option value="60">{{ __('Expiring in 60 days') }}</option>
            <option value="90">{{ __('Expiring in 90 days') }}</option>
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number or customer')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$agreements">
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Customer') }}</flux:table.column>
                <flux:table.column>{{ __('Units') }}</flux:table.column>
                <flux:table.column>{{ __('Dates') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($agreements as $agreement)
                    <flux:table.row :key="$agreement->id">
                        <flux:table.cell><flux:link :href="route('agreements.show', $agreement)" wire:navigate>{{ $agreement->label() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $agreement->customer->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $agreement->agreement_units_count }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $agreement->start_date->format('d/m/Y') }} – {{ $agreement->end_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $agreement->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```

- [ ] **Step 4: The draft form**

Create `app/Livewire/Agreements/Form.php`:
```php
<?php

namespace App\Livewire\Agreements;

use App\Actions\Agreements\SaveAgreement;
use App\Enums\AgreementStatus;
use App\Enums\ChargeType;
use App\Enums\PaymentFrequency;
use App\Enums\TaxCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Unit;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    use WithActor;

    #[Locked]
    public ?int $agreementId = null;

    /** @var array<string, mixed> header fields */
    public array $form = ['frequency' => 'monthly'];

    /** @var list<array{unit_id: int, label: string, deposit_amount: string, start_date: ?string, end_date: ?string, charges: list<array<string, mixed>>}> */
    public array $units = [];

    public string $customerSearch = '';

    public string $customerLabel = '';

    public ?int $pickBuilding = null;

    public ?int $pickUnit = null;

    public function mount(?Agreement $agreement = null): void
    {
        if (! $agreement?->exists) {
            abort_unless($this->actor()->can('create', Agreement::class), 403);

            return;
        }

        abort_unless($this->actor()->can('update', $agreement) && $agreement->status === AgreementStatus::Draft, 403);

        $agreement->load(['customer', 'agreementUnits.unit.building', 'agreementUnits.charges']);
        $this->agreementId = $agreement->id;
        $this->customerLabel = $agreement->customer->name_en.' ('.$agreement->customer->maskedId().')';
        $this->form = [
            'customer_id' => $agreement->customer_id,
            'start_date' => $agreement->start_date->toDateString(),
            'end_date' => $agreement->end_date->toDateString(),
            'frequency' => $agreement->frequency->value,
            'billing_day' => $agreement->billing_day,
            'grace_days' => $agreement->grace_days,
            'notice_period_days' => $agreement->notice_period_days,
            'contract_template_id' => $agreement->contract_template_id,
        ];
        $this->units = $agreement->agreementUnits->map(fn ($au) => [
            'unit_id' => $au->unit_id,
            'label' => $au->unit->building->code.' / '.$au->unit->code,
            'deposit_amount' => $au->deposit_amount,
            'start_date' => $au->start_date->toDateString(),
            'end_date' => $au->end_date->toDateString(),
            'charges' => $au->charges->map(fn ($c) => [
                'type' => $c->type->value, 'description' => $c->description, 'monthly_amount' => $c->monthly_amount, 'tax_category' => $c->tax_category->value,
            ])->values()->all(),
        ])->values()->all();
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::query()->visibleTo($this->actor())->findOrFail($customerId);
        $this->form['customer_id'] = $customer->id;
        $this->customerLabel = $customer->name_en.' ('.$customer->maskedId().')';
        $this->customerSearch = '';
    }

    public function updatedPickBuilding(): void
    {
        $this->pickUnit = null;
    }

    /** Adds the picked unit with its list terms: rent, deposit and service charge, taxed per its use (spec §5.3). */
    public function addUnit(): void
    {
        $unit = Unit::query()->visibleTo($this->actor())->with('building')->find($this->pickUnit);

        if (! $unit || collect($this->units)->contains('unit_id', $unit->id)) {
            return;
        }

        $tax = $unit->effectiveTaxCategory()->value;
        $charges = [['type' => ChargeType::Rent->value, 'description' => null, 'monthly_amount' => $unit->list_rent, 'tax_category' => $tax]];
        if (Fils::fromDecimal($unit->list_service_charge) > 0) {
            $charges[] = ['type' => ChargeType::ServiceCharge->value, 'description' => null, 'monthly_amount' => $unit->list_service_charge, 'tax_category' => $tax];
        }

        $this->units[] = [
            'unit_id' => $unit->id,
            'label' => $unit->building->code.' / '.$unit->code,
            'deposit_amount' => $unit->list_deposit,
            'start_date' => null,
            'end_date' => null,
            'charges' => $charges,
        ];
        $this->pickUnit = null;
    }

    public function removeUnit(int $index): void
    {
        unset($this->units[$index]);
        $this->units = array_values($this->units);
    }

    public function addCharge(int $index): void
    {
        $this->units[$index]['charges'][] = ['type' => ChargeType::Parking->value, 'description' => null, 'monthly_amount' => '0', 'tax_category' => TaxCategory::Exempt->value];
    }

    public function removeCharge(int $index, int $charge): void
    {
        unset($this->units[$index]['charges'][$charge]);
        $this->units[$index]['charges'] = array_values($this->units[$index]['charges']);
    }

    public function save(SaveAgreement $save): void
    {
        $data = [...$this->form, 'units' => array_map(fn (array $u) => collect($u)->except('label')->all(), $this->units)];

        try {
            $agreement = $save->handle($this->actor(), $this->agreementId ? Agreement::findOrFail($this->agreementId) : null, $data);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            // units.* keys match this component's $units property; header keys live under form.*.
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'units') ? $k : "form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('agreements.show', $agreement, navigate: true);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $term = trim($this->customerSearch);

        return view('livewire.agreements.form', [
            'customerResults' => mb_strlen($term) < 2 ? collect() : Customer::query()->visibleTo($actor)
                ->where(fn ($q) => $q->where('name_en', 'like', '%'.$term.'%')->orWhere('id_number', $term)->orWhere('mobile', $term))
                ->orderBy('name_en')->limit(8)->get(['id', 'name_en', 'id_number', 'mobile']),
            'buildings' => Building::query()->visibleTo($actor)->orderBy('name')->get(['id', 'code', 'name']),
            'pickableUnits' => $this->pickBuilding
                ? Unit::query()->visibleTo($actor)->where('building_id', $this->pickBuilding)->orderBy('code')->get(['id', 'code', 'list_rent'])
                : collect(),
            'frequencies' => PaymentFrequency::cases(),
            'chargeTypes' => ChargeType::cases(),
            'taxCategories' => TaxCategory::cases(),
            'templates' => ContractTemplate::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ])->title($this->agreementId ? __('Edit draft agreement') : __('New agreement'));
    }
}
```
Create `resources/views/livewire/agreements/form.blade.php`:
```blade
<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ $agreementId ? __('Edit draft agreement') : __('New agreement') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:fieldset>
            <flux:legend>{{ __('Customer') }}</flux:legend>
            @if ($customerLabel)
                <flux:text class="mb-2">{{ $customerLabel }}</flux:text>
            @endif
            <flux:input wire:model.live.debounce.300ms="customerSearch" :placeholder="__('Search name, exact ID or mobile')" icon="magnifying-glass" />
            @if ($customerResults->isNotEmpty())
                <ul class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($customerResults as $c)
                        <li><button type="button" wire:click="selectCustomer({{ $c->id }})" class="w-full px-3 py-2 text-start text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">{{ $c->name_en }} · {{ $c->maskedId() }} · {{ $c->mobile }}</button></li>
                    @endforeach
                </ul>
            @endif
            <flux:error name="form.customer_id" />
        </flux:fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.start_date" type="date" :label="__('Start date')" />
            <flux:input wire:model="form.end_date" type="date" :label="__('End date')" />
            <flux:select wire:model="form.frequency" :label="__('Billing frequency')">
                @foreach ($frequencies as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.billing_day" type="number" min="1" max="28" :label="__('Billing day (optional, 1–28)')" />
            <flux:input wire:model="form.grace_days" type="number" min="0" :label="__('Grace days (blank = company default)')" />
            <flux:input wire:model="form.notice_period_days" type="number" min="0" :label="__('Notice period (days)')" />
            <flux:select wire:model="form.contract_template_id" :label="__('Contract template')" class="sm:col-span-2">
                <option value="">{{ __('Default template') }}</option>
                @foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
            </flux:select>
        </div>

        <flux:fieldset>
            <flux:legend>{{ __('Units') }}</flux:legend>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <flux:select wire:model.live="pickBuilding" :label="__('Building')" class="sm:max-w-56">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($buildings as $b)<option value="{{ $b->id }}">{{ $b->code }} — {{ $b->name }}</option>@endforeach
                </flux:select>
                <flux:select wire:model="pickUnit" :label="__('Unit')" class="sm:max-w-44">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($pickableUnits as $u)<option value="{{ $u->id }}">{{ $u->code }} ({{ $u->list_rent }})</option>@endforeach
                </flux:select>
                <flux:button wire:click="addUnit" icon="plus">{{ __('Add unit') }}</flux:button>
            </div>
            <flux:error name="form.units" />

            @foreach ($units as $i => $line)
                <flux:card class="mt-4 space-y-3" wire:key="unit-{{ $line['unit_id'] }}">
                    <div class="flex items-center justify-between">
                        <flux:heading>{{ $line['label'] }}</flux:heading>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeUnit({{ $i }})" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <flux:input wire:model="units.{{ $i }}.deposit_amount" inputmode="decimal" :label="__('Deposit (BHD)')" />
                        <flux:input wire:model="units.{{ $i }}.start_date" type="date" :label="__('From (blank = agreement)')" />
                        <flux:input wire:model="units.{{ $i }}.end_date" type="date" :label="__('To (blank = agreement)')" />
                    </div>
                    <flux:error name="units.{{ $i }}.start_date" />
                    @foreach ($line['charges'] as $j => $charge)
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]" wire:key="charge-{{ $line['unit_id'] }}-{{ $j }}">
                            <flux:select wire:model="units.{{ $i }}.charges.{{ $j }}.type">
                                @foreach ($chargeTypes as $ct)<option value="{{ $ct->value }}">{{ $ct->label() }}</option>@endforeach
                            </flux:select>
                            <flux:input wire:model="units.{{ $i }}.charges.{{ $j }}.monthly_amount" inputmode="decimal" :placeholder="__('BHD / month')" />
                            <flux:select wire:model="units.{{ $i }}.charges.{{ $j }}.tax_category">
                                @foreach ($taxCategories as $tc)<option value="{{ $tc->value }}">{{ $tc->label() }}</option>@endforeach
                            </flux:select>
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeCharge({{ $i }}, {{ $j }})" />
                        </div>
                    @endforeach
                    <flux:error name="units.{{ $i }}.charges" />
                    <flux:button size="sm" wire:click="addCharge({{ $i }})" icon="plus">{{ __('Add charge') }}</flux:button>
                </flux:card>
            @endforeach
        </flux:fieldset>

        <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
    </form>
</section>
```

- [ ] **Step 5: The show page**

Create `app/Livewire/Agreements/Show.php`:
```php
<?php

namespace App\Livewire\Agreements;

use App\Actions\Agreements\DeleteDraftAgreement;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $agreementId;

    public function mount(Agreement $agreement): void
    {
        abort_unless($this->actor()->can('view', $agreement), 403);
        $this->agreementId = $agreement->id;
    }

    protected function agreement(): Agreement
    {
        return Agreement::with(['customer', 'creator:id,name', 'agreementUnits.unit.building', 'agreementUnits.charges'])->findOrFail($this->agreementId);
    }

    public function deleteDraft(DeleteDraftAgreement $delete): void
    {
        try {
            $delete->handle($this->actor(), $this->agreement());
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->redirectRoute('agreements.index', navigate: true);
    }

    public function render(): View
    {
        $agreement = $this->agreement();

        return view('livewire.agreements.show', [
            'agreement' => $agreement,
            'canManage' => $this->actor()->can('update', $agreement),
        ])->title($agreement->label());
    }
}
```
Create `resources/views/livewire/agreements/show.blade.php`:
```blade
@use('App\Enums\AgreementStatus')
@use('App\Support\Fils')
@php
    $list = $agreement->listRentFils();
    $discount = $agreement->discountFils();
@endphp
<section class="w-full max-w-4xl space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $agreement->label() }}</flux:heading>
            <flux:badge>{{ $agreement->status->label() }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canManage && $agreement->status === AgreementStatus::Draft)
                <flux:button :href="route('agreements.edit', $agreement)" wire:navigate>{{ __('Edit') }}</flux:button>
                <flux:button variant="ghost" wire:click="deleteDraft" wire:confirm="{{ __('Delete this draft?') }}">{{ __('Delete draft') }}</flux:button>
            @endif
            {{-- Task 7: submit + approvals. Task 8: contract PDF. Task 9: notice. Task 10: invoices. --}}
        </div>
    </div>

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Customer') }}</dt><dd><flux:link :href="route('customers.edit', $agreement->customer)" wire:navigate>{{ $agreement->customer->name_en }}</flux:link></dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Dates') }}</dt><dd>{{ $agreement->start_date->format('d/m/Y') }} – {{ $agreement->end_date->format('d/m/Y') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Billing') }}</dt><dd>{{ str($agreement->frequency->value)->headline() }}{{ $agreement->billing_day ? ', '.__('day :d', ['d' => $agreement->billing_day]) : '' }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Grace / notice') }}</dt><dd>{{ __(':g days / :n days', ['g' => $agreement->grace_days, 'n' => $agreement->notice_period_days]) }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Monthly rent (list → agreed)') }}</dt>
            <dd class="tabular-nums">{{ Fils::toDecimal($list) }} → {{ Fils::toDecimal($agreement->monthlyRentFils()) }}
                @if ($discount !== 0)
                    · {{ __('discount :bhd BHD (:pct%)', ['bhd' => Fils::toDecimal($discount), 'pct' => $list > 0 ? number_format($discount * 100 / $list, 1) : '0.0']) }}
                @endif
            </dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Total deposit') }}</dt><dd class="tabular-nums">{{ Fils::toDecimal($agreement->depositFils()) }} BHD</dd></div>
        @if ($agreement->planned_exit_date)
            <div><dt class="text-sm text-zinc-500">{{ __('Notice') }}</dt><dd>{{ __('given :n, leaving :p', ['n' => $agreement->notice_date?->format('d/m/Y'), 'p' => $agreement->planned_exit_date->format('d/m/Y')]) }}</dd></div>
        @endif
        <div><dt class="text-sm text-zinc-500">{{ __('Created by') }}</dt><dd>{{ $agreement->creator->name }}</dd></div>
    </dl>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                <flux:table.column>{{ __('Dates') }}</flux:table.column>
                <flux:table.column>{{ __('Charges / month') }}</flux:table.column>
                <flux:table.column>{{ __('List rent') }}</flux:table.column>
                <flux:table.column>{{ __('Deposit') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($agreement->agreementUnits as $au)
                    <flux:table.row :key="$au->id">
                        <flux:table.cell>{{ $au->unit->building->code }} / {{ $au->unit->code }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $au->start_date->format('d/m/Y') }} – {{ $au->end_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>
                            @foreach ($au->charges as $c)
                                <div class="tabular-nums">{{ $c->type->label() }}: {{ $c->monthly_amount }} <span class="text-xs text-zinc-500">{{ $c->tax_category->label() }}</span></div>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $au->list_rent }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $au->deposit_amount }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <livewire:documents.panel :documentable="$agreement" :key="'docs-agreement-'.$agreement->id" />
</section>
```
In `app/Livewire/Documents/Panel.php` add `Agreement::class` to `ALLOWED` (import `App\Models\Agreement`).

- [ ] **Step 6: Routes and navigation**

In `routes/property.php` add `use App\Livewire\Agreements;` and inside the group:
```php
    Route::livewire('agreements', Agreements\Index::class)->middleware('can:agreements.view')->name('agreements.index');
    Route::livewire('agreements/create', Agreements\Form::class)->middleware('can:agreements.manage')->name('agreements.create');
    Route::livewire('agreements/{agreement}/edit', Agreements\Form::class)->middleware('can:agreements.manage')->name('agreements.edit');
    Route::livewire('agreements/{agreement}', Agreements\Show::class)->middleware('can:agreements.view')->name('agreements.show');
```
In the sidebar's Leasing group add:
```blade
                    @can('agreements.view')
                        <flux:sidebar.item icon="document-text" :href="route('agreements.index')" :current="request()->routeIs('agreements.*')" wire:navigate>{{ __('Agreements') }}</flux:sidebar.item>
                    @endcan
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Agreements
"$PHP" artisan test
```
Expected: 5 new tests pass; full suite passes.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add agreement draft screens

Draft an agreement by looking up the customer and adding units with
their list terms; the detail page shows the discount against list rent.
Lists filter by status and by agreements expiring in 30, 60 or 90 days.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 5: The billing engine — periods, proration and tax (pure PHP)

**Spec:** §6.2 (periods from the anchor with end-of-month clamping, stub, last period ends on `end_date`; full period = monthly × months; partial = whole months from the period start × monthly + days × daily rate; `actual_365` or `days_30`; rounded once per line), §6.4 (tax = round(net × rate / 100, 3) for standard lines only), §2 (integer fils, half-up)

**Files:**
- Create: `app/Billing/BillingPeriod.php`, `app/Billing/BillingPeriods.php`, `app/Billing/Proration.php`, `app/Billing/Tax.php`, `tests/Unit/Billing/BillingPeriodsTest.php`, `tests/Unit/Billing/ProrationTest.php`, `tests/Unit/Billing/TaxTest.php`
- Modify: `app/Support/Fils.php`, `tests/Unit/FilsTest.php`

**Interfaces:**
- Consumes: `PaymentFrequency::months()`, `ProrationBasis`, `TaxCategory`, `Fils`
- Produces:
  - `Fils::divRound(int $numerator, int $denominator): int` — integer division rounded half away from zero
  - `BillingPeriod` (readonly: `CarbonImmutable $start`, `CarbonImmutable $end`, `bool $regular` — true when it runs anchor to next anchor − 1 day)
  - `BillingPeriods::for(CarbonImmutable $start, CarbonImmutable $end, PaymentFrequency $frequency, ?int $billingDay): list<BillingPeriod>`
  - `Proration::line(BillingPeriod $period, CarbonImmutable $from, CarbonImmutable $to, int $monthlyFils, int $months, ProrationBasis $basis): int` — the fils for one charge over its unit's dates `$from..$to` within `$period` (0 if they don't overlap)
  - `Proration::partial(int $monthlyFils, CarbonImmutable $from, CarbonImmutable $to, ProrationBasis $basis): int`
  - `Tax::amount(int $netFils, TaxCategory $category, bool $vatRegistered, string $rate): int` with `$rate` like `'10.00'`

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/FilsTest.php` add:
```php
test('divRound rounds half away from zero', function () {
    expect(Fils::divRound(5, 2))->toBe(3)
        ->and(Fils::divRound(4, 2))->toBe(2)
        ->and(Fils::divRound(7, 3))->toBe(2)
        ->and(Fils::divRound(-5, 2))->toBe(-3)
        ->and(Fils::divRound(0, 7))->toBe(0);
});
```
Create `tests/Unit/Billing/BillingPeriodsTest.php`:
```php
<?php

use App\Billing\BillingPeriod;
use App\Billing\BillingPeriods;
use App\Enums\PaymentFrequency;
use Carbon\CarbonImmutable;

function bpDate(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

/** @param list<BillingPeriod> $periods */
function spans(array $periods): array
{
    return array_map(fn (BillingPeriod $p) => $p->start->format('Y-m-d').'..'.$p->end->format('Y-m-d').($p->regular ? '' : ' partial'), $periods);
}

test('monthly from the first of the month gives twelve full periods', function () {
    $periods = BillingPeriods::for(bpDate('2026-11-01'), bpDate('2027-10-31'), PaymentFrequency::Monthly, null);

    expect($periods)->toHaveCount(12)
        ->and(spans($periods)[0])->toBe('2026-11-01..2026-11-30')
        ->and(spans($periods)[3])->toBe('2027-02-01..2027-02-28')
        ->and(spans($periods)[11])->toBe('2027-10-01..2027-10-31')
        ->and(collect($periods)->every(fn ($p) => $p->regular))->toBeTrue();
});

test('without a billing day the anchor is the start day', function () {
    $periods = BillingPeriods::for(bpDate('2026-11-15'), bpDate('2027-11-14'), PaymentFrequency::Monthly, null);

    expect($periods)->toHaveCount(12)
        ->and(spans($periods)[0])->toBe('2026-11-15..2026-12-14')
        ->and(spans($periods)[11])->toBe('2027-10-15..2027-11-14');
});

test('a billing day adds a stub at the start and a short last period', function () {
    $periods = BillingPeriods::for(bpDate('2026-11-20'), bpDate('2027-11-19'), PaymentFrequency::Monthly, 1);

    expect(spans($periods)[0])->toBe('2026-11-20..2026-11-30 partial')
        ->and(spans($periods)[1])->toBe('2026-12-01..2026-12-31')
        ->and(end($periods)->start->format('Y-m-d').'..'.end($periods)->end->format('Y-m-d'))->toBe('2027-11-01..2027-11-19')
        ->and(end($periods)->regular)->toBeFalse()
        ->and($periods)->toHaveCount(13);
});

test('month-end anchors clamp from the anchor, not from the previous period', function () {
    $periods = BillingPeriods::for(bpDate('2027-01-31'), bpDate('2027-04-30'), PaymentFrequency::Monthly, null);

    expect(spans($periods))->toBe([
        '2027-01-31..2027-02-27',
        '2027-02-28..2027-03-30',
        '2027-03-31..2027-04-29',
        '2027-04-30..2027-04-30 partial',
    ]);
});

test('quarterly periods end on the agreement end date', function () {
    expect(spans(BillingPeriods::for(bpDate('2027-01-01'), bpDate('2027-08-31'), PaymentFrequency::Quarterly, null)))->toBe([
        '2027-01-01..2027-03-31',
        '2027-04-01..2027-06-30',
        '2027-07-01..2027-08-31 partial',
    ]);
});

test('an agreement shorter than its first stub is one partial period', function () {
    expect(spans(BillingPeriods::for(bpDate('2026-11-20'), bpDate('2026-11-25'), PaymentFrequency::Monthly, 1)))->toBe(['2026-11-20..2026-11-25 partial']);
});
```
Create `tests/Unit/Billing/ProrationTest.php`:
```php
<?php

use App\Billing\BillingPeriod;
use App\Billing\Proration;
use App\Enums\ProrationBasis;
use Carbon\CarbonImmutable;

$d = fn (string $date) => CarbonImmutable::parse($date);

test('a full regular period is the monthly amount times its months, even in February', function () use ($d) {
    $feb = new BillingPeriod($d('2027-02-01'), $d('2027-02-28'), true);
    $quarter = new BillingPeriod($d('2027-01-01'), $d('2027-03-31'), true);

    expect(Proration::line($feb, $d('2026-11-01'), $d('2027-10-31'), 450_000, 1, ProrationBasis::Actual365))->toBe(450_000)
        ->and(Proration::line($quarter, $d('2026-11-01'), $d('2027-10-31'), 450_000, 3, ProrationBasis::Actual365))->toBe(1_350_000);
});

test('partial days use the daily rate of the chosen basis, rounded once', function () use ($d) {
    // 15 days of 450.000: actual/365 = 450 × 12 / 365 × 15 = 221.9178… → 221.918; 30-day = 225.000.
    expect(Proration::partial(450_000, $d('2026-11-01'), $d('2026-11-15'), ProrationBasis::Actual365))->toBe(221_918)
        ->and(Proration::partial(450_000, $d('2026-11-01'), $d('2026-11-15'), ProrationBasis::Days30))->toBe(225_000);
});

test('whole months count from the start, then remaining days', function () use ($d) {
    // 10 Oct → 9 Nov is one whole month; 10–24 Nov is 15 days.
    expect(Proration::partial(450_000, $d('2026-10-10'), $d('2026-11-24'), ProrationBasis::Actual365))->toBe(450_000 + 221_918)
        ->and(Proration::partial(450_000, $d('2026-10-01'), $d('2026-10-31'), ProrationBasis::Actual365))->toBe(450_000);
});

test('a unit inside a period is billed only for its own days', function () use ($d) {
    $nov = new BillingPeriod($d('2026-11-01'), $d('2026-11-30'), true);

    expect(Proration::line($nov, $d('2026-11-15'), $d('2027-10-31'), 850_000, 1, ProrationBasis::Actual365))->toBe(447_123) // 16 days
        ->and(Proration::line($nov, $d('2026-06-01'), $d('2026-11-10'), 850_000, 1, ProrationBasis::Days30))->toBe(283_333) // 10 days
        ->and(Proration::line($nov, $d('2026-12-01'), $d('2027-10-31'), 850_000, 1, ProrationBasis::Actual365))->toBe(0);
});
```
Create `tests/Unit/Billing/TaxTest.php`:
```php
<?php

use App\Billing\Tax;
use App\Enums\TaxCategory;

test('standard lines are taxed at the rate, rounded half-up to the fil', function () {
    expect(Tax::amount(132_500, TaxCategory::Standard, true, '10.00'))->toBe(13_250)
        ->and(Tax::amount(105, TaxCategory::Standard, true, '10.00'))->toBe(11)
        ->and(Tax::amount(1_000, TaxCategory::Standard, true, '5.50'))->toBe(55);
});

test('other categories and unregistered companies charge no tax', function (TaxCategory $category) {
    expect(Tax::amount(132_500, $category, true, '10.00'))->toBe(0);
})->with([TaxCategory::ZeroRated, TaxCategory::Exempt, TaxCategory::OutOfScope]);

test('an unregistered company charges no tax at all', function () {
    expect(Tax::amount(132_500, TaxCategory::Standard, false, '10.00'))->toBe(0);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Unit`
Expected: FAIL — `Class "App\Billing\BillingPeriods" not found` (and `divRound` undefined).

- [ ] **Step 3: Implement**

In `app/Support/Fils.php` add:
```php
    /** $numerator / $denominator rounded half away from zero, in integers (spec §2: half-up to the fil). */
    public static function divRound(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;

        return $sign * intdiv(2 * abs($numerator) + abs($denominator), 2 * abs($denominator));
    }
```
Create `app/Billing/BillingPeriod.php`:
```php
<?php

namespace App\Billing;

use Carbon\CarbonImmutable;

final readonly class BillingPeriod
{
    /** @param  bool  $regular  runs from an anchor to the day before the next anchor */
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end, public bool $regular) {}
}
```
Create `app/Billing/BillingPeriods.php`:
```php
<?php

namespace App\Billing;

use App\Enums\PaymentFrequency;
use Carbon\CarbonImmutable;

/** Spec §6.2 billing periods. */
final class BillingPeriods
{
    /** @return list<BillingPeriod> */
    public static function for(CarbonImmutable $start, CarbonImmutable $end, PaymentFrequency $frequency, ?int $billingDay): array
    {
        $start = $start->startOfDay();
        $end = $end->startOfDay();
        $months = $frequency->months();
        $anchor = $billingDay === null ? $start : self::firstOnOrAfter($start, $billingDay);
        $periods = [];

        if ($anchor->greaterThan($start)) {
            $periods[] = new BillingPeriod($start, $anchor->subDay()->min($end), false);
        }

        // Period k starts at anchor + k × frequency, always counted from the anchor so month-end days clamp and recover (31 Jan → 28 Feb → 31 Mar).
        for ($k = 0; ($from = $anchor->addMonthsNoOverflow($k * $months))->lessThanOrEqualTo($end); $k++) {
            $to = $anchor->addMonthsNoOverflow(($k + 1) * $months)->subDay();
            $periods[] = $to->lessThanOrEqualTo($end)
                ? new BillingPeriod($from, $to, true)
                : new BillingPeriod($from, $end, false);
        }

        return $periods;
    }

    private static function firstOnOrAfter(CarbonImmutable $date, int $day): CarbonImmutable
    {
        $candidate = $date->setDay($day);

        return $candidate->lessThan($date) ? $date->startOfMonth()->addMonthNoOverflow()->setDay($day) : $candidate;
    }
}
```
Create `app/Billing/Proration.php`:
```php
<?php

namespace App\Billing;

use App\Enums\ProrationBasis;
use App\Support\Fils;
use Carbon\CarbonImmutable;

/** Spec §6.2 amounts, in integer fils. */
final class Proration
{
    /** One charge over its unit's dates ($from..$to) within one billing period. */
    public static function line(BillingPeriod $period, CarbonImmutable $from, CarbonImmutable $to, int $monthlyFils, int $months, ProrationBasis $basis): int
    {
        $from = $from->startOfDay()->max($period->start);
        $to = $to->startOfDay()->min($period->end);

        if ($from->greaterThan($to)) {
            return 0;
        }

        if ($period->regular && $from->equalTo($period->start) && $to->equalTo($period->end)) {
            return $monthlyFils * $months;
        }

        return self::partial($monthlyFils, $from, $to, $basis);
    }

    /** Whole months counted from $from × monthly + remaining days × daily rate; rounded once. */
    public static function partial(int $monthlyFils, CarbonImmutable $from, CarbonImmutable $to, ProrationBasis $basis): int
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        $whole = 0;
        while ($from->addMonthsNoOverflow($whole + 1)->subDay()->lessThanOrEqualTo($to)) {
            $whole++;
        }

        $rest = $from->addMonthsNoOverflow($whole);
        $days = $rest->greaterThan($to) ? 0 : (int) $rest->diffInDays($to, true) + 1;

        $dayPart = match ($basis) {
            ProrationBasis::Actual365 => Fils::divRound($monthlyFils * $days * 12, 365),
            ProrationBasis::Days30 => Fils::divRound($monthlyFils * $days, 30),
        };

        return $monthlyFils * $whole + $dayPart;
    }
}
```
Create `app/Billing/Tax.php`:
```php
<?php

namespace App\Billing;

use App\Enums\TaxCategory;
use App\Support\Fils;

/** Spec §6.4. */
final class Tax
{
    /** @param  string  $rate  percent with up to 3 decimals, e.g. '10.00' */
    public static function amount(int $netFils, TaxCategory $category, bool $vatRegistered, string $rate): int
    {
        if (! $vatRegistered || $category !== TaxCategory::Standard) {
            return 0;
        }

        // Fils::fromDecimal reads '10.00' as 10000 thousandths, so tax = net × rate‰ / 100 000.
        return Fils::divRound($netFils * Fils::fromDecimal($rate), 100_000);
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Unit
"$PHP" artisan test
```
Expected: every Unit test passes; full suite passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the billing engine: periods, proration and tax

Periods follow the anchor with month-end clamping, a start stub and a
short last period. Partial periods are whole months plus days at the
actual/365 or 30-day rate, and tax is half-up to the fil, all in
integer fils.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 6: Invoices — schema, rent schedule, deposit invoice and issuing

**Spec:** §6.1 (tables, statuses, generated `balance`, line CHECK), §6.2 (one scheduled rent invoice per period, one line per agreement unit per recurring charge, due = period start, issue date = max(activation date, due − lead days); deposit invoice at activation, due on `start_date`, tax `out_of_scope`), §6.3 (issue: tax and owner stamp on each line, then number, `grace_until`, `issued_at`, then `issued`; hold back §4.6), §6.4, §4.6 (attribution date rules), §8.5 (no DELETE; issued immutable except `allocated`/`credited`; lines frozen once the parent is not draft; one-way status), §6.8

**Files:**
- Create: `app/Enums/{InvoiceType,InvoiceStatus,InvoiceChargeType}.php`, `database/migrations/2026_10_05_000400_create_invoices_tables.php`, `app/Models/{Invoice,InvoiceLine}.php`, `app/Actions/Billing/{GenerateRentSchedule,CreateDepositInvoice,IssueInvoice,IssueDueInvoices}.php`, `tests/Feature/Billing/RentScheduleTest.php`, `tests/Feature/Billing/IssueInvoiceTest.php`, `tests/Feature/Billing/InvoiceTriggersTest.php`
- Modify: `app/Models/Agreement.php`

**Interfaces:**
- Consumes: `BillingPeriods`, `Proration`, `Tax`, `Fils`, `Agreement` (+ `agreementUnits.charges`), `OwnerContract::effectiveOn()`, `NextDocumentNumber`, `NumberSequenceKey::Invoice`, `CompanySetting::current()`
- Produces:
  - `InvoiceType` (`Rent`, `Deposit`, `Manual`, `Opening`, `CreditNote`), `InvoiceStatus` (`Draft`, `PendingApproval`, `Scheduled`, `Issued`, `Cancelled`; `label()`), `InvoiceChargeType` (rent, service_charge, parking, other, deposit, damage, cleaning, utilities, opening_balance)
  - `Invoice` (relations `customer`, `agreement`, `lines`, `issuer`; scope `visibleTo($user)`; `label()`), `InvoiceLine` (relations `invoice`, `agreementUnit`, `unit`, `ownerContract`)
  - `Agreement::invoices()`
  - `GenerateRentSchedule::handle(Agreement $agreement, User $actor): Collection<int, Invoice>` (inside the caller's transaction)
  - `CreateDepositInvoice::handle(Agreement $agreement, User $actor): ?Invoice` (creates and issues; null when no unit has a deposit)
  - `IssueInvoice::handle(Invoice $invoice, ?User $issuer = null): bool` (false = held back by §4.6; throws `ValidationException` if not draft/scheduled)
  - `IssueDueInvoices::__invoke(?Agreement $only = null): int` (issues scheduled invoices with `issue_date ≤ today`; returns how many were issued)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Billing/RentScheduleTest.php`:
```php
<?php

use App\Actions\Billing\CreateDepositInvoice;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\EnsureNumberSequences;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CompanySetting::factory()->create(['invoice_lead_days' => 7, 'default_grace_days' => 5, 'proration_basis' => 'actual_365']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->actor = User::factory()->create();
    $building = Building::factory()->create(['code' => 'MT']);
    $this->flat = Unit::factory()->for($building)->create(['code' => '101']);
    $this->shop = Unit::factory()->for($building)->create(['code' => 'S1']);
});

/** A draft agreement with a flat (rent + service charge) and a shop joining on 15 Nov, walked to active. */
function scheduledAgreement(object $test, array $attributes = []): Agreement
{
    $agreement = Agreement::factory()->create(['start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'frequency' => 'monthly', ...$attributes]);
    $flat = $agreement->agreementUnits()->create(['unit_id' => $test->flat->id, 'list_rent' => '450.000', 'deposit_amount' => '450.000', 'start_date' => $agreement->start_date, 'end_date' => $agreement->end_date]);
    $flat->charges()->create(['type' => 'rent', 'monthly_amount' => '450.000', 'tax_category' => 'exempt']);
    $flat->charges()->create(['type' => 'service_charge', 'monthly_amount' => '20.000', 'tax_category' => 'exempt']);
    $shop = $agreement->agreementUnits()->create(['unit_id' => $test->shop->id, 'list_rent' => '850.000', 'deposit_amount' => '0.000', 'start_date' => max('2026-11-15', $agreement->start_date->toDateString()), 'end_date' => $agreement->end_date]);
    $shop->charges()->create(['type' => 'rent', 'monthly_amount' => '850.000', 'tax_category' => 'standard']);
    $agreement->forceFill(['status' => 'pending_approval'])->save();
    $agreement->forceFill(['status' => 'active', 'number' => 'AGR-T-1', 'verify_token' => str_repeat('x', 32)])->save();

    return $agreement->fresh();
}

test('one scheduled rent invoice per period, with a line per unit per charge, prorated', function () {
    $agreement = scheduledAgreement($this);

    $invoices = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->actor));

    expect($invoices)->toHaveCount(12)
        ->and($invoices->every(fn (Invoice $i) => $i->status === InvoiceStatus::Scheduled && $i->number === null && $i->type === InvoiceType::Rent))->toBeTrue();

    $nov = $invoices->first()->load('lines');
    expect($nov->period_start->toDateString())->toBe('2026-11-01')
        ->and($nov->due_date->toDateString())->toBe('2026-11-01')
        ->and($nov->issue_date->toDateString())->toBe('2026-10-25')             // due − 7 lead days
        ->and($nov->lines->pluck('net')->sort()->values()->all())->toBe(['20.000', '447.123', '450.000']) // shop: 16 days of 850
        ->and($nov->lines->firstWhere('net', '447.123')->period_start->toDateString())->toBe('2026-11-15')
        ->and($nov->total)->toBe('917.123');

    expect($invoices[1]->load('lines')->lines->sum(fn ($l) => (float) $l->net))->toBe(1320.0);
});

test('the issue date never falls before activation', function () {
    $agreement = scheduledAgreement($this, ['start_date' => '2026-10-01', 'end_date' => '2027-09-30']);

    $first = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->actor))->first();

    expect($first->issue_date->toDateString())->toBe('2026-10-05');
});

test('a quarterly agreement with a billing day starts with a stub', function () {
    $agreement = scheduledAgreement($this, ['start_date' => '2026-11-20', 'end_date' => '2027-11-19', 'frequency' => 'quarterly', 'billing_day' => 1]);

    $invoices = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->actor));

    expect($invoices->first()->period_end->toDateString())->toBe('2026-11-30')
        ->and($invoices[1]->period_start->toDateString())->toBe('2026-12-01')
        ->and($invoices[1]->period_end->toDateString())->toBe('2027-02-28');
});

test('the deposit invoice is issued at activation, out of scope, due on the start date', function () {
    $agreement = scheduledAgreement($this);

    $deposit = DB::transaction(fn () => app(CreateDepositInvoice::class)->handle($agreement, $this->actor))->load('lines');

    expect($deposit->type)->toBe(InvoiceType::Deposit)
        ->and($deposit->status)->toBe(InvoiceStatus::Issued)
        ->and($deposit->number)->toBe('INV-2026-000001')
        ->and($deposit->lines)->toHaveCount(1)                      // the shop has no deposit
        ->and($deposit->lines[0]->tax_category->value)->toBe('out_of_scope')
        ->and($deposit->total)->toBe('450.000')
        ->and($deposit->due_date->toDateString())->toBe('2026-11-01')
        ->and($deposit->grace_until->toDateString())->toBe('2026-11-06');
});
```
Create `tests/Feature/Billing/IssueInvoiceTest.php`:
```php
<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Billing\IssueDueInvoices;
use App\Actions\Billing\IssueInvoice;
use App\Actions\EnsureNumberSequences;
use App\Enums\InvoiceStatus;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'invoice_lead_days' => 7, 'default_grace_days' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->create();
    $this->building = Building::factory()->create();
    $this->flat = Unit::factory()->for($this->building)->create();
    $this->shop = Unit::factory()->for($this->building)->create();

    $agreement = Agreement::factory()->create(['start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'grace_days' => 7]);
    foreach ([[$this->flat, '450.000', 'exempt'], [$this->shop, '850.000', 'standard']] as [$unit, $rent, $tax]) {
        $au = $agreement->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => $rent, 'deposit_amount' => 0, 'start_date' => $agreement->start_date, 'end_date' => $agreement->end_date]);
        $au->charges()->create(['type' => 'rent', 'monthly_amount' => $rent, 'tax_category' => $tax]);
    }
    $agreement->forceFill(['status' => 'pending_approval'])->save();
    $agreement->forceFill(['status' => 'active', 'number' => 'AGR-T-2', 'verify_token' => str_repeat('y', 32)])->save();
    $this->agreement = $agreement;
    $this->invoices = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($agreement, $this->finance));
});

test('issuing writes tax per line, the number, grace date and issuer', function () {
    $invoice = $this->invoices->first();

    expect(app(IssueInvoice::class)->handle($invoice, $this->finance))->toBeTrue();

    $invoice->refresh()->load('lines');
    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->number)->toBe('INV-2026-000001')
        ->and($invoice->lines->firstWhere('unit_id', $this->shop->id)->tax_amount)->toBe('85.000')
        ->and($invoice->lines->firstWhere('unit_id', $this->shop->id)->tax_rate)->toBe('10.00')
        ->and($invoice->lines->firstWhere('unit_id', $this->flat->id)->tax_amount)->toBe('0.000')
        ->and($invoice->subtotal)->toBe('1300.000')
        ->and($invoice->tax_total)->toBe('85.000')
        ->and($invoice->total)->toBe('1385.000')
        ->and($invoice->balance)->toBe('1385.000')
        ->and($invoice->grace_until->toDateString())->toBe('2026-11-08')   // agreement grace 7 days
        ->and($invoice->issued_by)->toBe($this->finance->id);

    expect(fn () => app(IssueInvoice::class)->handle($invoice->fresh(), $this->finance))->toThrow(ValidationException::class);
});

test('each line is stamped with the owner contract covering its unit on its period start', function () {
    $managed = activeOwnerContract(['building_id' => $this->building->id, 'start_date' => '2026-01-01', 'end_date' => '2026-11-30'], [$this->flat]);

    app(IssueInvoice::class)->handle($this->invoices[0]);  // November: covered
    app(IssueInvoice::class)->handle($this->invoices[1]);  // December: contract has ended

    expect($this->invoices[0]->lines()->where('unit_id', $this->flat->id)->value('owner_contract_id'))->toBe($managed->id)
        ->and($this->invoices[0]->lines()->where('unit_id', $this->shop->id)->value('owner_contract_id'))->toBeNull()
        ->and($this->invoices[1]->lines()->where('unit_id', $this->flat->id)->value('owner_contract_id'))->toBeNull();
});

test('an invoice is held back while an owner contract covering one of its lines is pending', function () {
    $pending = OwnerContract::factory()->create(['building_id' => $this->building->id, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $pending->units()->attach($this->shop->id);
    $pending->forceFill(['status' => 'pending_approval'])->save();

    expect(app(IssueInvoice::class)->handle($this->invoices->first()))->toBeFalse()
        ->and($this->invoices->first()->fresh()->status)->toBe(InvoiceStatus::Scheduled);

    $pending->forceFill(['status' => 'active', 'number' => 'OC-TEST-9'])->save();
    expect(app(IssueInvoice::class)->handle($this->invoices->first()))->toBeTrue()
        ->and($this->invoices->first()->lines()->where('unit_id', $this->shop->id)->value('owner_contract_id'))->toBe($pending->id);
});

test('the due-invoice run issues only what is due, once', function () {
    expect(app(IssueDueInvoices::class)())->toBe(0);          // November is issued from 25 Oct

    $this->travelTo(CarbonImmutable::parse('2026-10-25 01:00', 'Asia/Bahrain'));
    expect(app(IssueDueInvoices::class)())->toBe(1)
        ->and(app(IssueDueInvoices::class)())->toBe(0)
        ->and(Invoice::where('status', 'issued')->count())->toBe(1);
});

test('a company that is not VAT registered issues plain invoices with no tax', function () {
    CompanySetting::current()->forceFill(['vat_registered' => false])->save();

    app(IssueInvoice::class)->handle($this->invoices->first());

    expect($this->invoices->first()->fresh()->tax_total)->toBe('0.000');
});
```
Create `tests/Feature/Billing/InvoiceTriggersTest.php`:
```php
<?php

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Spec §8.5, enforced by MySQL.

beforeEach(function () {
    $this->invoiceId = DB::table('invoices')->insertGetId([
        'type' => 'manual', 'customer_id' => Customer::factory()->create()->id, 'issue_date' => '2026-10-05', 'due_date' => '2026-10-05',
        'status' => 'draft', 'subtotal' => '100.000', 'tax_total' => '0.000', 'total' => '100.000', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->lineId = DB::table('invoice_lines')->insertGetId([
        'invoice_id' => $this->invoiceId, 'charge_type' => 'other', 'description' => 'x', 'net' => '100.000',
        'tax_category' => 'exempt', 'tax_rate' => '0.00', 'tax_amount' => '0.000', 'total' => '100.000', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function inv(int $id): Illuminate\Database\Query\Builder
{
    return DB::table('invoices')->where('id', $id);
}

function issueRaw(int $id): void
{
    inv($id)->update(['status' => 'issued', 'number' => 'INV-2026-999999', 'grace_until' => '2026-10-10', 'issued_at' => now()]);
}

test('invoices are never deleted', function () {
    expect(fn () => inv($this->invoiceId)->delete())->toThrow(QueryException::class, 'invoices cannot be deleted');
});

test('lines are added only to drafts; a scheduled line only takes tax and its owner stamp', function () {
    inv($this->invoiceId)->update(['status' => 'scheduled']);

    expect(fn () => DB::table('invoice_lines')->insert(['invoice_id' => $this->invoiceId, 'charge_type' => 'other', 'description' => 'y', 'net' => 1, 'tax_category' => 'exempt', 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 1]))
        ->toThrow(QueryException::class, 'invoice_lines');
    expect(fn () => DB::table('invoice_lines')->where('id', $this->lineId)->update(['net' => '90.000', 'total' => '90.000']))
        ->toThrow(QueryException::class, 'invoice_lines');

    DB::table('invoice_lines')->where('id', $this->lineId)->update(['tax_rate' => '10.00', 'tax_amount' => '10.000', 'total' => '110.000']);
    expect(fn () => DB::table('invoice_lines')->where('id', $this->lineId)->delete())->toThrow(QueryException::class, 'invoice_lines');
});

test('an issued invoice changes only its allocated and credited amounts', function () {
    issueRaw($this->invoiceId);

    expect(fn () => inv($this->invoiceId)->update(['total' => '1.000', 'subtotal' => '1.000']))->toThrow(QueryException::class, 'issued invoice is immutable');
    expect(fn () => inv($this->invoiceId)->update(['status' => 'cancelled']))->toThrow(QueryException::class, 'status change not allowed');
    expect(fn () => DB::table('invoice_lines')->where('id', $this->lineId)->update(['net' => '1.000', 'total' => '1.000']))->toThrow(QueryException::class, 'invoice_lines');

    DB::table('invoice_lines')->where('id', $this->lineId)->update(['allocated' => '40.000']);
    inv($this->invoiceId)->update(['allocated' => '40.000']);
    expect(inv($this->invoiceId)->value('balance'))->toBe('60.000');
});

test('allocations can never exceed the total', function () {
    issueRaw($this->invoiceId);

    expect(fn () => DB::table('invoice_lines')->where('id', $this->lineId)->update(['allocated' => '100.001']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});

test('a number appears exactly when the invoice is issued', function () {
    expect(fn () => inv($this->invoiceId)->update(['number' => 'INV-2026-000001']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Billing`
Expected: FAIL — `Class "App\Actions\Billing\GenerateRentSchedule" not found`.

- [ ] **Step 3: Enums**

Create `app/Enums/InvoiceType.php`:
```php
<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Rent = 'rent';
    case Deposit = 'deposit';
    case Manual = 'manual';
    case Opening = 'opening';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `app/Enums/InvoiceStatus.php`:
```php
<?php

namespace App\Enums;

/** Spec §6.1. "Partially paid", "Paid" and "Overdue" are derived labels, never stored. */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `app/Enums/InvoiceChargeType.php`:
```php
<?php

namespace App\Enums;

enum InvoiceChargeType: string
{
    case Rent = 'rent';
    case ServiceCharge = 'service_charge';
    case Parking = 'parking';
    case Other = 'other';
    case Deposit = 'deposit';
    case Damage = 'damage';
    case Cleaning = 'cleaning';
    case Utilities = 'utilities';
    case OpeningBalance = 'opening_balance';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```

- [ ] **Step 4: Migration with the generated balance, CHECKs and triggers**

Create `database/migrations/2026_10_05_000400_create_invoices_tables.php`:
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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->string('type', 20);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('grace_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('replaced_by_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('tax_total', 12, 3)->default(0);
            $table->decimal('total', 12, 3)->default(0);
            $table->decimal('allocated', 12, 3)->default(0);
            $table->decimal('credited', 12, 3)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'issue_date']);
            $table->index(['customer_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoices
                ADD COLUMN balance DECIMAL(12,3) GENERATED ALWAYS AS (IF(type = 'credit_note', 0, total - allocated - credited)) STORED,
                ADD CONSTRAINT invoices_type_chk CHECK (type IN ('rent', 'deposit', 'manual', 'opening', 'credit_note')),
                ADD CONSTRAINT invoices_status_chk CHECK (status IN ('draft', 'pending_approval', 'scheduled', 'issued', 'cancelled')),
                ADD CONSTRAINT invoices_number_chk CHECK ((status = 'issued') = (number IS NOT NULL)),
                ADD CONSTRAINT invoices_issued_chk CHECK (status <> 'issued' OR (issued_at IS NOT NULL AND grace_until IS NOT NULL)),
                ADD CONSTRAINT invoices_period_chk CHECK (period_end IS NULL OR period_end >= period_start),
                ADD CONSTRAINT invoices_money_chk CHECK (total = subtotal + tax_total AND allocated >= 0 AND credited >= 0
                    AND (type = 'credit_note' OR allocated + credited <= total))
            SQL);

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('charge_type', 20);
            $table->string('description', 255);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('net', 12, 3);
            $table->string('tax_category', 20);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('total', 12, 3);
            $table->foreignId('owner_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('credited_line_id')->nullable()->constrained('invoice_lines')->restrictOnDelete();
            $table->decimal('allocated', 12, 3)->default(0);
            $table->decimal('credited', 12, 3)->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoice_lines
                ADD CONSTRAINT invoice_lines_type_chk CHECK (charge_type IN ('rent', 'service_charge', 'parking', 'other', 'deposit', 'damage', 'cleaning', 'utilities', 'opening_balance')),
                ADD CONSTRAINT invoice_lines_tax_chk CHECK (tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT invoice_lines_total_chk CHECK (total = net + tax_amount),
                ADD CONSTRAINT invoice_lines_alloc_chk CHECK (allocated >= 0 AND allocated + credited <= total)
            SQL);

        // Spec §8.5. One statement per unprepared() call.
        DB::unprepared("CREATE TRIGGER invoices_no_delete BEFORE DELETE ON invoices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices cannot be deleted'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoices_guard BEFORE UPDATE ON invoices FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status IN ('pending_approval', 'scheduled', 'issued', 'cancelled'))
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'issued', 'cancelled'))
                    OR (OLD.status = 'scheduled' AND NEW.status IN ('issued', 'cancelled'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices: status change not allowed';
                END IF;

                IF OLD.status = 'issued' AND NOT (NEW.number <=> OLD.number AND NEW.type <=> OLD.type AND NEW.customer_id <=> OLD.customer_id
                    AND NEW.agreement_id <=> OLD.agreement_id AND NEW.related_invoice_id <=> OLD.related_invoice_id
                    AND NEW.period_start <=> OLD.period_start AND NEW.period_end <=> OLD.period_end
                    AND NEW.issue_date <=> OLD.issue_date AND NEW.due_date <=> OLD.due_date AND NEW.grace_until <=> OLD.grace_until
                    AND NEW.subtotal <=> OLD.subtotal AND NEW.tax_total <=> OLD.tax_total AND NEW.total <=> OLD.total
                    AND NEW.issued_by <=> OLD.issued_by AND NEW.issued_at <=> OLD.issued_at
                    AND NEW.replaced_by_invoice_id <=> OLD.replaced_by_invoice_id AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices: an issued invoice is immutable except allocated and credited';
                END IF;

                IF OLD.status = 'cancelled' AND (OLD.replaced_by_invoice_id IS NOT NULL
                    OR NOT (NEW.total <=> OLD.total AND NEW.allocated <=> OLD.allocated AND NEW.credited <=> OLD.credited
                        AND NEW.issue_date <=> OLD.issue_date AND NEW.due_date <=> OLD.due_date)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices: a cancelled invoice only records its replacement, once';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoice_lines_insert BEFORE INSERT ON invoice_lines FOR EACH ROW
            BEGIN
                IF (SELECT status FROM invoices WHERE id = NEW.invoice_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: lines can only be added to a draft invoice';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoice_lines_guard BEFORE UPDATE ON invoice_lines FOR EACH ROW
            BEGIN
                DECLARE s VARCHAR(20);
                SELECT status INTO s FROM invoices WHERE id = OLD.invoice_id;

                IF s <> 'draft' AND NOT (NEW.invoice_id <=> OLD.invoice_id AND NEW.agreement_unit_id <=> OLD.agreement_unit_id
                    AND NEW.unit_id <=> OLD.unit_id AND NEW.charge_type <=> OLD.charge_type AND NEW.description <=> OLD.description
                    AND NEW.period_start <=> OLD.period_start AND NEW.period_end <=> OLD.period_end AND NEW.net <=> OLD.net
                    AND NEW.tax_category <=> OLD.tax_category AND NEW.credited_line_id <=> OLD.credited_line_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: amounts and descriptions are frozen once the invoice is not draft';
                END IF;

                -- The issue Action writes tax and the owner stamp while scheduled or pending, before it flips the status (spec §6.3).
                IF s IN ('scheduled', 'pending_approval') AND NOT (NEW.allocated <=> OLD.allocated AND NEW.credited <=> OLD.credited) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: nothing is allocated before issue';
                END IF;

                IF s IN ('issued', 'cancelled') AND NOT (NEW.tax_rate <=> OLD.tax_rate AND NEW.tax_amount <=> OLD.tax_amount
                    AND NEW.total <=> OLD.total AND NEW.owner_contract_id <=> OLD.owner_contract_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: issued lines change only allocated and credited';
                END IF;

                IF s = 'cancelled' AND NOT (NEW.allocated <=> OLD.allocated AND NEW.credited <=> OLD.credited) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: a cancelled invoice is final';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoice_lines_delete BEFORE DELETE ON invoice_lines FOR EACH ROW
            BEGIN
                IF (SELECT status FROM invoices WHERE id = OLD.invoice_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: lines can only be removed from a draft invoice';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        foreach (['invoice_lines_delete', 'invoice_lines_guard', 'invoice_lines_insert', 'invoices_guard', 'invoices_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
```

- [ ] **Step 5: Models**

Create `app/Models/Invoice.php`:
```php
<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Written only by the billing Actions (amounts and status via forceFill).
 *
 * @property int $id
 * @property string|null $number
 * @property InvoiceType $type
 * @property int $customer_id
 * @property int|null $agreement_id
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $grace_until
 * @property InvoiceStatus $status
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property string $allocated
 * @property string $credited
 * @property string $balance
 * @property int|null $issued_by
 */
class Invoice extends Model
{
    use LogsActivity;

    protected $guarded = ['id', 'balance'];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'grace_until' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'subtotal' => 'decimal:3',
            'tax_total' => 'decimal:3',
            'total' => 'decimal:3',
            'allocated' => 'decimal:3',
            'credited' => 'decimal:3',
            'balance' => 'decimal:3',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'number', 'issue_date', 'due_date', 'total', 'tax_total', 'issued_at', 'replaced_by_invoice_id'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @param  Builder<Invoice>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return;
        }

        $query->whereHas('agreement', fn (Builder $a) => $a->visibleTo($user));
    }

    public function label(): string
    {
        return $this->number ?? __(':status #:id', ['status' => $this->status->label(), 'id' => $this->id]);
    }
}
```
Create `app/Models/InvoiceLine.php`:
```php
<?php

namespace App\Models;

use App\Enums\InvoiceChargeType;
use App\Enums\TaxCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $invoice_id
 * @property int|null $agreement_unit_id
 * @property int|null $unit_id
 * @property InvoiceChargeType $charge_type
 * @property string $description
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property string $net
 * @property TaxCategory $tax_category
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $total
 * @property int|null $owner_contract_id
 */
class InvoiceLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'charge_type' => InvoiceChargeType::class,
            'tax_category' => TaxCategory::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'net' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:3',
            'total' => 'decimal:3',
            'allocated' => 'decimal:3',
            'credited' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function ownerContract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class);
    }
}
```
In `app/Models/Agreement.php` add:
```php
    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
```

- [ ] **Step 6: The Actions**

Create `app/Actions/Billing/GenerateRentSchedule.php`:
```php
<?php

namespace App\Actions\Billing;

use App\Billing\BillingPeriods;
use App\Billing\Proration;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §6.2: one scheduled rent invoice per billing period for the whole term. Runs inside the activation transaction. */
final class GenerateRentSchedule
{
    /** @return Collection<int, Invoice> */
    public function handle(Agreement $agreement, User $actor): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('GenerateRentSchedule must run inside the caller\'s transaction.');
        }

        $agreement->load('agreementUnits.charges', 'agreementUnits.unit.building');
        $settings = CompanySetting::current();
        $today = CarbonImmutable::now('Asia/Bahrain')->startOfDay();
        $months = $agreement->frequency->months();
        $invoices = collect();

        foreach (BillingPeriods::for($agreement->start_date, $agreement->end_date, $agreement->frequency, $agreement->billing_day) as $period) {
            $lines = [];

            foreach ($agreement->agreementUnits as $au) {
                $from = $au->start_date->max($period->start);
                $to = $au->end_date->min($period->end);

                foreach ($au->charges as $charge) {
                    $net = Proration::line($period, $au->start_date, $au->end_date, Fils::fromDecimal($charge->monthly_amount), $months, $settings->proration_basis);
                    if ($net === 0) {
                        continue;
                    }

                    $lines[] = [
                        'agreement_unit_id' => $au->id,
                        'unit_id' => $au->unit_id,
                        'charge_type' => $charge->type->value,
                        'description' => sprintf('%s — %s / %s, %s–%s', $charge->description ?: $charge->type->label(),
                            $au->unit->building->code, $au->unit->code, $from->format('d/m/Y'), $to->format('d/m/Y')),
                        'period_start' => $from->toDateString(),
                        'period_end' => $to->toDateString(),
                        'net' => Fils::toDecimal($net),
                        'tax_category' => $charge->tax_category->value,
                        'tax_rate' => '0.00',
                        'tax_amount' => '0.000',
                        'total' => Fils::toDecimal($net), // tax is written at issue (spec §6.3)
                    ];
                }
            }

            if ($lines === []) {
                continue;
            }

            $sum = array_sum(array_map(fn (array $l) => Fils::fromDecimal($l['net']), $lines));
            $due = $period->start; // rent is billed in advance
            $issue = $due->subDays($settings->invoice_lead_days)->max($today);

            // Created as draft, lines inserted, then scheduled: lines may only be inserted into a draft (spec §8.5).
            $invoice = (new Invoice)->forceFill([
                'type' => InvoiceType::Rent,
                'customer_id' => $agreement->customer_id,
                'agreement_id' => $agreement->id,
                'period_start' => $period->start->toDateString(),
                'period_end' => $period->end->toDateString(),
                'issue_date' => $issue->toDateString(),
                'due_date' => $due->toDateString(),
                'status' => InvoiceStatus::Draft,
                'subtotal' => Fils::toDecimal($sum),
                'tax_total' => '0.000',
                'total' => Fils::toDecimal($sum),
                'created_by' => $actor->id,
            ]);
            $invoice->save();
            $invoice->lines()->createMany($lines);
            $invoice->forceFill(['status' => InvoiceStatus::Scheduled])->save();

            $invoices->push($invoice);
        }

        return $invoices;
    }
}
```
Create `app/Actions/Billing/CreateDepositInvoice.php`:
```php
<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceChargeType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TaxCategory;
use App\Models\Agreement;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §6.2: one line per agreement unit with a deposit, out of scope, due on the start date, issued at activation.
 * ponytail: M3 renewals bill only new deposit − transferred-in; imported agreements (M5) skip this.
 */
final class CreateDepositInvoice
{
    public function __construct(private IssueInvoice $issue) {}

    public function handle(Agreement $agreement, User $actor): ?Invoice
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CreateDepositInvoice must run inside the caller\'s transaction.');
        }

        $agreement->load('agreementUnits.unit.building');
        $lines = $agreement->agreementUnits
            ->filter(fn ($au) => Fils::fromDecimal($au->deposit_amount) > 0)
            ->map(fn ($au) => [
                'agreement_unit_id' => $au->id,
                'unit_id' => $au->unit_id,
                'charge_type' => InvoiceChargeType::Deposit->value,
                'description' => __('Security deposit — :b / :u', ['b' => $au->unit->building->code, 'u' => $au->unit->code]),
                'net' => $au->deposit_amount,
                'tax_category' => TaxCategory::OutOfScope->value,
                'tax_rate' => '0.00',
                'tax_amount' => '0.000',
                'total' => $au->deposit_amount,
            ])->values()->all();

        if ($lines === []) {
            return null;
        }

        $sum = Fils::toDecimal(array_sum(array_map(fn (array $l) => Fils::fromDecimal($l['net']), $lines)));

        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Deposit,
            'customer_id' => $agreement->customer_id,
            'agreement_id' => $agreement->id,
            'issue_date' => now('Asia/Bahrain')->toDateString(),
            'due_date' => $agreement->start_date->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => $sum,
            'tax_total' => '0.000',
            'total' => $sum,
            'created_by' => $actor->id,
        ]);
        $invoice->save();
        $invoice->lines()->createMany($lines);

        $this->issue->handle($invoice, $actor);

        return $invoice->refresh();
    }
}
```
Create `app/Actions/Billing/IssueInvoice.php`:
```php
<?php

namespace App\Actions\Billing;

use App\Actions\NextDocumentNumber;
use App\Billing\Tax;
use App\Enums\InvoiceChargeType;
use App\Enums\InvoiceStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\OwnerContractStatus;
use App\Enums\TaxCategory;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\OwnerContract;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §6.3 — the only place tax, owner attribution, numbers and grace dates are written. */
final class IssueInvoice
{
    public function __construct(private NextDocumentNumber $next) {}

    /** @return bool false when held back by a pending owner contract (spec §4.6) */
    public function handle(Invoice $invoice, ?User $issuer = null): bool
    {
        return DB::transaction(function () use ($invoice, $issuer) {
            $invoice = Invoice::query()->lockForUpdate()->with(['lines.agreementUnit', 'agreement'])->findOrFail($invoice->id);

            if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Scheduled], true)) {
                throw ValidationException::withMessages(['invoice' => __('Only a draft or scheduled invoice can be issued.')]);
            }

            foreach ($invoice->lines as $line) {
                if ($line->unit_id !== null && self::coveredByPendingContract($line->unit_id, self::attributionDate($line, $invoice))) {
                    return false;
                }
            }

            $settings = CompanySetting::current();
            $subtotal = 0;
            $taxTotal = 0;

            foreach ($invoice->lines as $line) {
                $net = Fils::fromDecimal($line->net);
                $rate = $settings->vat_registered && $line->tax_category === TaxCategory::Standard ? (string) $settings->vat_rate : '0.00';
                $tax = Tax::amount($net, $line->tax_category, (bool) $settings->vat_registered, $rate);
                $date = self::attributionDate($line, $invoice)->toDateString();

                $line->forceFill([
                    'tax_rate' => $rate,
                    'tax_amount' => Fils::toDecimal($tax),
                    'total' => Fils::toDecimal($net + $tax),
                    'owner_contract_id' => $line->unit_id === null ? null : OwnerContract::query()
                        ->effectiveOn($date)
                        ->whereHas('units', fn ($q) => $q->whereKey($line->unit_id))
                        ->value('owner_contracts.id'),
                ])->save();

                $subtotal += $net;
                $taxTotal += $tax;
            }

            $graceDays = $invoice->agreement?->grace_days ?? $settings->default_grace_days;

            // One UPDATE: the CHECKs tie the number, issued_at and grace_until to status = issued.
            $invoice->forceFill([
                'subtotal' => Fils::toDecimal($subtotal),
                'tax_total' => Fils::toDecimal($taxTotal),
                'total' => Fils::toDecimal($subtotal + $taxTotal),
                'number' => ($this->next)(NumberSequenceKey::Invoice),
                'grace_until' => $invoice->due_date->addDays($graceDays)->toDateString(),
                'issued_at' => now(),
                'issued_by' => $issuer?->id,
                'status' => InvoiceStatus::Issued,
            ])->save();

            return true;
        });
    }

    /** Spec §4.6: the line's period start; for deposits the agreement unit's start; otherwise the due date. */
    private static function attributionDate(InvoiceLine $line, Invoice $invoice): CarbonImmutable
    {
        return $line->period_start
            ?? ($line->charge_type === InvoiceChargeType::Deposit ? $line->agreementUnit?->start_date : null)
            ?? $invoice->due_date;
    }

    private static function coveredByPendingContract(int $unitId, CarbonImmutable $date): bool
    {
        return OwnerContract::query()
            ->where('status', OwnerContractStatus::PendingApproval)
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->whereHas('units', fn ($q) => $q->whereKey($unitId))
            ->exists();
    }
}
```
Create `app/Actions/Billing/IssueDueInvoices.php`:
```php
<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Agreement;
use App\Models\Invoice;

/** The 01:00 job and activation (spec §6.3, §12). Idempotent; each invoice is its own transaction. Held-back invoices wait. */
final class IssueDueInvoices
{
    public function __construct(private IssueInvoice $issue) {}

    public function __invoke(?Agreement $only = null): int
    {
        $issued = 0;

        Invoice::query()
            ->where('status', InvoiceStatus::Scheduled)
            ->where('issue_date', '<=', now('Asia/Bahrain')->toDateString())
            ->when($only, fn ($q) => $q->where('agreement_id', $only->id))
            ->orderBy('issue_date')->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$issued) {
                if ($this->issue->handle(Invoice::findOrFail($id))) {
                    $issued++;
                }
            });

        // ponytail: M3 auto-allocates customer credit here after issuing (spec §12, 01:00).
        return $issued;
    }
}
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Billing tests/Unit
"$PHP" artisan test
```
Expected: every test in `tests/Feature/Billing` passes; full suite passes. If `CompanySetting::factory()->create([...])` rejects `vat_registered`/`vat_rate`/`proration_basis` keys, check the column names in `database/migrations/2026_09_28_000400_create_company_settings_table.php` and use those.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add invoices, the rent schedule, deposit invoices and issuing

Activation will create one scheduled rent invoice per period, prorated
per unit, and issue the deposit invoice. Issuing writes tax, stamps
each line's owner contract, numbers the invoice and sets its grace date,
and waits while an owner contract covering a line is pending. MySQL
keeps issued invoices immutable apart from allocations and credits.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 7: Submit and approve an agreement

**Spec:** §5.4 (draft → pending_approval → active; reject → draft; locked while pending), §5.5 (overlap rule with `effective_end`, blocked units rejected, unit rows locked in ascending id order as the transaction's first statement, overlap query as a locking read; checked on submit and again on approval; drafts don't hold units), §5.6 (clauses rendered into `agreement_clauses` on submit; merge values; Arabic name fallback; Arabic `{frequency}`), §8.3 item 1 (Management approves; not the requester or the creator), §6.2/§6.3 (activation creates the schedule, issues the deposit invoice and issues what is already due), §6.8 (AGR number on approval), §9.4 (32-character `verify_token` stored at approval), §14 flows 1, 2 and 13

**Files:**
- Create: `app/Agreements/ContractMerge.php`, `app/Actions/Agreements/{EnsureNoAgreementOverlap,SubmitAgreement,ActivateAgreement}.php`, `app/Approvals/AgreementActivation.php`, `tests/Feature/Agreements/AgreementApprovalTest.php`, `tests/Feature/Agreements/AgreementOverlapTest.php`, `tests/Concurrency/AgreementOverlapLockTest.php`
- Modify: `app/Enums/ApprovalAction.php`, `app/Livewire/Agreements/Show.php`, `resources/views/livewire/agreements/show.blade.php`

**Interfaces:**
- Consumes: `RequestApproval`, `DecideApproval`, `ApprovalHandler`, `AgreementUnit::effectiveEndSql()`, `ContractTemplate` (+ `defaultTemplate()`), `GenerateRentSchedule`, `CreateDepositInvoice`, `IssueDueInvoices`, `NextDocumentNumber` + `NumberSequenceKey::Agreement`, `Customer::displayName()`, `PaymentFrequency::english()/arabic()`
- Produces:
  - `ContractMerge::clauses(Agreement $agreement, ContractTemplate $template): list<array{position: int, heading_en: string, heading_ar: string, body_en: string, body_ar: string}>` — leaves `{agreement_number}` and `{units_table}` in place
  - `EnsureNoAgreementOverlap::handle(Agreement $agreement): void` (inside the caller's transaction; throws `ValidationException` keyed `units`)
  - `SubmitAgreement::handle(User $actor, Agreement $agreement): Approval`
  - `ActivateAgreement::handle(Agreement $agreement, User $approver): Agreement` (no authorisation; for the handler and the M5 importer)
  - `ApprovalAction::AgreementActivation` (`'agreement.activate'`); `App\Approvals\AgreementActivation` — Task 8 adds the contract PDF job to its `approve()`
  - `Agreements\Show::submit()`; the view variable `$approvals`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Agreements/AgreementApprovalTest.php`:
```php
<?php

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\EnsureNumberSequences;
use App\Enums\AgreementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\RoleName;
use App\Livewire\Agreements\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['name_en' => 'Demo Properties', 'name_ar' => 'ديمو للعقارات', 'invoice_lead_days' => 7]);
    app(EnsureDefaultContractTemplate::class)();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);

    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $building = Building::factory()->create();
    $this->leasing->buildings()->attach($building->id);
    $this->unit = Unit::factory()->for($building)->create(['list_rent' => '500.000']);
    $this->customer = Customer::factory()->create(['name_en' => 'Hassan Qasim', 'name_ar' => null, 'id_number' => '850505555']);

    $this->draft = app(SaveAgreement::class)->handle($this->leasing, null, [
        'customer_id' => $this->customer->id, 'start_date' => '2026-10-10', 'end_date' => '2027-10-09', 'frequency' => 'monthly',
        'units' => [['unit_id' => $this->unit->id, 'deposit_amount' => '450', 'charges' => [['type' => 'rent', 'monthly_amount' => '450', 'tax_category' => 'exempt']]]],
    ]);
});

test('submitting freezes the merged clauses, locks the draft and emails Management', function () {
    Notification::fake();

    app(SubmitAgreement::class)->handle($this->leasing, $this->draft);

    $agreement = $this->draft->fresh()->load('clauses');
    expect($agreement->status)->toBe(AgreementStatus::PendingApproval)
        ->and($agreement->clauses)->toHaveCount(9);

    $parties = $agreement->clauses[0];
    expect($parties->body_en)->toContain('Demo Properties')->toContain('Hassan Qasim')->toContain('850505555')
        ->toContain('{agreement_number}')                                  // numbered only on approval
        ->and($parties->body_ar)->toContain('ديمو للعقارات')->toContain('Hassan Qasim') // no Arabic name → English
        ->and($agreement->clauses[1]->body_en)->toBe('{units_table}')
        ->and($agreement->clauses[3]->body_en)->toContain('450.000')->toContain('monthly')
        ->and($agreement->clauses[3]->body_ar)->toContain('شهرياً');

    Notification::assertSentTo($this->management, ApprovalRequested::class);
    expect(fn () => app(SaveAgreement::class)->handle($this->leasing, $agreement, []))->toThrow(ValidationException::class, 'Only draft agreements can be edited.');
});

test('approval activates: AGR number, verify token, schedule, deposit invoice, and what is already due', function () {
    $approval = app(SubmitAgreement::class)->handle($this->leasing, $this->draft);

    app(DecideApproval::class)->handle($this->management, $approval, true);

    $agreement = $this->draft->fresh();
    expect($agreement->status)->toBe(AgreementStatus::Active)
        ->and($agreement->number)->toBe('AGR-2026-000001')
        ->and(strlen((string) $agreement->verify_token))->toBe(32);

    $deposit = $agreement->invoices()->where('type', InvoiceType::Deposit)->sole();
    expect($deposit->status)->toBe(InvoiceStatus::Issued)->and($deposit->number)->toBe('INV-2026-000001');

    $rent = $agreement->invoices()->where('type', InvoiceType::Rent)->orderBy('period_start')->get();
    expect($rent)->toHaveCount(12)
        ->and($rent[0]->status)->toBe(InvoiceStatus::Issued)            // due 10 Oct, issue date 5 Oct (today) → issued at activation
        ->and($rent[0]->number)->toBe('INV-2026-000002')
        ->and($rent[1]->status)->toBe(InvoiceStatus::Scheduled);
});

test('rejection returns the draft, clears the frozen clauses, and the requester cannot approve', function () {
    $approval = app(SubmitAgreement::class)->handle($this->leasing, $this->draft);

    $dual = User::factory()->withTwoFactor()->create()->assignRole([RoleName::Management, RoleName::Leasing]);
    $dual->buildings()->attach($this->unit->building_id);
    $own = app(SaveAgreement::class)->handle($dual, null, [
        'customer_id' => $this->customer->id, 'start_date' => '2028-01-01', 'end_date' => '2028-12-31', 'frequency' => 'monthly',
        'units' => [['unit_id' => $this->unit->id, 'charges' => [['type' => 'rent', 'monthly_amount' => '1', 'tax_category' => 'exempt']]]],
    ]);
    $ownApproval = app(SubmitAgreement::class)->handle($dual, $own);
    expect(fn () => app(DecideApproval::class)->handle($dual, $ownApproval, true))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, false, 'Rent too low');

    $agreement = $this->draft->fresh();
    expect($agreement->status)->toBe(AgreementStatus::Draft)
        ->and($agreement->clauses()->count())->toBe(0)
        ->and($agreement->invoices()->count())->toBe(0);
});

test('the approval summary shows the discount against list rent', function () {
    $approval = app(SubmitAgreement::class)->handle($this->leasing, $this->draft);

    expect($approval->handler()->summary($approval))->toContain('Hassan Qasim')->toContain('450.000')->toContain('50.000')->toContain('10.0%');
});

test('Leasing submits from the agreement page and sees the approval history', function () {
    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $this->draft])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('Agreement activation');

    expect($this->draft->fresh()->status)->toBe(AgreementStatus::PendingApproval);
});
```
Create `tests/Feature/Agreements/AgreementOverlapTest.php`:
```php
<?php

use App\Actions\Agreements\SubmitAgreement;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\CompanySetting;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureDefaultContractTemplate::class)();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->unit = Unit::factory()->create(['code' => 'U-7']);
});

function draftFor(Unit $unit, string $from, string $to, User $creator): Agreement
{
    $agreement = Agreement::factory()->create(['start_date' => $from, 'end_date' => $to, 'created_by' => $creator->id]);
    $au = $agreement->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => '400.000', 'deposit_amount' => 0, 'start_date' => $from, 'end_date' => $to]);
    $au->charges()->create(['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'exempt']);

    return $agreement;
}

test('an occupied unit cannot be let for overlapping dates, but can be pre-let from the day after', function () {
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-10-31'], [$this->unit]);

    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2026-10-31', '2027-10-30', $this->pm)))
        ->toThrow(ValidationException::class, 'U-7');

    app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2026-11-01', '2027-10-31', $this->pm));
    expect(Agreement::where('status', 'pending_approval')->count())->toBe(1);
});

test('a blocked unit is rejected', function () {
    $this->unit->update(['blocked' => true, 'blocked_reason' => 'Renovation']);

    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2026-11-01', '2027-10-31', $this->pm)))
        ->toThrow(ValidationException::class, 'blocked');
});

test('drafts do not hold units: the second to submit is rejected', function () {
    $first = draftFor($this->unit, '2026-11-01', '2027-10-31', $this->pm);
    $second = draftFor($this->unit, '2027-01-01', '2027-12-31', $this->pm);

    app(SubmitAgreement::class)->handle($this->pm, $first);
    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, $second))->toThrow(ValidationException::class);
});

test('an expired agreement without a move-out is an open-ended overstay', function () {
    $old = activeAgreement(['start_date' => '2025-01-01', 'end_date' => '2025-12-31'], [$this->unit]);
    $old->forceFill(['status' => 'expired'])->save();

    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2027-06-01', '2028-05-31', $this->pm)))
        ->toThrow(ValidationException::class);
});

test('a move-out after the end date extends occupancy to the move-out', function () {
    $current = activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    DB::table('agreement_units')->where('agreement_id', $current->id)->update(['move_out_date' => '2027-01-15']);

    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2027-01-10', '2027-12-31', $this->pm)))
        ->toThrow(ValidationException::class);
    app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2027-01-16', '2027-12-31', $this->pm));
});
```
Create `tests/Concurrency/AgreementOverlapLockTest.php`:
```php
<?php

use App\Actions\Agreements\EnsureNoAgreementOverlap;
use App\Models\Agreement;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => config(['database.connections.mysql_b' => config('database.connections.mysql')]));

afterEach(fn () => DB::purge('mysql_b'));

// Spec §14 flow 2: concurrent submissions for the same unit queue on the unit row lock.
it('makes a second connection wait on the unit lock until the first commits', function () {
    $unit = Unit::factory()->create();
    $agreement = Agreement::factory()->create();
    $agreement->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => 1, 'deposit_amount' => 0, 'start_date' => $agreement->start_date, 'end_date' => $agreement->end_date]);

    $b = DB::connection('mysql_b');
    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();
    app(EnsureNoAgreementOverlap::class)->handle($agreement);

    expect(fn () => $b->transaction(fn () => $b->table('units')->where('id', $unit->id)->lockForUpdate()->first()))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1205));

    DB::commit();

    expect($b->transaction(fn () => $b->table('units')->where('id', $unit->id)->lockForUpdate()->value('id')))->toBe($unit->id);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Agreements tests/Concurrency`
Expected: FAIL — `Class "App\Actions\Agreements\SubmitAgreement" not found`.

- [ ] **Step 3: The clause merge**

Create `app/Agreements/ContractMerge.php`:
```php
<?php

namespace App\Agreements;

use App\Models\Agreement;
use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Models\ContractTemplateClause;
use App\Support\Fils;

/**
 * Spec §5.6: renders a template's clauses for one agreement. {agreement_number} and {units_table} are kept
 * for the PDF renderer (the number is only assigned on approval; the units table is not text).
 */
final class ContractMerge
{
    /** @return list<array{position: int, heading_en: string, heading_ar: string, body_en: string, body_ar: string}> */
    public function clauses(Agreement $agreement, ContractTemplate $template): array
    {
        $agreement->loadMissing('customer', 'agreementUnits.charges');
        $settings = CompanySetting::current();
        $customer = $agreement->customer;

        $common = [
            'customer_id_number' => $customer->id_number,
            'start_date' => $agreement->start_date->format('d/m/Y'),
            'end_date' => $agreement->end_date->format('d/m/Y'),
            'total_monthly_rent' => Fils::toDecimal($agreement->monthlyRentFils()),
            'total_deposit' => Fils::toDecimal($agreement->depositFils()),
            'notice_period_days' => (string) $agreement->notice_period_days,
            'grace_days' => (string) $agreement->grace_days,
        ];
        $en = self::tokens([...$common,
            'company_name' => $settings->name_en,
            'customer_name' => $customer->displayName('en'),
            'frequency' => $agreement->frequency->english(),
        ]);
        $ar = self::tokens([...$common,
            'company_name' => filled($settings->name_ar) ? (string) $settings->name_ar : $settings->name_en,
            'customer_name' => $customer->displayName('ar'),
            'frequency' => $agreement->frequency->arabic(),
        ]);

        return $template->clauses->map(fn (ContractTemplateClause $c) => [
            'position' => $c->position,
            'heading_en' => $c->heading_en,
            'heading_ar' => $c->heading_ar,
            'body_en' => strtr($c->body_en, $en),
            'body_ar' => strtr($c->body_ar, $ar),
        ])->values()->all();
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private static function tokens(array $values): array
    {
        $tokens = [];
        foreach ($values as $field => $value) {
            // Braces in data (a customer called "{units_table}") must never become merge fields.
            $tokens['{'.$field.'}'] = str_replace(['{', '}'], ['(', ')'], (string) $value);
        }

        return $tokens;
    }
}
```

- [ ] **Step 4: Overlap, submit, activate, handler**

Create `app/Actions/Agreements/EnsureNoAgreementOverlap.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Models\Agreement;
use App\Models\AgreementUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Spec §5.5, on submit and again on approval. Run inside the caller's transaction, before anything else. */
final class EnsureNoAgreementOverlap
{
    public function handle(Agreement $agreement): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EnsureNoAgreementOverlap must run inside the caller\'s transaction.');
        }

        $lines = DB::table('agreement_units')->where('agreement_id', $agreement->id)->orderBy('unit_id')
            ->get(['unit_id', 'start_date', 'end_date']);

        // Ascending id order, so concurrent submissions for the same units queue instead of deadlocking.
        $units = DB::table('units')->whereIn('id', $lines->pluck('unit_id'))->orderBy('id')->lockForUpdate()->get(['id', 'code', 'blocked']);

        $blocked = $units->filter(fn ($u) => (bool) $u->blocked)->pluck('code')->all();
        if ($blocked !== []) {
            throw ValidationException::withMessages(['units' => __('These units are blocked: :list.', ['list' => implode(', ', $blocked)])]);
        }

        $today = now('Asia/Bahrain')->toDateString();
        $clashes = [];

        foreach ($lines as $line) {
            $clash = DB::table('agreement_units as au')
                ->join('agreements as a', 'a.id', '=', 'au.agreement_id')
                ->join('units as u', 'u.id', '=', 'au.unit_id')
                ->where('au.unit_id', $line->unit_id)
                ->where('a.id', '<>', $agreement->id)
                ->where('a.status', '<>', 'draft')          // drafts never hold units
                ->whereNull('a.deleted_at')
                ->where('au.start_date', '<=', $line->end_date)
                ->whereRaw('('.AgreementUnit::effectiveEndSql('au').') >= ?', [$today, $line->start_date])
                ->lockForUpdate()
                ->first(['u.code', 'a.id', 'a.number']);

            if ($clash) {
                $clashes[] = $clash->code.' ('.($clash->number ?? __('pending #:id', ['id' => $clash->id])).')';
            }
        }

        if ($clashes !== []) {
            throw ValidationException::withMessages(['units' => __('Already let on these dates: :list.', ['list' => implode(', ', $clashes)])]);
        }
    }
}
```
Create `app/Actions/Agreements/SubmitAgreement.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Actions\Approvals\RequestApproval;
use App\Agreements\ContractMerge;
use App\Enums\AgreementStatus;
use App\Enums\ApprovalAction;
use App\Enums\ChargeType;
use App\Models\Agreement;
use App\Models\Approval;
use App\Models\ContractTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitAgreement
{
    public function __construct(
        private EnsureNoAgreementOverlap $overlap,
        private ContractMerge $merge,
        private RequestApproval $request,
    ) {}

    public function handle(User $actor, Agreement $agreement): Approval
    {
        if (! $actor->can('update', $agreement)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $agreement) {
            $this->overlap->handle($agreement); // the unit locks come first (spec §5.5)

            $agreement = Agreement::query()->lockForUpdate()->with(['agreementUnits.charges', 'customer', 'contractTemplate.clauses'])->findOrFail($agreement->id);

            if ($agreement->status !== AgreementStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('Only a draft can be submitted.')]);
            }
            if ($agreement->agreementUnits->isEmpty()) {
                throw ValidationException::withMessages(['units' => __('Add at least one unit.')]);
            }
            if ($agreement->agreementUnits->contains(fn ($au) => $au->charges->where('type', ChargeType::Rent)->count() !== 1)) {
                throw ValidationException::withMessages(['units' => __('Each unit needs exactly one rent charge.')]);
            }

            $template = $agreement->contractTemplate?->active ? $agreement->contractTemplate : ContractTemplate::defaultTemplate()?->load('clauses');
            if (! $template) {
                throw ValidationException::withMessages(['contract_template_id' => __('No active contract template. Ask Admin to set one up.')]);
            }

            // Clauses first: agreement_clauses accept writes only while the agreement is draft (spec §8.5).
            $agreement->forceFill(['contract_template_id' => $template->id])->save();
            $agreement->clauses()->delete();
            foreach ($this->merge->clauses($agreement, $template) as $clause) {
                $agreement->clauses()->create($clause);
            }

            $agreement->forceFill(['status' => AgreementStatus::PendingApproval])->save();

            return $this->request->handle($actor, $agreement, ApprovalAction::AgreementActivation);
        });
    }
}
```
Create `app/Actions/Agreements/ActivateAgreement.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Actions\Billing\CreateDepositInvoice;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Billing\IssueDueInvoices;
use App\Actions\NextDocumentNumber;
use App\Enums\AgreementStatus;
use App\Enums\NumberSequenceKey;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Pending → active (spec §5.4, §6.2, §6.3, §9.4). No authorisation here: DecideApproval (and the M5 importer) authorise. */
final class ActivateAgreement
{
    public function __construct(
        private EnsureNoAgreementOverlap $overlap,
        private NextDocumentNumber $next,
        private GenerateRentSchedule $schedule,
        private CreateDepositInvoice $deposit,
        private IssueDueInvoices $issueDue,
    ) {}

    public function handle(Agreement $agreement, User $approver): Agreement
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ActivateAgreement must run inside the caller\'s transaction.');
        }

        $this->overlap->handle($agreement); // checked again on approval

        $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
        if ($agreement->status !== AgreementStatus::PendingApproval) {
            throw new LogicException("Agreement {$agreement->id} is not pending approval.");
        }

        $agreement->forceFill([
            'status' => AgreementStatus::Active,
            'number' => ($this->next)(NumberSequenceKey::Agreement),
            'verify_token' => Str::random(32), // random, not derived from APP_KEY, so it survives a key rotation
        ])->save();

        $this->schedule->handle($agreement, $approver);
        $this->deposit->handle($agreement, $approver);
        ($this->issueDue)($agreement); // activation itself issues what is already due (spec §6.3)

        return $agreement;
    }
}
```
Create `app/Approvals/AgreementActivation.php`:
```php
<?php

namespace App\Approvals;

use App\Actions\Agreements\ActivateAgreement;
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\Approval;
use App\Models\User;
use App\Support\Fils;

/** Spec §8.3 item 1. */
final class AgreementActivation implements ApprovalHandler
{
    public function __construct(private ActivateAgreement $activate) {}

    private function agreement(Approval $approval): Agreement
    {
        return Agreement::query()->findOrFail($approval->approvable_id);
    }

    public function creatorId(Approval $approval): int
    {
        return $this->agreement($approval)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->activate->handle($this->agreement($approval), $approver);
    }

    /** Back to draft, then the frozen clauses go (they accept writes only while draft); the comment stays on the approval. */
    public function reject(Approval $approval, User $approver): void
    {
        $agreement = Agreement::query()->lockForUpdate()->findOrFail($approval->approvable_id);
        $agreement->forceFill(['status' => AgreementStatus::Draft])->save();
        $agreement->clauses()->delete();
    }

    public function summary(Approval $approval): string
    {
        $agreement = Agreement::with(['customer', 'agreementUnits.charges'])->findOrFail($approval->approvable_id);
        $list = $agreement->listRentFils();
        $discount = $agreement->discountFils();

        return __('Agreement :label: :customer, :units unit(s), :start to :end. Rent :rent BHD/month (list :list; discount :disc BHD, :pct%).', [
            'label' => $agreement->label(),
            'customer' => $agreement->customer->name_en,
            'units' => $agreement->agreementUnits->count(),
            'start' => $agreement->start_date->format('d/m/Y'),
            'end' => $agreement->end_date->format('d/m/Y'),
            'rent' => Fils::toDecimal($agreement->monthlyRentFils()),
            'list' => Fils::toDecimal($list),
            'disc' => Fils::toDecimal($discount),
            'pct' => $list > 0 ? number_format($discount * 100 / $list, 1) : '0.0',
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('agreements.show', $approval->approvable_id);
    }
}
```
In `app/Enums/ApprovalAction.php` add the import `use App\Approvals\AgreementActivation;`, the case `case AgreementActivation = 'agreement.activate';`, and the match arms `self::AgreementActivation => __('Agreement activation'),` in `label()` and `self::AgreementActivation => AgreementActivation::class,` in `handler()`.

- [ ] **Step 5: Submit on the agreement page**

In `app/Livewire/Agreements/Show.php` add the imports `App\Actions\Agreements\SubmitAgreement` and `Flux\Flux`, the method:
```php
    public function submit(SubmitAgreement $submit): void
    {
        try {
            $submit->handle($this->actor(), $this->agreement());
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: __('Submitted for approval.'));
    }
```
and add to the view data in `render()`:
```php
            'approvals' => $agreement->approvals()->with(['requester:id,name', 'decider:id,name'])->latest('id')->get(),
```
In `resources/views/livewire/agreements/show.blade.php`, replace `{{-- Task 7: submit + approvals. Task 8: contract PDF. Task 9: notice. Task 10: invoices. --}}` with:
```blade
            @if ($canManage && $agreement->status === AgreementStatus::Draft)
                <flux:button variant="primary" wire:click="submit" wire:confirm="{{ __('Submit this agreement for Management approval?') }}">{{ __('Submit for approval') }}</flux:button>
            @endif
            {{-- Task 8: contract PDF. Task 9: notice. Task 10: invoices. --}}
```
and insert after the header block (before the `<dl>`):
```blade
    <flux:error name="units" />
    <flux:error name="status" />
    <flux:error name="approval" />
    <flux:error name="contract_template_id" />

    @php($lastRejection = $agreement->status === AgreementStatus::Draft ? $approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected) : null)
    @if ($lastRejection)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Rejected by :name', ['name' => $lastRejection->decider?->name])" :text="$lastRejection->comment" />
    @endif
```
and before the documents panel:
```blade
    @if ($approvals->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Approval history') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($approvals as $approval)
                    <li class="py-2 text-sm">
                        {{ $approval->action->label() }} · {{ str($approval->status->value)->headline() }} · {{ __('requested by :name', ['name' => $approval->requester->name]) }}
                        @if ($approval->decider) · {{ __('decided by :name', ['name' => $approval->decider->name]) }} @endif
                        @if ($approval->comment) — {{ $approval->comment }} @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Agreements tests/Feature/Approvals tests/Concurrency
"$PHP" artisan test
```
Expected: every test passes, including the M1 approvals tests (the engine is unchanged).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add agreement submission and activation

Submitting checks each unit for overlapping lets (with overstays and
late move-outs counted), freezes the merged bilingual clauses and asks
Management to approve. Approval numbers the agreement, creates its
prorated rent schedule, issues the deposit invoice and whatever is
already due. Rejection returns the draft.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 8: The contract PDF, its QR code and the verification page

**Spec:** §5.6 (approved PDF generated from `agreement_clauses`, stored once as a document, never regenerated; draft PDFs any time with a DRAFT watermark and no QR), §9.2 (mPDF, IBM Plex Sans Arabic, one row per clause paragraph, escaped values, local images only; generated in a queued job and stored privately), §9.3, §9.4 (QR encodes `APP_URL/v/{verify_token}`; public page shows only number, status, start and end dates; unknown tokens 404; rate-limited; `noindex`), §8.4 (document downloads audited)

**Files:**
- Create: `app/Pdf/ContractPdf.php`, `resources/views/pdf/contract.blade.php`, `app/Jobs/StoreApprovedContract.php`, `app/Http/Controllers/AgreementPdfController.php`, `app/Http/Controllers/VerifyAgreementController.php`, `resources/views/verify.blade.php`, `tests/Feature/Agreements/ContractPdfTest.php`, `tests/Feature/Agreements/VerifyPageTest.php`
- Modify: `app/Approvals/AgreementActivation.php`, `routes/web.php`, `routes/property.php`, `resources/views/livewire/agreements/show.blade.php`, `tests/Feature/Agreements/AgreementApprovalTest.php`

**Interfaces:**
- Consumes: `PdfRenderer`, `ContractMerge`, `ContractTemplate::paragraphs()`, `Agreement` (+ `clauses`, `agreementUnits`), `Document`, `DocumentCategory::GeneratedPdf`, `Audit`
- Produces: `ContractPdf::build(Agreement $agreement): array{view: string, data: array<string, mixed>, chrome: array{footer: string, watermark: ?string}}` and `ContractPdf::render(Agreement $agreement): string`; job `StoreApprovedContract(int $agreementId, int $approverId)`; routes `agreements.pdf` (auth) and `agreements.verify` (`GET /v/{token}`, public)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Agreements/ContractPdfTest.php`:
```php
<?php

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\EnsureNumberSequences;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Jobs\StoreApprovedContract;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Unit;
use App\Models\User;
use App\Pdf\ContractPdf;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureDefaultContractTemplate::class)();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    config(['app.url' => 'https://rms.example.test']);

    $this->pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->unit = Unit::factory()->for(Building::factory()->create(['name' => 'Marina Tower']))->create(['code' => '1204']);
    $this->draft = app(SaveAgreement::class)->handle($this->pm, null, [
        'customer_id' => Customer::factory()->create(['name_en' => 'Layla Karim', 'name_ar' => 'ليلى كريم'])->id,
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'frequency' => 'monthly',
        'units' => [['unit_id' => $this->unit->id, 'deposit_amount' => '600', 'charges' => [['type' => 'rent', 'monthly_amount' => '600', 'tax_category' => 'exempt']]]],
    ]);
});

test('a draft contract renders live from the template with a DRAFT watermark and no QR', function () {
    $built = app(ContractPdf::class)->build($this->draft);

    expect($built['view'])->toBe('pdf.contract')
        ->and($built['chrome']['watermark'])->toBe('DRAFT')
        ->and($built['data']['verifyUrl'])->toBeNull()
        ->and($built['data']['number'])->toBe('DRAFT')
        ->and($built['data']['clauses'][0]['paragraphs'][0][0])->toContain('No. DRAFT')->toContain('Layla Karim')
        ->and($built['data']['clauses'][0]['paragraphs'][0][1])->toContain('ليلى كريم')
        ->and($built['data']['clauses'][1]['units'])->toBeTrue()
        ->and($built['data']['units'][0]['unit'])->toBe('1204');
});

test('approval stores the frozen contract once, with the number and the QR link', function () {
    $approval = app(SubmitAgreement::class)->handle($this->pm, $this->draft);
    app(DecideApproval::class)->handle($this->management, $approval, true); // sync queue in tests: the job runs after commit

    $agreement = $this->draft->fresh();
    $built = app(ContractPdf::class)->build($agreement);
    expect($built['chrome']['watermark'])->toBeNull()
        ->and($built['data']['verifyUrl'])->toBe('https://rms.example.test/v/'.$agreement->verify_token)
        ->and($built['data']['clauses'][0]['paragraphs'][0][0])->toContain('No. AGR-2026-000001');

    $document = Document::query()->where('documentable_id', $agreement->id)->where('category', DocumentCategory::GeneratedPdf)->sole();
    expect(Storage::disk('local')->get($document->path))->toStartWith('%PDF')
        ->and($document->uploaded_by)->toBe($this->management->id);

    (new StoreApprovedContract($agreement->id, $this->management->id))->handle(app(ContractPdf::class));
    expect(Document::query()->where('documentable_id', $agreement->id)->where('category', DocumentCategory::GeneratedPdf)->count())->toBe(1);
});

test('the PDF route streams a watermarked draft and serves the stored copy once approved', function () {
    $this->actingAs($this->pm)->get(route('agreements.pdf', $this->draft))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(Activity::query()->where('event', 'agreement.draft_pdf_viewed')->exists())->toBeTrue();

    $approval = app(SubmitAgreement::class)->handle($this->pm, $this->draft);
    app(DecideApproval::class)->handle($this->management, $approval, true);

    $document = Document::query()->where('category', DocumentCategory::GeneratedPdf)->sole();
    $this->actingAs($this->pm)->get(route('agreements.pdf', $this->draft))->assertRedirect(route('documents.download', $document));

    $outsider = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($outsider)->get(route('agreements.pdf', $this->draft))->assertForbidden();
});
```
Create `tests/Feature/Agreements/VerifyPageTest.php`:
```php
<?php

use App\Models\Customer;
use App\Models\Unit;

beforeEach(function () {
    $this->agreement = activeAgreement([
        'customer_id' => Customer::factory()->create(['name_en' => 'Private Person'])->id,
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
    ], [Unit::factory()->create()]);
});

test('the public page shows only number, status and dates, and is not indexed', function () {
    $this->get('/v/'.$this->agreement->verify_token)
        ->assertOk()
        ->assertSee($this->agreement->number)
        ->assertSee('Active')
        ->assertSee('01/11/2026')
        ->assertSee('31/10/2027')
        ->assertDontSee('Private Person')
        ->assertSee('noindex', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('unknown tokens are 404 and the page is rate-limited', function () {
    $this->get('/v/'.str_repeat('z', 32))->assertNotFound();

    foreach (range(1, 30) as $i) {
        $this->get('/v/'.str_repeat('q', 32));
    }
    $this->get('/v/'.$this->agreement->verify_token)->assertStatus(429);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Agreements/ContractPdfTest.php tests/Feature/Agreements/VerifyPageTest.php`
Expected: FAIL — `Class "App\Pdf\ContractPdf" not found`.

- [ ] **Step 3: The PDF builder and view**

Create `app/Pdf/ContractPdf.php`:
```php
<?php

namespace App\Pdf;

use App\Agreements\ContractMerge;
use App\Enums\AgreementStatus;
use App\Enums\ChargeType;
use App\Models\Agreement;
use App\Models\AgreementClause;
use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Support\Fils;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Spec §5.6, §9.2–§9.4. */
final class ContractPdf
{
    public function __construct(private PdfRenderer $renderer, private ContractMerge $merge) {}

    /** @return array{view: string, data: array<string, mixed>, chrome: array{footer: string, watermark: ?string}} */
    public function build(Agreement $agreement): array
    {
        $agreement->loadMissing(['customer', 'clauses', 'contractTemplate.clauses', 'agreementUnits.unit.building', 'agreementUnits.charges']);
        $approved = ! in_array($agreement->status, [AgreementStatus::Draft, AgreementStatus::PendingApproval], true);
        $number = $agreement->number ?? 'DRAFT';

        // Drafts render live from the template; from submit on, only the frozen clauses are used.
        $clauses = $agreement->status === AgreementStatus::Draft
            ? $this->merge->clauses($agreement, $agreement->contractTemplate ?? ContractTemplate::defaultTemplate()?->load('clauses') ?? throw new RuntimeException('No contract template.'))
            : $agreement->clauses->map(fn (AgreementClause $c) => $c->only(['position', 'heading_en', 'heading_ar', 'body_en', 'body_ar']))->all();

        $rows = array_map(function (array $c) use ($number) {
            if (trim($c['body_en']) === '{units_table}') {
                return [...self::headings($c), 'units' => true, 'paragraphs' => []];
            }

            $en = ContractTemplate::paragraphs(str_replace('{agreement_number}', $number, $c['body_en']));
            $ar = ContractTemplate::paragraphs(str_replace('{agreement_number}', $number, $c['body_ar']));
            $pairs = [];
            foreach (array_keys($en + $ar) as $i) {
                $pairs[] = [$en[$i] ?? '', $ar[$i] ?? ''];
            }

            return [...self::headings($c), 'units' => false, 'paragraphs' => $pairs];
        }, $clauses);

        $settings = CompanySetting::current();
        $logo = $settings->logo_path && Storage::disk('local')->exists($settings->logo_path) ? Storage::disk('local')->path($settings->logo_path) : null;

        return [
            'view' => 'pdf.contract',
            'data' => [
                'number' => $number,
                'companyEn' => $settings->name_en,
                'companyAr' => filled($settings->name_ar) ? $settings->name_ar : $settings->name_en,
                'logo' => $logo,
                'clauses' => array_values($rows),
                'units' => $agreement->agreementUnits->map(fn ($au) => [
                    'building' => $au->unit->building->name,
                    'unit' => $au->unit->code,
                    'from' => $au->start_date->format('d/m/Y'),
                    'to' => $au->end_date->format('d/m/Y'),
                    'rent' => Fils::toDecimal($au->charges->where('type', ChargeType::Rent)->sum(fn ($c) => Fils::fromDecimal($c->monthly_amount))),
                    'service' => Fils::toDecimal($au->charges->where('type', '!=', ChargeType::Rent)->sum(fn ($c) => Fils::fromDecimal($c->monthly_amount))),
                    'deposit' => $au->deposit_amount,
                ])->values()->all(),
                'verifyUrl' => $approved ? rtrim((string) config('app.url'), '/').'/v/'.$agreement->verify_token : null,
            ],
            'chrome' => [
                'footer' => '<div style="text-align:center; font-size:8pt; color:#555">'.e($number).' &nbsp;·&nbsp; {PAGENO} / {nbpg}</div>',
                'watermark' => $approved ? null : 'DRAFT',
            ],
        ];
    }

    public function render(Agreement $agreement): string
    {
        $built = $this->build($agreement);

        return $this->renderer->render($built['view'], $built['data'], $built['chrome']);
    }

    /**
     * @param  array<string, mixed>  $clause
     * @return array{position: int, heading_en: string, heading_ar: string}
     */
    private static function headings(array $clause): array
    {
        return ['position' => (int) $clause['position'], 'heading_en' => (string) $clause['heading_en'], 'heading_ar' => (string) $clause['heading_ar']];
    }
}
```
Create `resources/views/pdf/contract.blade.php`:
```blade
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9.5pt; }
    .ar { font-family: arabic; font-size: 11pt; direction: rtl; text-align: justify; }
    h1 { font-size: 13pt; text-align: center; margin: 0 0 3mm; }
    table.clauses { width: 100%; border-collapse: collapse; }
    table.clauses td { width: 50%; vertical-align: top; padding: 1.2mm 2mm; }
    tr.heading td { font-weight: bold; padding-top: 3mm; border-bottom: 0.2mm solid #999; }
    table.units { width: 100%; border-collapse: collapse; margin: 1mm 0; }
    table.units th, table.units td { border: 0.2mm solid #666; padding: 1mm 1.5mm; font-size: 8.5pt; }
    table.units th .ar { font-size: 9pt; }
    .num { text-align: right; }
</style>
</head>
<body>
<table style="width:100%; margin-bottom:3mm">
    <tr>
        <td style="width:40%">{{ $companyEn }}</td>
        <td style="width:20%; text-align:center">@if ($logo)<img src="{{ $logo }}" style="height:14mm">@endif</td>
        <td style="width:40%; text-align:right" class="ar" dir="rtl">{{ $companyAr }}</td>
    </tr>
</table>

<h1>Lease Agreement {{ $number }} &nbsp;|&nbsp; <span class="ar">عقد إيجار رقم {{ $number }}</span></h1>

{{-- One row per paragraph (spec §9.2): mPDF never splits a row, and a tall row shrinks the whole table. --}}
<table class="clauses">
    @foreach ($clauses as $clause)
        <tr class="heading">
            <td>{{ $clause['position'] }}. {{ $clause['heading_en'] }}</td>
            <td class="ar" dir="rtl" lang="ar">{{ $clause['position'] }}. {{ $clause['heading_ar'] }}</td>
        </tr>
        @if ($clause['units'])
            <tr>
                <td colspan="2">
                    <table class="units">
                        <tr>
                            <th>Building <span class="ar">المبنى</span></th><th>Unit <span class="ar">الوحدة</span></th>
                            <th>From <span class="ar">من</span></th><th>To <span class="ar">إلى</span></th>
                            <th>Rent/month <span class="ar">الإيجار الشهري</span></th><th>Other/month <span class="ar">رسوم أخرى</span></th>
                            <th>Deposit <span class="ar">التأمين</span></th>
                        </tr>
                        @foreach ($units as $u)
                            <tr>
                                <td>{{ $u['building'] }}</td><td>{{ $u['unit'] }}</td><td>{{ $u['from'] }}</td><td>{{ $u['to'] }}</td>
                                <td class="num">{{ $u['rent'] }}</td><td class="num">{{ $u['service'] }}</td><td class="num">{{ $u['deposit'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        @else
            @foreach ($clause['paragraphs'] as [$en, $ar])
                <tr>
                    <td>{{ $en }}</td>
                    <td class="ar" dir="rtl" lang="ar">{{ $ar }}</td>
                </tr>
            @endforeach
        @endif
    @endforeach
</table>

<table style="width:100%; margin-top:10mm">
    <tr>
        <td style="width:50%">Landlord / <span class="ar">المؤجر</span><br><br>______________________</td>
        <td style="width:50%">Tenant / <span class="ar">المستأجر</span><br><br>______________________</td>
    </tr>
</table>

@if ($verifyUrl)
    <table style="width:100%; margin-top:6mm">
        <tr>
            <td style="width:70%; vertical-align:bottom; font-size:8pt">Scan to verify this agreement:<br>{{ $verifyUrl }}</td>
            <td style="width:30%; text-align:right">
                {{-- Vector QR drawn by mpdf/qrcode: no image fetch. --}}
                <barcode code="{{ $verifyUrl }}" type="QR" size="0.9" error="M" disableborder="1" />
            </td>
        </tr>
    </table>
@endif
</body>
</html>
```

- [ ] **Step 4: The job, the routes and the controllers**

Create `app/Jobs/StoreApprovedContract.php`:
```php
<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Enums\DocumentCategory;
use App\Models\Agreement;
use App\Models\Document;
use App\Pdf\ContractPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Spec §5.6: the approved contract is generated once, stored privately, and never regenerated. */
class StoreApprovedContract implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $agreementId, public int $approverId) {}

    public function handle(ContractPdf $pdf): void
    {
        $agreement = Agreement::query()->findOrFail($this->agreementId);

        if (self::stored($agreement)) {
            return;
        }

        $bytes = $pdf->render($agreement);
        $path = sprintf('documents/%s/%s.pdf', now()->format('Y/m'), Str::uuid()); // random name (spec §13.3)
        Storage::disk('local')->put($path, $bytes);

        DB::transaction(function () use ($agreement, $path, $bytes) {
            $document = new Document([
                'category' => DocumentCategory::GeneratedPdf,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $agreement->number.'.pdf',
                'mime' => 'application/pdf',
                'size' => strlen($bytes),
                'uploaded_by' => $this->approverId,
            ]);
            $document->documentable()->associate($agreement);
            $document->save();

            Audit::log('document.generated', $document, properties: ['agreement' => $agreement->number]);
        });
    }

    public static function stored(Agreement $agreement): ?Document
    {
        return Document::query()
            ->where('documentable_type', $agreement->getMorphClass())
            ->where('documentable_id', $agreement->id)
            ->where('category', DocumentCategory::GeneratedPdf)
            ->first();
    }
}
```
(check `app/Models/Document.php`: if a field above is not fillable, set it with `forceFill` as `StoreDocument` does.)

In `app/Approvals/AgreementActivation.php` add `use App\Jobs\StoreApprovedContract;` and `use Illuminate\Support\Facades\DB;`, and change `approve()` to:
```php
    public function approve(Approval $approval, User $approver): void
    {
        $agreement = $this->activate->handle($this->agreement($approval), $approver);

        // After commit: a rolled-back approval must not leave a contract behind.
        DB::afterCommit(fn () => StoreApprovedContract::dispatch($agreement->id, $approver->id));
    }
```
Create `app/Http/Controllers/AgreementPdfController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Enums\AgreementStatus;
use App\Jobs\StoreApprovedContract;
use App\Models\Agreement;
use App\Pdf\ContractPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AgreementPdfController
{
    public function __invoke(Request $request, Agreement $agreement, ContractPdf $pdf): Response|RedirectResponse
    {
        abort_unless($request->user()?->can('view', $agreement), 403);

        if (! in_array($agreement->status, [AgreementStatus::Draft, AgreementStatus::PendingApproval], true)) {
            // The frozen copy, through the audited download route. Built now if the queued job hasn't run yet.
            if (! StoreApprovedContract::stored($agreement)) {
                StoreApprovedContract::dispatchSync($agreement->id, (int) $request->user()->getKey());
            }

            return redirect()->route('documents.download', StoreApprovedContract::stored($agreement));
        }

        Audit::log('agreement.draft_pdf_viewed', $agreement, causer: $request->user());

        return response($pdf->render($agreement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="agreement-'.$agreement->id.'-draft.pdf"',
        ]);
    }
}
```
Create `app/Http/Controllers/VerifyAgreementController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use Illuminate\Http\Response;

/** Spec §9.4: public, rate-limited, noindex; shows only number, status and dates. */
final class VerifyAgreementController
{
    public function __invoke(string $token): Response
    {
        $agreement = Agreement::query()->where('verify_token', $token)->firstOrFail();

        return response()
            ->view('verify', [
                'number' => $agreement->number,
                'status' => $agreement->status->label(),
                'start' => $agreement->start_date->format('d/m/Y'),
                'end' => $agreement->end_date->format('d/m/Y'),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
```
Create `resources/views/verify.blade.php`:
```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Agreement verification') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px 16px; background: #fafafa; color: #18181b; }
        main { max-width: 420px; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 24px; }
        dt { color: #71717a; font-size: 14px; margin-top: 12px; }
        dd { margin: 2px 0 0; font-size: 18px; }
    </style>
</head>
<body>
    <main>
        <h1 style="font-size:20px; margin:0 0 8px">{{ __('Agreement verification') }}</h1>
        <p style="margin:0; color:#52525b">{{ __('This lease agreement is on record.') }}</p>
        <dl>
            <dt>{{ __('Agreement number') }}</dt><dd>{{ $number }}</dd>
            <dt>{{ __('Status') }}</dt><dd>{{ $status }}</dd>
            <dt>{{ __('Start date') }}</dt><dd>{{ $start }}</dd>
            <dt>{{ __('End date') }}</dt><dd>{{ $end }}</dd>
        </dl>
    </main>
</body>
</html>
```
In `routes/web.php` add `use App\Http\Controllers\VerifyAgreementController;` and, outside the `auth` group:
```php
Route::get('v/{token}', VerifyAgreementController::class)->middleware('throttle:30,1')->name('agreements.verify');
```
In `routes/property.php` add `use App\Http\Controllers\AgreementPdfController;` and inside the group:
```php
    Route::get('agreements/{agreement}/contract.pdf', AgreementPdfController::class)->middleware('can:agreements.view')->name('agreements.pdf');
```
In `resources/views/livewire/agreements/show.blade.php` replace `{{-- Task 8: contract PDF. Task 9: notice. Task 10: invoices. --}}` with:
```blade
            <flux:button :href="route('agreements.pdf', $agreement)" target="_blank" icon="document-arrow-down">
                {{ in_array($agreement->status, [AgreementStatus::Draft, AgreementStatus::PendingApproval], true) ? __('Draft contract PDF') : __('Contract PDF') }}
            </flux:button>
            {{-- Task 9: notice. Task 10: invoices. --}}
```

In `tests/Feature/Agreements/AgreementApprovalTest.php` add `use Illuminate\Support\Facades\Storage;` and make `Storage::fake('local');` the first line of its `beforeEach`: approval now renders and stores the contract (the test queue is sync), and tests must not write into `storage/app/private`. **Every later test that approves an agreement fakes the local disk the same way.**

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Agreements tests/Feature/Pdf
"$PHP" artisan test
```
Expected: every test passes. Then open a draft's contract PDF in the preview and check: Arabic letters join, numbers and dates inside Arabic text read correctly, the units table isn't shrunk, and an approved contract shows the QR code.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the bilingual contract PDF and its verification page

Drafts render live from the template with a DRAFT watermark; approval
stores the frozen contract once, with a QR code to a public, rate-limited,
noindex page that shows only the number, status and dates.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 9: Unit statuses from agreements, and recording notice

**Spec:** §4.3 (Occupied, Notice given, Reserved, Blocked, Available — computed for today from agreement units using `effective_end`; "Occupied · next tenant from {date}"), §5.4 (Record notice: `agreements.manage`, no approval, sets `notice_date` and `planned_exit_date` on an active or expired agreement or one of its units; changes neither `end_date` nor billing), §8.2 (unit-targeted writes need the unit in scope)

**Files:**
- Create: `app/Actions/Agreements/RecordNotice.php`, `tests/Feature/Units/UnitStatusTest.php`, `tests/Feature/Agreements/NoticeTest.php`
- Modify: `app/Models/Unit.php`, `app/Livewire/Units/Index.php`, `resources/views/livewire/units/index.blade.php`, `resources/views/livewire/units/form.blade.php`, `app/Livewire/Agreements/Show.php`, `resources/views/livewire/agreements/show.blade.php`

**Interfaces:**
- Consumes: `AgreementUnit::effectiveEndSql()`, `AgreementStatus`, `UnitStatus`, `AgreementPolicy::allUnitsInScope()`, `Audit`
- Produces: `Unit::status(?CarbonInterface $today = null): UnitStatus` (replaces the M1 version), `Unit::nextTenantFrom(?CarbonInterface $today = null): ?CarbonImmutable`, relation `Unit::occupancy()` and scope `Unit::withOccupancy(?CarbonInterface $today = null)` for lists; `RecordNotice::handle(User $actor, Agreement $agreement, ?AgreementUnit $unit, string $noticeDate, string $plannedExitDate): void`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Units/UnitStatusTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Enums\UnitStatus;
use App\Livewire\Units\Index;
use App\Models\Agreement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->unit = Unit::factory()->create(['code' => 'A-1']);
});

function pendingFor(Unit $unit, string $from, string $to): Agreement
{
    $agreement = Agreement::factory()->create(['start_date' => $from, 'end_date' => $to]);
    $au = $agreement->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => 1, 'deposit_amount' => 0, 'start_date' => $from, 'end_date' => $to]);
    $au->charges()->create(['type' => 'rent', 'monthly_amount' => 1, 'tax_category' => 'exempt']);
    $agreement->forceFill(['status' => 'pending_approval'])->save();

    return $agreement;
}

test('with no agreements a unit is available, or blocked', function () {
    expect($this->unit->status())->toBe(UnitStatus::Available);
    $this->unit->update(['blocked' => true, 'blocked_reason' => 'Repairs']);
    expect($this->unit->fresh()->status())->toBe(UnitStatus::Blocked);
});

test('drafts do not count; a pending or future agreement reserves the unit', function () {
    Agreement::factory()->create()->agreementUnits()->create(['unit_id' => $this->unit->id, 'list_rent' => 1, 'deposit_amount' => 0, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30']);
    expect($this->unit->status())->toBe(UnitStatus::Available);

    pendingFor($this->unit, '2026-10-01', '2027-09-30');
    expect($this->unit->status())->toBe(UnitStatus::Reserved);
});

test('an active agreement starting later reserves; one covering today occupies', function () {
    $future = activeAgreement(['start_date' => '2026-11-01', 'end_date' => '2027-10-31'], [$this->unit]);
    expect($this->unit->status())->toBe(UnitStatus::Reserved);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Bahrain'));
    expect($this->unit->status())->toBe(UnitStatus::Occupied);
});

test('notice on the agreement or on the unit shows Notice given, and a next tenant is shown', function () {
    $current = activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $current->forceFill(['notice_date' => '2026-10-01', 'planned_exit_date' => '2026-12-31'])->save();
    expect($this->unit->status())->toBe(UnitStatus::NoticeGiven);

    $current->forceFill(['notice_date' => null, 'planned_exit_date' => null])->save();
    DB::table('agreement_units')->where('agreement_id', $current->id)->update(['planned_exit_date' => '2026-12-31']);
    expect($this->unit->fresh()->status())->toBe(UnitStatus::NoticeGiven);

    activeAgreement(['start_date' => '2027-01-01', 'end_date' => '2027-12-31'], [$this->unit]);
    expect($this->unit->fresh()->nextTenantFrom()?->toDateString())->toBe('2027-01-01');
});

test('an expired agreement without a move-out is an overstay; with one it frees the unit', function () {
    $old = activeAgreement(['start_date' => '2025-10-01', 'end_date' => '2026-09-30'], [$this->unit]);
    $old->forceFill(['status' => 'expired'])->save();
    expect($this->unit->status())->toBe(UnitStatus::Occupied);

    DB::table('agreement_units')->where('agreement_id', $old->id)->update(['move_out_date' => '2026-09-30']);
    expect($this->unit->fresh()->status())->toBe(UnitStatus::Available);
});

test('the units list shows the computed status', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    activeAgreement(['start_date' => '2027-01-01', 'end_date' => '2027-12-31'], [$this->unit]);
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    Livewire::actingAs($pm)->test(Index::class)
        ->assertSee('Occupied')
        ->assertSee('next tenant from 01/01/2027');
});
```
Create `tests/Feature/Agreements/NoticeTest.php`:
```php
<?php

use App\Actions\Agreements\RecordNotice;
use App\Enums\RoleName;
use App\Livewire\Agreements\Show;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->mine = Building::factory()->create();
    $this->theirs = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($this->mine->id);
    $this->ownUnit = Unit::factory()->for($this->mine)->create();
    $this->otherUnit = Unit::factory()->for($this->theirs)->create();
    $this->agreement = activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->ownUnit, $this->otherUnit]);
});

test('notice on the whole agreement sets its dates and leaves the end date alone', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    app(RecordNotice::class)->handle($pm, $this->agreement, null, '2026-10-01', '2026-11-30');

    $fresh = $this->agreement->fresh();
    expect($fresh->notice_date->toDateString())->toBe('2026-10-01')
        ->and($fresh->planned_exit_date->toDateString())->toBe('2026-11-30')
        ->and($fresh->end_date->toDateString())->toBe('2026-12-31');
});

test('a scoped user can give notice for a unit in their building, not for the whole spanning agreement', function () {
    $own = $this->agreement->agreementUnits()->where('unit_id', $this->ownUnit->id)->sole();
    $other = $this->agreement->agreementUnits()->where('unit_id', $this->otherUnit->id)->sole();

    app(RecordNotice::class)->handle($this->leasing, $this->agreement, $own, '2026-10-02', '2026-10-31');
    expect($own->fresh()->planned_exit_date->toDateString())->toBe('2026-10-31');

    expect(fn () => app(RecordNotice::class)->handle($this->leasing, $this->agreement, $other, '2026-10-02', '2026-10-31'))->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordNotice::class)->handle($this->leasing, $this->agreement, null, '2026-10-02', '2026-10-31'))->toThrow(AuthorizationException::class);
});

test('notice needs an active or expired agreement and sensible dates', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    expect(fn () => app(RecordNotice::class)->handle($pm, $this->agreement, null, '2026-10-10', '2026-10-01'))->toThrow(ValidationException::class);
    expect(fn () => app(RecordNotice::class)->handle($pm, $this->agreement, null, '2026-11-01', '2026-11-30'))->toThrow(ValidationException::class); // notice in the future

    $draft = Agreement::factory()->create(['created_by' => $pm->id]);
    expect(fn () => app(RecordNotice::class)->handle($pm, $draft, null, '2026-10-01', '2026-11-30'))->toThrow(ValidationException::class);
});

test('notice is recorded from the agreement page', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    Livewire::actingAs($pm)->test(Show::class, ['agreement' => $this->agreement])
        ->set('noticeDate', '2026-10-05')
        ->set('plannedExit', '2026-12-15')
        ->call('recordNotice')
        ->assertHasNoErrors();

    expect($this->agreement->fresh()->planned_exit_date->toDateString())->toBe('2026-12-15');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Units/UnitStatusTest.php tests/Feature/Agreements/NoticeTest.php`
Expected: FAIL — statuses stay Available and `RecordNotice` is missing.

- [ ] **Step 3: Unit status from agreements**

In `app/Models/Unit.php` add the imports `App\Enums\AgreementStatus`, `Carbon\CarbonImmutable`, `Carbon\CarbonInterface`, `Illuminate\Database\Eloquent\Relations\HasMany`, `Illuminate\Support\Collection`, then replace the M1 `status()` with:
```php
    /** @return HasMany<AgreementUnit, $this> the rows of agreementUnits(), eager-loaded with effective_end by withOccupancy() */
    public function occupancy(): HasMany
    {
        return $this->hasMany(AgreementUnit::class);
    }

    /** @param  Builder<Unit>  $query */
    #[Scope]
    protected function withOccupancy(Builder $query, ?CarbonInterface $today = null): void
    {
        $day = ($today ?? now('Asia/Bahrain'))->toDateString();
        $query->with(['occupancy' => fn (HasMany $q) => self::constrainOccupancy($q, $day)]);
    }

    /**
     * Spec §4.3, for today (Asia/Bahrain).
     */
    public function status(?CarbonInterface $today = null): UnitStatus
    {
        $day = ($today ?? now('Asia/Bahrain'))->toDateString();
        $lines = $this->occupancyLines($day);

        $current = self::currentLine($lines, $day);
        if ($current) {
            return $current->planned_exit_date !== null || $current->agreement->planned_exit_date !== null
                ? UnitStatus::NoticeGiven
                : UnitStatus::Occupied;
        }

        if ($lines->contains(fn (AgreementUnit $au) => $au->agreement->status === AgreementStatus::PendingApproval
            || ($au->agreement->status === AgreementStatus::Active && $au->start_date->toDateString() > $day))) {
            return UnitStatus::Reserved;
        }

        return $this->blocked ? UnitStatus::Blocked : UnitStatus::Available;
    }

    /** "Occupied · next tenant from {date}" (spec §4.3). */
    public function nextTenantFrom(?CarbonInterface $today = null): ?CarbonImmutable
    {
        $day = ($today ?? now('Asia/Bahrain'))->toDateString();
        $lines = $this->occupancyLines($day);

        if (! self::currentLine($lines, $day)) {
            return null;
        }

        return $lines
            ->filter(fn (AgreementUnit $au) => in_array($au->agreement->status, [AgreementStatus::Active, AgreementStatus::PendingApproval], true)
                && $au->start_date->toDateString() > $day)
            ->sortBy(fn (AgreementUnit $au) => $au->start_date->toDateString())
            ->first()?->start_date;
    }

    /** @param  Collection<int, AgreementUnit>  $lines */
    private static function currentLine(Collection $lines, string $day): ?AgreementUnit
    {
        return $lines->first(fn (AgreementUnit $au) => $au->agreement->status !== AgreementStatus::PendingApproval
            && $au->start_date->toDateString() <= $day
            && (string) $au->getAttribute('effective_end') >= $day);
    }

    /** @return Collection<int, AgreementUnit> */
    private function occupancyLines(string $day): Collection
    {
        if ($this->relationLoaded('occupancy')) {
            return $this->getRelation('occupancy');
        }

        return self::constrainOccupancy($this->occupancy(), $day)->get();
    }

    /**
     * Non-draft agreement units with effective_end computed by the same SQL the overlap check uses.
     *
     * @template TQuery of HasMany<AgreementUnit, Unit>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private static function constrainOccupancy(HasMany $query, string $day): HasMany
    {
        return $query->select('agreement_units.*')
            ->selectRaw('('.AgreementUnit::effectiveEndSql().') as effective_end', [$day])
            ->whereHas('agreement', fn (Builder $a) => $a->where('status', '<>', AgreementStatus::Draft->value))
            ->with('agreement:id,status,planned_exit_date');
    }
```
In `app/Livewire/Units/Index.php` add `->withOccupancy()` to the units query (after `->visibleTo($this->actor())`).
In `resources/views/livewire/units/index.blade.php` replace the status cell with:
```blade
                        <flux:table.cell>
                            <flux:badge size="sm">{{ $unit->status()->label() }}</flux:badge>
                            @if ($next = $unit->nextTenantFrom())
                                <span class="text-xs text-zinc-500">{{ __('next tenant from :date', ['date' => $next->format('d/m/Y')]) }}</span>
                            @endif
                        </flux:table.cell>
```
In `resources/views/livewire/units/form.blade.php` next to the existing status badge add the same `nextTenantFrom()` line.

- [ ] **Step 4: Record notice**

Create `app/Actions/Agreements/RecordNotice.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Audit\Audit;
use App\Enums\AgreementStatus;
use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\Unit;
use App\Models\User;
use App\Policies\AgreementPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §5.4 "Record notice": no approval; changes neither end_date nor billing. */
final class RecordNotice
{
    public function handle(User $actor, Agreement $agreement, ?AgreementUnit $unit, string $noticeDate, string $plannedExitDate): void
    {
        $inScope = $unit
            ? $unit->agreement_id === $agreement->id && Unit::query()->visibleTo($actor)->whereKey($unit->unit_id)->exists()
            : AgreementPolicy::allUnitsInScope($actor, $agreement);

        if (! $actor->can(PermissionName::AgreementsManage) || ! $inScope) {
            throw new AuthorizationException;
        }

        $dates = Validator::make(['notice_date' => $noticeDate, 'planned_exit_date' => $plannedExitDate], [
            'notice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'planned_exit_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:notice_date'],
        ])->validate();

        DB::transaction(function () use ($actor, $agreement, $unit, $dates) {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true)) {
                throw ValidationException::withMessages(['notice_date' => __('Notice can be recorded only on an active or expired agreement.')]);
            }

            if ($unit) {
                $unit->forceFill(['planned_exit_date' => $dates['planned_exit_date']])->save();
            } else {
                $agreement->forceFill($dates)->save();
            }

            Audit::log('agreement.notice_recorded', $agreement, properties: [...$dates, 'agreement_unit_id' => $unit?->id], causer: $actor);
        });
    }
}
```
In `app/Livewire/Agreements/Show.php` add the imports `App\Actions\Agreements\RecordNotice`, `App\Models\AgreementUnit` and `Illuminate\Validation\ValidationException`, the properties:
```php
    public string $noticeTarget = '';

    public string $noticeDate = '';

    public string $plannedExit = '';
```
and the method:
```php
    public function recordNotice(RecordNotice $notice): void
    {
        $agreement = $this->agreement();
        $unit = $this->noticeTarget !== '' ? AgreementUnit::query()->where('agreement_id', $agreement->id)->findOrFail((int) $this->noticeTarget) : null;

        try {
            $notice->handle($this->actor(), $agreement, $unit, $this->noticeDate, $this->plannedExit);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(array_filter([
                'noticeDate' => $e->errors()['notice_date'] ?? [],
                'plannedExit' => $e->errors()['planned_exit_date'] ?? [],
            ]));
        }

        $this->reset('noticeTarget', 'noticeDate', 'plannedExit');
        Flux::toast(variant: 'success', text: __('Notice recorded.'));
    }
```
In `resources/views/livewire/agreements/show.blade.php` replace `{{-- Task 9: notice. Task 10: invoices. --}}` with `{{-- Task 10: invoices. --}}` and insert before the approval history block:
```blade
    @if (auth()->user()->can('agreements.manage') && in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true))
        <flux:fieldset>
            <flux:legend>{{ __('Record notice') }}</flux:legend>
            <form wire:submit="recordNotice" class="mt-2 grid gap-3 sm:grid-cols-3 sm:items-end">
                <flux:select wire:model="noticeTarget" :label="__('For')">
                    <option value="">{{ __('The whole agreement') }}</option>
                    @foreach ($agreement->agreementUnits as $au)<option value="{{ $au->id }}">{{ $au->unit->building->code }} / {{ $au->unit->code }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="noticeDate" type="date" :label="__('Notice given on')" />
                <flux:input wire:model="plannedExit" type="date" :label="__('Planned exit')" />
                <flux:button type="submit" class="sm:col-span-3 sm:justify-self-start">{{ __('Record notice') }}</flux:button>
            </form>
        </flux:fieldset>
    @endif
```

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Units tests/Feature/Agreements
"$PHP" artisan test
```
Expected: every test passes, including M1's `UnitsTest` (a unit with no agreements is still Available or Blocked).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Compute unit status from agreements and record notice

Units show Occupied, Notice given, Reserved, Blocked or Available for
today, using the same effective end date as the overlap check, plus the
next tenant's start. Notice sets planned exit dates without touching the
end date or billing.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 10: Invoice screens, "Issue now", and the 01:00 and 02:00 jobs

**Spec:** §6.1 (displayed Partially paid / Paid / Overdue labels are derived), §6.3 ("Issue now" by Finance), §6.6 (overdue = balance > 0 and today > `grace_until`), §8.1 (invoices: Admin view, Management view, Finance ✔; Property Mgr and Leasing –), §12 (01:00 issue; 02:00 agreements past `end_date` → `expired`; idempotent, `withoutOverlapping(120)`, heartbeat)

**Files:**
- Create: `app/Policies/InvoicePolicy.php`, `app/Livewire/Invoices/{Index,Show}.php`, `resources/views/livewire/invoices/{index,show}.blade.php`, `app/Actions/Agreements/ExpireAgreements.php`, `app/Console/Commands/{IssueInvoices,ExpireAgreementsCommand}.php`, `tests/Feature/Invoices/InvoiceScreensTest.php`, `tests/Feature/Agreements/ExpireAgreementsTest.php`
- Modify: `app/Models/Invoice.php`, `routes/property.php`, `routes/console.php`, `config/services.php`, `.env.example`, `resources/views/layouts/app/sidebar.blade.php`, `app/Livewire/Agreements/Show.php`, `resources/views/livewire/agreements/show.blade.php`, `tests/Feature/Backup/ScheduleTest.php`

**Interfaces:**
- Consumes: `IssueInvoice`, `IssueDueInvoices`, `Invoice::visibleTo`, `PermissionName::FinanceView`, `PermissionName::InvoicesManage`
- Produces: `InvoicePolicy::viewAny/view/issue`; `Invoice::displayLabel(): string` (Scheduled, Draft, Cancelled, or for issued: Paid / Partially paid / Overdue / Unpaid); routes `invoices.index`, `invoices.show`; `ExpireAgreements::__invoke(): int`; commands `rms:invoices:issue`, `rms:agreements:expire`; heartbeat keys `invoices_issue`, `agreements_expire`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Invoices/InvoiceScreensTest.php`:
```php
<?php

use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\EnsureNumberSequences;
use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Agreements\Show as AgreementShow;
use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\Show;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->unit = Unit::factory()->create();
    $this->agreement = activeAgreement(['start_date' => '2026-11-01', 'end_date' => '2027-10-31'], [$this->unit]);
    $this->invoices = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->agreement, $this->finance));
});

test('Finance issues a scheduled invoice now, for a customer paying ahead', function () {
    $december = $this->invoices[1];

    Livewire::actingAs($this->finance)->test(Show::class, ['invoice' => $december])
        ->assertSee('Scheduled')
        ->call('issueNow')
        ->assertHasNoErrors()
        ->assertSee('INV-2026-000001');

    expect($december->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('a held-back invoice explains why', function () {
    $pending = OwnerContract::factory()->create(['building_id' => $this->unit->building_id, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31']);
    $pending->units()->attach($this->unit->id);
    $pending->forceFill(['status' => 'pending_approval'])->save();

    Livewire::actingAs($this->finance)->test(Show::class, ['invoice' => $this->invoices[0]])
        ->call('issueNow')
        ->assertHasErrors('invoice');
});

test('issued invoices past their grace date show as overdue', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['invoice' => $this->invoices[0]])->call('issueNow');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Bahrain'));

    expect($this->invoices[0]->fresh()->displayLabel())->toBe('Overdue');
    Livewire::actingAs($this->finance)->test(Index::class)->assertSee('Overdue')->assertSee('Scheduled');
});

test('who sees invoices, and who may issue them', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    $this->actingAs($management)->get(route('invoices.index'))->assertOk();
    $this->actingAs($pm)->get(route('invoices.index'))->assertForbidden();

    Livewire::actingAs($management)->test(Show::class, ['invoice' => $this->invoices[0]])->call('issueNow')->assertForbidden();

    Livewire::actingAs($this->finance)->test(AgreementShow::class, ['agreement' => $this->agreement])->assertSee(__('Invoices'));
    Livewire::actingAs($pm)->test(AgreementShow::class, ['agreement' => $this->agreement])->assertDontSee(route('invoices.show', $this->invoices[0]));
});

test('the 01:00 job issues due invoices and is scheduled with its siblings', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 01:00', 'Asia/Bahrain'));

    $this->artisan('rms:invoices:issue')->assertSuccessful();

    expect(Invoice::where('status', 'issued')->count())->toBe(1);
});
```
Create `tests/Feature/Agreements/ExpireAgreementsTest.php`:
```php
<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Enums\AgreementStatus;
use App\Models\Unit;
use Carbon\CarbonImmutable;

test('active agreements past their end date expire at 02:00, once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 02:00', 'Asia/Bahrain'));
    $ended = activeAgreement(['start_date' => '2025-10-01', 'end_date' => '2026-10-04'], [Unit::factory()->create()]);
    $today = activeAgreement(['start_date' => '2025-10-05', 'end_date' => '2026-10-05'], [Unit::factory()->create()]);

    expect(app(ExpireAgreements::class)())->toBe(1)
        ->and(app(ExpireAgreements::class)())->toBe(0)
        ->and($ended->fresh()->status)->toBe(AgreementStatus::Expired)
        ->and($today->fresh()->status)->toBe(AgreementStatus::Active);

    $this->artisan('rms:agreements:expire')->assertSuccessful();
});
```
In `tests/Feature/Backup/ScheduleTest.php` add to the schedule dataset:
```php
    ['rms:invoices:issue', '0 1 * * *'],
    ['rms:agreements:expire', '0 2 * * *'],
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Invoices tests/Feature/Agreements/ExpireAgreementsTest.php`
Expected: FAIL — `Class "App\Livewire\Invoices\Show" not found`.

- [ ] **Step 3: Policy, label, jobs**

Create `app/Policies/InvoicePolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\PermissionName;
use App\Models\Invoice;
use App\Models\User;

/** Spec §8.1: Admin and Management view; Finance manages. */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::FinanceView) && Invoice::visibleTo($user)->whereKey($invoice->getKey())->exists();
    }

    /** "Issue now" (spec §6.3). */
    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesManage) && $invoice->status === InvoiceStatus::Scheduled && $this->view($user, $invoice);
    }
}
```
In `app/Models/Invoice.php` add (import `App\Support\Fils`):
```php
    /** Spec §6.1/§6.6: payment labels are derived from balance and grace_until, never stored. */
    public function displayLabel(): string
    {
        if ($this->status !== InvoiceStatus::Issued) {
            return $this->status->label();
        }

        $balance = Fils::fromDecimal($this->balance);
        $total = Fils::fromDecimal($this->total);

        return match (true) {
            $balance <= 0 => __('Paid'),
            $this->grace_until !== null && now('Asia/Bahrain')->toDateString() > $this->grace_until->toDateString() => __('Overdue'),
            $balance < $total => __('Partially paid'),
            default => __('Unpaid'),
        };
    }
```
Create `app/Actions/Agreements/ExpireAgreements.php`:
```php
<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use Illuminate\Support\Facades\DB;

/**
 * The 02:00 job (spec §12). M2 only has active → expired; ponytail: M3 adds renewed / closed / terminated,
 * which need renewals and move-outs, and the draft deposit settlements.
 */
final class ExpireAgreements
{
    public function __invoke(): int
    {
        $today = now('Asia/Bahrain')->toDateString();

        return DB::transaction(fn () => Agreement::query()
            ->where('status', AgreementStatus::Active)
            ->where('end_date', '<', $today)
            ->lockForUpdate()
            ->get()
            ->each(fn (Agreement $agreement) => $agreement->forceFill(['status' => AgreementStatus::Expired])->save())
            ->count());
    }
}
```
Create `app/Console/Commands/IssueInvoices.php`:
```php
<?php

namespace App\Console\Commands;

use App\Actions\Billing\IssueDueInvoices;
use Illuminate\Console\Command;

class IssueInvoices extends Command
{
    protected $signature = 'rms:invoices:issue';

    protected $description = 'Issue scheduled invoices whose issue date has come (held-back invoices wait)';

    public function handle(IssueDueInvoices $issue): int
    {
        $this->info(sprintf('%d invoice(s) issued.', $issue()));

        return self::SUCCESS;
    }
}
```
Create `app/Console/Commands/ExpireAgreementsCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Actions\Agreements\ExpireAgreements;
use Illuminate\Console\Command;

class ExpireAgreementsCommand extends Command
{
    protected $signature = 'rms:agreements:expire';

    protected $description = 'Move active agreements past their end date to expired';

    public function handle(ExpireAgreements $expire): int
    {
        $this->info(sprintf('%d agreement(s) expired.', $expire()));

        return self::SUCCESS;
    }
}
```
In `routes/console.php` add:
```php
Schedule::command('rms:invoices:issue')->dailyAt('01:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.invoices_issue')), (string) $url);

Schedule::command('rms:agreements:expire')->dailyAt('02:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.agreements_expire')), (string) $url);
```
In `config/services.php` add to `forge.heartbeats`:
```php
            'invoices_issue' => env('HEARTBEAT_INVOICES_ISSUE'),
            'agreements_expire' => env('HEARTBEAT_AGREEMENTS_EXPIRE'),
```
and in `.env.example` after the other `HEARTBEAT_` lines:
```
HEARTBEAT_INVOICES_ISSUE=
HEARTBEAT_AGREEMENTS_EXPIRE=
```

- [ ] **Step 4: Screens**

Create `app/Livewire/Invoices/Index.php`:
```php
<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Livewire\Concerns\WithActor;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Invoices')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $invoices = Invoice::query()
            ->visibleTo($this->actor())
            ->with(['customer:id,name_en', 'agreement:id,number'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', '%'.$this->search.'%'))))
            ->orderBy('due_date')->orderBy('id')
            ->paginate(50);

        return view('livewire.invoices.index', [
            'invoices' => $invoices,
            'statuses' => InvoiceStatus::cases(),
            'types' => InvoiceType::cases(),
        ]);
    }
}
```
Create `resources/views/livewire/invoices/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Invoices') }}</flux:heading>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="status" class="sm:max-w-44">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="type" class="sm:max-w-44">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number or customer')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$invoices">
            <flux:table.columns>
                <flux:table.column>{{ __('Invoice') }}</flux:table.column>
                <flux:table.column>{{ __('Customer') }}</flux:table.column>
                <flux:table.column>{{ __('Period / due') }}</flux:table.column>
                <flux:table.column>{{ __('Total') }}</flux:table.column>
                <flux:table.column>{{ __('Balance') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($invoices as $invoice)
                    <flux:table.row :key="$invoice->id">
                        <flux:table.cell><flux:link :href="route('invoices.show', $invoice)" wire:navigate>{{ $invoice->label() }}</flux:link> <span class="text-xs text-zinc-500">{{ $invoice->type->label() }}</span></flux:table.cell>
                        <flux:table.cell>{{ $invoice->customer->name_en }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $invoice->period_start?->format('d/m/Y') }}{{ $invoice->period_start ? ' – '.$invoice->period_end?->format('d/m/Y') : '' }} · {{ __('due :d', ['d' => $invoice->due_date->format('d/m/Y')]) }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $invoice->total }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $invoice->status->value === 'issued' ? $invoice->balance : '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$invoice->displayLabel() === __('Overdue') ? 'red' : 'zinc'">{{ $invoice->displayLabel() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/Invoices/Show.php`:
```php
<?php

namespace App\Livewire\Invoices;

use App\Actions\Billing\IssueInvoice;
use App\Livewire\Concerns\WithActor;
use App\Models\Invoice;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $invoiceId;

    public function mount(Invoice $invoice): void
    {
        abort_unless($this->actor()->can('view', $invoice), 403);
        $this->invoiceId = $invoice->id;
    }

    /** "Issue now" (spec §6.3): e.g. when a customer pays several periods ahead. */
    public function issueNow(IssueInvoice $issue): void
    {
        $invoice = Invoice::findOrFail($this->invoiceId);
        abort_unless($this->actor()->can('issue', $invoice), 403);

        if (! $issue->handle($invoice, $this->actor())) {
            throw ValidationException::withMessages(['invoice' => __('Held back: an owner contract covering one of its units is waiting for approval.')]);
        }

        Flux::toast(variant: 'success', text: __('Invoice issued.'));
    }

    public function render(): View
    {
        $invoice = Invoice::with(['customer', 'agreement:id,number', 'lines.unit.building', 'lines.ownerContract:id,number', 'issuer:id,name'])->findOrFail($this->invoiceId);

        return view('livewire.invoices.show', [
            'invoice' => $invoice,
            'canIssue' => $this->actor()->can('issue', $invoice),
        ])->title($invoice->label());
    }
}
```
Create `resources/views/livewire/invoices/show.blade.php`:
```blade
<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $invoice->label() }}</flux:heading>
            <flux:badge>{{ $invoice->displayLabel() }}</flux:badge>
        </div>
        @if ($canIssue)
            <flux:button variant="primary" wire:click="issueNow" wire:confirm="{{ __('Issue this invoice now?') }}">{{ __('Issue now') }}</flux:button>
        @endif
    </div>
    <flux:error name="invoice" />

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
        <div><dt class="text-sm text-zinc-500">{{ __('Customer') }}</dt><dd>{{ $invoice->customer->name_en }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Agreement') }}</dt><dd>@if ($invoice->agreement)<flux:link :href="route('agreements.show', $invoice->agreement_id)" wire:navigate>{{ $invoice->agreement->number }}</flux:link>@endif</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Type') }}</dt><dd>{{ $invoice->type->label() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Issue date') }}</dt><dd>{{ $invoice->issue_date->format('d/m/Y') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Due / grace until') }}</dt><dd>{{ $invoice->due_date->format('d/m/Y') }}{{ $invoice->grace_until ? ' / '.$invoice->grace_until->format('d/m/Y') : '' }}</dd></div>
        @if ($invoice->issuer)
            <div><dt class="text-sm text-zinc-500">{{ __('Issued by') }}</dt><dd>{{ $invoice->issuer->name }}</dd></div>
        @endif
    </dl>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Line') }}</flux:table.column>
                <flux:table.column>{{ __('Net') }}</flux:table.column>
                <flux:table.column>{{ __('Tax') }}</flux:table.column>
                <flux:table.column>{{ __('Total') }}</flux:table.column>
                <flux:table.column>{{ __('Owner contract') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($invoice->lines as $line)
                    <flux:table.row :key="$line->id">
                        <flux:table.cell>{{ $line->description }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->net }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->tax_amount }} <span class="text-xs text-zinc-500">{{ $line->tax_category->label() }}{{ (float) $line->tax_rate > 0 ? ' '.$line->tax_rate.'%' : '' }}</span></flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $line->total }}</flux:table.cell>
                        <flux:table.cell>{{ $line->ownerContract?->number ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <dl class="ms-auto grid max-w-xs grid-cols-2 gap-y-1 tabular-nums">
        <dt>{{ __('Subtotal') }}</dt><dd class="text-end">{{ $invoice->subtotal }}</dd>
        <dt>{{ __('Tax') }}</dt><dd class="text-end">{{ $invoice->tax_total }}</dd>
        <dt class="font-semibold">{{ __('Total (BHD)') }}</dt><dd class="text-end font-semibold">{{ $invoice->total }}</dd>
        @if ($invoice->status->value === 'issued')
            <dt>{{ __('Balance') }}</dt><dd class="text-end">{{ $invoice->balance }}</dd>
        @endif
    </dl>
</section>
```
In `app/Livewire/Agreements/Show.php` add to the view data in `render()`:
```php
            'invoices' => $this->actor()->can('viewAny', \App\Models\Invoice::class)
                ? $agreement->invoices()->orderBy('due_date')->orderBy('id')->get()
                : collect(),
```
(import `App\Models\Invoice` rather than the fully qualified name.) In `resources/views/livewire/agreements/show.blade.php` replace `{{-- Task 10: invoices. --}}` with nothing, and insert before the approval history block:
```blade
    @if ($invoices->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Invoices') }}</flux:heading>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.rows>
                        @foreach ($invoices as $invoice)
                            <flux:table.row :key="'inv-'.$invoice->id">
                                <flux:table.cell><flux:link :href="route('invoices.show', $invoice)" wire:navigate>{{ $invoice->label() }}</flux:link></flux:table.cell>
                                <flux:table.cell>{{ $invoice->type->label() }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">{{ __('due :d', ['d' => $invoice->due_date->format('d/m/Y')]) }}</flux:table.cell>
                                <flux:table.cell class="text-end tabular-nums">{{ $invoice->total }}</flux:table.cell>
                                <flux:table.cell><flux:badge size="sm">{{ $invoice->displayLabel() }}</flux:badge></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endif
```
In `routes/property.php` add `use App\Livewire\Invoices;` and inside the group:
```php
    Route::livewire('invoices', Invoices\Index::class)->middleware('can:finance.view')->name('invoices.index');
    Route::livewire('invoices/{invoice}', Invoices\Show::class)->middleware('can:finance.view')->name('invoices.show');
```
In the sidebar, add a **Finance** group after the Leasing group (M3 adds payments to it):
```blade
                @can('finance.view')
                    <flux:sidebar.group :heading="__('Finance')" class="grid">
                        <flux:sidebar.item icon="banknotes" :href="route('invoices.index')" :current="request()->routeIs('invoices.*')" wire:navigate>{{ __('Invoices') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan
```

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Invoices tests/Feature/Agreements tests/Feature/Backup/ScheduleTest.php
"$PHP" artisan test
```
Expected: every test passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add invoice screens, Issue now, and the 01:00 and 02:00 jobs

Finance sees each invoice's lines, tax and owner attribution, with
Paid, Partially paid and Overdue derived from the balance and grace
date, and can issue a scheduled invoice early. Nightly jobs issue what
is due and expire agreements past their end date.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 11: The M2 permission matrix, and wrap-up

**Spec:** §14 flow 11 (every role against every protected action, including building-assignment scoping), §8.1 table (Customers: Admin ✔, Management view, Finance view, Property Mgr ✔, Leasing ✔ assigned; Agreements: Admin ✔, Management view, Finance view, Property Mgr ✔, Leasing ✔ assigned; Decide approvals: Management; Invoices: Admin view, Management view, Finance ✔; templates: Admin), §14 CI

**Files:**
- Create: `tests/Feature/Permissions/LeasingPermissionMatrixTest.php`
- Modify: whatever Pint and Larastan flag

**Interfaces:**
- Consumes: every route, policy and Action from Tasks 1–10
- Produces: nothing new

- [ ] **Step 1: Write the matrix test**

Create `tests/Feature/Permissions/LeasingPermissionMatrixTest.php`:
```php
<?php

use App\Actions\Agreements\RecordNotice;
use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\ContractTemplates\SaveContractTemplate;
use App\Actions\Customers\SaveCustomer;
use App\Actions\EnsureNumberSequences;
use App\Actions\Billing\GenerateRentSchedule;
use App\Enums\RoleName as R;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Spec §14 flow 11 for M2. Every user is assigned the fixture building, so Leasing is tested on its role, not its scope
// (scope has its own tests: AgreementScopeTest, NoticeTest, AgreementDraftsTest).

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureDefaultContractTemplate::class)();
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);

    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->customer = Customer::factory()->create();
    $this->leasingCreator = User::factory()->create()->assignRole(R::Leasing);
    $this->leasingCreator->buildings()->attach($this->building->id);

    $this->terms = fn (string $from, string $to) => [
        'customer_id' => $this->customer->id, 'start_date' => $from, 'end_date' => $to, 'frequency' => 'monthly',
        'units' => [['unit_id' => $this->unit->id, 'charges' => [['type' => 'rent', 'monthly_amount' => '300', 'tax_category' => 'exempt']]]],
    ];
    $this->draft = app(SaveAgreement::class)->handle($this->leasingCreator, null, ($this->terms)('2031-01-01', '2031-12-31'));
    $this->pending = app(SubmitAgreement::class)->handle($this->leasingCreator, app(SaveAgreement::class)->handle($this->leasingCreator, null, ($this->terms)('2032-01-01', '2032-12-31')));
    $this->active = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2020-01-01', 'end_date' => '2020-12-31'], [$this->unit]);
    $this->scheduled = DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->active, $this->leasingCreator))->first();
});

function matrixUser(R $role, Building $building): User
{
    $user = User::factory()->withTwoFactor()->create()->assignRole($role);
    $user->buildings()->attach($building->id);

    return $user;
}

test('pages open for exactly the roles the spec allows', function (string $route, array $allowed) {
    foreach (R::cases() as $role) {
        $status = $this->actingAs(matrixUser($role, $this->building))->get(route($route))->status();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$route} as {$role->value}");
    }
})->with([
    ['customers.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['customers.create', [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['agreements.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['agreements.create', [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['admin.templates.index', [R::Admin, R::VendorSupport]],
    ['invoices.index', [R::Admin, R::Management, R::Finance, R::VendorSupport]],
]);

test('each protected Action allows exactly the spec roles', function (string $name, Closure $run, array $allowed) {
    foreach (R::cases() as $role) {
        $user = matrixUser($role, $this->building);
        $denied = false;

        try {
            $run->call($this, $user);
        } catch (AuthorizationException) {
            $denied = true;
        } catch (ValidationException) {
            // Authorised; the data was refused (e.g. already submitted by an earlier role).
        }

        expect($denied)->toBe(! in_array($role, $allowed, true), "{$name} as {$role->value}");
    }
})->with([
    ['save customer', function (User $u) {
        app(SaveCustomer::class)->handle($u, null, ['type' => 'individual', 'name_en' => 'X', 'id_type' => 'cpr', 'id_number' => uniqid(), 'mobile' => '+97330000000']);
    }, [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['save agreement draft', function (User $u) {
        app(SaveAgreement::class)->handle($u, null, ($this->terms)('2033-01-01', '2033-12-31'));
    }, [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['submit agreement', function (User $u) {
        app(SubmitAgreement::class)->handle($u, $this->draft);
    }, [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['decide agreement approval', function (User $u) {
        app(DecideApproval::class)->handle($u, $this->pending, false, 'No');
    }, [R::Management]],
    ['record notice', function (User $u) {
        app(RecordNotice::class)->handle($u, $this->active, null, '2020-06-01', '2020-12-31');
    }, [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['issue invoice now', function (User $u) {
        if (! $u->can('issue', $this->scheduled)) {
            throw new AuthorizationException;
        }
    }, [R::Finance]],
    ['save contract template', function (User $u) {
        app(SaveContractTemplate::class)->handle($u, null, ['name' => uniqid('T'), 'active' => true, 'is_default' => false,
            'clauses' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => 'x', 'body_ar' => 'س']]]);
    }, [R::Admin, R::VendorSupport]],
]);
```

- [ ] **Step 2: Run it**

Run: `"$PHP" artisan test tests/Feature/Permissions`
Expected: all rows pass. A failure names the route or Action and the role: fix the policy or seeder if the code disagrees with §8.1; fix the expectation only if the spec table says otherwise, and say which row in the report.

- [ ] **Step 3: Pint, Larastan, audit, full suite**

```bash
"$PHP" vendor/bin/pint
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
"$PHP" /c/Users/ababy/.config/herd/bin/composer.phar audit
"$PHP" artisan test
```
Expected: Pint clean; Larastan level 8 with no new errors (fix them in code, never in the baseline); no advisories; every test passes.

- [ ] **Step 4: Check the screens at 375 px and the contract PDF by eye**

At 375 px: Customers (list, form), Agreements (list, form with two units, show with notice form), Contract templates (edit), Invoices (list, show). Nothing may scroll sideways except inside a table's `overflow-x-auto` wrapper.

Open a draft contract PDF and an approved one: Arabic letters join, numbers and dates inside Arabic read correctly, the units table is not shrunk, the approved one has the QR code, and scanning it opens `/v/{token}`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the M2 permission matrix

Every role against every customer, agreement, template and invoice page
and Action.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

## M2 exit

- All tasks committed on `m2-customers-agreements`, CI green.
- **Exit criteria (spec §15):** 10 real agreements (at least one multi-unit across two buildings) entered and approved; the client signs off the EN/AR contract PDF. Both need the client: the agreements can be entered on staging once it exists, and the contract template's wording is the client's lawyer's call (Admin edits it under Contract templates).
