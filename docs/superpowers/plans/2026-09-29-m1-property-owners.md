# M1 Property and Owners Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a company record its buildings, units, owners and owner contracts (leased or managed, approved by Management), record expenses charged to the company or an owner, and dry-run an import of its existing property data.

**Architecture:** Builds on M0 (branch `m0-foundation`). Same pattern: thin Livewire screens call `app/Actions/*`, one DB transaction per Action, audit via `App\Audit\Audit`, MySQL triggers ship with each financial or contractual table. The approvals engine is a generic `approvals` table plus an `Approvable` interface; owner contracts are its first implementer.

**Tech Stack:** as M0 (Laravel 13.33, Livewire 4.4, Flux 2 free, MySQL 26.7, Pest 5) plus `spatie/simple-excel` ^3 for the importer.

**Spec:** `docs/superpowers/specs/2026-09-28-rental-management-v1-design.md` — §15 M1 row; §4.1, §4.2, §4.3, §4.4, §4.5, §4.7, §8.1–§8.5, §9.1, §11, §12.

## Global Constraints

Everything in the M0 plan's Global Constraints still applies (latest versions, no Redis, Asia/Bahrain, DECIMAL(12,3) + integer fils, two DB users, one statement per `DB::unprepared()`, Actions as the only write path, no secrets in the audit log, English UI usable at 375 px, free Flux only, tests on MySQL as `rms_app`).

## Deliberately NOT in M1 (and where it lands)

| Item | Spec | Lands in |
|---|---|---|
| Unit statuses Occupied / Reserved / Notice given (need agreements) | §4.3 | M2 — M1 computes Blocked / Available only |
| Head-lease payables and their cancellation on termination/successor | §7.8, §4.5 | M4 |
| Owner-ledger posting of owner-charged expenses | §7.9 | M4 |
| Expenses charged to a tenant (creates an invoice) | §4.7 | M3 |
| Import of customers, agreements, balances, deposits, cheques | §11 | M5 |
| Remittance-screen bank-change banner | §4.4 | M4 (M1 shows it on the owner screen) |

## Working environment

As M0:
```bash
export PHP="/c/Users/ababy/.config/herd/bin/php85/php.exe"
export COMPOSER="$PHP /c/Users/ababy/.config/herd/bin/composer.phar"
export MYSQL="/c/Users/ababy/.mysql/mysql-26.7.0-winx64/bin/mysql.exe"
cd /c/Users/ababy/Documents/RentalManagementSystem
```
Branch: `m1-property-owners` (from `m0-foundation`). Run migrations with `"$PHP" artisan migrate:fresh --database=migrator --force` after adding any.

## Conventions carried over from M0 (do not re-create)
`App\Audit\Audit::log()`, `App\Actions\NextDocumentNumber` (+ `NumberSequenceKey::OwnerContract` → `OC`), `App\Support\Approvers::notifiable()`, `App\Livewire\Concerns\WithActor` (`$this->actor(): User`), `App\Models\CompanySetting::current()`, `App\Models\Building::visibleTo($user)` scope, `BuildingPolicy`, `App\Actions\Documents\{StoreDocument,DeleteDocument}`, `DocumentPolicy`, route `documents.download`, `RolesAndPermissionsSeeder`, `RoleName`, `PermissionName`. Tests: Pest function style, `$this->seed(RolesAndPermissionsSeeder::class)`, users with sensitive permissions need `->withTwoFactor()` to pass the 2FA middleware on HTTP requests.

---

### Task 1: Buildings screens and the documents panel

**Spec:** §4.1, §8.2 (scope), §9.1 (documents), §2 (375 px)

**Files:**
- Create: `app/Actions/Buildings/SaveBuilding.php`, `app/Livewire/Buildings/Index.php`, `app/Livewire/Buildings/Form.php`, `app/Livewire/Documents/Panel.php`, `resources/views/livewire/buildings/index.blade.php`, `resources/views/livewire/buildings/form.blade.php`, `resources/views/livewire/documents/panel.blade.php`, `routes/property.php`, `tests/Feature/Buildings/BuildingScreensTest.php`
- Modify: `app/Policies/BuildingPolicy.php`, `routes/web.php`, `resources/views/layouts/app/sidebar.blade.php`

**Interfaces:**
- Consumes: `Building`, `BuildingPolicy::view/update`, `StoreDocument`, `DeleteDocument`, `DocumentCategory`, `WithActor`
- Produces: `SaveBuilding::handle(User $actor, ?Building $building, array $data): Building`; `BuildingPolicy::viewAny(User)`, `create(User)`; routes `buildings.index`, `buildings.create`, `buildings.edit` in `routes/property.php`; Livewire `documents.panel` (`<livewire:documents.panel :documentable="$model" />`), whose `ALLOWED` list later tasks extend

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Buildings/BuildingScreensTest.php`:
```php
<?php

use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Livewire\Buildings\Form;
use App\Livewire\Buildings\Index;
use App\Livewire\Documents\Panel;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
});

test('the list respects building assignment', function () {
    [$a, $b] = Building::factory()->count(2)->create()->all();
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($a->id);

    Livewire::actingAs($leasing)->test(Index::class)->assertSee($a->name)->assertDontSee($b->name);
    Livewire::actingAs($this->manager)->test(Index::class)->assertSee($a->name)->assertSee($b->name);
});

test('users without buildings.view cannot open the list', function () {
    $this->actingAs(User::factory()->create())->get(route('buildings.index'))->assertForbidden();
    $this->actingAs($this->manager)->get(route('buildings.index'))->assertOk();
});

test('a property manager creates and edits a building', function () {
    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.name', 'Marina Tower')
        ->set('form.code', 'MT-01')
        ->set('form.type', 'residential')
        ->set('form.floors_count', 12)
        ->call('save')
        ->assertHasNoErrors();

    $building = Building::where('code', 'MT-01')->firstOrFail();

    Livewire::actingAs($this->manager)->test(Form::class, ['building' => $building])
        ->set('form.name', 'Marina Tower A')
        ->call('save')
        ->assertHasNoErrors();

    expect($building->fresh()->name)->toBe('Marina Tower A');
});

test('codes are unique and required fields are validated', function () {
    Building::factory()->create(['code' => 'DUP']);

    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.name', '')
        ->set('form.code', 'DUP')
        ->set('form.type', 'palace')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.code', 'form.type']);
});

test('Leasing cannot edit buildings', function () {
    $building = Building::factory()->create();
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($building->id);

    Livewire::actingAs($leasing)->test(Form::class, ['building' => $building])
        ->set('form.name', 'Hacked')
        ->call('save')
        ->assertForbidden();
});

test('the documents panel uploads, lists and deletes', function () {
    Storage::fake('local');
    $building = Building::factory()->create();

    $panel = Livewire::actingAs($this->manager)->test(Panel::class, ['documentable' => $building])
        ->set('upload', UploadedFile::fake()->create('title-deed.pdf', 20, 'application/pdf'))
        ->set('category', DocumentCategory::Other->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('title-deed.pdf');

    $document = $building->documents()->firstOrFail();
    $panel->call('delete', $document->id)->assertDontSee('title-deed.pdf');

    expect($building->documents()->count())->toBe(0);
});

test('the documents panel refuses models outside its allow-list', function () {
    Livewire::actingAs($this->manager)->test(Panel::class, ['documentable' => $this->manager])->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Buildings/BuildingScreensTest.php`
Expected: FAIL — `Class "App\Livewire\Buildings\Index" not found`.

- [ ] **Step 3: Policy abilities and the Action**

In `app/Policies/BuildingPolicy.php` add:
```php
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::BuildingsView);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::BuildingsManage);
    }
```
Create `app/Actions/Buildings/SaveBuilding.php`:
```php
<?php

namespace App\Actions\Buildings;

use App\Enums\BuildingType;
use App\Models\Building;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveBuilding
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Building $building, array $data): Building
    {
        if (! ($building ? $actor->can('update', $building) : $actor->can('create', Building::class))) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', Rule::unique('buildings', 'code')->ignore($building?->id)],
            'location' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::enum(BuildingType::class)],
            'floors_count' => ['nullable', 'integer', 'between:0,200'],
            'parking' => ['nullable', 'string', 'max:150'],
            'facilities' => ['nullable', 'string', 'max:1000'],
            'property_manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($building, $validated) {
            $building ??= new Building;
            $building->fill($validated)->save(); // LogsActivity records old/new

            return $building;
        });
    }
}
```

- [ ] **Step 4: The documents panel**

Create `app/Livewire/Documents/Panel.php`:
```php
<?php

namespace App\Livewire\Documents;

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\StoreDocument;
use App\Enums\DocumentCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Document;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Reusable attachments list for any allow-listed record (spec §9.1). */
class Panel extends Component
{
    use WithActor, WithFileUploads;

    /** Record types that may carry documents; later milestones add theirs. */
    public const array ALLOWED = [Building::class];

    #[Locked]
    public string $type = '';

    #[Locked]
    public int $recordId = 0;

    /** @var UploadedFile|null */
    public $upload = null;

    public string $category = 'other';

    public function mount(Model $documentable): void
    {
        abort_unless(in_array($documentable::class, self::ALLOWED, true), 403);

        $this->type = $documentable::class;
        $this->recordId = (int) $documentable->getKey();
    }

    private function documentable(): Model
    {
        abort_unless(in_array($this->type, self::ALLOWED, true), 403);

        /** @var class-string<Model> $class */
        $class = $this->type;

        return $class::query()->findOrFail($this->recordId);
    }

    public function save(StoreDocument $store): void
    {
        $this->validate([
            'upload' => ['required', 'file'],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
        ]);

        try {
            $store->handle($this->actor(), $this->documentable(), $this->upload, DocumentCategory::from($this->category));
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->reset('upload');
    }

    public function delete(int $documentId, DeleteDocument $delete): void
    {
        $document = Document::query()
            ->where('documentable_type', $this->documentable()->getMorphClass())
            ->where('documentable_id', $this->recordId)
            ->findOrFail($documentId);

        try {
            $delete->handle($this->actor(), $document);
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    public function render(): View
    {
        $record = $this->documentable();
        abort_unless($this->actor()->can('view', $record), 403);

        return view('livewire.documents.panel', [
            'documents' => Document::query()
                ->where('documentable_type', $record->getMorphClass())
                ->where('documentable_id', $record->getKey())
                ->latest()
                ->get(),
            'canUpload' => $this->actor()->can('update', $record),
            'categories' => DocumentCategory::cases(),
        ]);
    }
}
```
Create `resources/views/livewire/documents/panel.blade.php`:
```blade
<div class="space-y-4">
    <flux:heading size="lg">{{ __('Documents') }}</flux:heading>

    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @forelse ($documents as $document)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <flux:link :href="route('documents.download', $document)">{{ $document->original_name }}</flux:link>
                <div class="flex items-center gap-2">
                    <flux:badge size="sm">{{ str($document->category->value)->headline() }}</flux:badge>
                    @can('delete', $document)
                        <flux:button size="sm" variant="ghost" wire:click="delete({{ $document->id }})" wire:confirm="{{ __('Delete this document?') }}">{{ __('Delete') }}</flux:button>
                    @endcan
                </div>
            </li>
        @empty
            <li class="py-2"><flux:text>{{ __('No documents yet.') }}</flux:text></li>
        @endforelse
    </ul>

    @if ($canUpload)
        <form wire:submit="save" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:field class="flex-1">
                <flux:label>{{ __('File (PDF, image, Word or Excel, max 10 MB)') }}</flux:label>
                <input type="file" wire:model="upload" class="block w-full text-sm" />
                <flux:error name="upload" />
            </flux:field>
            <flux:select wire:model="category" :label="__('Category')" class="sm:max-w-48">
                @foreach ($categories as $category)
                    <option value="{{ $category->value }}">{{ str($category->value)->headline() }}</option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary">{{ __('Upload') }}</flux:button>
        </form>
    @endif
</div>
```

- [ ] **Step 5: Screens and routes**

Create `app/Livewire/Buildings/Index.php`:
```php
<?php

namespace App\Livewire\Buildings;

use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Buildings')]
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
        $buildings = Building::query()
            ->visibleTo($this->actor())
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('code', 'like', '%'.$this->search.'%')))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.buildings.index', ['buildings' => $buildings]);
    }
}
```

Create `resources/views/livewire/buildings/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Buildings') }}</flux:heading>
        @can('create', \App\Models\Building::class)
            <flux:button variant="primary" :href="route('buildings.create')" wire:navigate>{{ __('New building') }}</flux:button>
        @endcan
    </div>

    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search name or code')" icon="magnifying-glass" class="sm:max-w-xs" />

    <div class="overflow-x-auto">
        <flux:table :paginate="$buildings">
            <flux:table.columns>
                <flux:table.column>{{ __('Code') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Location') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($buildings as $building)
                    <flux:table.row :key="$building->id">
                        <flux:table.cell>{{ $building->code }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('buildings.edit', $building)" wire:navigate>{{ $building->name }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $building->location }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/Buildings/Form.php`:
```php
<?php

namespace App\Livewire\Buildings;

use App\Actions\Buildings\SaveBuilding;
use App\Enums\BuildingType;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\User;
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
    public ?int $buildingId = null;

    /** @var array<string, mixed> */
    public array $form = ['type' => 'residential'];

    public function mount(?Building $building = null): void
    {
        if ($building?->exists) {
            abort_unless($this->actor()->can('view', $building), 403);
            $this->buildingId = $building->id;
            $this->form = $building->only(['name', 'code', 'location', 'address', 'floors_count', 'parking', 'facilities', 'property_manager_user_id', 'notes'])
                + ['type' => $building->type->value];
        } else {
            abort_unless($this->actor()->can('create', Building::class), 403);
        }
    }

    public function save(SaveBuilding $save): void
    {
        try {
            $building = $save->handle($this->actor(), $this->buildingId ? Building::findOrFail($this->buildingId) : null, $this->form);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Building saved.'));
        $this->redirectRoute('buildings.edit', $building, navigate: true);
    }

    public function render(): View
    {
        $building = $this->buildingId ? Building::findOrFail($this->buildingId) : null;

        return view('livewire.buildings.form', [
            'building' => $building,
            'types' => BuildingType::cases(),
            'managers' => User::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'canEdit' => $building ? $this->actor()->can('update', $building) : true,
        ])->title($building ? $building->name : __('New building'));
    }
}
```
Create `resources/views/livewire/buildings/form.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-8">
    <flux:heading size="xl" level="1">{{ $building?->name ?? __('New building') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:input wire:model="form.name" :label="__('Name')" required />
            <flux:input wire:model="form.code" :label="__('Code')" required />
            <flux:select wire:model="form.type" :label="__('Type')">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ str($type->value)->headline() }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model="form.location" :label="__('Location')" />
            <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" />
            <flux:input wire:model="form.floors_count" :label="__('Floors')" type="number" min="0" />
            <flux:input wire:model="form.parking" :label="__('Parking')" />
            <flux:textarea wire:model="form.facilities" :label="__('Facilities')" rows="2" />
            <flux:select wire:model="form.property_manager_user_id" :label="__('Property manager')">
                <option value="">{{ __('None') }}</option>
                @foreach ($managers as $manager)
                    <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($building)
        <livewire:documents.panel :documentable="$building" :key="'docs-building-'.$building->id" />
    @endif
</section>
```
Create `routes/property.php`:
```php
<?php

use App\Livewire\Buildings;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::livewire('buildings', Buildings\Index::class)->middleware('can:buildings.view')->name('buildings.index');
    Route::livewire('buildings/create', Buildings\Form::class)->middleware('can:buildings.manage')->name('buildings.create');
    Route::livewire('buildings/{building}/edit', Buildings\Form::class)->middleware('can:buildings.view')->name('buildings.edit');
});
```
In `routes/web.php` add after `require __DIR__.'/admin.php';`:
```php
require __DIR__.'/property.php';
```
In `resources/views/layouts/app/sidebar.blade.php`, insert before `@canany(['users.manage', …])` (later tasks add their items to this group):
```blade
                <flux:sidebar.group :heading="__('Property')" class="grid">
                    @can('buildings.view')
                        <flux:sidebar.item icon="building-office-2" :href="route('buildings.index')" :current="request()->routeIs('buildings.*')" wire:navigate>{{ __('Buildings') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Buildings
"$PHP" artisan test
```
Expected: 7 new tests pass; full suite passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add buildings screens and a reusable documents panel

Buildings are listed within the user's building scope and edited by
holders of buildings.manage; any allow-listed record can show, upload
and delete its private documents.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 2: Units

**Spec:** §4.2 (fields), §4.3 (computed status), §2 (integer fils)

**Files:**
- Create: `app/Support/Fils.php`, `app/Enums/{UnitUse,UnitType,Furnishing,UnitStatus}.php`, `database/migrations/2026_10_01_000100_create_units_table.php`, `app/Models/Unit.php`, `database/factories/UnitFactory.php`, `app/Policies/UnitPolicy.php`, `app/Actions/Units/SaveUnit.php`, `app/Livewire/Units/{Index,Form}.php`, `resources/views/livewire/units/{index,form}.blade.php`, `tests/Unit/FilsTest.php`, `tests/Feature/Units/UnitsTest.php`
- Modify: `app/Models/Building.php`, `routes/property.php`, `app/Livewire/Documents/Panel.php`, `resources/views/layouts/app/sidebar.blade.php`

**Interfaces:**
- Consumes: `Building`, `BuildingPolicy`, `TaxCategory`, `CompanySetting::current()`
- Produces: `App\Support\Fils::fromDecimal(string|int $value): int`, `Fils::toDecimal(int $fils): string`, `Fils::rule(): string` (regex rule for 3-decimal money); `Unit` with `building()`, `visibleTo($user)` scope, `status(): UnitStatus`, `effectiveTaxCategory(): TaxCategory`; `Building::units()`; `UnitPolicy::view/update/create`; routes `units.index`, `units.create`, `units.edit`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/FilsTest.php`:
```php
<?php

use App\Support\Fils;

test('decimals convert to integer fils and back without floats', function () {
    expect(Fils::fromDecimal('350.5'))->toBe(350500)
        ->and(Fils::fromDecimal('0.001'))->toBe(1)
        ->and(Fils::fromDecimal('-12.345'))->toBe(-12345)
        ->and(Fils::fromDecimal('7'))->toBe(7000)
        ->and(Fils::fromDecimal(7))->toBe(7000)
        ->and(Fils::toDecimal(350500))->toBe('350.500')
        ->and(Fils::toDecimal(-5))->toBe('-0.005')
        ->and(Fils::toDecimal(0))->toBe('0.000');
});

test('more than three decimals or junk is rejected', function (string $bad) {
    expect(fn () => Fils::fromDecimal($bad))->toThrow(InvalidArgumentException::class);
})->with(['1.2345', 'abc', '', '1,000', '1e3']);
```
Create `tests/Feature/Units/UnitsTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Enums\TaxCategory;
use App\Enums\UnitStatus;
use App\Livewire\Units\Form;
use App\Livewire\Units\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
});

test('a property manager creates a unit with 3-decimal money', function () {
    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.building_id', $this->building->id)
        ->set('form.code', '502')
        ->set('form.floor', '5')
        ->set('form.use', 'residential')
        ->set('form.type', 'flat')
        ->set('form.furnishing', 'unfurnished')
        ->set('form.bedrooms', 2)
        ->set('form.list_rent', '450.500')
        ->set('form.list_deposit', '450')
        ->set('form.list_service_charge', '12.250')
        ->call('save')
        ->assertHasNoErrors();

    $unit = Unit::where('code', '502')->firstOrFail();
    expect($unit->list_rent)->toBe('450.500')->and($unit->list_deposit)->toBe('450.000');
});

test('money with more than 3 decimals and duplicate codes are rejected', function () {
    Unit::factory()->for($this->building)->create(['code' => '101']);

    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.building_id', $this->building->id)
        ->set('form.code', '101')
        ->set('form.use', 'residential')
        ->set('form.type', 'flat')
        ->set('form.furnishing', 'unfurnished')
        ->set('form.list_rent', '1.2345')
        ->call('save')
        ->assertHasErrors(['form.code', 'form.list_rent']);
});

test('the database rejects bad enum values', function () {
    // Raw insert: the enum cast would throw in PHP before MySQL saw the value.
    expect(fn () => DB::table('units')->insert(['building_id' => $this->building->id, 'code' => 'Z9', 'use' => 'castle', 'type' => 'flat']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});

test('status is Blocked or Available until agreements exist', function () {
    $unit = Unit::factory()->for($this->building)->create();
    expect($unit->status())->toBe(UnitStatus::Available);

    $unit->update(['blocked' => true, 'blocked_reason' => 'Renovation']);
    expect($unit->fresh()->status())->toBe(UnitStatus::Blocked);
});

test('the tax category falls back to the settings default for its use', function () {
    $flat = Unit::factory()->for($this->building)->create(['use' => 'residential', 'default_tax_category' => null]);
    $shop = Unit::factory()->for($this->building)->create(['use' => 'commercial', 'default_tax_category' => null]);
    $override = Unit::factory()->for($this->building)->create(['use' => 'commercial', 'default_tax_category' => 'zero_rated']);

    expect($flat->effectiveTaxCategory())->toBe(TaxCategory::Exempt)
        ->and($shop->effectiveTaxCategory())->toBe(TaxCategory::Standard)
        ->and($override->effectiveTaxCategory())->toBe(TaxCategory::ZeroRated);
});

test('units follow the building scope, for lists and for writes', function () {
    $other = Building::factory()->create();
    $mine = Unit::factory()->for($this->building)->create(['code' => 'MINE-1']);
    Unit::factory()->for($other)->create(['code' => 'THEIRS-1']);
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->building->id);

    Livewire::actingAs($leasing)->test(Index::class)->assertSee('MINE-1')->assertDontSee('THEIRS-1');
    expect($leasing->can('view', $mine))->toBeTrue()->and($leasing->can('update', $mine))->toBeFalse();

    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.building_id', $other->id)
        ->set('form.code', 'X1')
        ->set('form.use', 'residential')
        ->set('form.type', 'flat')
        ->set('form.furnishing', 'unfurnished')
        ->set('form.list_rent', '100')
        ->call('save')
        ->assertHasNoErrors(); // Property Mgr has buildings.view-all
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Unit/FilsTest.php tests/Feature/Units`
Expected: FAIL — `Class "App\Support\Fils" not found`.

- [ ] **Step 3: Fils and enums**

Create `app/Support/Fils.php`:
```php
<?php

namespace App\Support;

use InvalidArgumentException;

/** BHD money as integer fils (1 BHD = 1000 fils). Never floats (spec §2). */
final class Fils
{
    private const string PATTERN = '/^(-)?(\d+)(?:\.(\d{1,3}))?$/';

    public static function fromDecimal(string|int $value): int
    {
        if (is_int($value)) {
            return $value * 1000;
        }

        if (! preg_match(self::PATTERN, trim($value), $m)) {
            throw new InvalidArgumentException("Not a money amount with at most 3 decimals: [{$value}]");
        }

        $fils = ((int) $m[2]) * 1000 + (int) str_pad($m[3] ?? '', 3, '0');

        return $m[1] === '-' ? -$fils : $fils;
    }

    public static function toDecimal(int $fils): string
    {
        $sign = $fils < 0 ? '-' : '';
        $abs = abs($fils);

        return sprintf('%s%d.%03d', $sign, intdiv($abs, 1000), $abs % 1000);
    }

    /** Validation rule for a non-negative money input with at most 3 decimals. */
    public static function rule(): string
    {
        return 'regex:/^\d{1,9}(\.\d{1,3})?$/';
    }
}
```
Create `app/Enums/UnitUse.php`:
```php
<?php

namespace App\Enums;

enum UnitUse: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';
}
```
Create `app/Enums/UnitType.php`:
```php
<?php

namespace App\Enums;

enum UnitType: string
{
    case Flat = 'flat';
    case Villa = 'villa';
    case Studio = 'studio';
    case Shop = 'shop';
    case Office = 'office';
    case Showroom = 'showroom';
    case Warehouse = 'warehouse';
    case Other = 'other';
}
```
Create `app/Enums/Furnishing.php`:
```php
<?php

namespace App\Enums;

enum Furnishing: string
{
    case Unfurnished = 'unfurnished';
    case Semi = 'semi';
    case Furnished = 'furnished';
}
```
Create `app/Enums/UnitStatus.php`:
```php
<?php

namespace App\Enums;

/** Computed, never stored (spec §4.3). */
enum UnitStatus: string
{
    case Occupied = 'occupied';
    case NoticeGiven = 'notice_given';
    case Reserved = 'reserved';
    case Blocked = 'blocked';
    case Available = 'available';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```

- [ ] **Step 4: Migration, model, factory, policy**

Create `database/migrations/2026_10_01_000100_create_units_table.php`:
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
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('floor', 10)->nullable();
            $table->string('use', 20);
            $table->string('type', 20);
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->decimal('area_sqm', 8, 2)->nullable();
            $table->string('furnishing', 20)->default('unfurnished');
            $table->decimal('list_rent', 12, 3)->default(0);
            $table->decimal('list_deposit', 12, 3)->default(0);
            $table->decimal('list_service_charge', 12, 3)->default(0);
            $table->string('default_tax_category', 20)->nullable();
            $table->string('ewa_account_no', 30)->nullable();
            $table->boolean('blocked')->default(false);
            $table->string('blocked_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['building_id', 'code']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE units
                ADD CONSTRAINT units_use_chk CHECK (`use` IN ('residential', 'commercial')),
                ADD CONSTRAINT units_type_chk CHECK (type IN ('flat', 'villa', 'studio', 'shop', 'office', 'showroom', 'warehouse', 'other')),
                ADD CONSTRAINT units_furnishing_chk CHECK (furnishing IN ('unfurnished', 'semi', 'furnished')),
                ADD CONSTRAINT units_tax_chk CHECK (default_tax_category IS NULL OR default_tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT units_money_chk CHECK (list_rent >= 0 AND list_deposit >= 0 AND list_service_charge >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
```
Create `app/Models/Unit.php`:
```php
<?php

namespace App\Models;

use App\Enums\Furnishing;
use App\Enums\TaxCategory;
use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Enums\UnitUse;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $building_id
 * @property string $code
 * @property UnitUse $use
 * @property TaxCategory|null $default_tax_category
 * @property bool $blocked
 * @property string $list_rent
 * @property string $list_deposit
 * @property string $list_service_charge
 */
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'building_id', 'code', 'floor', 'use', 'type', 'bedrooms', 'bathrooms', 'area_sqm', 'furnishing',
        'list_rent', 'list_deposit', 'list_service_charge', 'default_tax_category', 'ewa_account_no',
        'blocked', 'blocked_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'use' => UnitUse::class,
            'type' => UnitType::class,
            'furnishing' => Furnishing::class,
            'default_tax_category' => TaxCategory::class,
            'blocked' => 'boolean',
            'list_rent' => 'decimal:3',
            'list_deposit' => 'decimal:3',
            'list_service_charge' => 'decimal:3',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @param  Builder<Unit>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('building', fn (Builder $q) => $q->visibleTo($user));
    }

    /** Spec §4.3. ponytail: agreements (M2) add Occupied, Reserved and Notice given before these two. */
    public function status(): UnitStatus
    {
        return $this->blocked ? UnitStatus::Blocked : UnitStatus::Available;
    }

    public function effectiveTaxCategory(): TaxCategory
    {
        $settings = CompanySetting::current();

        return $this->default_tax_category ?? ($this->use === UnitUse::Commercial
            ? $settings->commercial_tax_category
            : $settings->residential_tax_category);
    }
}
```
In `app/Models/Building.php` add:
```php
    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Unit, $this> */
    public function units(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Unit::class);
    }
```
Create `database/factories/UnitFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'code' => (string) fake()->unique()->numberBetween(100, 9999),
            'floor' => (string) fake()->numberBetween(0, 20),
            'use' => 'residential',
            'type' => 'flat',
            'furnishing' => 'unfurnished',
            'bedrooms' => 2,
            'bathrooms' => 2,
            'list_rent' => '400.000',
            'list_deposit' => '400.000',
            'list_service_charge' => '0.000',
        ];
    }
}
```
Create `app/Policies/UnitPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Unit;
use App\Models\User;

/** Units inherit their building's rules (spec §8.2). */
class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::BuildingsView);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::BuildingsManage);
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->can('view', $unit->building);
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->can('update', $unit->building);
    }
}
```

- [ ] **Step 5: Action, screens, routes**

Create `app/Actions/Units/SaveUnit.php`:
```php
<?php

namespace App\Actions\Units;

use App\Enums\Furnishing;
use App\Enums\TaxCategory;
use App\Enums\UnitType;
use App\Enums\UnitUse;
use App\Models\Building;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveUnit
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Unit $unit, array $data): Unit
    {
        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        $buildingId = $unit?->building_id ?? (int) ($data['building_id'] ?? 0);

        $validated = Validator::make([...$data, 'building_id' => $buildingId], [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'code' => ['required', 'string', 'max:30', Rule::unique('units', 'code')->where('building_id', $buildingId)->ignore($unit?->id)],
            'floor' => ['nullable', 'string', 'max:10'],
            'use' => ['required', Rule::enum(UnitUse::class)],
            'type' => ['required', Rule::enum(UnitType::class)],
            'bedrooms' => ['nullable', 'integer', 'between:0,20'],
            'bathrooms' => ['nullable', 'integer', 'between:0,20'],
            'area_sqm' => ['nullable', 'numeric', 'between:0,999999'],
            'furnishing' => ['required', Rule::enum(Furnishing::class)],
            'list_rent' => ['required', Fils::rule()],
            'list_deposit' => ['nullable', Fils::rule()],
            'list_service_charge' => ['nullable', Fils::rule()],
            'default_tax_category' => ['nullable', Rule::enum(TaxCategory::class)],
            'ewa_account_no' => ['nullable', 'string', 'max:30'],
            'blocked' => ['boolean'],
            'blocked_reason' => ['nullable', 'required_if:blocked,true', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        // Unit writes need the building in scope (spec §8.2) and buildings.manage.
        if (! $actor->can('update', Building::findOrFail($buildingId))) {
            throw new AuthorizationException;
        }

        foreach (['list_rent', 'list_deposit', 'list_service_charge'] as $money) {
            $validated[$money] = Fils::toDecimal(Fils::fromDecimal((string) ($validated[$money] ?? '0')));
        }

        return DB::transaction(function () use ($unit, $validated) {
            $unit ??= new Unit;
            $unit->fill($validated)->save();

            return $unit;
        });
    }
}
```
Create `app/Livewire/Units/Index.php`:
```php
<?php

namespace App\Livewire\Units;

use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Units')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $buildingId = null;

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $units = Unit::query()
            ->visibleTo($this->actor())
            ->with('building:id,code,name')
            ->when($this->buildingId, fn ($q) => $q->where('building_id', $this->buildingId))
            ->when($this->search !== '', fn ($q) => $q->where('code', 'like', '%'.$this->search.'%'))
            ->orderBy('building_id')->orderBy('code')
            ->paginate(50);

        return view('livewire.units.index', [
            'units' => $units,
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
}
```
Create `resources/views/livewire/units/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Units') }}</flux:heading>
        @can('create', \App\Models\Unit::class)
            <flux:button variant="primary" :href="route('units.create')" wire:navigate>{{ __('New unit') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="buildingId" class="sm:max-w-64">
            <option value="">{{ __('All buildings') }}</option>
            @foreach ($buildings as $building)
                <option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Unit code')" icon="magnifying-glass" class="sm:max-w-40" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$units">
            <flux:table.columns>
                <flux:table.column>{{ __('Building') }}</flux:table.column>
                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('List rent (BHD)') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($units as $unit)
                    <flux:table.row :key="$unit->id">
                        <flux:table.cell>{{ $unit->building->code }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('units.edit', $unit)" wire:navigate>{{ $unit->code }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ str($unit->type->value)->headline() }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $unit->list_rent }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $unit->status()->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/Units/Form.php`:
```php
<?php

namespace App\Livewire\Units;

use App\Actions\Units\SaveUnit;
use App\Enums\Furnishing;
use App\Enums\TaxCategory;
use App\Enums\UnitType;
use App\Enums\UnitUse;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Unit;
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
    public ?int $unitId = null;

    /** @var array<string, mixed> */
    public array $form = ['use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'blocked' => false];

    public function mount(?Unit $unit = null): void
    {
        if ($unit?->exists) {
            abort_unless($this->actor()->can('view', $unit), 403);
            $this->unitId = $unit->id;
            $this->form = [
                ...$unit->only(['building_id', 'code', 'floor', 'bedrooms', 'bathrooms', 'area_sqm', 'list_rent', 'list_deposit', 'list_service_charge', 'ewa_account_no', 'blocked', 'blocked_reason', 'notes']),
                'use' => $unit->use->value,
                'type' => $unit->type->value,
                'furnishing' => $unit->furnishing->value,
                'default_tax_category' => $unit->default_tax_category?->value,
            ];
        } else {
            abort_unless($this->actor()->can('create', Unit::class), 403);
        }
    }

    public function save(SaveUnit $save): void
    {
        try {
            $unit = $save->handle($this->actor(), $this->unitId ? Unit::findOrFail($this->unitId) : null, $this->form);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Unit saved.'));
        $this->redirectRoute('units.edit', $unit, navigate: true);
    }

    public function render(): View
    {
        $unit = $this->unitId ? Unit::findOrFail($this->unitId) : null;

        return view('livewire.units.form', [
            'unit' => $unit,
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
            'uses' => UnitUse::cases(),
            'types' => UnitType::cases(),
            'furnishings' => Furnishing::cases(),
            'taxCategories' => TaxCategory::cases(),
            'canEdit' => $unit ? $this->actor()->can('update', $unit) : true,
        ])->title($unit ? __('Unit :code', ['code' => $unit->code]) : __('New unit'));
    }
}
```
Create `resources/views/livewire/units/form.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $unit ? __('Unit :code', ['code' => $unit->code]) : __('New unit') }}</flux:heading>
    @if ($unit)
        <flux:badge>{{ $unit->status()->label() }}</flux:badge>
    @endif

    <form wire:submit="save" class="space-y-4">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:select wire:model="form.building_id" :label="__('Building')" :disabled="(bool) $unit">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($buildings as $building)
                    <option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.code" :label="__('Unit code')" required />
                <flux:input wire:model="form.floor" :label="__('Floor')" />
                <flux:select wire:model="form.use" :label="__('Use')">
                    @foreach ($uses as $use)<option value="{{ $use->value }}">{{ str($use->value)->headline() }}</option>@endforeach
                </flux:select>
                <flux:select wire:model="form.type" :label="__('Type')">
                    @foreach ($types as $type)<option value="{{ $type->value }}">{{ str($type->value)->headline() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.bedrooms" :label="__('Bedrooms')" type="number" min="0" />
                <flux:input wire:model="form.bathrooms" :label="__('Bathrooms')" type="number" min="0" />
                <flux:input wire:model="form.area_sqm" :label="__('Area (m²)')" inputmode="decimal" />
                <flux:select wire:model="form.furnishing" :label="__('Furnishing')">
                    @foreach ($furnishings as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.list_rent" :label="__('List rent / month (BHD)')" inputmode="decimal" required />
                <flux:input wire:model="form.list_deposit" :label="__('List deposit (BHD)')" inputmode="decimal" />
                <flux:input wire:model="form.list_service_charge" :label="__('Service charge / month (BHD)')" inputmode="decimal" />
                <flux:select wire:model="form.default_tax_category" :label="__('Tax category')">
                    <option value="">{{ __('Company default for this use') }}</option>
                    @foreach ($taxCategories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.ewa_account_no" :label="__('EWA account no.')" />
            </div>
            <flux:checkbox wire:model="form.blocked" :label="__('Blocked (not available to let)')" />
            <flux:input wire:model="form.blocked_reason" :label="__('Blocked reason')" />
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($unit)
        <livewire:documents.panel :documentable="$unit" :key="'docs-unit-'.$unit->id" />
    @endif
</section>
```
In `app/Livewire/Documents/Panel.php` change `ALLOWED` to `[Building::class, Unit::class]` and import `App\Models\Unit`.

In `routes/property.php` add `use App\Livewire\Units;` and inside the group:
```php
    Route::livewire('units', Units\Index::class)->middleware('can:buildings.view')->name('units.index');
    Route::livewire('units/create', Units\Form::class)->middleware('can:buildings.manage')->name('units.create');
    Route::livewire('units/{unit}/edit', Units\Form::class)->middleware('can:buildings.view')->name('units.edit');
```

In `resources/views/layouts/app/sidebar.blade.php`, add inside the Property group:
```blade
                    @can('buildings.view')
                        <flux:sidebar.item icon="home-modern" :href="route('units.index')" :current="request()->routeIs('units.*')" wire:navigate>{{ __('Units') }}</flux:sidebar.item>
                    @endcan
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Unit/FilsTest.php tests/Feature/Units
"$PHP" artisan test
```
Expected: 12 new tests pass; full suite passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add units with integer-fils money and a computed status

Units follow their building's scope for viewing and writing. Money is
validated to 3 decimals and handled as integer fils. Status is Blocked
or Available until agreements arrive in M2.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 3: Owners

**Spec:** §4.4 (fields, bank controls, 30-day notice), §8.1 (owners.view/manage, owners.bank.manage, Finance views bank details)

**Files:**
- Create: `app/Enums/{PartyType,IdType}.php`, `database/migrations/2026_10_01_000200_create_owners_table.php`, `app/Models/Owner.php`, `database/factories/OwnerFactory.php`, `app/Policies/OwnerPolicy.php`, `app/Actions/Owners/SaveOwner.php`, `app/Livewire/Owners/{Index,Form}.php`, `resources/views/livewire/owners/{index,form}.blade.php`, `tests/Feature/Owners/OwnersTest.php`
- Modify: `routes/property.php`, `app/Livewire/Documents/Panel.php`, `resources/views/layouts/app/sidebar.blade.php`

**Interfaces:**
- Consumes: `Audit`, `PermissionName`
- Produces: `PartyType` (person, company), `IdType` (cpr, passport, cr) — reused by customers in M2; `Owner` with `bankChangedRecently(): bool`, `maskedIban(): ?string`; `OwnerPolicy::viewAny/view/create/update/viewBank/updateBank`; `SaveOwner::handle(User $actor, ?Owner $owner, array $data): Owner`; routes `owners.index`, `owners.create`, `owners.edit`; audit event `owner.bank.changed`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Owners/OwnersTest.php`:
```php
<?php

use App\Actions\Owners\SaveOwner;
use App\Enums\RoleName;
use App\Livewire\Owners\Form;
use App\Models\Owner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
});

function ownerData(array $overrides = []): array
{
    return [
        'type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '880101234',
        'phone' => '+97333000000', 'iban' => 'BH67 BMAG 0000 1299 1234 56', 'bank_name' => 'NBB', 'account_name' => 'Ali Hassan',
        ...$overrides,
    ];
}

test('Finance can create owners but not set bank details', function () {
    expect(fn () => app(SaveOwner::class)->handle($this->finance, null, ownerData()))->toThrow(AuthorizationException::class);

    $owner = app(SaveOwner::class)->handle($this->finance, null, ownerData(['iban' => null, 'bank_name' => null, 'account_name' => null]));
    expect($owner->name_en)->toBe('Ali Hassan');
});

test('Admin sets bank details; the IBAN is normalised and the change is stamped and audited with a masked value', function () {
    $owner = app(SaveOwner::class)->handle($this->admin, null, ownerData());

    expect($owner->iban)->toBe('BH67BMAG00001299123456')
        ->and($owner->bank_changed_by)->toBe($this->admin->id)
        ->and($owner->bankChangedRecently())->toBeTrue();

    $row = Activity::query()->where('event', 'owner.bank.changed')->latest('id')->firstOrFail();
    expect($row->attribute_changes['attributes']['iban'])->toBe('••••3456');
    expect(DB::table('activity_log')->where('properties', 'like', '%BH67BMAG%')->orWhere('attribute_changes', 'like', '%BH67BMAG%')->exists())->toBeFalse();
});

test('ID numbers are unique per ID type and IBANs are validated', function () {
    app(SaveOwner::class)->handle($this->admin, null, ownerData());

    expect(fn () => app(SaveOwner::class)->handle($this->admin, null, ownerData()))->toThrow(ValidationException::class);
    expect(fn () => app(SaveOwner::class)->handle($this->admin, null, ownerData(['id_number' => '990202345', 'iban' => 'not-an-iban'])))->toThrow(ValidationException::class);
});

test('Finance sees bank details read-only; Leasing cannot open owners', function () {
    $owner = app(SaveOwner::class)->handle($this->admin, null, ownerData());

    Livewire::actingAs($this->finance)->test(Form::class, ['owner' => $owner])
        ->assertSee('BH67BMAG00001299123456')
        ->assertSee('Bank details changed');

    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($leasing)->get(route('owners.index'))->assertForbidden();
});

test('editing non-bank fields does not touch the bank stamp', function () {
    $owner = app(SaveOwner::class)->handle($this->admin, null, ownerData());
    $this->travel(40)->days();

    app(SaveOwner::class)->handle($this->finance, $owner->fresh(), [...ownerData(), 'phone' => '+97339999999']);

    expect($owner->fresh()->bankChangedRecently())->toBeFalse()
        ->and($owner->fresh()->phone)->toBe('+97339999999');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Owners`
Expected: FAIL — `Class "App\Actions\Owners\SaveOwner" not found`.

- [ ] **Step 3: Enums, migration, model, factory, policy**

Create `app/Enums/PartyType.php`:
```php
<?php

namespace App\Enums;

enum PartyType: string
{
    case Person = 'person';
    case Company = 'company';
}
```
Create `app/Enums/IdType.php`:
```php
<?php

namespace App\Enums;

enum IdType: string
{
    case Cpr = 'cpr';
    case Passport = 'passport';
    case Cr = 'cr';

    public function label(): string
    {
        return match ($this) {
            self::Cpr => 'CPR',
            self::Passport => 'Passport',
            self::Cr => 'CR',
        };
    }
}
```
Create `database/migrations/2026_10_01_000200_create_owners_table.php`:
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
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name_en', 150);
            $table->string('name_ar', 150)->nullable();
            $table->string('id_type', 20);
            $table->string('id_number', 30);
            $table->string('nationality', 60)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->timestamp('bank_changed_at')->nullable();
            $table->foreignId('bank_changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['id_type', 'id_number']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE owners
                ADD CONSTRAINT owners_type_chk CHECK (type IN ('person', 'company')),
                ADD CONSTRAINT owners_id_type_chk CHECK (id_type IN ('cpr', 'passport', 'cr'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('owners');
    }
};
```
Create `app/Models/Owner.php`:
```php
<?php

namespace App\Models;

use App\Enums\IdType;
use App\Enums\PartyType;
use Database\Factories\OwnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property PartyType $type
 * @property string $name_en
 * @property IdType $id_type
 * @property string $id_number
 * @property string|null $iban
 * @property Carbon|null $bank_changed_at
 * @property int|null $bank_changed_by
 */
class Owner extends Model
{
    /** @use HasFactory<OwnerFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public const array BANK_FIELDS = ['bank_name', 'iban', 'account_name'];

    protected $fillable = [
        'type', 'name_en', 'name_ar', 'id_type', 'id_number', 'nationality', 'phone', 'email', 'address',
        'bank_name', 'iban', 'account_name', 'notes',
    ];

    protected function casts(): array
    {
        return ['type' => PartyType::class, 'id_type' => IdType::class, 'bank_changed_at' => 'datetime'];
    }

    /** Bank fields are audited explicitly with a masked IBAN (SaveOwner), never in full. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept([...self::BANK_FIELDS, 'bank_changed_at', 'bank_changed_by'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<User, $this> */
    public function bankChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bank_changed_by');
    }

    /** Spec §4.4: remittances warn for 30 days after a bank change. */
    public function bankChangedRecently(): bool
    {
        return $this->bank_changed_at !== null && $this->bank_changed_at->greaterThan(now()->subDays(30));
    }

    public function maskedIban(): ?string
    {
        return $this->iban ? '••••'.substr($this->iban, -4) : null;
    }
}
```
Create `database/factories/OwnerFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Owner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Owner>
 */
class OwnerFactory extends Factory
{
    protected $model = Owner::class;

    public function definition(): array
    {
        return [
            'type' => 'person',
            'name_en' => fake()->name(),
            'id_type' => 'cpr',
            'id_number' => (string) fake()->unique()->numerify('#########'),
            'phone' => '+973'.fake()->numerify('3#######'),
        ];
    }
}
```
Create `app/Policies/OwnerPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Owner;
use App\Models\User;

class OwnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::OwnersView);
    }

    public function view(User $user, Owner $owner): bool
    {
        return $user->can(PermissionName::OwnersView);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::OwnersManage);
    }

    public function update(User $user, Owner $owner): bool
    {
        return $user->can(PermissionName::OwnersManage);
    }

    /** Spec §8.1: Finance views, Admin edits. */
    public function viewBank(User $user, Owner $owner): bool
    {
        return $user->can(PermissionName::OwnersBankManage) || $user->can(PermissionName::FinanceView);
    }

    public function updateBank(User $user, Owner $owner): bool
    {
        return $user->can(PermissionName::OwnersBankManage);
    }
}
```

- [ ] **Step 4: The Action**

Create `app/Actions/Owners/SaveOwner.php`:
```php
<?php

namespace App\Actions\Owners;

use App\Audit\Audit;
use App\Enums\IdType;
use App\Enums\PartyType;
use App\Enums\PermissionName;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveOwner
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Owner $owner, array $data): Owner
    {
        if (! ($owner ? $actor->can('update', $owner) : $actor->can('create', Owner::class))) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        if (isset($data['iban']) && is_string($data['iban'])) {
            $data['iban'] = strtoupper(str_replace(' ', '', $data['iban'])) ?: null;
        }

        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(PartyType::class)],
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['required', 'string', 'max:30',
                Rule::unique('owners', 'id_number')->where('id_type', $data['id_type'] ?? null)->ignore($owner?->id)],
            'nationality' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $bankNew = Arr::only($validated, Owner::BANK_FIELDS) + array_fill_keys(Owner::BANK_FIELDS, null);
        $bankOld = $owner ? $owner->only(Owner::BANK_FIELDS) : array_fill_keys(Owner::BANK_FIELDS, null);
        $bankChanged = $bankNew != $bankOld;

        if ($bankChanged && ! $actor->can(PermissionName::OwnersBankManage)) {
            throw new AuthorizationException(__('Only holders of owners.bank.manage can change bank details.'));
        }

        return DB::transaction(function () use ($actor, $owner, $validated, $bankChanged, $bankOld, $bankNew) {
            $owner ??= new Owner;
            $owner->fill($validated);

            if ($bankChanged) {
                $owner->forceFill(['bank_changed_at' => now(), 'bank_changed_by' => $actor->id]);
            }

            $owner->save();

            if ($bankChanged) {
                $mask = fn (?string $iban) => $iban ? '••••'.substr($iban, -4) : null;
                Audit::log('owner.bank.changed', $owner,
                    ['iban' => $mask($bankOld['iban']), 'bank_name' => $bankOld['bank_name']],
                    ['iban' => $mask($bankNew['iban']), 'bank_name' => $bankNew['bank_name']],
                    causer: $actor,
                );
            }

            return $owner;
        });
    }
}
```

- [ ] **Step 5: Screens and routes**

Create `app/Livewire/Owners/Index.php`:
```php
<?php

namespace App\Livewire\Owners;

use App\Models\Owner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Owners')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $owners = Owner::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name_en', 'like', '%'.$this->search.'%')
                ->orWhere('id_number', $this->search)))
            ->orderBy('name_en')
            ->paginate(25);

        return view('livewire.owners.index', ['owners' => $owners]);
    }
}
```
Create `resources/views/livewire/owners/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Owners') }}</flux:heading>
        @can('create', \App\Models\Owner::class)
            <flux:button variant="primary" :href="route('owners.create')" wire:navigate>{{ __('New owner') }}</flux:button>
        @endcan
    </div>

    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name or exact ID number')" icon="magnifying-glass" class="sm:max-w-xs" />

    <div class="overflow-x-auto">
        <flux:table :paginate="$owners">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('ID') }}</flux:table.column>
                <flux:table.column>{{ __('Phone') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($owners as $owner)
                    <flux:table.row :key="$owner->id">
                        <flux:table.cell><flux:link :href="route('owners.edit', $owner)" wire:navigate>{{ $owner->name_en }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $owner->id_type->label() }} {{ $owner->id_number }}</flux:table.cell>
                        <flux:table.cell>{{ $owner->phone }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/Owners/Form.php`:
```php
<?php

namespace App\Livewire\Owners;

use App\Actions\Owners\SaveOwner;
use App\Enums\IdType;
use App\Enums\PartyType;
use App\Livewire\Concerns\WithActor;
use App\Models\Owner;
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
    public ?int $ownerId = null;

    /** @var array<string, mixed> */
    public array $form = ['type' => 'person', 'id_type' => 'cpr'];

    public function mount(?Owner $owner = null): void
    {
        if ($owner?->exists) {
            abort_unless($this->actor()->can('view', $owner), 403);
            $this->ownerId = $owner->id;
            $this->form = [
                ...$owner->only(['name_en', 'name_ar', 'id_number', 'nationality', 'phone', 'email', 'address', 'notes']),
                'type' => $owner->type->value,
                'id_type' => $owner->id_type->value,
            ];
            if ($this->actor()->can('viewBank', $owner)) {
                $this->form += $owner->only(Owner::BANK_FIELDS);
            }
        } else {
            abort_unless($this->actor()->can('create', Owner::class), 403);
        }
    }

    public function save(SaveOwner $save): void
    {
        $existing = $this->ownerId ? Owner::findOrFail($this->ownerId) : null;
        $data = $this->form;

        // Users who cannot see bank details must not wipe them: keep the stored values.
        if ($existing && ! $this->actor()->can('viewBank', $existing)) {
            $data = [...$data, ...$existing->only(Owner::BANK_FIELDS)];
        }

        try {
            $owner = $save->handle($this->actor(), $existing, $data);
        } catch (AuthorizationException $e) {
            $this->addError('form.iban', $e->getMessage() ?: __('This action is not allowed.'));

            return;
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Owner saved.'));
        $this->redirectRoute('owners.edit', $owner, navigate: true);
    }

    public function render(): View
    {
        $owner = $this->ownerId ? Owner::with('bankChanger:id,name')->findOrFail($this->ownerId) : null;

        return view('livewire.owners.form', [
            'owner' => $owner,
            'types' => PartyType::cases(),
            'idTypes' => IdType::cases(),
            'canEdit' => $owner ? $this->actor()->can('update', $owner) : true,
            'canViewBank' => $owner ? $this->actor()->can('viewBank', $owner) : $this->actor()->can('owners.bank.manage'),
            'canEditBank' => $owner ? $this->actor()->can('updateBank', $owner) : $this->actor()->can('owners.bank.manage'),
        ])->title($owner?->name_en ?? __('New owner'));
    }
}
```
Create `resources/views/livewire/owners/form.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $owner?->name_en ?? __('New owner') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-4">
            <flux:select wire:model="form.type" :label="__('Owner type')">
                @foreach ($types as $type)<option value="{{ $type->value }}">{{ str($type->value)->headline() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
            <flux:input wire:model="form.name_ar" :label="__('Name (Arabic)')" dir="rtl" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.id_type" :label="__('ID type')">
                    @foreach ($idTypes as $idType)<option value="{{ $idType->value }}">{{ $idType->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.id_number" :label="__('ID number')" required />
                <flux:input wire:model="form.nationality" :label="__('Nationality')" />
                <flux:input wire:model="form.phone" :label="__('Phone')" type="tel" />
            </div>
            <flux:input wire:model="form.email" :label="__('Email')" type="email" />
            <flux:textarea wire:model="form.address" :label="__('Address')" rows="2" />
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />
        </fieldset>

        @if ($canViewBank)
            <flux:fieldset>
                <flux:legend>{{ __('Bank details') }}</flux:legend>
                @if ($owner?->bankChangedRecently())
                    <flux:callout variant="warning" icon="exclamation-triangle"
                        :heading="__('Bank details changed :date by :user', ['date' => $owner->bank_changed_at->timezone('Asia/Bahrain')->format('d/m/Y'), 'user' => $owner->bankChanger?->name])" />
                @endif
                <fieldset @disabled(! $canEditBank) class="mt-4 space-y-4">
                    <flux:input wire:model="form.bank_name" :label="__('Bank')" />
                    <flux:input wire:model="form.iban" :label="__('IBAN')" />
                    <flux:input wire:model="form.account_name" :label="__('Account name')" />
                </fieldset>
            </flux:fieldset>
        @endif

        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    @if ($owner)
        <livewire:documents.panel :documentable="$owner" :key="'docs-owner-'.$owner->id" />
    @endif
</section>
```
In `app/Livewire/Documents/Panel.php` add `Owner::class` to `ALLOWED` (import `App\Models\Owner`). Owner documents are visible to `owners.view` holders and editable by `owners.manage` holders through `OwnerPolicy::view/update`, which `DocumentPolicy` uses.

In `routes/property.php` add `use App\Livewire\Owners;` and:
```php
    Route::livewire('owners', Owners\Index::class)->middleware('can:owners.view')->name('owners.index');
    Route::livewire('owners/create', Owners\Form::class)->middleware('can:owners.manage')->name('owners.create');
    Route::livewire('owners/{owner}/edit', Owners\Form::class)->middleware('can:owners.view')->name('owners.edit');
```

In `resources/views/layouts/app/sidebar.blade.php`, add inside the Property group:
```blade
                    @can('owners.view')
                        <flux:sidebar.item icon="user-group" :href="route('owners.index')" :current="request()->routeIs('owners.*')" wire:navigate>{{ __('Owners') }}</flux:sidebar.item>
                    @endcan
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Owners
"$PHP" artisan test
```
Expected: 5 new tests pass; full suite passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add owners with controlled bank details

Only owners.bank.manage holders change bank details; each change is
stamped, audited with a masked IBAN, and flagged for 30 days. Finance
sees bank details read-only.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 4: Owner contracts — schema, triggers and drafts

**Spec:** §4.5 (fields, owned = no contract), §8.5 (no DELETE, one-way status, frozen terms, `owner_contract_units` frozen once the parent is not draft), §8.1

**Files:**
- Create: `app/Enums/{OwnerContractType,OwnerContractStatus,PaymentFrequency,FeeType,DepositsHeldBy}.php`, `database/migrations/2026_10_01_000300_create_owner_contracts_tables.php`, `app/Models/OwnerContract.php`, `database/factories/OwnerContractFactory.php`, `app/Policies/OwnerContractPolicy.php`, `app/Actions/OwnerContracts/SaveOwnerContract.php`, `tests/Feature/OwnerContracts/OwnerContractDraftsTest.php`, `tests/Feature/OwnerContracts/OwnerContractTriggersTest.php`
- Modify: `tests/Pest.php`

**Interfaces:**
- Consumes: `Owner`, `Building`, `Unit`, `Fils`, `Audit`
- Produces: `OwnerContract` (`units()`, `owner()`, `building()`, `previous()`, `creator()`, scopes `visibleTo($user)` and `effectiveOn($date)`, `label()`, consts `ATTRIBUTED`, `LEASED_TERMS`, `MANAGED_TERMS`); `OwnerContractStatus` (`Draft`, `PendingApproval`, `Active`, `Ended`, `Terminated`); `OwnerContractType` (`Leased`, `Managed`); `SaveOwnerContract::handle(User $actor, ?OwnerContract $contract, array $data): OwnerContract` where `$data['unit_ids']` is a list of unit ids; `OwnerContractPolicy::viewAny/view/create/update`; test helper `activeOwnerContract(array $attributes, iterable $units): OwnerContract` in `tests/Pest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/OwnerContracts/OwnerContractDraftsTest.php`:
```php
<?php

use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->building = Building::factory()->create();
    $this->units = Unit::factory()->for($this->building)->count(3)->create();
    $this->owner = Owner::factory()->create();
    $this->managed = [
        'owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'type' => 'managed',
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
        'fee_type' => 'percent_collected', 'fee_value' => '7.5', 'expense_approval_limit' => '100',
        'deposits_held_by' => 'company', 'unit_ids' => $this->units->pluck('id')->all(),
    ];
});

test('Finance saves a managed draft with its units', function () {
    $contract = app(SaveOwnerContract::class)->handle($this->finance, null, $this->managed);

    expect($contract->status)->toBe(OwnerContractStatus::Draft)
        ->and($contract->number)->toBeNull()
        ->and($contract->fee_value)->toBe('7.500')
        ->and($contract->rent_amount)->toBeNull()
        ->and($contract->created_by)->toBe($this->finance->id)
        ->and($contract->units()->pluck('units.id')->sort()->values()->all())->toBe($this->units->pluck('id')->sort()->values()->all());
});

test('switching a draft to leased clears the managed terms and the unit list can change', function () {
    $contract = app(SaveOwnerContract::class)->handle($this->finance, null, $this->managed);

    app(SaveOwnerContract::class)->handle($this->finance, $contract, [
        ...$this->managed, 'type' => 'leased', 'rent_amount' => '12000', 'payment_frequency' => 'quarterly',
        'unit_ids' => [$this->units[0]->id],
    ]);

    $contract->refresh();
    expect($contract->fee_type)->toBeNull()
        ->and($contract->deposits_held_by)->toBeNull()
        ->and($contract->rent_amount)->toBe('12000.000')
        ->and($contract->units()->count())->toBe(1);
});

test('units must belong to the building, and at least one is needed', function (array $unitIds) {
    $other = Unit::factory()->create();
    $ids = $unitIds === ['other'] ? [$other->id] : $unitIds;

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'unit_ids' => $ids]))
        ->toThrow(ValidationException::class);
})->with([[['other']], [[]]]);

test('percentage fees cannot exceed 100 and leased rent must be positive', function () {
    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'fee_value' => '101']))
        ->toThrow(ValidationException::class);

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'type' => 'leased', 'rent_amount' => '0', 'payment_frequency' => 'monthly']))
        ->toThrow(ValidationException::class);

    // A fixed fee is BHD per month, so it may exceed 100.
    expect(app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'fee_type' => 'fixed', 'fee_value' => '250'])->fee_value)->toBe('250.000');
});

test('Management and Property managers view contracts but cannot create them; Leasing cannot view', function () {
    $contract = app(SaveOwnerContract::class)->handle($this->finance, null, $this->managed);

    foreach ([RoleName::Management, RoleName::PropertyManager] as $role) {
        $user = User::factory()->create()->assignRole($role);
        expect($user->can('view', $contract))->toBeTrue()
            ->and($user->can('create', OwnerContract::class))->toBeFalse();
        expect(fn () => app(SaveOwnerContract::class)->handle($user, null, $this->managed))->toThrow(AuthorizationException::class);
    }

    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->building->id);
    expect($leasing->can('view', $contract))->toBeFalse();
});

test('only drafts can be edited', function () {
    $active = activeOwnerContract(['owner_id' => $this->owner->id, 'building_id' => $this->building->id], $this->units);

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, $active, $this->managed))
        ->toThrow(ValidationException::class, 'Only draft contracts can be edited.');
});

test('a successor must follow an active contract of the same owner and building, and start after it', function () {
    $active = activeOwnerContract([
        'owner_id' => $this->owner->id, 'building_id' => $this->building->id,
        'start_date' => '2025-11-01', 'end_date' => '2026-12-31',
    ], $this->units);

    $successor = app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'previous_contract_id' => $active->id]);
    expect($successor->previous_contract_id)->toBe($active->id);

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'owner_id' => Owner::factory()->create()->id, 'previous_contract_id' => $active->id]))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->managed, 'start_date' => '2025-10-01', 'previous_contract_id' => $active->id]))
        ->toThrow(ValidationException::class);
});

test('effectiveOn finds the attributed contract covering a date', function () {
    $active = activeOwnerContract([
        'owner_id' => $this->owner->id, 'building_id' => $this->building->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ], [$this->units[0]]);
    app(SaveOwnerContract::class)->handle($this->finance, null, $this->managed); // a draft never counts

    expect(OwnerContract::effectiveOn('2026-06-15')->pluck('id')->all())->toBe([$active->id])
        ->and(OwnerContract::effectiveOn(now()->setDate(2026, 12, 31)->setTime(15, 0))->count())->toBe(1)
        ->and(OwnerContract::effectiveOn('2027-01-01')->count())->toBe(0);
});
```
Create `tests/Feature/OwnerContracts/OwnerContractTriggersTest.php`:
```php
<?php

use App\Models\OwnerContract;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Spec §8.5 — each rule is enforced by MySQL, not only by the Actions.

beforeEach(function () {
    $this->draft = OwnerContract::factory()->create();
    $this->unit = Unit::factory()->create(['building_id' => $this->draft->building_id]);
    $this->spare = Unit::factory()->create(['building_id' => $this->draft->building_id]);
    $this->draft->units()->attach($this->unit->id);
});

function ocRow(OwnerContract $contract): Illuminate\Database\Query\Builder
{
    return DB::table('owner_contracts')->where('id', $contract->id);
}

test('owner contracts can never be deleted, not even drafts', function () {
    expect(fn () => ocRow($this->draft)->delete())->toThrow(QueryException::class, 'owner_contracts cannot be deleted');
});

test('drafts are freely editable, including their units', function () {
    ocRow($this->draft)->update(['fee_value' => '9.000']);
    $this->draft->units()->attach($this->spare->id);
    $this->draft->units()->detach($this->unit->id);

    expect($this->draft->units()->pluck('units.id')->all())->toBe([$this->spare->id]);
});

test('status only moves forward, except a rejection back to draft', function () {
    ocRow($this->draft)->update(['status' => 'pending_approval']);
    ocRow($this->draft)->update(['status' => 'draft']);                          // rejected
    ocRow($this->draft)->update(['status' => 'pending_approval']);
    ocRow($this->draft)->update(['status' => 'active', 'number' => 'OC-2026-000001']);

    expect(fn () => ocRow($this->draft)->update(['status' => 'draft']))->toThrow(QueryException::class, 'status change not allowed');
    expect(fn () => ocRow($this->draft)->update(['status' => 'pending_approval']))->toThrow(QueryException::class, 'status change not allowed');

    ocRow($this->draft)->update(['status' => 'ended']);
    expect(fn () => ocRow($this->draft)->update(['status' => 'active']))->toThrow(QueryException::class, 'status change not allowed');
});

test('a draft cannot jump straight to active', function () {
    expect(fn () => ocRow($this->draft)->update(['status' => 'active', 'number' => 'OC-2026-000009']))->toThrow(QueryException::class, 'status change not allowed');
});

test('terms are frozen once submitted', function () {
    ocRow($this->draft)->update(['status' => 'pending_approval']);

    expect(fn () => ocRow($this->draft)->update(['fee_value' => '9.000']))->toThrow(QueryException::class, 'terms are frozen');
    expect(fn () => ocRow($this->draft)->update(['end_date' => '2030-01-01']))->toThrow(QueryException::class, 'only end_date');
});

test('an active contract may only shorten its end date and set its termination once', function () {
    ocRow($this->draft)->update(['status' => 'pending_approval']);
    ocRow($this->draft)->update(['status' => 'active', 'number' => 'OC-2026-000001']);
    $end = ocRow($this->draft)->value('end_date');

    expect(fn () => ocRow($this->draft)->update(['end_date' => '2099-01-01']))->toThrow(QueryException::class, 'only end_date');
    expect(fn () => ocRow($this->draft)->update(['number' => 'OC-2026-000002']))->toThrow(QueryException::class, 'terms are frozen');

    $shorter = now()->parse($end)->subMonth()->toDateString();
    ocRow($this->draft)->update(['end_date' => $shorter, 'terminated_on' => $shorter, 'termination_reason' => 'Sold']);

    expect(fn () => ocRow($this->draft)->update(['terminated_on' => now()->parse($shorter)->subDay()->toDateString()]))->toThrow(QueryException::class, 'only end_date');
});

test('contract units are frozen once the contract is not draft', function () {
    ocRow($this->draft)->update(['status' => 'pending_approval']);

    expect(fn () => $this->draft->units()->attach($this->spare->id))->toThrow(QueryException::class, 'owner_contract_units are frozen');
    expect(fn () => $this->draft->units()->detach($this->unit->id))->toThrow(QueryException::class, 'owner_contract_units are frozen');
    expect(fn () => DB::table('owner_contract_units')->update(['unit_id' => $this->spare->id]))->toThrow(QueryException::class, 'owner_contract_units cannot be updated');
});

test('type-specific terms are enforced by CHECK constraints', function () {
    expect(fn () => ocRow($this->draft)->update(['rent_amount' => '100.000']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/OwnerContracts`
Expected: FAIL — `Class "App\Models\OwnerContract" not found`.

- [ ] **Step 3: Enums**

Create `app/Enums/OwnerContractType.php`:
```php
<?php

namespace App\Enums;

enum OwnerContractType: string
{
    case Leased = 'leased';   // the company rents the property from the owner
    case Managed = 'managed'; // the company manages it for the owner
}
```
Create `app/Enums/OwnerContractStatus.php`:
```php
<?php

namespace App\Enums;

enum OwnerContractStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Ended = 'ended';
    case Terminated = 'terminated';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
```
Create `app/Enums/PaymentFrequency.php`:
```php
<?php

namespace App\Enums;

enum PaymentFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';
}
```
Create `app/Enums/FeeType.php`:
```php
<?php

namespace App\Enums;

enum FeeType: string
{
    case PercentCollected = 'percent_collected';
    case PercentBilled = 'percent_billed';
    case Fixed = 'fixed'; // BHD per month
}
```
Create `app/Enums/DepositsHeldBy.php`:
```php
<?php

namespace App\Enums;

enum DepositsHeldBy: string
{
    case Company = 'company';
    case Owner = 'owner';
}
```

- [ ] **Step 4: Migration with CHECKs and triggers**

Create `database/migrations/2026_10_01_000300_create_owner_contracts_tables.php`:
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
        Schema::create('owner_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('owner_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft');
            $table->date('terminated_on')->nullable();
            $table->string('termination_reason', 500)->nullable();
            $table->foreignId('previous_contract_id')->nullable()->constrained('owner_contracts')->restrictOnDelete();
            $table->decimal('rent_amount', 12, 3)->nullable();
            $table->string('payment_frequency', 20)->nullable();
            $table->string('fee_type', 20)->nullable();
            $table->decimal('fee_value', 12, 3)->nullable();
            $table->decimal('expense_approval_limit', 12, 3)->nullable();
            $table->string('deposits_held_by', 20)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'end_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE owner_contracts
                ADD CONSTRAINT oc_type_chk CHECK (type IN ('leased', 'managed')),
                ADD CONSTRAINT oc_status_chk CHECK (status IN ('draft', 'pending_approval', 'active', 'ended', 'terminated')),
                ADD CONSTRAINT oc_dates_chk CHECK (end_date >= start_date),
                ADD CONSTRAINT oc_number_chk CHECK (status IN ('draft', 'pending_approval') OR number IS NOT NULL),
                ADD CONSTRAINT oc_termination_chk CHECK ((terminated_on IS NULL) = (termination_reason IS NULL)),
                ADD CONSTRAINT oc_terminated_chk CHECK (status <> 'terminated' OR terminated_on IS NOT NULL),
                ADD CONSTRAINT oc_terms_chk CHECK (
                    (type = 'leased' AND rent_amount > 0
                        AND payment_frequency IN ('monthly', 'quarterly', 'half_yearly', 'yearly')
                        AND fee_type IS NULL AND fee_value IS NULL AND expense_approval_limit IS NULL AND deposits_held_by IS NULL)
                    OR (type = 'managed' AND fee_type IN ('percent_collected', 'percent_billed', 'fixed')
                        AND fee_value >= 0 AND (fee_type = 'fixed' OR fee_value <= 100)
                        AND deposits_held_by IN ('company', 'owner')
                        AND (expense_approval_limit IS NULL OR expense_approval_limit >= 0)
                        AND rent_amount IS NULL AND payment_frequency IS NULL)
                )
            SQL);

        Schema::create('owner_contract_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unique(['owner_contract_id', 'unit_id']);
        });

        // Spec §8.5. One statement per unprepared() call.
        DB::unprepared("CREATE TRIGGER owner_contracts_no_delete BEFORE DELETE ON owner_contracts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contracts cannot be deleted'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER owner_contracts_guard BEFORE UPDATE ON owner_contracts FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'active'))
                    OR (OLD.status = 'active' AND NEW.status IN ('ended', 'terminated'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contracts: status change not allowed';
                END IF;

                IF OLD.status <> 'draft' AND NOT (
                    NEW.owner_id <=> OLD.owner_id AND NEW.building_id <=> OLD.building_id AND NEW.type <=> OLD.type
                    AND NEW.start_date <=> OLD.start_date AND NEW.previous_contract_id <=> OLD.previous_contract_id
                    AND NEW.rent_amount <=> OLD.rent_amount AND NEW.payment_frequency <=> OLD.payment_frequency
                    AND NEW.fee_type <=> OLD.fee_type AND NEW.fee_value <=> OLD.fee_value
                    AND NEW.expense_approval_limit <=> OLD.expense_approval_limit AND NEW.deposits_held_by <=> OLD.deposits_held_by
                    AND NEW.created_by <=> OLD.created_by
                    AND (OLD.number IS NULL OR NEW.number <=> OLD.number)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contracts: terms are frozen once submitted';
                END IF;

                IF (OLD.status IN ('pending_approval', 'ended', 'terminated')
                        AND NOT (NEW.end_date <=> OLD.end_date AND NEW.terminated_on <=> OLD.terminated_on AND NEW.termination_reason <=> OLD.termination_reason))
                    OR (OLD.status = 'active' AND (NEW.end_date > OLD.end_date
                        OR (OLD.terminated_on IS NOT NULL AND NOT (NEW.terminated_on <=> OLD.terminated_on AND NEW.termination_reason <=> OLD.termination_reason)))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contracts: only end_date (earlier) and terminated_on (once) may change';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER owner_contract_units_insert BEFORE INSERT ON owner_contract_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM owner_contracts WHERE id = NEW.owner_contract_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contract_units are frozen once the contract is submitted';
                END IF;
            END
            SQL);

        DB::unprepared("CREATE TRIGGER owner_contract_units_no_update BEFORE UPDATE ON owner_contract_units FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contract_units cannot be updated'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER owner_contract_units_delete BEFORE DELETE ON owner_contract_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM owner_contracts WHERE id = OLD.owner_contract_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contract_units are frozen once the contract is submitted';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        foreach (['owner_contract_units_delete', 'owner_contract_units_no_update', 'owner_contract_units_insert', 'owner_contracts_guard', 'owner_contracts_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('owner_contract_units');
        Schema::dropIfExists('owner_contracts');
    }
};
```

- [ ] **Step 5: Model, factory, policy, test helper**

Create `app/Models/OwnerContract.php`:
```php
<?php

namespace App\Models;

use App\Enums\DepositsHeldBy;
use App\Enums\FeeType;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\PaymentFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OwnerContractFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string|null $number
 * @property int $owner_id
 * @property int $building_id
 * @property OwnerContractType $type
 * @property OwnerContractStatus $status
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable|null $terminated_on
 * @property string|null $termination_reason
 * @property int|null $previous_contract_id
 * @property string|null $rent_amount
 * @property PaymentFrequency|null $payment_frequency
 * @property FeeType|null $fee_type
 * @property string|null $fee_value
 * @property string|null $expense_approval_limit
 * @property DepositsHeldBy|null $deposits_held_by
 * @property int $created_by
 */
class OwnerContract extends Model
{
    /** @use HasFactory<OwnerContractFactory> */
    use HasFactory, LogsActivity;

    /** Statuses whose dates attribute income and expenses to the owner (spec §4.5, §4.6). */
    public const array ATTRIBUTED = [OwnerContractStatus::Active, OwnerContractStatus::Ended, OwnerContractStatus::Terminated];

    public const array LEASED_TERMS = ['rent_amount', 'payment_frequency'];

    public const array MANAGED_TERMS = ['fee_type', 'fee_value', 'expense_approval_limit', 'deposits_held_by'];

    protected $fillable = [
        'owner_id', 'building_id', 'type', 'start_date', 'end_date', 'previous_contract_id',
        ...self::LEASED_TERMS, ...self::MANAGED_TERMS, 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'type' => OwnerContractType::class,
            'status' => OwnerContractStatus::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'terminated_on' => 'immutable_date',
            'rent_amount' => 'decimal:3',
            'payment_frequency' => PaymentFrequency::class,
            'fee_type' => FeeType::class,
            'fee_value' => 'decimal:3',
            'expense_approval_limit' => 'decimal:3',
            'deposits_held_by' => DepositsHeldBy::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Owner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_contract_id');
    }

    /** @return BelongsToMany<Unit, $this> */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'owner_contract_units');
    }

    /** @param  Builder<OwnerContract>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('building', fn (Builder $q) => $q->visibleTo($user));
    }

    /**
     * Attributed contracts whose dates cover $date. Always compares DATE to a Y-m-d string:
     * a DATE compared to a datetime string drops the last day after midnight.
     *
     * @param  Builder<OwnerContract>  $query
     */
    #[Scope]
    protected function effectiveOn(Builder $query, CarbonInterface|string $date): void
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        $query->whereIn($query->qualifyColumn('status'), array_map(fn (OwnerContractStatus $s) => $s->value, self::ATTRIBUTED))
            ->where($query->qualifyColumn('start_date'), '<=', $day)
            ->where($query->qualifyColumn('end_date'), '>=', $day);
    }

    public function label(): string
    {
        return $this->number ?? __('Draft #:id', ['id' => $this->id]);
    }
}
```
Create `database/factories/OwnerContractFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Always a draft: go through the allowed status steps (activeOwnerContract() in tests/Pest.php) for others.
 *
 * @extends Factory<OwnerContract>
 */
class OwnerContractFactory extends Factory
{
    protected $model = OwnerContract::class;

    public function definition(): array
    {
        return [
            'owner_id' => Owner::factory(),
            'building_id' => Building::factory(),
            'type' => 'managed',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->startOfMonth()->addYear()->subDay()->toDateString(),
            'fee_type' => 'percent_collected',
            'fee_value' => '5.000',
            'deposits_held_by' => 'company',
            'created_by' => User::factory(),
        ];
    }

    public function leased(string $rent = '1000.000'): static
    {
        return $this->state([
            'type' => 'leased', 'rent_amount' => $rent, 'payment_frequency' => 'monthly',
            'fee_type' => null, 'fee_value' => null, 'expense_approval_limit' => null, 'deposits_held_by' => null,
        ]);
    }
}
```
`created_by` is not fillable, but factories bypass `$fillable` (they use `forceFill` semantics through `Model::unguarded`), so the state works.

Create `app/Policies/OwnerContractPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Building;
use App\Models\OwnerContract;
use App\Models\User;

/** Spec §8.1 (owners.view / owners.manage) within the building scope of §8.2. */
class OwnerContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::OwnersView);
    }

    public function view(User $user, OwnerContract $contract): bool
    {
        return $user->can(PermissionName::OwnersView) && self::inScope($user, $contract);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::OwnersManage);
    }

    public function update(User $user, OwnerContract $contract): bool
    {
        return $user->can(PermissionName::OwnersManage) && self::inScope($user, $contract);
    }

    private static function inScope(User $user, OwnerContract $contract): bool
    {
        return Building::visibleTo($user)->whereKey($contract->building_id)->exists();
    }
}
```
In `tests/Pest.php` replace the example `function something() { … }` with:
```php
/** An active owner contract with units, walked through the status steps the triggers allow. */
function activeOwnerContract(array $attributes, iterable $units): App\Models\OwnerContract
{
    $contract = App\Models\OwnerContract::factory()->create($attributes);
    $contract->units()->attach(collect($units)->map(fn ($unit) => is_int($unit) ? $unit : $unit->id)->all());
    $contract->forceFill(['status' => 'pending_approval'])->save();
    $contract->forceFill(['status' => 'active', 'number' => 'OC-TEST-'.$contract->id])->save();

    return $contract->fresh();
}
```

- [ ] **Step 6: The draft Action**

Create `app/Actions/OwnerContracts/SaveOwnerContract.php`:
```php
<?php

namespace App\Actions\OwnerContracts;

use App\Audit\Audit;
use App\Enums\DepositsHeldBy;
use App\Enums\FeeType;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\PaymentFrequency;
use App\Models\Building;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Creates or edits a DRAFT owner contract (spec §4.5). Submitted contracts are frozen. */
final class SaveOwnerContract
{
    /** @param  array<string, mixed>  $data  including unit_ids: list<int> */
    public function handle(User $actor, ?OwnerContract $contract, array $data): OwnerContract
    {
        if (! ($contract ? $actor->can('update', $contract) : $actor->can('create', OwnerContract::class))) {
            throw new AuthorizationException;
        }

        if ($contract && $contract->status !== OwnerContractStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('Only draft contracts can be edited.')]);
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        $validated = Validator::make($data, [
            'owner_id' => ['required', 'integer', Rule::exists('owners', 'id')->whereNull('deleted_at')],
            'building_id' => ['required', 'integer', Rule::exists('buildings', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::enum(OwnerContractType::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'previous_contract_id' => ['nullable', 'integer'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'distinct'],
            'rent_amount' => ['exclude_unless:type,leased', 'required', Fils::rule()],
            'payment_frequency' => ['exclude_unless:type,leased', 'required', Rule::enum(PaymentFrequency::class)],
            'fee_type' => ['exclude_unless:type,managed', 'required', Rule::enum(FeeType::class)],
            'fee_value' => ['exclude_unless:type,managed', 'required', Fils::rule()],
            'expense_approval_limit' => ['exclude_unless:type,managed', 'nullable', Fils::rule()],
            'deposits_held_by' => ['exclude_unless:type,managed', 'required', Rule::enum(DepositsHeldBy::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->after(fn (ValidatorInstance $validator) => $this->checkTerms($validator, $data, $contract))->validate();

        if (! Building::visibleTo($actor)->whereKey($validated['building_id'])->exists()) {
            throw new AuthorizationException;
        }

        $unitIds = array_map(intval(...), $validated['unit_ids']);
        unset($validated['unit_ids']);

        foreach (['rent_amount', 'fee_value', 'expense_approval_limit'] as $money) {
            if (isset($validated[$money])) {
                $validated[$money] = Fils::toDecimal(Fils::fromDecimal((string) $validated[$money]));
            }
        }

        return DB::transaction(function () use ($actor, $contract, $validated, $unitIds) {
            $contract ??= (new OwnerContract)->forceFill(['created_by' => $actor->id]);

            // Clear the other type's terms, and optional fields left out of $data.
            $contract->fill([
                ...array_fill_keys([...OwnerContract::LEASED_TERMS, ...OwnerContract::MANAGED_TERMS], null),
                'previous_contract_id' => null,
                'notes' => null,
                ...$validated,
            ])->save();

            $changes = $contract->units()->sync($unitIds);

            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                Audit::log('owner_contract.units_changed', $contract,
                    ['detached' => array_values($changes['detached'])],
                    ['attached' => array_values($changes['attached'])],
                    causer: $actor,
                );
            }

            return $contract;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function checkTerms(ValidatorInstance $validator, array $data, ?OwnerContract $contract): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $unitIds = array_map(intval(...), (array) $data['unit_ids']);
        if (Unit::query()->where('building_id', $data['building_id'])->whereKey($unitIds)->count() !== count($unitIds)) {
            $validator->errors()->add('unit_ids', __('Every unit must belong to the chosen building.'));
        }

        if ($data['type'] === OwnerContractType::Leased->value && Fils::fromDecimal((string) $data['rent_amount']) === 0) {
            $validator->errors()->add('rent_amount', __('Rent must be more than zero.'));
        }

        if ($data['type'] === OwnerContractType::Managed->value && $data['fee_type'] !== FeeType::Fixed->value
            && Fils::fromDecimal((string) $data['fee_value']) > 100_000) {
            $validator->errors()->add('fee_value', __('A percentage fee cannot exceed 100.'));
        }

        if (filled($data['previous_contract_id'] ?? null)) {
            $previous = OwnerContract::find($data['previous_contract_id']);

            if (! $previous
                || $previous->id === $contract?->id
                || $previous->status !== OwnerContractStatus::Active
                || $previous->owner_id !== (int) $data['owner_id']
                || $previous->building_id !== (int) $data['building_id']
                || $data['start_date'] <= $previous->start_date->toDateString()) {
                $validator->errors()->add('previous_contract_id', __('A successor must follow an active contract of the same owner and building, and start after it.'));
            }
        }
    }
}
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/OwnerContracts
"$PHP" artisan test
```
Expected: every test in `tests/Feature/OwnerContracts` passes; full suite passes.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add owner contracts as drafts, guarded by triggers

Leased and managed contracts link one or many units. MySQL refuses
deletes, backward status moves, edits to submitted terms, and unit
changes once a contract leaves draft.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 5: Owner contract screens

**Spec:** §4.5, §8.1, §8.2, §9.1, §2 (375 px)

**Files:**
- Create: `app/Livewire/OwnerContracts/{Index,Form,Show}.php`, `resources/views/livewire/owner-contracts/{index,form,show}.blade.php`, `tests/Feature/OwnerContracts/OwnerContractScreensTest.php`
- Modify: `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`, `app/Livewire/Documents/Panel.php`

**Interfaces:**
- Consumes: `SaveOwnerContract`, `OwnerContractPolicy`, `OwnerContract::visibleTo`, `WithActor`, `documents.panel`
- Produces: routes `owner-contracts.index`, `owner-contracts.create` (accepts `?previous={id}` to start a successor), `owner-contracts.edit`, `owner-contracts.show`; `OwnerContracts\Show` is where Tasks 6 and 7 add their buttons (Submit, Request early termination)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OwnerContracts/OwnerContractScreensTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\OwnerContracts\Form;
use App\Livewire\OwnerContracts\Index;
use App\Livewire\OwnerContracts\Show;
use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->building = Building::factory()->create(['name' => 'Seef Heights']);
    $this->units = Unit::factory()->for($this->building)->count(2)->create();
    $this->owner = Owner::factory()->create(['name_en' => 'Fatima Saleh']);
});

test('Finance creates a draft from the form, picking all units at once', function () {
    Livewire::actingAs($this->finance)->test(Form::class)
        ->set('form.owner_id', $this->owner->id)
        ->set('form.building_id', $this->building->id)
        ->call('selectAllUnits')
        ->set('form.type', 'leased')
        ->set('form.start_date', '2026-11-01')
        ->set('form.end_date', '2027-10-31')
        ->set('form.rent_amount', '15000')
        ->set('form.payment_frequency', 'yearly')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $contract = OwnerContract::sole();
    expect($contract->units()->count())->toBe(2)->and($contract->rent_amount)->toBe('15000.000');
});

test('validation errors land on the form fields', function () {
    Livewire::actingAs($this->finance)->test(Form::class)
        ->set('form.building_id', $this->building->id)
        ->set('form.type', 'managed')
        ->call('save')
        ->assertHasErrors(['form.owner_id', 'form.start_date', 'form.unit_ids', 'form.fee_value']);
});

test('a successor form is prefilled from its predecessor', function () {
    $active = activeOwnerContract(['owner_id' => $this->owner->id, 'building_id' => $this->building->id], $this->units);

    Livewire::actingAs($this->finance)->withQueryParams(['previous' => $active->id])->test(Form::class)
        ->assertSet('form.previous_contract_id', $active->id)
        ->assertSet('form.owner_id', $active->owner_id)
        ->assertSet('form.unit_ids', $this->units->pluck('id')->all());
});

test('the show page lists the contract, and only drafts offer Edit', function () {
    $draft = OwnerContract::factory()->create(['owner_id' => $this->owner->id, 'building_id' => $this->building->id]);
    $active = activeOwnerContract(['owner_id' => $this->owner->id, 'building_id' => $this->building->id], $this->units);

    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $draft])->assertSee('Fatima Saleh')->assertSee(route('owner-contracts.edit', $draft));
    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $active])->assertSee($active->number)->assertDontSee(route('owner-contracts.edit', $active));

    $this->actingAs($this->finance)->get(route('owner-contracts.edit', $active))->assertForbidden();
});

test('lists and pages follow owners.view and the building scope', function () {
    $contract = OwnerContract::factory()->create(['owner_id' => $this->owner->id, 'building_id' => $this->building->id]);
    OwnerContract::factory()->create(['building_id' => Building::factory()->create(['name' => 'Juffair Point'])]);

    Livewire::actingAs($this->finance)->test(Index::class)->assertSee('Seef Heights')->assertSee('Juffair Point');

    $pm = User::factory()->create()->assignRole(RoleName::PropertyManager);
    $this->actingAs($pm)->get(route('owner-contracts.show', $contract))->assertOk();
    $this->actingAs($pm)->get(route('owner-contracts.create'))->assertForbidden();

    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->building->id);
    $this->actingAs($leasing)->get(route('owner-contracts.index'))->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/OwnerContracts/OwnerContractScreensTest.php`
Expected: FAIL — `Class "App\Livewire\OwnerContracts\Form" not found`.

- [ ] **Step 3: The list**

Create `app/Livewire/OwnerContracts/Index.php`:
```php
<?php

namespace App\Livewire\OwnerContracts;

use App\Enums\OwnerContractStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\OwnerContract;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Owner contracts')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $contracts = OwnerContract::query()
            ->visibleTo($this->actor())
            ->with(['owner:id,name_en', 'building:id,code,name'])
            ->withCount('units')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('owner', fn ($o) => $o->where('name_en', 'like', '%'.$this->search.'%'))))
            ->latest('id')
            ->paginate(25);

        return view('livewire.owner-contracts.index', [
            'contracts' => $contracts,
            'statuses' => OwnerContractStatus::cases(),
        ]);
    }
}
```
Create `resources/views/livewire/owner-contracts/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Owner contracts') }}</flux:heading>
        @can('create', \App\Models\OwnerContract::class)
            <flux:button variant="primary" :href="route('owner-contracts.create')" wire:navigate>{{ __('New contract') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="status" class="sm:max-w-48">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number or owner')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$contracts">
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column>{{ __('Building') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Dates') }}</flux:table.column>
                <flux:table.column>{{ __('Units') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($contracts as $contract)
                    <flux:table.row :key="$contract->id">
                        <flux:table.cell><flux:link :href="route('owner-contracts.show', $contract)" wire:navigate>{{ $contract->label() }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $contract->owner->name_en }}</flux:table.cell>
                        <flux:table.cell>{{ $contract->building->name }}</flux:table.cell>
                        <flux:table.cell>{{ str($contract->type->value)->headline() }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $contract->start_date->format('d/m/Y') }} – {{ $contract->end_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $contract->units_count }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $contract->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```

- [ ] **Step 4: The draft form**

Create `app/Livewire/OwnerContracts/Form.php`:
```php
<?php

namespace App\Livewire\OwnerContracts;

use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Enums\DepositsHeldBy;
use App\Enums\FeeType;
use App\Enums\OwnerContractStatus;
use App\Enums\PaymentFrequency;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
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
    public ?int $contractId = null;

    /** @var array<string, mixed> */
    public array $form = ['type' => 'managed', 'fee_type' => 'percent_collected', 'deposits_held_by' => 'company', 'payment_frequency' => 'monthly', 'unit_ids' => []];

    public function mount(?OwnerContract $contract = null): void
    {
        if ($contract?->exists) {
            abort_unless($this->actor()->can('update', $contract) && $contract->status === OwnerContractStatus::Draft, 403);
            $this->contractId = $contract->id;
            $this->form = $this->fromContract($contract);

            return;
        }

        abort_unless($this->actor()->can('create', OwnerContract::class), 403);

        $previousId = request()->integer('previous');

        if ($previousId && ($prev = OwnerContract::find($previousId)) && $this->actor()->can('view', $prev)) {
            // A successor starts from its predecessor's terms (spec §4.5: rent or fee changes and renewals).
            $this->form = [
                ...$this->fromContract($prev),
                'previous_contract_id' => $prev->id,
                'start_date' => $prev->end_date->addDay()->toDateString(),
                'end_date' => $prev->end_date->addYear()->toDateString(),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function fromContract(OwnerContract $contract): array
    {
        return [
            ...$contract->only(['owner_id', 'building_id', 'previous_contract_id', 'rent_amount', 'fee_value', 'expense_approval_limit', 'notes']),
            'type' => $contract->type->value,
            'start_date' => $contract->start_date->toDateString(),
            'end_date' => $contract->end_date->toDateString(),
            'payment_frequency' => $contract->payment_frequency?->value ?? 'monthly',
            'fee_type' => $contract->fee_type?->value ?? 'percent_collected',
            'deposits_held_by' => $contract->deposits_held_by?->value ?? 'company',
            'unit_ids' => $contract->units()->orderBy('units.id')->pluck('units.id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    public function updatedForm(mixed $value, string $key): void
    {
        if ($key === 'building_id') {
            $this->form['unit_ids'] = [];
        }
    }

    public function selectAllUnits(): void
    {
        $this->form['unit_ids'] = Unit::query()->where('building_id', $this->form['building_id'] ?? 0)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function save(SaveOwnerContract $save): void
    {
        $data = [...$this->form, 'unit_ids' => array_map(intval(...), (array) ($this->form['unit_ids'] ?? []))];

        try {
            $contract = $save->handle($this->actor(), $this->contractId ? OwnerContract::findOrFail($this->contractId) : null, $data);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['form.'.explode('.', $k)[0] => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Contract saved as draft.'));
        $this->redirectRoute('owner-contracts.show', $contract, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.owner-contracts.form', [
            'owners' => Owner::query()->orderBy('name_en')->get(['id', 'name_en']),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
            'units' => Unit::query()->where('building_id', $this->form['building_id'] ?? 0)->orderBy('code')->get(['id', 'code', 'floor']),
            'predecessors' => OwnerContract::query()->visibleTo($this->actor())->where('status', OwnerContractStatus::Active)->orderBy('number')->get(['id', 'number']),
            'frequencies' => PaymentFrequency::cases(),
            'feeTypes' => FeeType::cases(),
            'holders' => DepositsHeldBy::cases(),
        ])->title($this->contractId ? __('Edit draft contract') : __('New owner contract'));
    }
}
```
Create `resources/views/livewire/owner-contracts/form.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $contractId ? __('Edit draft contract') : __('New owner contract') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <flux:select wire:model="form.owner_id" :label="__('Owner')">
            <option value="">{{ __('Choose…') }}</option>
            @foreach ($owners as $owner)<option value="{{ $owner->id }}">{{ $owner->name_en }}</option>@endforeach
        </flux:select>

        <flux:select wire:model.live="form.building_id" :label="__('Building')">
            <option value="">{{ __('Choose…') }}</option>
            @foreach ($buildings as $building)<option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>@endforeach
        </flux:select>

        <flux:radio.group wire:model.live="form.type" :label="__('Arrangement')" variant="segmented">
            <flux:radio value="managed" :label="__('Managed for the owner')" />
            <flux:radio value="leased" :label="__('Leased from the owner')" />
        </flux:radio.group>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.start_date" type="date" :label="__('Start date')" />
            <flux:input wire:model="form.end_date" type="date" :label="__('End date')" />
        </div>

        @if (($form['type'] ?? '') === 'leased')
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.rent_amount" inputmode="decimal" :label="__('Rent per payment period (BHD)')" />
                <flux:select wire:model="form.payment_frequency" :label="__('Paid')">
                    @foreach ($frequencies as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                </flux:select>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.fee_type" :label="__('Management fee')">
                    @foreach ($feeTypes as $f)<option value="{{ $f->value }}">{{ str($f->value)->headline() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.fee_value" inputmode="decimal" :label="__('Fee (% or BHD per month)')" />
                <flux:input wire:model="form.expense_approval_limit" inputmode="decimal" :label="__('Owner approval needed above (BHD)')" />
                <flux:select wire:model="form.deposits_held_by" :label="__('Tenant deposits held by')">
                    @foreach ($holders as $h)<option value="{{ $h->value }}">{{ str($h->value)->headline() }}</option>@endforeach
                </flux:select>
            </div>
        @endif

        <flux:fieldset>
            <div class="flex items-center justify-between">
                <flux:legend>{{ __('Units') }}</flux:legend>
                @if ($units->isNotEmpty())
                    <flux:button size="sm" variant="ghost" wire:click="selectAllUnits">{{ __('Whole building') }}</flux:button>
                @endif
            </div>
            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @forelse ($units as $unit)
                    <flux:checkbox wire:model="form.unit_ids" :value="$unit->id" :label="$unit->code" />
                @empty
                    <flux:text>{{ __('Choose a building first.') }}</flux:text>
                @endforelse
            </div>
            <flux:error name="form.unit_ids" />
        </flux:fieldset>

        <flux:select wire:model="form.previous_contract_id" :label="__('Replaces contract (successor)')">
            <option value="">{{ __('None') }}</option>
            @foreach ($predecessors as $p)<option value="{{ $p->id }}">{{ $p->number }}</option>@endforeach
        </flux:select>

        <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="2" />

        <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
    </form>
</section>
```

- [ ] **Step 5: The show page**

Create `app/Livewire/OwnerContracts/Show.php`:
```php
<?php

namespace App\Livewire\OwnerContracts;

use App\Livewire\Concerns\WithActor;
use App\Models\OwnerContract;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $contractId;

    public function mount(OwnerContract $contract): void
    {
        abort_unless($this->actor()->can('view', $contract), 403);
        $this->contractId = $contract->id;
    }

    protected function contract(): OwnerContract
    {
        return OwnerContract::with(['owner:id,name_en', 'building:id,code,name', 'units:id,code', 'previous:id,number', 'creator:id,name'])
            ->findOrFail($this->contractId);
    }

    public function render(): View
    {
        $contract = $this->contract();

        return view('livewire.owner-contracts.show', [
            'contract' => $contract,
            'canManage' => $this->actor()->can('update', $contract),
        ])->title($contract->label());
    }
}
```
Create `resources/views/livewire/owner-contracts/show.blade.php`:
```blade
<section class="w-full max-w-3xl space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $contract->label() }}</flux:heading>
            <flux:badge>{{ $contract->status->label() }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Draft)
                <flux:button :href="route('owner-contracts.edit', $contract)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endif
            @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Active)
                <flux:button :href="route('owner-contracts.create', ['previous' => $contract->id])" wire:navigate>{{ __('New successor') }}</flux:button>
            @endif
            {{-- Task 6 adds Submit; Task 7 adds Request early termination. --}}
        </div>
    </div>

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Owner') }}</dt><dd>{{ $contract->owner->name_en }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Building') }}</dt><dd>{{ $contract->building->code }} — {{ $contract->building->name }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Arrangement') }}</dt><dd>{{ str($contract->type->value)->headline() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Dates') }}</dt><dd>{{ $contract->start_date->format('d/m/Y') }} – {{ $contract->end_date->format('d/m/Y') }}</dd></div>
        @if ($contract->type === \App\Enums\OwnerContractType::Leased)
            <div><dt class="text-sm text-zinc-500">{{ __('Rent') }}</dt><dd class="tabular-nums">{{ $contract->rent_amount }} BHD, {{ str($contract->payment_frequency?->value)->headline() }}</dd></div>
        @else
            <div><dt class="text-sm text-zinc-500">{{ __('Fee') }}</dt><dd class="tabular-nums">{{ $contract->fee_value }} ({{ str($contract->fee_type?->value)->headline() }})</dd></div>
            <div><dt class="text-sm text-zinc-500">{{ __('Owner approval above') }}</dt><dd class="tabular-nums">{{ $contract->expense_approval_limit ?? __('No limit') }}</dd></div>
            <div><dt class="text-sm text-zinc-500">{{ __('Deposits held by') }}</dt><dd>{{ str($contract->deposits_held_by?->value)->headline() }}</dd></div>
        @endif
        @if ($contract->previous)
            <div><dt class="text-sm text-zinc-500">{{ __('Replaces') }}</dt><dd><flux:link :href="route('owner-contracts.show', $contract->previous_contract_id)" wire:navigate>{{ $contract->previous->number }}</flux:link></dd></div>
        @endif
        @if ($contract->terminated_on)
            <div><dt class="text-sm text-zinc-500">{{ __('Terminated on') }}</dt><dd>{{ $contract->terminated_on->format('d/m/Y') }} — {{ $contract->termination_reason }}</dd></div>
        @endif
        <div><dt class="text-sm text-zinc-500">{{ __('Created by') }}</dt><dd>{{ $contract->creator->name }}</dd></div>
    </dl>

    <div>
        <flux:heading size="lg">{{ __('Units (:count)', ['count' => $contract->units->count()]) }}</flux:heading>
        <p class="mt-2 flex flex-wrap gap-2">
            @foreach ($contract->units as $unit)<flux:badge size="sm">{{ $unit->code }}</flux:badge>@endforeach
        </p>
    </div>

    <livewire:documents.panel :documentable="$contract" :key="'docs-oc-'.$contract->id" />
</section>
```
In `app/Livewire/Documents/Panel.php` add `OwnerContract::class` to `ALLOWED` (import `App\Models\OwnerContract`).

- [ ] **Step 6: Routes and navigation**

In `routes/property.php` add `use App\Livewire\OwnerContracts;` and inside the group:
```php
    Route::livewire('owner-contracts', OwnerContracts\Index::class)->middleware('can:owners.view')->name('owner-contracts.index');
    Route::livewire('owner-contracts/create', OwnerContracts\Form::class)->middleware('can:owners.manage')->name('owner-contracts.create');
    Route::livewire('owner-contracts/{contract}/edit', OwnerContracts\Form::class)->middleware('can:owners.manage')->name('owner-contracts.edit');
    Route::livewire('owner-contracts/{contract}', OwnerContracts\Show::class)->middleware('can:owners.view')->name('owner-contracts.show');
```
In `resources/views/layouts/app/sidebar.blade.php`, add inside the Property group:
```blade
                    @can('owners.view')
                        <flux:sidebar.item icon="document-text" :href="route('owner-contracts.index')" :current="request()->routeIs('owner-contracts.*')" wire:navigate>{{ __('Owner contracts') }}</flux:sidebar.item>
                    @endcan
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan test tests/Feature/OwnerContracts
"$PHP" artisan test
```
Expected: 5 new tests pass; full suite passes. Open `/owner-contracts/create` in the preview at 375 px and check the unit grid wraps without horizontal scroll.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add owner contract screens

List, draft form (whole-building shortcut, successor prefill) and a
detail page with documents. Only drafts can be edited.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 6: The approvals engine, with owner-contract activation as its first user

**Spec:** §8.3 (table, rules, "Pending my approval", emails), §4.5 (overlap rule with locking, successors, number on activation), §8.5 (`approvals` immutable once decided), §14 flows 2 and 13

**Files:**
- Create: `app/Enums/{ApprovalAction,ApprovalStatus}.php`, `database/migrations/2026_10_01_000400_create_approvals_table.php`, `app/Models/Approval.php`, `app/Approvals/ApprovalHandler.php`, `app/Approvals/OwnerContractActivation.php`, `app/Actions/Approvals/{RequestApproval,DecideApproval}.php`, `app/Actions/OwnerContracts/{EnsureNoOverlap,SubmitOwnerContract,ActivateOwnerContract}.php`, `app/Notifications/ApprovalRequested.php`, `app/Livewire/Approvals/Index.php`, `resources/views/livewire/approvals/index.blade.php`, `tests/Feature/Approvals/OwnerContractActivationTest.php`, `tests/Feature/Approvals/ApprovalsScreenTest.php`, `tests/Concurrency/OwnerContractOverlapLockTest.php`
- Modify: `app/Models/OwnerContract.php`, `app/Livewire/OwnerContracts/Show.php`, `resources/views/livewire/owner-contracts/show.blade.php`, `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`

**Interfaces:**
- Consumes: `OwnerContract`, `NextDocumentNumber`, `NumberSequenceKey::OwnerContract`, `Approvers::notifiable()`, `CompanySetting::current()->require_different_approver`, `Audit`
- Produces:
  - `App\Approvals\ApprovalHandler` interface: `creatorId(Approval): ?int`, `approve(Approval, User): void`, `reject(Approval, User): void`, `summary(Approval): string`, `url(Approval): ?string`
  - `ApprovalAction` enum (`OwnerContractActivation = 'owner_contract.activate'`) with `label()` and `handler(): class-string<ApprovalHandler>` — every later approval adds a case and a handler, nothing else
  - `RequestApproval::handle(User $requester, Model $approvable, ApprovalAction $action, ?string $reason = null, array $payload = []): Approval` — inside the caller's transaction
  - `DecideApproval::handle(User $approver, Approval $approval, bool $approve, ?string $comment = null): Approval`
  - `EnsureNoOverlap::handle(OwnerContract): void`, `SubmitOwnerContract::handle(User, OwnerContract): Approval`, `ActivateOwnerContract::handle(OwnerContract): OwnerContract` (no authorisation — for DecideApproval and the importer)
  - `OwnerContract::approvals()`; route `approvals.index`; audit events `approval.requested`, `approval.approved`, `approval.rejected`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Approvals/OwnerContractActivationTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Enums\ApprovalStatus;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);

    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    $this->units = Unit::factory()->for($this->building)->count(2)->create();
    $this->owner = Owner::factory()->create();
    $this->terms = [
        'owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'type' => 'managed',
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
        'fee_type' => 'percent_collected', 'fee_value' => '5', 'deposits_held_by' => 'company',
        'unit_ids' => $this->units->pluck('id')->all(),
    ];
    $this->draft = app(SaveOwnerContract::class)->handle($this->finance, null, $this->terms);
});

test('submitting locks the draft, opens one pending approval and emails the other approvers', function () {
    Notification::fake();
    $otherApprover = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);

    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);

    expect($this->draft->fresh()->status)->toBe(OwnerContractStatus::PendingApproval)
        ->and($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->requested_by)->toBe($this->finance->id);

    Notification::assertSentTo([$this->management, $otherApprover], ApprovalRequested::class);
    Notification::assertNotSentTo($this->finance, ApprovalRequested::class);

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, $this->draft->fresh(), $this->terms))
        ->toThrow(ValidationException::class, 'Only draft contracts can be edited.');
    expect(fn () => app(SubmitOwnerContract::class)->handle($this->finance, $this->draft->fresh()))
        ->toThrow(ValidationException::class);
});

test('Management approves: the contract becomes active with the next OC number', function () {
    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);

    app(DecideApproval::class)->handle($this->management, $approval, true);

    $contract = $this->draft->fresh();
    expect($contract->status)->toBe(OwnerContractStatus::Active)
        ->and($contract->number)->toBe('OC-2026-000001')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->fresh()->decided_by)->toBe($this->management->id)
        ->and(Activity::query()->where('event', 'approval.approved')->where('causer_id', $this->management->id)->exists())->toBeTrue();
});

test('neither the requester nor the creator can approve while require_different_approver is on', function () {
    $dual = User::factory()->withTwoFactor()->create()->assignRole([RoleName::Finance, RoleName::Management]);

    // The dual user CREATED it; Finance submits.
    $created = app(SaveOwnerContract::class)->handle($dual, null, $this->terms);
    $a = app(SubmitOwnerContract::class)->handle($this->finance, $created);
    expect(fn () => app(DecideApproval::class)->handle($dual, $a, true))->toThrow(ValidationException::class, 'you requested or a record you created');

    // Finance created it; the dual user REQUESTED approval.
    $other = app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->terms, 'start_date' => '2028-01-01', 'end_date' => '2028-12-31']);
    $b = app(SubmitOwnerContract::class)->handle($dual, $other);
    expect(fn () => app(DecideApproval::class)->handle($dual, $b, true))->toThrow(ValidationException::class);

    CompanySetting::current()->forceFill(['require_different_approver' => false])->save();
    app(DecideApproval::class)->handle($dual, $b, true);
    expect($other->fresh()->status)->toBe(OwnerContractStatus::Active);
});

test('only approvals.decide holders decide, and a rejection needs a reason and returns the draft', function () {
    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);

    expect(fn () => app(DecideApproval::class)->handle($this->finance, $approval, true))->toThrow(AuthorizationException::class);
    expect(fn () => app(DecideApproval::class)->handle($this->management, $approval, false, ''))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, false, 'Fee should be 6%');

    expect($this->draft->fresh()->status)->toBe(OwnerContractStatus::Draft)
        ->and($approval->fresh()->comment)->toBe('Fee should be 6%');

    // Fix and resubmit: a new pending approval is allowed once the old one is decided.
    app(SaveOwnerContract::class)->handle($this->finance, $this->draft->fresh(), [...$this->terms, 'fee_value' => '6']);
    $again = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft->fresh());
    expect($again->id)->not->toBe($approval->id);

    expect(fn () => app(DecideApproval::class)->handle($this->management, $approval->fresh(), true))->toThrow(ValidationException::class, 'already been decided');
});

test('the database keeps one pending request per record and action, and decided approvals immutable', function () {
    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);
    $copy = collect($approval->getAttributes())->except(['id', 'pending_key'])->all();

    expect(fn () => DB::table('approvals')->insert($copy))->toThrow(UniqueConstraintViolationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, true);

    expect(fn () => DB::table('approvals')->where('id', $approval->id)->update(['comment' => 'edited']))->toThrow(QueryException::class, 'decided approvals are immutable');
    expect(fn () => DB::table('approvals')->where('id', $approval->id)->delete())->toThrow(QueryException::class, 'approvals cannot be deleted');
});

test('a unit cannot be covered twice on the same dates; a later pre-arranged contract is fine', function () {
    app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);

    $clash = app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->terms, 'owner_id' => Owner::factory()->create()->id, 'unit_ids' => [$this->units[1]->id], 'start_date' => '2027-06-01', 'end_date' => '2028-05-31']);
    expect(fn () => app(SubmitOwnerContract::class)->handle($this->finance, $clash))->toThrow(ValidationException::class, $this->units[1]->code);
    expect($clash->fresh()->status)->toBe(OwnerContractStatus::Draft);

    $later = app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->terms, 'start_date' => '2027-11-01', 'end_date' => '2028-10-31']);
    expect(app(SubmitOwnerContract::class)->handle($this->finance, $later)->status)->toBe(ApprovalStatus::Pending);
});

test('approving a successor ends its predecessor the day before', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerContract::class)->handle($this->finance, $this->draft), true);

    $successor = app(SaveOwnerContract::class)->handle($this->finance, null, [
        ...$this->terms, 'fee_value' => '6', 'start_date' => '2027-05-01', 'end_date' => '2028-04-30', 'previous_contract_id' => $this->draft->id,
    ]);
    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $successor); // overlap with the predecessor is allowed
    app(DecideApproval::class)->handle($this->management, $approval, true);

    expect($this->draft->fresh()->end_date->toDateString())->toBe('2027-04-30')
        ->and($successor->fresh()->number)->toBe('OC-2026-000002');
});
```
Create `tests/Feature/Approvals/ApprovalsScreenTest.php`:
```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Livewire\Approvals\Index;
use App\Livewire\OwnerContracts\Show;
use App\Models\CompanySetting;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->contract = OwnerContract::factory()->create(['created_by' => $this->finance->id]);
    $this->contract->units()->attach(Unit::factory()->create(['building_id' => $this->contract->building_id])->id);
});

test('Finance submits from the contract page', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $this->contract])
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->contract->fresh()->status)->toBe(OwnerContractStatus::PendingApproval);
});

test('Management approves and rejects from the pending list', function () {
    app(SubmitOwnerContract::class)->handle($this->finance, $this->contract);
    $approval = $this->contract->approvals()->sole();

    Livewire::actingAs($this->management)->test(Index::class)
        ->assertSee('Owner contract activation')
        ->call('startRejecting', $approval->id)
        ->call('reject')
        ->assertHasErrors('comment')
        ->set('comment', 'Wrong dates')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertSee('Nothing is waiting for approval.');

    expect($this->contract->fresh()->status)->toBe(OwnerContractStatus::Draft);

    app(SubmitOwnerContract::class)->handle($this->finance, $this->contract->fresh());
    Livewire::actingAs($this->management)->test(Index::class)
        ->call('approve', $this->contract->approvals()->where('status', 'pending')->sole()->id);

    expect($this->contract->fresh()->status)->toBe(OwnerContractStatus::Active);
});

test('the pending list is only for approvers', function () {
    $this->actingAs($this->finance)->get(route('approvals.index'))->assertForbidden();
    $this->actingAs($this->management)->get(route('approvals.index'))->assertOk();
});
```
Create `tests/Concurrency/OwnerContractOverlapLockTest.php`:
```php
<?php

use App\Actions\OwnerContracts\EnsureNoOverlap;
use App\Models\OwnerContract;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => config(['database.connections.mysql_b' => config('database.connections.mysql')]));

afterEach(fn () => DB::purge('mysql_b'));

// Spec §14 flow 2: two submissions for the same unit on two connections queue on the unit row lock.
it('makes a second connection wait on the unit lock until the first commits', function () {
    $contract = OwnerContract::factory()->create();
    $unit = Unit::factory()->create(['building_id' => $contract->building_id]);
    $contract->units()->attach($unit->id);

    $b = DB::connection('mysql_b');
    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();
    app(EnsureNoOverlap::class)->handle($contract);

    expect(fn () => $b->transaction(fn () => $b->table('units')->where('id', $unit->id)->lockForUpdate()->first()))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1205));

    DB::commit();

    expect($b->transaction(fn () => $b->table('units')->where('id', $unit->id)->lockForUpdate()->value('id')))->toBe($unit->id);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Approvals tests/Concurrency/OwnerContractOverlapLockTest.php`
Expected: FAIL — `Class "App\Actions\Approvals\DecideApproval" not found`.

- [ ] **Step 3: Enums, migration, model, handler interface**

Create `app/Enums/ApprovalStatus.php`:
```php
<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```
Create `app/Enums/ApprovalAction.php`:
```php
<?php

namespace App\Enums;

use App\Approvals\ApprovalHandler;
use App\Approvals\OwnerContractActivation;

/** Spec §8.3. Each later approval adds a case here and a handler in app/Approvals. */
enum ApprovalAction: string
{
    case OwnerContractActivation = 'owner_contract.activate';

    public function label(): string
    {
        return match ($this) {
            self::OwnerContractActivation => __('Owner contract activation'),
        };
    }

    /** @return class-string<ApprovalHandler> */
    public function handler(): string
    {
        return match ($this) {
            self::OwnerContractActivation => OwnerContractActivation::class,
        };
    }
}
```
Create `database/migrations/2026_10_01_000400_create_approvals_table.php`:
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
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->string('action', 50);
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'requested_at']);
        });

        // One pending request per record and action: NULLs never collide in a UNIQUE index.
        DB::statement(<<<'SQL'
            ALTER TABLE approvals
                ADD COLUMN pending_key VARCHAR(320) GENERATED ALWAYS AS (IF(status = 'pending', CONCAT(approvable_type, '#', approvable_id, '#', action), NULL)) STORED,
                ADD UNIQUE KEY approvals_one_pending (pending_key),
                ADD CONSTRAINT approvals_status_chk CHECK (status IN ('pending', 'approved', 'rejected')),
                ADD CONSTRAINT approvals_decided_chk CHECK ((status = 'pending') = (decided_at IS NULL) AND (status = 'pending') = (decided_by IS NULL))
            SQL);

        // Spec §8.5: no UPDATE or DELETE once decided. Nothing ever deletes an approval.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER approvals_guard BEFORE UPDATE ON approvals FOR EACH ROW
            BEGIN
                IF OLD.status <> 'pending' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approvals: decided approvals are immutable';
                END IF;
                IF NOT (NEW.approvable_type <=> OLD.approvable_type AND NEW.approvable_id <=> OLD.approvable_id
                    AND NEW.action <=> OLD.action AND NEW.requested_by <=> OLD.requested_by
                    AND NEW.requested_at <=> OLD.requested_at AND NEW.payload <=> OLD.payload AND NEW.reason <=> OLD.reason) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approvals: the request itself cannot change';
                END IF;
            END
            SQL);

        DB::unprepared("CREATE TRIGGER approvals_no_delete BEFORE DELETE ON approvals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approvals cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS approvals_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS approvals_guard');
        Schema::dropIfExists('approvals');
    }
};
```
Create `app/Models/Approval.php`:
```php
<?php

namespace App\Models;

use App\Approvals\ApprovalHandler;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Spec §8.3. Decisions are explicit audit entries (DecideApproval), so no LogsActivity here.
 *
 * @property int $id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property ApprovalAction $action
 * @property ApprovalStatus $status
 * @property string|null $reason
 * @property array<string, mixed>|null $payload
 * @property int $requested_by
 * @property CarbonImmutable $requested_at
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $comment
 */
class Approval extends Model
{
    protected $guarded = ['id', 'pending_key'];

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param  Builder<Approval>  $query */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending);
    }

    public function handler(): ApprovalHandler
    {
        return app($this->action->handler());
    }
}
```
Create `app/Approvals/ApprovalHandler.php`:
```php
<?php

namespace App\Approvals;

use App\Models\Approval;
use App\Models\User;

/** What an approval does to its record (spec §8.3). approve() and reject() run inside DecideApproval's transaction. */
interface ApprovalHandler
{
    /** created_by / recorded_by of the underlying record, for require_different_approver. */
    public function creatorId(Approval $approval): ?int;

    public function approve(Approval $approval, User $approver): void;

    public function reject(Approval $approval, User $approver): void;

    public function summary(Approval $approval): string;

    public function url(Approval $approval): ?string;
}
```
In `app/Models/OwnerContract.php` add:
```php
    /** @return \Illuminate\Database\Eloquent\Relations\MorphMany<Approval, $this> */
    public function approvals(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
```

- [ ] **Step 4: Request and decide**

Create `app/Notifications/ApprovalRequested.php`:
```php
<?php

namespace App\Notifications;

use App\Models\Approval;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalRequested extends Notification
{
    public function __construct(public Approval $approval) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approval = $this->approval;

        $mail = (new MailMessage)
            ->subject(__('Approval needed: :action', ['action' => $approval->action->label()]))
            ->line($approval->handler()->summary($approval))
            ->line(__('Requested by :name.', ['name' => $approval->requester->name ?? '—']));

        if ($approval->reason) {
            $mail->line(__('Reason: :reason', ['reason' => $approval->reason]));
        }

        return $mail->action(__('Open pending approvals'), route('approvals.index'));
    }
}
```
Create `app/Actions/Approvals/RequestApproval.php`:
```php
<?php

namespace App\Actions\Approvals;

use App\Audit\Audit;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Support\Approvers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Opens a pending approval (spec §8.3). The caller's Action has already authorised and locked its record. */
final class RequestApproval
{
    /** @param  array<string, mixed>  $payload */
    public function handle(User $requester, Model $approvable, ApprovalAction $action, ?string $reason = null, array $payload = []): Approval
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RequestApproval must run inside the caller\'s transaction.');
        }

        try {
            $approval = Approval::create([
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'action' => $action,
                'status' => ApprovalStatus::Pending,
                'reason' => $reason,
                'payload' => $payload ?: null,
                'requested_by' => $requester->id,
                'requested_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['approval' => __('This request is already waiting for approval.')]);
        }

        Audit::log('approval.requested', $approvable, properties: ['approval_id' => $approval->id, 'action' => $action->value], causer: $requester);

        // After commit: a rolled-back request must not email anyone.
        DB::afterCommit(fn () => Notification::send(Approvers::notifiable($requester), new ApprovalRequested($approval)));

        return $approval;
    }
}
```
Create `app/Actions/Approvals/DecideApproval.php`:
```php
<?php

namespace App\Actions\Approvals;

use App\Audit\Audit;
use App\Enums\ApprovalStatus;
use App\Enums\PermissionName;
use App\Models\Approval;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §8.3. Single step in v1. */
final class DecideApproval
{
    public function handle(User $approver, Approval $approval, bool $approve, ?string $comment = null): Approval
    {
        if (! $approver->can(PermissionName::ApprovalsDecide)) {
            throw new AuthorizationException;
        }

        $comment = filled($comment) ? trim($comment) : null;

        if (! $approve && $comment === null) {
            throw ValidationException::withMessages(['comment' => __('Give a reason for rejecting.')]);
        }

        return DB::transaction(function () use ($approver, $approval, $approve, $comment) {
            $approval = Approval::query()->lockForUpdate()->findOrFail($approval->id);

            if ($approval->status !== ApprovalStatus::Pending) {
                throw ValidationException::withMessages(['approval' => __('This request has already been decided.')]);
            }

            $handler = $approval->handler();

            if (CompanySetting::current()->require_different_approver
                && in_array($approver->id, [$approval->requested_by, $handler->creatorId($approval)], true)) {
                throw ValidationException::withMessages(['approval' => __('You cannot decide a request you requested or a record you created.')]);
            }

            $approve ? $handler->approve($approval, $approver) : $handler->reject($approval, $approver);

            $approval->forceFill([
                'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'comment' => $comment,
                'ip' => request()->ip(),
            ])->save();

            Audit::log($approve ? 'approval.approved' : 'approval.rejected', $approval->approvable,
                properties: ['approval_id' => $approval->id, 'action' => $approval->action->value, 'comment' => $comment],
                causer: $approver,
            );

            return $approval;
        });
    }
}
```

- [ ] **Step 5: Overlap, submit, activate, and the handler**

Create `app/Actions/OwnerContracts/EnsureNoOverlap.php`:
```php
<?php

namespace App\Actions\OwnerContracts;

use App\Models\OwnerContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Spec §4.5: a unit is covered by at most one owner contract on any date. Pending and active contracts
 * are in the spec; ended and terminated ones are included too, because their dates still attribute income (§4.6).
 */
final class EnsureNoOverlap
{
    private const array COVERING = ['pending_approval', 'active', 'ended', 'terminated'];

    public function handle(OwnerContract $contract): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EnsureNoOverlap must run inside the caller\'s transaction.');
        }

        $unitIds = DB::table('owner_contract_units')->where('owner_contract_id', $contract->id)
            ->pluck('unit_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        // Ascending id order, so concurrent submits for the same units queue instead of deadlocking.
        DB::table('units')->whereIn('id', $unitIds)->orderBy('id')->lockForUpdate()->pluck('id');

        $clashes = DB::table('owner_contract_units as ocu')
            ->join('owner_contracts as oc', 'oc.id', '=', 'ocu.owner_contract_id')
            ->join('units as u', 'u.id', '=', 'ocu.unit_id')
            ->whereIn('ocu.unit_id', $unitIds)
            ->where('oc.id', '<>', $contract->id)
            ->when($contract->previous_contract_id, fn ($q, $previous) => $q->where('oc.id', '<>', $previous)) // the successor rule
            ->whereIn('oc.status', self::COVERING)
            ->where('oc.start_date', '<=', $contract->end_date->toDateString())
            ->where('oc.end_date', '>=', $contract->start_date->toDateString())
            ->lockForUpdate()
            ->get(['u.code', 'oc.id', 'oc.number']);

        if ($clashes->isNotEmpty()) {
            throw ValidationException::withMessages(['unit_ids' => __('Already covered on these dates: :list.', [
                'list' => $clashes->map(fn ($c) => $c->code.' ('.($c->number ?? __('pending #:id', ['id' => $c->id])).')')->unique()->implode(', '),
            ])]);
        }
    }
}
```
Create `app/Actions/OwnerContracts/SubmitOwnerContract.php`:
```php
<?php

namespace App\Actions\OwnerContracts;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\OwnerContractStatus;
use App\Models\Approval;
use App\Models\OwnerContract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitOwnerContract
{
    public function __construct(private EnsureNoOverlap $overlap, private RequestApproval $request) {}

    public function handle(User $actor, OwnerContract $contract): Approval
    {
        if (! $actor->can('update', $contract)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $contract) {
            $this->overlap->handle($contract); // the unit locks come first (spec §4.5)

            $contract = OwnerContract::query()->lockForUpdate()->findOrFail($contract->id);

            if ($contract->status !== OwnerContractStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('Only a draft can be submitted.')]);
            }

            $contract->forceFill(['status' => OwnerContractStatus::PendingApproval])->save();

            return $this->request->handle($actor, $contract, ApprovalAction::OwnerContractActivation);
        });
    }
}
```
Create `app/Actions/OwnerContracts/ActivateOwnerContract.php`:
```php
<?php

namespace App\Actions\OwnerContracts;

use App\Actions\NextDocumentNumber;
use App\Enums\NumberSequenceKey;
use App\Enums\OwnerContractStatus;
use App\Models\OwnerContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Pending → active (spec §4.5). No authorisation here: DecideApproval and the importer authorise. */
final class ActivateOwnerContract
{
    public function __construct(private EnsureNoOverlap $overlap, private NextDocumentNumber $next) {}

    public function handle(OwnerContract $contract): OwnerContract
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ActivateOwnerContract must run inside the caller\'s transaction.');
        }

        $this->overlap->handle($contract); // checked again on approval

        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($contract->id);

        if ($contract->status !== OwnerContractStatus::PendingApproval) {
            throw new LogicException("Owner contract {$contract->id} is not pending approval.");
        }

        if ($contract->previous_contract_id !== null) {
            $previous = OwnerContract::query()->lockForUpdate()->findOrFail($contract->previous_contract_id);
            $dayBefore = $contract->start_date->subDay();

            if ($previous->end_date->greaterThan($dayBefore)) {
                if ($previous->status !== OwnerContractStatus::Active) {
                    throw ValidationException::withMessages(['previous_contract_id' => __('The contract this one replaces is no longer active, and their dates overlap.')]);
                }

                $previous->forceFill(['end_date' => $dayBefore])->save();
                // ponytail: M4 cancels the predecessor's head-lease payables after $dayBefore and prorates a straddling one (spec §7.8).
            }
        }

        $contract->forceFill([
            'status' => OwnerContractStatus::Active,
            'number' => ($this->next)(NumberSequenceKey::OwnerContract),
        ])->save();
        // ponytail: M4 generates a leased contract's payment schedule to the owner here (spec §7.8).

        return $contract;
    }
}
```
Create `app/Approvals/OwnerContractActivation.php`:
```php
<?php

namespace App\Approvals;

use App\Actions\OwnerContracts\ActivateOwnerContract;
use App\Enums\OwnerContractStatus;
use App\Models\Approval;
use App\Models\OwnerContract;
use App\Models\User;

/** Spec §8.3 item 7 (activation). */
final class OwnerContractActivation implements ApprovalHandler
{
    public function __construct(private ActivateOwnerContract $activate) {}

    private function contract(Approval $approval): OwnerContract
    {
        return OwnerContract::query()->findOrFail($approval->approvable_id);
    }

    public function creatorId(Approval $approval): ?int
    {
        return $this->contract($approval)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->activate->handle($this->contract($approval));
    }

    /** Back to draft; the comment stays on the approval (spec §8.3). */
    public function reject(Approval $approval, User $approver): void
    {
        $this->contract($approval)->forceFill(['status' => OwnerContractStatus::Draft])->save();
    }

    public function summary(Approval $approval): string
    {
        $contract = OwnerContract::with(['owner:id,name_en', 'building:id,name'])->withCount('units')->findOrFail($approval->approvable_id);

        return __(':type contract :label: :owner, :building, :units unit(s), :start to :end', [
            'type' => str($contract->type->value)->headline()->toString(),
            'label' => $contract->label(),
            'owner' => $contract->owner->name_en,
            'building' => $contract->building->name,
            'units' => $contract->units_count,
            'start' => $contract->start_date->format('d/m/Y'),
            'end' => $contract->end_date->format('d/m/Y'),
        ]);
    }

    public function url(Approval $approval): ?string
    {
        return route('owner-contracts.show', $approval->approvable_id);
    }
}
```

- [ ] **Step 6: The pending list, the Submit button, routes and navigation**

Create `app/Livewire/Approvals/Index.php`:
```php
<?php

namespace App\Livewire\Approvals;

use App\Actions\Approvals\DecideApproval;
use App\Livewire\Concerns\WithActor;
use App\Models\Approval;
use App\Models\CompanySetting;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** "Pending my approval" (spec §8.3). */
#[Title('Pending approvals')]
class Index extends Component
{
    use WithActor;

    public ?int $rejecting = null;

    public string $comment = '';

    public function approve(int $approvalId, DecideApproval $decide): void
    {
        $this->decide($decide, $approvalId, true);
    }

    public function startRejecting(int $approvalId): void
    {
        $this->rejecting = $approvalId;
        $this->comment = '';
        $this->resetErrorBag();
    }

    public function reject(DecideApproval $decide): void
    {
        $this->decide($decide, (int) $this->rejecting, false, $this->comment);
        $this->rejecting = null;
    }

    /** ValidationExceptions (comment, already decided, same approver) surface as field errors. */
    private function decide(DecideApproval $decide, int $approvalId, bool $approve, ?string $comment = null): void
    {
        try {
            $decide->handle($this->actor(), Approval::findOrFail($approvalId), $approve, $comment);
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: $approve ? __('Approved.') : __('Rejected.'));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $different = CompanySetting::current()->require_different_approver;

        // ponytail: one handler lookup per row; fine while the pending list stays short.
        $rows = Approval::query()->pending()->with('requester:id,name')->orderBy('requested_at')->get()
            ->map(function (Approval $approval) use ($actor, $different) {
                $handler = $approval->handler();

                return [
                    'approval' => $approval,
                    'summary' => $handler->summary($approval),
                    'url' => $handler->url($approval),
                    'mine' => $different && in_array($actor->id, [$approval->requested_by, $handler->creatorId($approval)], true),
                ];
            });

        return view('livewire.approvals.index', ['rows' => $rows]);
    }
}
```
Create `resources/views/livewire/approvals/index.blade.php`:
```blade
<section class="w-full max-w-4xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Pending approvals') }}</flux:heading>
    <flux:error name="approval" />

    @forelse ($rows as $row)
        @php($approval = $row['approval'])
        <flux:card class="space-y-3" wire:key="approval-{{ $approval->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="space-y-1">
                    <flux:heading>{{ $approval->action->label() }}</flux:heading>
                    <flux:text>{{ $row['summary'] }}</flux:text>
                    <flux:text size="sm">{{ __('Requested by :name on :date', ['name' => $approval->requester->name, 'date' => $approval->requested_at->timezone('Asia/Bahrain')->format('d/m/Y H:i')]) }}</flux:text>
                    @if ($approval->reason)
                        <flux:text size="sm">{{ __('Reason: :reason', ['reason' => $approval->reason]) }}</flux:text>
                    @endif
                </div>
                @if ($row['url'])
                    <flux:link :href="$row['url']" wire:navigate>{{ __('Open') }}</flux:link>
                @endif
            </div>

            @if ($row['mine'])
                <flux:text size="sm">{{ __('Another approver must decide this: you requested it or created the record.') }}</flux:text>
            @elseif ($rejecting === $approval->id)
                <form wire:submit="reject" class="space-y-2">
                    <flux:textarea wire:model="comment" :label="__('Reason for rejecting')" rows="2" />
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="danger">{{ __('Reject') }}</flux:button>
                        <flux:button wire:click="$set('rejecting', null)">{{ __('Cancel') }}</flux:button>
                    </div>
                </form>
            @else
                <div class="flex gap-2">
                    <flux:button variant="primary" wire:click="approve({{ $approval->id }})" wire:confirm="{{ __('Approve this request?') }}">{{ __('Approve') }}</flux:button>
                    <flux:button wire:click="startRejecting({{ $approval->id }})">{{ __('Reject') }}</flux:button>
                </div>
            @endif
        </flux:card>
    @empty
        <flux:text>{{ __('Nothing is waiting for approval.') }}</flux:text>
    @endforelse
</section>
```
In `app/Livewire/OwnerContracts/Show.php` add the imports `App\Actions\OwnerContracts\SubmitOwnerContract`, `Flux\Flux`, `Illuminate\Auth\Access\AuthorizationException`, and the method:
```php
    public function submit(SubmitOwnerContract $submit): void
    {
        try {
            $submit->handle($this->actor(), $this->contract());
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: __('Submitted for approval.'));
    }
```
and pass the approval history to the view — in `render()` change the view data to:
```php
            'contract' => $contract,
            'canManage' => $this->actor()->can('update', $contract),
            'approvals' => $contract->approvals()->with(['requester:id,name', 'decider:id,name'])->latest('id')->get(),
```
In `resources/views/livewire/owner-contracts/show.blade.php` replace the `{{-- Task 6 adds Submit; … --}}` line with:
```blade
            @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Draft)
                <flux:button variant="primary" wire:click="submit" wire:confirm="{{ __('Submit this contract for Management approval?') }}">{{ __('Submit for approval') }}</flux:button>
            @endif
            {{-- Task 7 adds Request early termination. --}}
```
and insert after the heading `<div class="flex flex-wrap items-center …">…</div>` block:
```blade
    <flux:error name="unit_ids" />
    <flux:error name="status" />
    <flux:error name="approval" />

    @php($lastRejection = $contract->status === \App\Enums\OwnerContractStatus::Draft ? $approvals->firstWhere('status', \App\Enums\ApprovalStatus::Rejected) : null)
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
                        {{ $approval->action->label() }} · {{ str($approval->status->value)->headline() }} ·
                        {{ __('requested by :name', ['name' => $approval->requester->name]) }}
                        @if ($approval->decider) · {{ __('decided by :name', ['name' => $approval->decider->name]) }} @endif
                        @if ($approval->comment) — {{ $approval->comment }} @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
```
In `routes/property.php` add `use App\Livewire\Approvals;` and inside the group:
```php
    Route::livewire('approvals', Approvals\Index::class)->middleware('can:approvals.decide')->name('approvals.index');
```
In `resources/views/layouts/app/sidebar.blade.php`, in the Platform group after the Dashboard item:
```blade
                    @can('approvals.decide')
                        <flux:sidebar.item icon="check-badge" :href="route('approvals.index')" :current="request()->routeIs('approvals.*')"
                            :badge="\App\Models\Approval::pending()->count() ?: null" wire:navigate>{{ __('Approvals') }}</flux:sidebar.item>
                    @endcan
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Approvals tests/Concurrency
"$PHP" artisan test
```
Expected: 10 new Feature tests and 1 Concurrency test pass; full suite passes.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the approvals engine and owner contract activation

A generic approvals table (one pending request per record and action,
immutable once decided) with a handler per action. Owner contracts are
submitted with a locked overlap check, approved by someone other than
the requester and creator, numbered on activation, and cut their
predecessor short. Approvers get an email and a pending list.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 7: Early termination and the 02:15 job

**Spec:** §4.5 (early termination approval sets `end_date = terminated_on`; 02:15 job → ended / terminated), §8.3 item 7 and "rejecting a request about an existing record leaves it unchanged", §12

**Files:**
- Create: `app/Approvals/OwnerContractTermination.php`, `app/Actions/OwnerContracts/{RequestOwnerContractTermination,CloseEndedOwnerContracts}.php`, `app/Console/Commands/CloseOwnerContracts.php`, `tests/Feature/OwnerContracts/OwnerContractTerminationTest.php`
- Modify: `app/Enums/ApprovalAction.php`, `routes/console.php`, `config/services.php`, `.env.example`, `app/Livewire/OwnerContracts/Show.php`, `resources/views/livewire/owner-contracts/show.blade.php`, `tests/Feature/Backup/ScheduleTest.php`

**Interfaces:**
- Consumes: `RequestApproval`, `DecideApproval`, `ApprovalHandler`, `OwnerContract`
- Produces: `ApprovalAction::OwnerContractTermination` (`'owner_contract.terminate'`); `RequestOwnerContractTermination::handle(User $actor, OwnerContract $contract, string $terminatedOn, string $reason): Approval`; `CloseEndedOwnerContracts::__invoke(): int`; command `rms:owner-contracts:close`; heartbeat key `services.forge.heartbeats.owner_contracts`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OwnerContracts/OwnerContractTerminationTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\OwnerContracts\CloseEndedOwnerContracts;
use App\Actions\OwnerContracts\RequestOwnerContractTermination;
use App\Enums\ApprovalAction;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Livewire\OwnerContracts\Show;
use App\Models\CompanySetting;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->contract = activeOwnerContract(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'created_by' => $this->finance->id], []);
});

test('an approved early termination moves the end date; the job then marks it terminated', function () {
    $approval = app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-10-31', 'Owner sold the building');

    expect($approval->action)->toBe(ApprovalAction::OwnerContractTermination)
        ->and($approval->payload)->toBe(['terminated_on' => '2026-10-31', 'termination_reason' => 'Owner sold the building'])
        ->and($this->contract->fresh()->terminated_on)->toBeNull(); // nothing changes until approved

    app(DecideApproval::class)->handle($this->management, $approval, true);

    $contract = $this->contract->fresh();
    expect($contract->end_date->toDateString())->toBe('2026-10-31')
        ->and($contract->terminated_on->toDateString())->toBe('2026-10-31')
        ->and($contract->status)->toBe(OwnerContractStatus::Active);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 02:15', 'Asia/Bahrain'));
    expect(app(CloseEndedOwnerContracts::class)())->toBe(1)
        ->and(app(CloseEndedOwnerContracts::class)())->toBe(0) // idempotent
        ->and($contract->fresh()->status)->toBe(OwnerContractStatus::Terminated);
});

test('a rejected termination leaves the contract unchanged', function () {
    $approval = app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-10-31', 'Dispute');
    app(DecideApproval::class)->handle($this->management, $approval, false, 'Settle the dispute first');

    expect($this->contract->fresh()->end_date->toDateString())->toBe('2026-12-31')
        ->and($this->contract->fresh()->terminated_on)->toBeNull();
});

test('the termination date must fall before the current end and not before the start', function (string $date) {
    expect(fn () => app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, $date, 'x'))
        ->toThrow(ValidationException::class);
})->with(['2026-12-31', '2027-02-01', '2025-12-31', 'not-a-date']);

test('only one termination request can be pending, and only active contracts can be terminated', function () {
    app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-10-31', 'x');

    expect(fn () => app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-11-30', 'y'))
        ->toThrow(ValidationException::class, 'already waiting for approval');

    $draft = App\Models\OwnerContract::factory()->create();
    expect(fn () => app(RequestOwnerContractTermination::class)->handle($this->finance, $draft, '2026-10-31', 'x'))
        ->toThrow(ValidationException::class);
});

test('the job ends contracts past their end date that were never terminated', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-01 02:15', 'Asia/Bahrain'));

    $this->artisan('rms:owner-contracts:close')->assertSuccessful();

    expect($this->contract->fresh()->status)->toBe(OwnerContractStatus::Ended);
});

test('Finance requests termination from the contract page', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $this->contract])
        ->set('terminatedOn', '2026-11-30')
        ->set('terminationReason', 'Owner request')
        ->call('requestTermination')
        ->assertHasNoErrors();

    expect($this->contract->approvals()->where('action', 'owner_contract.terminate')->where('status', 'pending')->exists())->toBeTrue();
});
```
In `tests/Feature/Backup/ScheduleTest.php` add a row to the schedule dataset:
```php
    ['rms:owner-contracts:close', '15 2 * * *'],
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/OwnerContracts/OwnerContractTerminationTest.php`
Expected: FAIL — `Class "App\Actions\OwnerContracts\RequestOwnerContractTermination" not found`.

- [ ] **Step 3: The approval case and handler**

In `app/Enums/ApprovalAction.php` add the import `use App\Approvals\OwnerContractTermination;`, the case:
```php
    case OwnerContractTermination = 'owner_contract.terminate';
```
and the match arms `self::OwnerContractTermination => __('Owner contract early termination'),` in `label()` and `self::OwnerContractTermination => OwnerContractTermination::class,` in `handler()`.

Create `app/Approvals/OwnerContractTermination.php`:
```php
<?php

namespace App\Approvals;

use App\Enums\OwnerContractStatus;
use App\Models\Approval;
use App\Models\OwnerContract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Spec §8.3 item 7 (early termination). The payload carries terminated_on and termination_reason. */
final class OwnerContractTermination implements ApprovalHandler
{
    public function creatorId(Approval $approval): ?int
    {
        return OwnerContract::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($approval->approvable_id);
        $on = CarbonImmutable::parse((string) ($approval->payload['terminated_on'] ?? ''));

        if ($contract->status !== OwnerContractStatus::Active || $on->greaterThanOrEqualTo($contract->end_date)) {
            throw ValidationException::withMessages(['approval' => __('The contract is no longer active, or already ends by that date.')]);
        }

        $contract->forceFill([
            'terminated_on' => $on,
            'termination_reason' => $approval->payload['termination_reason'] ?? '',
            'end_date' => $on,
        ])->save();
        // ponytail: M4 cancels head-lease payables after end_date and prorates a straddling one (spec §7.8).
    }

    /** A rejected request about an existing record leaves it unchanged (spec §8.3). */
    public function reject(Approval $approval, User $approver): void {}

    public function summary(Approval $approval): string
    {
        $contract = OwnerContract::with('owner:id,name_en')->findOrFail($approval->approvable_id);

        return __('Terminate :label (:owner) on :date, instead of :end', [
            'label' => $contract->label(),
            'owner' => $contract->owner->name_en,
            'date' => CarbonImmutable::parse((string) $approval->payload['terminated_on'])->format('d/m/Y'),
            'end' => $contract->end_date->format('d/m/Y'),
        ]);
    }

    public function url(Approval $approval): ?string
    {
        return route('owner-contracts.show', $approval->approvable_id);
    }
}
```

- [ ] **Step 4: The request Action, the job and its command**

Create `app/Actions/OwnerContracts/RequestOwnerContractTermination.php`:
```php
<?php

namespace App\Actions\OwnerContracts;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\OwnerContractStatus;
use App\Models\Approval;
use App\Models\OwnerContract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RequestOwnerContractTermination
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, OwnerContract $contract, string $terminatedOn, string $reason): Approval
    {
        if (! $actor->can('update', $contract)) {
            throw new AuthorizationException;
        }

        $data = Validator::make(
            ['terminated_on' => $terminatedOn, 'termination_reason' => trim($reason)],
            [
                'terminated_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$contract->start_date->toDateString(), 'before:'.$contract->end_date->toDateString()],
                'termination_reason' => ['required', 'string', 'max:500'],
            ],
        )->validate();

        return DB::transaction(function () use ($actor, $contract, $data) {
            $contract = OwnerContract::query()->lockForUpdate()->findOrFail($contract->id);

            if ($contract->status !== OwnerContractStatus::Active || $contract->terminated_on !== null) {
                throw ValidationException::withMessages(['terminated_on' => __('Only an active contract that is not already terminating can be terminated early.')]);
            }

            return $this->request->handle($actor, $contract, ApprovalAction::OwnerContractTermination, $data['termination_reason'], $data);
        });
    }
}
```
Create `app/Actions/OwnerContracts/CloseEndedOwnerContracts.php`:
```php
<?php

namespace App\Actions\OwnerContracts;

use App\Enums\OwnerContractStatus;
use App\Models\OwnerContract;
use Illuminate\Support\Facades\DB;

/** The 02:15 job (spec §4.5, §12). Idempotent: only active contracts past their end date move. */
final class CloseEndedOwnerContracts
{
    public function __invoke(): int
    {
        $today = now('Asia/Bahrain')->toDateString();

        return DB::transaction(fn () => OwnerContract::query()
            ->where('status', OwnerContractStatus::Active)
            ->where('end_date', '<', $today)
            ->lockForUpdate()
            ->get()
            ->each(fn (OwnerContract $contract) => $contract->forceFill([
                'status' => $contract->terminated_on ? OwnerContractStatus::Terminated : OwnerContractStatus::Ended,
            ])->save())
            ->count());
    }
}
```
Create `app/Console/Commands/CloseOwnerContracts.php`:
```php
<?php

namespace App\Console\Commands;

use App\Actions\OwnerContracts\CloseEndedOwnerContracts;
use Illuminate\Console\Command;

class CloseOwnerContracts extends Command
{
    protected $signature = 'rms:owner-contracts:close';

    protected $description = 'Move active owner contracts past their end date to ended or terminated';

    public function handle(CloseEndedOwnerContracts $close): int
    {
        $this->info(sprintf('%d owner contract(s) closed.', $close()));

        return self::SUCCESS;
    }
}
```
In `routes/console.php` add:
```php
Schedule::command('rms:owner-contracts:close')->dailyAt('02:15')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.owner_contracts')), (string) $url);
```
In `config/services.php` add to `forge.heartbeats`:
```php
            'owner_contracts' => env('HEARTBEAT_OWNER_CONTRACTS'),
```
In `.env.example` add after `HEARTBEAT_NUMBER_SEQUENCES=`:
```
HEARTBEAT_OWNER_CONTRACTS=
```

- [ ] **Step 5: The termination form on the contract page**

In `app/Livewire/OwnerContracts/Show.php` add the imports `App\Actions\OwnerContracts\RequestOwnerContractTermination` and `Illuminate\Validation\ValidationException`, the properties:
```php
    public string $terminatedOn = '';

    public string $terminationReason = '';
```
and the method:
```php
    public function requestTermination(RequestOwnerContractTermination $request): void
    {
        try {
            $request->handle($this->actor(), $this->contract(), $this->terminatedOn, $this->terminationReason);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            // The Action's keys → this component's properties, so errors show under the right field.
            throw ValidationException::withMessages(array_filter([
                'terminatedOn' => $e->errors()['terminated_on'] ?? [],
                'terminationReason' => $e->errors()['termination_reason'] ?? [],
                'approval' => $e->errors()['approval'] ?? [],
            ]));
        }

        $this->reset('terminatedOn', 'terminationReason');
        Flux::toast(variant: 'success', text: __('Early termination sent for approval.'));
    }
```

In `resources/views/livewire/owner-contracts/show.blade.php` replace `{{-- Task 7 adds Request early termination. --}}` with nothing, and insert before the "Approval history" block:
```blade
    @php($terminationPending = $approvals->contains(fn ($a) => $a->action === \App\Enums\ApprovalAction::OwnerContractTermination && $a->status === \App\Enums\ApprovalStatus::Pending))
    @if ($canManage && $contract->status === \App\Enums\OwnerContractStatus::Active && ! $contract->terminated_on)
        <flux:fieldset>
            <flux:legend>{{ __('Early termination') }}</flux:legend>
            @if ($terminationPending)
                <flux:text>{{ __('An early termination is waiting for Management approval.') }}</flux:text>
            @else
                <form wire:submit="requestTermination" class="mt-2 space-y-3">
                    <flux:input wire:model="terminatedOn" type="date" :label="__('Last day of the contract')" />
                    <flux:textarea wire:model="terminationReason" :label="__('Reason')" rows="2" />
                    <flux:button type="submit">{{ __('Request early termination') }}</flux:button>
                </form>
            @endif
        </flux:fieldset>
    @endif
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/OwnerContracts tests/Feature/Backup/ScheduleTest.php
"$PHP" artisan test
```
Expected: 9 new tests (with dataset rows) and the new schedule row pass; full suite passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add owner contract early termination and the 02:15 close job

Early termination goes through Management approval and shortens the
contract; the nightly job marks contracts past their end date as
ended or terminated.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 8: Expenses charged to the company or an owner

**Spec:** §4.7 (fields, owner-charge rule, approval limit, reversal), §8.1 (Finance ✔, Property Mgr enter, Admin and Management view), §8.2 (scope), §8.5 (no DELETE; amounts, `charge_to` and `owner_contract_id` frozen)

**Files:**
- Create: `app/Enums/{ExpenseCategory,ChargeTo,ExpenseStatus}.php`, `database/migrations/2026_10_01_000500_create_expenses_table.php`, `app/Models/Expense.php`, `database/factories/ExpenseFactory.php`, `app/Policies/ExpensePolicy.php`, `app/Actions/Expenses/{RecordExpense,ReverseExpense}.php`, `app/Livewire/Expenses/{Index,Form,Show}.php`, `resources/views/livewire/expenses/{index,form,show}.blade.php`, `tests/Feature/Expenses/ExpensesTest.php`, `tests/Feature/Expenses/ExpenseScreensTest.php`
- Modify: `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`, `app/Livewire/Documents/Panel.php`

**Interfaces:**
- Consumes: `OwnerContract::effectiveOn()`, `OwnerContractType::Managed`, `StoreDocument` (+ `MIMES`, `MAX_KB`), `DocumentCategory::OwnerApproval`, `Fils`, `Building::visibleTo()`
- Produces: `Expense` (`visibleTo($user)`, relations `building`, `unit`, `ownerContract`, `recorder`); `ExpensePolicy::viewAny/view/create/update/reverse`; `RecordExpense::handle(User $actor, array $data, ?UploadedFile $ownerApproval = null): Expense`; `ReverseExpense::handle(User $actor, Expense $expense, string $reason): Expense`; routes `expenses.index`, `expenses.create`, `expenses.show`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Expenses/ExpensesTest.php`:
```php
<?php

use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Enums\DocumentCategory;
use App\Enums\ExpenseStatus;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\Expense;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->pm = User::factory()->create()->assignRole(RoleName::PropertyManager);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->building = Building::factory()->create();
    $this->units = Unit::factory()->for($this->building)->count(2)->create();
    $this->owner = Owner::factory()->create();
    $this->managed = activeOwnerContract([
        'owner_id' => $this->owner->id, 'building_id' => $this->building->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'expense_approval_limit' => '200.000',
    ], [$this->units[0]]);
    $this->base = [
        'building_id' => $this->building->id, 'unit_id' => $this->units[0]->id, 'category' => 'maintenance',
        'description' => 'AC repair', 'expense_date' => '2026-10-01', 'net' => '120', 'tax_amount' => '12', 'charge_to' => 'company',
    ];
});

test('a property manager records a company expense', function () {
    $expense = app(RecordExpense::class)->handle($this->pm, $this->base);

    expect($expense->total)->toBe('132.000')
        ->and($expense->owner_contract_id)->toBeNull()
        ->and($expense->status)->toBe(ExpenseStatus::Recorded)
        ->and($expense->recorded_by)->toBe($this->pm->id)
        ->and($expense->posted_at)->not->toBeNull();
});

test('charging the owner resolves the managed contract covering the unit on the expense date', function () {
    $expense = app(RecordExpense::class)->handle($this->pm, [...$this->base, 'charge_to' => 'owner']);
    expect($expense->owner_contract_id)->toBe($this->managed->id);

    // Unit outside the contract, date outside the contract, or a leased contract: refused.
    foreach ([
        ['unit_id' => $this->units[1]->id],
        ['expense_date' => '2025-12-31'],
    ] as $change) {
        expect(fn () => app(RecordExpense::class)->handle($this->pm, [...$this->base, 'charge_to' => 'owner', ...$change]))
            ->toThrow(ValidationException::class, 'No managed owner contract covers this unit');
    }

    $leasedBuilding = Building::factory()->create();
    $leasedUnit = Unit::factory()->for($leasedBuilding)->create();
    activeOwnerContract(App\Models\OwnerContract::factory()->leased()->raw(['building_id' => $leasedBuilding->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']), [$leasedUnit]);
    expect(fn () => app(RecordExpense::class)->handle($this->pm, [...$this->base, 'building_id' => $leasedBuilding->id, 'unit_id' => $leasedUnit->id, 'charge_to' => 'owner']))
        ->toThrow(ValidationException::class);
});

test('a building-level owner charge needs exactly one managed contract in the building', function () {
    $expense = app(RecordExpense::class)->handle($this->pm, [...$this->base, 'unit_id' => null, 'charge_to' => 'owner']);
    expect($expense->owner_contract_id)->toBe($this->managed->id);

    activeOwnerContract(['building_id' => $this->building->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->units[1]]);

    expect(fn () => app(RecordExpense::class)->handle($this->pm, [...$this->base, 'unit_id' => null, 'charge_to' => 'owner']))
        ->toThrow(ValidationException::class, 'enter one expense per unit');
});

test('above the owner approval limit, a note and the approval document are required', function () {
    Storage::fake('local');
    $big = [...$this->base, 'charge_to' => 'owner', 'net' => '250', 'tax_amount' => '0'];
    $pdf = UploadedFile::fake()->create('owner-ok.pdf', 20, 'application/pdf');

    expect(fn () => app(RecordExpense::class)->handle($this->pm, $big))->toThrow(ValidationException::class);
    expect(fn () => app(RecordExpense::class)->handle($this->pm, [...$big, 'owner_approval_note' => 'Owner OK by phone']))->toThrow(ValidationException::class);

    $expense = app(RecordExpense::class)->handle($this->pm, [...$big, 'owner_approval_note' => 'Owner approved by email 3 Oct'], $pdf);

    expect(DB::table('documents')->where('documentable_type', $expense->getMorphClass())->where('documentable_id', $expense->id)->value('category'))
        ->toBe(DocumentCategory::OwnerApproval->value);

    // At or below the limit nothing extra is needed.
    expect(app(RecordExpense::class)->handle($this->pm, [...$big, 'net' => '200'])->total)->toBe('200.000');
});

test('Finance reverses once; property managers and Admin cannot reverse; Admin cannot record', function () {
    $expense = app(RecordExpense::class)->handle($this->pm, $this->base);
    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);

    expect(fn () => app(ReverseExpense::class)->handle($this->pm, $expense, 'Duplicate'))->toThrow(AuthorizationException::class);
    expect(fn () => app(ReverseExpense::class)->handle($admin, $expense, 'Duplicate'))->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordExpense::class)->handle($admin, $this->base))->toThrow(AuthorizationException::class);
    expect(fn () => app(ReverseExpense::class)->handle($this->finance, $expense, ' '))->toThrow(ValidationException::class);

    $reversed = app(ReverseExpense::class)->handle($this->finance, $expense, 'Duplicate of INV 88');
    expect($reversed->status)->toBe(ExpenseStatus::Reversed)
        ->and($reversed->reversed_by)->toBe($this->finance->id);

    expect(fn () => app(ReverseExpense::class)->handle($this->finance, $expense->fresh(), 'Again'))->toThrow(ValidationException::class, 'already reversed');
});

test('the database freezes amounts and attribution, refuses deletes, and keeps reversals final', function () {
    $expense = app(RecordExpense::class)->handle($this->pm, [...$this->base, 'charge_to' => 'owner']);
    $row = fn () => DB::table('expenses')->where('id', $expense->id);

    expect(fn () => $row()->update(['net' => '1.000', 'total' => '13.000']))->toThrow(QueryException::class, 'frozen');
    expect(fn () => $row()->update(['charge_to' => 'company', 'owner_contract_id' => null]))->toThrow(QueryException::class, 'frozen');
    expect(fn () => $row()->delete())->toThrow(QueryException::class, 'expenses cannot be deleted');

    app(ReverseExpense::class)->handle($this->finance, $expense, 'Wrong unit');
    expect(fn () => $row()->update(['status' => 'recorded', 'reversed_at' => null, 'reversed_by' => null, 'reversal_reason' => null]))
        ->toThrow(QueryException::class, 'a reversal is final');
});

test('expense writes and lists follow the building scope', function () {
    $other = Building::factory()->create();
    $scoped = User::factory()->create()->assignRole(RoleName::Leasing);
    $scoped->givePermissionTo('expenses.manage');
    $scoped->buildings()->attach($this->building->id);

    app(RecordExpense::class)->handle($scoped, [...$this->base, 'unit_id' => null]);
    expect(fn () => app(RecordExpense::class)->handle($scoped, [...$this->base, 'building_id' => $other->id, 'unit_id' => null]))
        ->toThrow(AuthorizationException::class);

    Expense::factory()->create(['building_id' => $other->id]);
    expect(Expense::visibleTo($scoped)->count())->toBe(1)
        ->and(Expense::visibleTo($this->finance)->count())->toBe(2);
});
```
Create `tests/Feature/Expenses/ExpenseScreensTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\Expenses\Form;
use App\Livewire\Expenses\Show;
use App\Models\Building;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->building = Building::factory()->create();
    $this->pm = User::factory()->create()->assignRole(RoleName::PropertyManager);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
});

test('a property manager records an expense from the form', function () {
    Livewire::actingAs($this->pm)->test(Form::class)
        ->set('form.building_id', $this->building->id)
        ->set('form.description', 'Lift service')
        ->set('form.net', '80')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Expense::sole()->total)->toBe('80.000');
});

test('the reverse form is only offered to Finance', function () {
    $expense = Expense::factory()->create(['building_id' => $this->building->id]);

    Livewire::actingAs($this->pm)->test(Show::class, ['expense' => $expense])->assertDontSee('Reverse expense');

    Livewire::actingAs($this->finance)->test(Show::class, ['expense' => $expense])
        ->assertSee('Reverse expense')
        ->set('reason', 'Entered twice')
        ->call('reverse')
        ->assertHasNoErrors();

    expect($expense->fresh()->status->value)->toBe('reversed');
});

test('who can open the expense pages', function () {
    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($admin)->get(route('expenses.index'))->assertOk();
    $this->actingAs($admin)->get(route('expenses.create'))->assertForbidden();
    $this->actingAs($leasing)->get(route('expenses.index'))->assertForbidden();
    $this->actingAs($this->pm)->get(route('expenses.create'))->assertOk();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Expenses`
Expected: FAIL — `Class "App\Actions\Expenses\RecordExpense" not found`.

- [ ] **Step 3: Enums and migration**

Create `app/Enums/ExpenseCategory.php`:
```php
<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Maintenance = 'maintenance';
    case Utilities = 'utilities';
    case Cleaning = 'cleaning';
    case Security = 'security';
    case Insurance = 'insurance';
    case Government = 'government'; // municipality and other official fees
    case Legal = 'legal';
    case Commission = 'commission';
    case Other = 'other';
}
```
Create `app/Enums/ChargeTo.php`:
```php
<?php

namespace App\Enums;

enum ChargeTo: string
{
    case Company = 'company';
    case Owner = 'owner';
    case Tenant = 'tenant'; // M3: creates a manual invoice (spec §4.7)
}
```
Create `app/Enums/ExpenseStatus.php`:
```php
<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Recorded = 'recorded';
    case Reversed = 'reversed';
}
```
Create `database/migrations/2026_10_01_000500_create_expenses_table.php`:
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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category', 30);
            $table->string('description', 500);
            $table->date('expense_date');
            $table->decimal('net', 12, 3);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('total', 12, 3);
            $table->string('charge_to', 20);
            $table->foreignId('owner_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('owner_approval_note')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['building_id', 'expense_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expenses
                ADD CONSTRAINT expenses_category_chk CHECK (category IN ('maintenance', 'utilities', 'cleaning', 'security', 'insurance', 'government', 'legal', 'commission', 'other')),
                ADD CONSTRAINT expenses_charge_to_chk CHECK (charge_to IN ('company', 'owner', 'tenant')),
                ADD CONSTRAINT expenses_status_chk CHECK (status IN ('recorded', 'reversed')),
                ADD CONSTRAINT expenses_amounts_chk CHECK (net >= 0 AND tax_amount >= 0 AND total = net + tax_amount AND total > 0),
                ADD CONSTRAINT expenses_owner_chk CHECK ((charge_to = 'owner') = (owner_contract_id IS NOT NULL)),
                ADD CONSTRAINT expenses_reversed_chk CHECK ((status = 'reversed') = (reversed_at IS NOT NULL) AND (status = 'reversed') = (reversed_by IS NOT NULL))
            SQL);

        // Spec §8.5.
        DB::unprepared("CREATE TRIGGER expenses_no_delete BEFORE DELETE ON expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'expenses cannot be deleted'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER expenses_guard BEFORE UPDATE ON expenses FOR EACH ROW
            BEGIN
                IF NOT (NEW.net <=> OLD.net AND NEW.tax_amount <=> OLD.tax_amount AND NEW.total <=> OLD.total
                    AND NEW.charge_to <=> OLD.charge_to AND NEW.owner_contract_id <=> OLD.owner_contract_id
                    AND NEW.building_id <=> OLD.building_id AND NEW.unit_id <=> OLD.unit_id AND NEW.expense_date <=> OLD.expense_date
                    AND NEW.recorded_by <=> OLD.recorded_by AND NEW.posted_at <=> OLD.posted_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'expenses: amounts, charge_to and attribution are frozen';
                END IF;
                IF OLD.status = 'reversed' AND NOT (NEW.status <=> OLD.status AND NEW.reversed_at <=> OLD.reversed_at
                    AND NEW.reversed_by <=> OLD.reversed_by AND NEW.reversal_reason <=> OLD.reversal_reason) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'expenses: a reversal is final';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS expenses_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS expenses_no_delete');
        Schema::dropIfExists('expenses');
    }
};
```

- [ ] **Step 4: Model, factory, policy**

Create `app/Models/Expense.php`:
```php
<?php

namespace App\Models;

use App\Enums\ChargeTo;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Written only by RecordExpense and ReverseExpense; amounts are set with forceFill.
 *
 * @property int $id
 * @property int $building_id
 * @property int|null $unit_id
 * @property ExpenseCategory $category
 * @property CarbonImmutable $expense_date
 * @property string $net
 * @property string $tax_amount
 * @property string $total
 * @property ChargeTo $charge_to
 * @property int|null $owner_contract_id
 * @property ExpenseStatus $status
 * @property int|null $reversed_by
 * @property int $recorded_by
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['building_id', 'unit_id', 'category', 'description', 'expense_date', 'owner_approval_note'];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'charge_to' => ChargeTo::class,
            'status' => ExpenseStatus::class,
            'expense_date' => 'immutable_date',
            'net' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'total' => 'decimal:3',
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
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

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @param  Builder<Expense>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('building', fn (Builder $q) => $q->visibleTo($user));
    }
}
```
Create `database/factories/ExpenseFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'category' => 'maintenance',
            'description' => fake()->sentence(3),
            'expense_date' => now()->toDateString(),
            'net' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'charge_to' => 'company',
            'status' => 'recorded',
            'posted_at' => now(),
            'recorded_by' => User::factory(),
        ];
    }
}
```
Create `app/Policies/ExpensePolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Building;
use App\Models\Expense;
use App\Models\User;

/** Spec §8.1: Finance records and reverses; Property Mgr records; finance.view holders (Admin, Management) view. */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::ExpensesManage) || $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user) && self::inScope($user, $expense);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::ExpensesManage);
    }

    /** Attaching documents. */
    public function update(User $user, Expense $expense): bool
    {
        return $user->can(PermissionName::ExpensesManage) && self::inScope($user, $expense);
    }

    public function reverse(User $user, Expense $expense): bool
    {
        return $user->can(PermissionName::ExpensesManage) && $user->can(PermissionName::FinanceView) && self::inScope($user, $expense);
    }

    private static function inScope(User $user, Expense $expense): bool
    {
        return Building::visibleTo($user)->whereKey($expense->building_id)->exists();
    }
}
```

- [ ] **Step 5: The Actions**

Create `app/Actions/Expenses/RecordExpense.php`:
```php
<?php

namespace App\Actions\Expenses;

use App\Actions\Documents\StoreDocument;
use App\Enums\ChargeTo;
use App\Enums\DocumentCategory;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\OwnerContractType;
use App\Models\Building;
use App\Models\Expense;
use App\Models\OwnerContract;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §4.7, company and owner charges. */
final class RecordExpense
{
    public function __construct(private StoreDocument $documents) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, array $data, ?UploadedFile $ownerApproval = null): Expense
    {
        if (! $actor->can('create', Expense::class)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data);

        $validated = Validator::make([...$data, 'owner_approval' => $ownerApproval], [
            'building_id' => ['required', 'integer', Rule::exists('buildings', 'id')->whereNull('deleted_at')],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('building_id', (int) ($data['building_id'] ?? 0))->whereNull('deleted_at')],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'max:500'],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'net' => ['required', Fils::rule()],
            'tax_amount' => ['nullable', Fils::rule()],
            // ponytail: charge_to = tenant arrives in M3, where it creates a manual invoice (spec §4.7).
            'charge_to' => ['required', Rule::in([ChargeTo::Company->value, ChargeTo::Owner->value])],
            'owner_approval_note' => ['nullable', 'string', 'max:2000'],
            'owner_approval' => ['nullable', 'file', 'mimes:'.StoreDocument::MIMES, 'max:'.StoreDocument::MAX_KB],
        ])->validate();

        if (! Building::visibleTo($actor)->whereKey($validated['building_id'])->exists()) {
            throw new AuthorizationException;
        }

        $net = Fils::fromDecimal((string) $validated['net']);
        $tax = Fils::fromDecimal((string) ($validated['tax_amount'] ?? '0'));

        if ($net + $tax === 0) {
            throw ValidationException::withMessages(['net' => __('Enter the amount.')]);
        }

        return DB::transaction(function () use ($actor, $validated, $net, $tax, $ownerApproval) {
            $contract = $validated['charge_to'] === ChargeTo::Owner->value ? $this->ownerContract($validated) : null;

            if ($contract?->expense_approval_limit !== null && $net + $tax > Fils::fromDecimal($contract->expense_approval_limit)
                && (blank($validated['owner_approval_note'] ?? null) || $ownerApproval === null)) {
                throw ValidationException::withMessages(['owner_approval_note' => __('Above the owner\'s approval limit of :limit BHD: add the owner\'s approval note and document.', ['limit' => $contract->expense_approval_limit])]);
            }

            $expense = new Expense(Arr::only($validated, ['building_id', 'unit_id', 'category', 'description', 'expense_date', 'owner_approval_note']));
            $expense->forceFill([
                'net' => Fils::toDecimal($net),
                'tax_amount' => Fils::toDecimal($tax),
                'total' => Fils::toDecimal($net + $tax),
                'charge_to' => $validated['charge_to'],
                'owner_contract_id' => $contract?->id,
                'status' => ExpenseStatus::Recorded,
                'posted_at' => now(),
                'recorded_by' => $actor->id,
            ])->save();
            // ponytail: M4 posts owner-charged expenses to the owner ledger (spec §7.9).

            if ($ownerApproval) {
                $this->documents->handle($actor, $expense, $ownerApproval, DocumentCategory::OwnerApproval);
            }

            return $expense;
        });
    }

    /** @param  array<string, mixed>  $v */
    private function ownerContract(array $v): OwnerContract
    {
        $unitId = $v['unit_id'] ?? null;

        $contracts = OwnerContract::query()
            ->effectiveOn((string) $v['expense_date'])
            ->where('type', OwnerContractType::Managed)
            ->where('building_id', $v['building_id'])
            ->when($unitId, fn ($q) => $q->whereHas('units', fn ($units) => $units->whereKey($unitId)))
            ->get();

        if ($contracts->count() !== 1) {
            throw ValidationException::withMessages(['charge_to' => $unitId
                ? __('No managed owner contract covers this unit on that date.')
                : __('Charging the owner without a unit needs exactly one managed contract in the building on that date; enter one expense per unit instead.')]);
        }

        return $contracts->first();
    }
}
```
Create `app/Actions/Expenses/ReverseExpense.php`:
```php
<?php

namespace App\Actions\Expenses;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReverseExpense
{
    public function handle(User $actor, Expense $expense, string $reason): Expense
    {
        if (! $actor->can('reverse', $expense)) {
            throw new AuthorizationException;
        }

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason (up to 500 characters).')]);
        }

        return DB::transaction(function () use ($actor, $expense, $reason) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status === ExpenseStatus::Reversed) {
                throw ValidationException::withMessages(['reason' => __('This expense is already reversed.')]);
            }

            $expense->forceFill([
                'status' => ExpenseStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();
            // ponytail: M4 shows the opposite owner-ledger entry at reversed_at (spec §7.9).

            return $expense;
        });
    }
}
```

- [ ] **Step 6: Screens, routes, navigation**

Create `app/Livewire/Expenses/Index.php`:
```php
<?php

namespace App\Livewire\Expenses;

use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Expense;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Expenses')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public ?int $buildingId = null;

    #[Url]
    public string $status = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $expenses = Expense::query()
            ->visibleTo($this->actor())
            ->with(['building:id,code', 'unit:id,code', 'ownerContract:id,number'])
            ->when($this->buildingId, fn ($q) => $q->where('building_id', $this->buildingId))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest('expense_date')->latest('id')
            ->paginate(25);

        return view('livewire.expenses.index', [
            'expenses' => $expenses,
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
}
```
Create `resources/views/livewire/expenses/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Expenses') }}</flux:heading>
        @can('create', \App\Models\Expense::class)
            <flux:button variant="primary" :href="route('expenses.create')" wire:navigate>{{ __('Record expense') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="buildingId" class="sm:max-w-64">
            <option value="">{{ __('All buildings') }}</option>
            @foreach ($buildings as $building)<option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="status" class="sm:max-w-40">
            <option value="">{{ __('Any status') }}</option>
            <option value="recorded">{{ __('Recorded') }}</option>
            <option value="reversed">{{ __('Reversed') }}</option>
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$expenses">
            <flux:table.columns>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Building / unit') }}</flux:table.column>
                <flux:table.column>{{ __('Description') }}</flux:table.column>
                <flux:table.column>{{ __('Charged to') }}</flux:table.column>
                <flux:table.column>{{ __('Total (BHD)') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($expenses as $expense)
                    <flux:table.row :key="$expense->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $expense->expense_date->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $expense->building->code }}{{ $expense->unit ? ' / '.$expense->unit->code : '' }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('expenses.show', $expense)" wire:navigate>{{ str($expense->description)->limit(40) }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ str($expense->charge_to->value)->headline() }}{{ $expense->ownerContract ? ' ('.$expense->ownerContract->number.')' : '' }}</flux:table.cell>
                        <flux:table.cell class="text-end tabular-nums">{{ $expense->total }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$expense->status->value === 'reversed' ? 'zinc' : 'green'">{{ str($expense->status->value)->headline() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/Expenses/Form.php`:
```php
<?php

namespace App\Livewire\Expenses;

use App\Actions\Expenses\RecordExpense;
use App\Enums\ExpenseCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Expense;
use App\Models\Unit;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Record expense')]
class Form extends Component
{
    use WithActor, WithFileUploads;

    /** @var array<string, mixed> */
    public array $form = ['category' => 'maintenance', 'charge_to' => 'company', 'tax_amount' => '0'];

    /** @var UploadedFile|null */
    public $ownerApproval = null;

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', Expense::class), 403);
        $this->form['expense_date'] = now('Asia/Bahrain')->toDateString();
    }

    public function updatedForm(mixed $value, string $key): void
    {
        if ($key === 'building_id') {
            $this->form['unit_id'] = null;
        }
    }

    public function save(RecordExpense $record): void
    {
        try {
            $expense = $record->handle($this->actor(), $this->form, $this->ownerApproval);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [in_array($k, ['owner_approval', 'file'], true) ? 'ownerApproval' : "form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Expense recorded.'));
        $this->redirectRoute('expenses.show', $expense, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.expenses.form', [
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
            'units' => Unit::query()->where('building_id', $this->form['building_id'] ?? 0)->orderBy('code')->get(['id', 'code']),
            'categories' => ExpenseCategory::cases(),
        ]);
    }
}
```
Create `resources/views/livewire/expenses/form.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Record expense') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model.live="form.building_id" :label="__('Building')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($buildings as $building)<option value="{{ $building->id }}">{{ $building->code }} — {{ $building->name }}</option>@endforeach
            </flux:select>
            <flux:select wire:model="form.unit_id" :label="__('Unit')">
                <option value="">{{ __('Whole building') }}</option>
                @foreach ($units as $unit)<option value="{{ $unit->id }}">{{ $unit->code }}</option>@endforeach
            </flux:select>
            <flux:select wire:model="form.category" :label="__('Category')">
                @foreach ($categories as $c)<option value="{{ $c->value }}">{{ str($c->value)->headline() }}</option>@endforeach
            </flux:select>
            <flux:input wire:model="form.expense_date" type="date" :label="__('Date')" />
        </div>
        <flux:input wire:model="form.description" :label="__('Description')" />
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.net" inputmode="decimal" :label="__('Net (BHD)')" />
            <flux:input wire:model="form.tax_amount" inputmode="decimal" :label="__('VAT (BHD)')" />
        </div>

        <flux:radio.group wire:model.live="form.charge_to" :label="__('Charge to')" variant="segmented">
            <flux:radio value="company" :label="__('Company')" />
            <flux:radio value="owner" :label="__('Owner')" />
        </flux:radio.group>

        @if (($form['charge_to'] ?? '') === 'owner')
            <flux:textarea wire:model="form.owner_approval_note" :label="__('Owner approval note (needed above the contract limit)')" rows="2" />
            <flux:field>
                <flux:label>{{ __('Owner approval document') }}</flux:label>
                <input type="file" wire:model="ownerApproval" class="block w-full text-sm" />
                <flux:error name="ownerApproval" />
            </flux:field>
        @endif

        <flux:button variant="primary" type="submit">{{ __('Record') }}</flux:button>
    </form>
</section>
```
Create `app/Livewire/Expenses/Show.php`:
```php
<?php

namespace App\Livewire\Expenses;

use App\Actions\Expenses\ReverseExpense;
use App\Enums\ExpenseStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Expense;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $expenseId;

    public string $reason = '';

    public function mount(Expense $expense): void
    {
        abort_unless($this->actor()->can('view', $expense), 403);
        $this->expenseId = $expense->id;
    }

    public function reverse(ReverseExpense $reverse): void
    {
        try {
            $reverse->handle($this->actor(), Expense::findOrFail($this->expenseId), $this->reason);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->reset('reason');
        Flux::toast(variant: 'success', text: __('Expense reversed.'));
    }

    public function render(): View
    {
        $expense = Expense::with(['building:id,code,name', 'unit:id,code', 'ownerContract:id,number', 'recorder:id,name'])->findOrFail($this->expenseId);

        return view('livewire.expenses.show', [
            'expense' => $expense,
            'canReverse' => $expense->status === ExpenseStatus::Recorded && $this->actor()->can('reverse', $expense),
        ])->title(__('Expense #:id', ['id' => $expense->id]));
    }
}
```
Create `resources/views/livewire/expenses/show.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-8">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ $expense->description }}</flux:heading>
        <flux:badge>{{ str($expense->status->value)->headline() }}</flux:badge>
    </div>

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Date') }}</dt><dd>{{ $expense->expense_date->format('d/m/Y') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Building / unit') }}</dt><dd>{{ $expense->building->code }}{{ $expense->unit ? ' / '.$expense->unit->code : ' ('.__('whole building').')' }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Category') }}</dt><dd>{{ str($expense->category->value)->headline() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Charged to') }}</dt><dd>{{ str($expense->charge_to->value)->headline() }}{{ $expense->ownerContract ? ' ('.$expense->ownerContract->number.')' : '' }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Net / VAT / total (BHD)') }}</dt><dd class="tabular-nums">{{ $expense->net }} / {{ $expense->tax_amount }} / {{ $expense->total }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Recorded by') }}</dt><dd>{{ $expense->recorder->name }}</dd></div>
        @if ($expense->owner_approval_note)
            <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Owner approval') }}</dt><dd>{{ $expense->owner_approval_note }}</dd></div>
        @endif
        @if ($expense->reversal_reason)
            <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Reversed') }}</dt><dd>{{ $expense->reversed_at?->timezone('Asia/Bahrain')->format('d/m/Y H:i') }} — {{ $expense->reversal_reason }}</dd></div>
        @endif
    </dl>

    @if ($canReverse)
        <form wire:submit="reverse" class="space-y-3">
            <flux:heading size="lg">{{ __('Reverse expense') }}</flux:heading>
            <flux:textarea wire:model="reason" :label="__('Reason')" rows="2" />
            <flux:button type="submit" variant="danger" wire:confirm="{{ __('Reverse this expense? This cannot be undone.') }}">{{ __('Reverse') }}</flux:button>
        </form>
    @endif

    <livewire:documents.panel :documentable="$expense" :key="'docs-expense-'.$expense->id" />
</section>
```
In `app/Livewire/Documents/Panel.php` add `Expense::class` to `ALLOWED` (import `App\Models\Expense`).

In `routes/property.php` add `use App\Livewire\Expenses;` and `use App\Models\Expense;` and inside the group:
```php
    Route::livewire('expenses', Expenses\Index::class)->middleware('can:viewAny,'.Expense::class)->name('expenses.index');
    Route::livewire('expenses/create', Expenses\Form::class)->middleware('can:expenses.manage')->name('expenses.create');
    Route::livewire('expenses/{expense}', Expenses\Show::class)->middleware('can:viewAny,'.Expense::class)->name('expenses.show');
```
In `resources/views/layouts/app/sidebar.blade.php`, add inside the Property group:
```blade
                    @can('viewAny', \App\Models\Expense::class)
                        <flux:sidebar.item icon="receipt-percent" :href="route('expenses.index')" :current="request()->routeIs('expenses.*')" wire:navigate>{{ __('Expenses') }}</flux:sidebar.item>
                    @endcan
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Expenses
"$PHP" artisan test
```
Expected: 10 new tests pass; full suite passes.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add expenses charged to the company or an owner

Owner charges resolve the managed contract covering the unit on the
expense date, and need the owner's note and document above the
contract's limit. Finance reverses; MySQL freezes amounts and
attribution and refuses deletes.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 9: The go-live importer with dry run

**Spec:** §11 (import.run only, closed after `go_live_at`, templates with Text columns, dry run row by row, one transaction per file set, imported contracts active with an "Imported by" approval), §15 M1 exit criterion

**Files:**
- Create: `app/Enums/ImportKind.php`, `app/Actions/Import/{RunImport,ImportResult}.php`, `app/Http/Controllers/ImportTemplateController.php`, `app/Livewire/Import/Index.php`, `resources/views/livewire/import/index.blade.php`, `tests/Feature/Import/ImportTest.php`
- Modify: `composer.json`/`composer.lock` (add `spatie/simple-excel`), `app/Actions/Owners/SaveOwner.php`, `routes/property.php`, `resources/views/layouts/app/sidebar.blade.php`, `deploy/README.md`

**Interfaces:**
- Consumes: `SaveBuilding`, `SaveUnit`, `SaveOwner`, `SaveOwnerContract`, `ActivateOwnerContract`, `Approval`, `ApprovalAction::OwnerContractActivation`, `CompanySetting::current()->go_live_at`, `Audit`
- Produces: `ImportKind` (`Buildings`, `Units`, `Owners`, `OwnerContracts`; `headers()`, `textColumns()`) — M5 adds its kinds here; `RunImport::handle(User $actor, array $paths, bool $commit): ImportResult` with `$paths` = kind value ⇒ local `.xlsx`/`.csv` path; `RunImport::ensureOpen(): void`; `ImportResult` (`errors`: kind ⇒ line ⇒ messages, `counts`, `committed`); `SaveOwner::handle(..., bool $viaImport = false)`; routes `import.index`, `import.template`; audit event `import.run`

- [ ] **Step 1: Install simple-excel**

```bash
$COMPOSER require spatie/simple-excel:^3.10
```
Expected: installs 3.10.x with openspout 4.x. Check the reader API in the installed source before coding: `vendor/spatie/simple-excel/src/SimpleExcelReader.php` must have `create()`, `headersToSnakeCase()`, `getHeaders()` and `getRows()`; the writer must have `create()`, `addHeader()`, `addRow()` and `close()`. If a name differs, use the installed one in the code below.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Import/ImportTest.php`:
```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Import\RunImport;
use App\Enums\ApprovalStatus;
use App\Enums\ImportKind;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Livewire\Import\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

/** Writes an .xlsx with the kind's full header row; $rows only list the columns they fill. */
function importFile(ImportKind $kind, array $rows): string
{
    $path = sys_get_temp_dir().'/'.uniqid($kind->value.'-').'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    foreach ($rows as $row) {
        $writer->addRow([...array_fill_keys($kind->headers(), ''), ...$row]);
    }
    $writer->close();

    return $path;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->vendor = User::factory()->withTwoFactor()->create()->assignRole(RoleName::VendorSupport);

    $this->files = [
        'buildings' => importFile(ImportKind::Buildings, [
            ['code' => 'MT', 'name' => 'Marina Tower', 'type' => 'residential', 'floors_count' => 12],
        ]),
        'units' => importFile(ImportKind::Units, [
            ['building_code' => 'MT', 'code' => '101', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '450.000', 'blocked' => 'no'],
            ['building_code' => 'MT', 'code' => '102', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'semi', 'list_rent' => '480.500', 'blocked' => 'yes', 'blocked_reason' => 'Renovation'],
        ]),
        'owners' => importFile(ImportKind::Owners, [
            ['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '080101234', 'phone' => '+97333000000', 'iban' => 'BH67BMAG00001299123456', 'bank_name' => 'NBB', 'account_name' => 'Ali Hassan'],
        ]),
        'owner_contracts' => importFile(ImportKind::OwnerContracts, [
            ['owner_id_type' => 'cpr', 'owner_id_number' => '080101234', 'building_code' => 'MT', 'units' => 'ALL', 'type' => 'managed',
                'start_date' => '01/01/2026', 'end_date' => '2026-12-31', 'fee_type' => 'percent_collected', 'fee_value' => '7', 'deposits_held_by' => 'company'],
        ]),
    ];
});

test('a dry run validates every file and saves nothing', function () {
    $result = app(RunImport::class)->handle($this->vendor, $this->files, commit: false);

    expect($result->errors)->toBe([])
        ->and($result->counts)->toBe(['buildings' => 1, 'units' => 2, 'owners' => 1, 'owner_contracts' => 1])
        ->and($result->committed)->toBeFalse()
        ->and(Building::count() + Unit::count() + Owner::count() + OwnerContract::count())->toBe(0);
});

test('a clean import saves everything and activates contracts with an Imported by approval', function () {
    $result = app(RunImport::class)->handle($this->vendor, $this->files, commit: true);

    expect($result->committed)->toBeTrue();

    $owner = Owner::sole();
    expect($owner->id_number)->toBe('080101234') // leading zero kept: the column is Text
        ->and($owner->iban)->toBe('BH67BMAG00001299123456')
        ->and(Unit::where('code', '102')->sole()->blocked)->toBeTrue();

    $contract = OwnerContract::sole();
    $approval = $contract->approvals()->sole();
    expect($contract->status)->toBe(OwnerContractStatus::Active)
        ->and($contract->number)->toBe('OC-2026-000001')
        ->and($contract->start_date->toDateString())->toBe('2026-01-01')
        ->and($contract->units()->count())->toBe(2)
        ->and($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->comment)->toBe('Imported by '.$this->vendor->name);
});

test('any bad row blocks the whole file set, and errors name the file and line', function () {
    $this->files['units'] = importFile(ImportKind::Units, [
        ['building_code' => 'MT', 'code' => '101', 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '450.000'],
        ['building_code' => 'NOPE', 'code' => '102', 'use' => 'castle', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '1.2345'],
    ]);

    $result = app(RunImport::class)->handle($this->vendor, $this->files, commit: true);

    expect($result->committed)->toBeFalse()
        ->and(array_keys($result->errors['units']))->toBe([3])
        ->and($result->errors['units'][3][0])->toContain('NOPE')
        ->and(Building::count())->toBe(0);
});

test('numbers typed into Text columns are refused', function () {
    $this->files['owners'] = importFile(ImportKind::Owners, [
        ['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => 80101234],
    ]);

    $result = app(RunImport::class)->handle($this->vendor, ['owners' => $this->files['owners']], commit: false);

    expect($result->errors['owners'][2][0])->toContain('formatted as Text');
});

test('a file missing columns is reported on line 1', function () {
    $path = sys_get_temp_dir().'/'.uniqid('bad-').'.xlsx';
    SimpleExcelWriter::create($path)->addRow(['code' => 'MT', 'name' => 'Marina'])->close();

    $result = app(RunImport::class)->handle($this->vendor, ['buildings' => $path], commit: false);

    expect($result->errors['buildings'][1][0])->toContain('Missing columns');
});

test('only import.run holders import, and never after go-live', function () {
    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    expect(fn () => app(RunImport::class)->handle($admin, $this->files, commit: false))->toThrow(AuthorizationException::class);

    CompanySetting::current()->forceFill(['go_live_at' => now()->subDay()])->save();
    expect(fn () => app(RunImport::class)->handle($this->vendor, $this->files, commit: false))->toThrow(ValidationException::class, 'imports are closed');
});

test('templates download with the header row', function () {
    $response = $this->actingAs($this->vendor)->get(route('import.template', 'units'))->assertOk()->assertDownload('units-template.xlsx');

    expect(SimpleExcelReader::create($response->getFile()->getPathname())->getHeaders())->toBe(ImportKind::Units->headers());

    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin))->get(route('import.template', 'units'))->assertForbidden();
});

test('the import screen runs a dry run on uploaded files', function () {
    $upload = UploadedFile::fake()->createWithContent('owners.xlsx', file_get_contents($this->files['owners']));

    Livewire::actingAs($this->vendor)->test(Index::class)
        ->set('files.owners', $upload)
        ->call('run', false)
        ->assertHasNoErrors()
        ->assertSet('result.counts.owners', 1)
        ->assertSet('result.committed', false);

    expect(Owner::count())->toBe(0);
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Import`
Expected: FAIL — `Class "App\Enums\ImportKind" not found`.

- [ ] **Step 4: Let SaveOwner accept imported bank details**

Vendor Support runs the import but never holds `owners.bank.manage` (spec §8.1). In `app/Actions/Owners/SaveOwner.php` change the signature to:
```php
    public function handle(User $actor, ?Owner $owner, array $data, bool $viaImport = false): Owner
```
replace the bank permission check with:
```php
        // The go-live import (import.run, new owners only) brings existing bank details across (spec §11).
        $importing = $viaImport && $owner === null && $actor->can(PermissionName::ImportRun);

        if ($bankChanged && ! $importing && ! $actor->can(PermissionName::OwnersBankManage)) {
            throw new AuthorizationException(__('Only holders of owners.bank.manage can change bank details.'));
        }
```
pass `$importing` into the transaction closure (`use (..., $importing)`) and stamp only real changes, so imported owners do not show the 30-day "bank details changed" warning:
```php
            if ($bankChanged && ! $importing) {
                $owner->forceFill(['bank_changed_at' => now(), 'bank_changed_by' => $actor->id]);
            }
```
The masked `owner.bank.changed` audit entry is still written for imports.

- [ ] **Step 5: ImportKind, ImportResult, RunImport**

Create `app/Enums/ImportKind.php`:
```php
<?php

namespace App\Enums;

/** Go-live import files, in the order they are imported (later files refer to earlier ones by code). */
enum ImportKind: string
{
    case Buildings = 'buildings';
    case Units = 'units';
    case Owners = 'owners';
    case OwnerContracts = 'owner_contracts';

    /** @return list<string> */
    public function headers(): array
    {
        return match ($this) {
            self::Buildings => ['code', 'name', 'type', 'location', 'address', 'floors_count', 'parking', 'facilities', 'notes'],
            self::Units => ['building_code', 'code', 'floor', 'use', 'type', 'bedrooms', 'bathrooms', 'area_sqm', 'furnishing',
                'list_rent', 'list_deposit', 'list_service_charge', 'default_tax_category', 'ewa_account_no', 'blocked', 'blocked_reason', 'notes'],
            self::Owners => ['type', 'name_en', 'name_ar', 'id_type', 'id_number', 'nationality', 'phone', 'email', 'address',
                'bank_name', 'iban', 'account_name', 'notes'],
            self::OwnerContracts => ['owner_id_type', 'owner_id_number', 'building_code', 'units', 'type', 'start_date', 'end_date',
                'rent_amount', 'payment_frequency', 'fee_type', 'fee_value', 'expense_approval_limit', 'deposits_held_by', 'notes'],
        };
    }

    /**
     * Spec §11: ID, phone, IBAN and money columns must be Text in Excel; a number there has
     * already lost leading zeros or decimals, so it is refused rather than guessed at.
     *
     * @return list<string>
     */
    public function textColumns(): array
    {
        return match ($this) {
            self::Buildings => [],
            self::Units => ['list_rent', 'list_deposit', 'list_service_charge', 'ewa_account_no'],
            self::Owners => ['id_number', 'phone', 'iban'],
            self::OwnerContracts => ['owner_id_number', 'rent_amount', 'fee_value', 'expense_approval_limit'],
        };
    }
}
```
Create `app/Actions/Import/ImportResult.php`:
```php
<?php

namespace App\Actions\Import;

final readonly class ImportResult
{
    /**
     * @param  array<string, array<int, list<string>>>  $errors  kind => spreadsheet line => messages
     * @param  array<string, int>  $counts  rows that passed, per kind
     */
    public function __construct(public array $errors, public array $counts, public bool $committed) {}
}
```
Create `app/Actions/Import/RunImport.php`:
```php
<?php

namespace App\Actions\Import;

use App\Actions\Buildings\SaveBuilding;
use App\Actions\OwnerContracts\ActivateOwnerContract;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\Owners\SaveOwner;
use App\Actions\Units\SaveUnit;
use App\Audit\Audit;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\ImportKind;
use App\Enums\OwnerContractStatus;
use App\Enums\PermissionName;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Spec §11. Dry run and real run are the same code: every row goes through the normal Actions
 * inside one transaction, which is committed only when asked AND every row of every file passed.
 */
final class RunImport
{
    public function __construct(
        private SaveBuilding $buildings,
        private SaveUnit $units,
        private SaveOwner $owners,
        private SaveOwnerContract $contracts,
        private ActivateOwnerContract $activate,
    ) {}

    /** @param  array<string, string>  $paths  ImportKind value => local .xlsx or .csv path */
    public function handle(User $actor, array $paths, bool $commit): ImportResult
    {
        if (! $actor->can(PermissionName::ImportRun)) {
            throw new AuthorizationException;
        }

        self::ensureOpen();

        $errors = [];
        $counts = [];

        DB::beginTransaction();

        try {
            foreach (ImportKind::cases() as $kind) {
                if (! isset($paths[$kind->value])) {
                    continue;
                }

                $counts[$kind->value] = 0;

                try {
                    $reader = SimpleExcelReader::create($paths[$kind->value])->headersToSnakeCase();
                    $headers = $reader->getHeaders() ?? [];
                } catch (Throwable) {
                    $errors[$kind->value][1] = [__('This file cannot be read as .xlsx or .csv.')];

                    continue;
                }

                $missing = array_values(array_diff($kind->headers(), $headers));

                if ($missing !== []) {
                    $errors[$kind->value][1] = [__('Missing columns: :list', ['list' => implode(', ', $missing)])];

                    continue;
                }

                foreach ($reader->getRows() as $index => $row) {
                    try {
                        // A savepoint per row: a failed row leaves nothing behind for later rows to trip on.
                        DB::transaction(fn () => $this->importRow($actor, $kind, $this->normalise($kind, $row)));
                        $counts[$kind->value]++;
                    } catch (ValidationException $e) {
                        $errors[$kind->value][(int) $index + 2] = array_values(Arr::flatten($e->errors()));
                    }
                }
            }

            $committed = $commit && $errors === [];

            if ($committed) {
                Audit::log('import.run', properties: ['counts' => $counts], causer: $actor);
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return new ImportResult($errors, $counts, $committed);
    }

    /** After cutover the import screens and Actions refuse to run (spec §11). */
    public static function ensureOpen(): void
    {
        $goLive = CompanySetting::current()->go_live_at;

        if ($goLive !== null && $goLive->isPast()) {
            throw ValidationException::withMessages(['import' => __('This company went live on :date; imports are closed.', [
                'date' => $goLive->timezone('Asia/Bahrain')->format('d/m/Y'),
            ])]);
        }
    }

    /** @param  array<string, mixed>  $row */
    private function importRow(User $actor, ImportKind $kind, array $row): void
    {
        match ($kind) {
            ImportKind::Buildings => $this->buildings->handle($actor, null, $row),
            ImportKind::Units => $this->units->handle($actor, null, [
                ...$row,
                'building_id' => $this->buildingId($row['building_code'] ?? null),
                'blocked' => in_array(strtolower((string) ($row['blocked'] ?? '')), ['yes', 'y', 'true', '1'], true),
            ]),
            ImportKind::Owners => $this->owners->handle($actor, null, $row, viaImport: true),
            ImportKind::OwnerContracts => $this->ownerContract($actor, $row),
        };
    }

    /** Imported contracts are created active, with an approval recorded as "Imported by {user}" (spec §11). */
    private function ownerContract(User $actor, array $row): void
    {
        $buildingId = $this->buildingId($row['building_code'] ?? null);

        $ownerId = Owner::query()->where('id_type', $row['owner_id_type'] ?? '')->where('id_number', $row['owner_id_number'] ?? '')->value('id')
            ?? throw ValidationException::withMessages(['owner_id_number' => __('No owner with ID :type :number.', ['type' => $row['owner_id_type'] ?? '', 'number' => $row['owner_id_number'] ?? ''])]);

        $list = trim((string) ($row['units'] ?? ''));
        $codes = strtoupper($list) === 'ALL' ? null : array_values(array_unique(array_filter(array_map(trim(...), explode(',', $list)))));
        $unitIds = Unit::query()->where('building_id', $buildingId)
            ->when($codes !== null, fn ($q) => $q->whereIn('code', $codes))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($codes !== null && count($unitIds) !== count($codes)) {
            throw ValidationException::withMessages(['units' => __('Unknown unit codes in :list.', ['list' => $list])]);
        }

        $contract = $this->contracts->handle($actor, null, [...$row, 'owner_id' => $ownerId, 'building_id' => $buildingId, 'unit_ids' => $unitIds]);
        $contract->forceFill(['status' => OwnerContractStatus::PendingApproval])->save();

        Approval::create([
            'approvable_type' => $contract->getMorphClass(),
            'approvable_id' => $contract->id,
            'action' => ApprovalAction::OwnerContractActivation,
            'status' => ApprovalStatus::Approved,
            'requested_by' => $actor->id,
            'requested_at' => now(),
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'comment' => __('Imported by :name', ['name' => $actor->name]),
            'ip' => request()->ip(),
        ]);

        $this->activate->handle($contract);
    }

    private function buildingId(mixed $code): int
    {
        return (int) (Building::query()->where('code', (string) $code)->value('id')
            ?? throw ValidationException::withMessages(['building_code' => __('No building with code :code.', ['code' => (string) $code])]));
    }

    /**
     * Cells to strings: dates to Y-m-d (dd/mm/yyyy text too), blanks to null; numbers in Text columns are refused.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalise(ImportKind $kind, array $row): array
    {
        $out = [];

        foreach ($row as $column => $value) {
            if ((is_int($value) || is_float($value)) && in_array($column, $kind->textColumns(), true)) {
                throw ValidationException::withMessages([$column => __('Column :column must be formatted as Text in Excel.', ['column' => $column])]);
            }

            $value = match (true) {
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                is_int($value), is_float($value) => (string) $value,
                is_string($value) => trim($value),
                default => $value,
            };

            if (is_string($value) && str_ends_with((string) $column, '_date') && preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m)) {
                $value = "{$m[3]}-{$m[2]}-{$m[1]}";
            }

            $out[(string) $column] = $value === '' ? null : $value;
        }

        return $out;
    }
}
```

- [ ] **Step 6: Templates, the screen, routes, navigation**

Create `app/Http/Controllers/ImportTemplateController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\ImportKind;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ImportTemplateController
{
    // ponytail: header row only; Excel's Text format on the ID/phone/IBAN/money columns is set by hand
    // (openspout styles cells, not empty columns). RunImport refuses numbers in those columns either way.
    public function __invoke(ImportKind $kind): BinaryFileResponse
    {
        $path = sys_get_temp_dir().'/rms-template-'.Str::uuid().'.xlsx';
        SimpleExcelWriter::create($path)->addHeader($kind->headers())->close();

        return response()->download($path, "{$kind->value}-template.xlsx")->deleteFileAfterSend();
    }
}
```
Create `app/Livewire/Import/Index.php`:
```php
<?php

namespace App\Livewire\Import;

use App\Actions\Import\RunImport;
use App\Enums\ImportKind;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Data import')]
class Index extends Component
{
    use WithActor, WithFileUploads;

    /** @var array<string, UploadedFile|null> */
    public array $files = [];

    /** @var array{errors: array<string, array<int, list<string>>>, counts: array<string, int>, committed: bool, commit: bool}|null */
    public ?array $result = null;

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::ImportRun), 403);
    }

    public function run(RunImport $import, bool $commit = false): void
    {
        $this->files = array_filter($this->files);
        $this->validate([
            'files' => ['required', 'array'],
            // By extension: finfo often reports .xlsx as application/zip. A bad file is reported by RunImport.
            'files.*' => ['file', 'extensions:xlsx,csv', 'max:10240'],
        ]);

        $stored = [];
        foreach ($this->files as $kind => $file) {
            abort_if(ImportKind::tryFrom((string) $kind) === null, 422);
            $extension = strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
            $stored[$kind] = $file->storeAs('imports', Str::uuid().'.'.$extension, 'local');
        }

        try {
            $result = $import->handle($this->actor(), array_map(fn ($path) => Storage::disk('local')->path($path), $stored), $commit);
        } catch (AuthorizationException) {
            abort(403);
        } finally {
            Storage::disk('local')->delete(array_values($stored));
        }

        $this->result = ['errors' => $result->errors, 'counts' => $result->counts, 'committed' => $result->committed, 'commit' => $commit];

        if ($result->committed) {
            $this->reset('files');
            Flux::toast(variant: 'success', text: __('Import saved.'));
        }
    }

    public function render(): View
    {
        $closed = null;
        try {
            RunImport::ensureOpen();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $closed = collect($e->errors())->flatten()->first();
        }

        return view('livewire.import.index', ['kinds' => ImportKind::cases(), 'closed' => $closed]);
    }
}
```
Create `resources/views/livewire/import/index.blade.php`:
```blade
<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Data import') }}</flux:heading>
    <flux:text>{{ __('Fill the templates (ID, phone, IBAN and money columns formatted as Text), then run a dry run. Nothing is saved until every row of every file passes.') }}</flux:text>

    @if ($closed)
        <flux:callout variant="danger" icon="lock-closed" :heading="$closed" />
    @else
        <flux:error name="import" />

        <div class="space-y-4">
            @foreach ($kinds as $kind)
                <flux:field wire:key="file-{{ $kind->value }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:label>{{ str($kind->value)->headline() }}</flux:label>
                        <flux:link :href="route('import.template', $kind)">{{ __('Download template') }}</flux:link>
                    </div>
                    <input type="file" wire:model="files.{{ $kind->value }}" accept=".xlsx,.csv" class="block w-full text-sm" />
                    <flux:error name="files.{{ $kind->value }}" />
                </flux:field>
            @endforeach
            <flux:error name="files" />
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="run(false)">{{ __('Dry run') }}</flux:button>
            <flux:button variant="primary" wire:click="run(true)" wire:confirm="{{ __('Import these files for real?') }}">{{ __('Import') }}</flux:button>
        </div>
    @endif

    @if ($result)
        <flux:callout :variant="$result['errors'] === [] ? 'success' : 'warning'"
            :heading="$result['committed'] ? __('Imported.') : ($result['errors'] === [] ? __('Dry run passed: nothing was saved.') : __('Problems found: nothing was saved.'))" />

        <ul class="text-sm">
            @foreach ($result['counts'] as $kind => $count)
                <li>{{ str($kind)->headline() }}: {{ trans_choice(':count row passed|:count rows passed', $count) }}</li>
            @endforeach
        </ul>

        @foreach ($result['errors'] as $kind => $lines)
            <div class="space-y-1">
                <flux:heading>{{ str($kind)->headline() }}</flux:heading>
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($lines as $line => $messages)
                        <li>{{ __('Line :line', ['line' => $line]) }}: {{ implode(' ', $messages) }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif
</section>
```
In `routes/property.php` add `use App\Http\Controllers\ImportTemplateController;` and `use App\Livewire\Import;` and inside the group:
```php
    Route::livewire('import', Import\Index::class)->middleware('can:import.run')->name('import.index');
    Route::get('import/templates/{kind}', ImportTemplateController::class)->middleware('can:import.run')->name('import.template');
```
In `resources/views/layouts/app/sidebar.blade.php` change `@canany(['users.manage', 'roles.manage', 'settings.manage', 'audit.view'])` to `@canany(['users.manage', 'roles.manage', 'settings.manage', 'audit.view', 'import.run'])` and add inside the Administration group:
```blade
                        @can('import.run')
                            <flux:sidebar.item icon="arrow-up-tray" :href="route('import.index')" :current="request()->routeIs('import.*')" wire:navigate>{{ __('Data import') }}</flux:sidebar.item>
                        @endcan
```

- [ ] **Step 7: The runbook**

In `deploy/README.md`, under "## 5. A company's go-live", add after step 1:
```markdown
   - In M1 the client gets the four templates (Data import → Download template, as Vendor Support): buildings, units, owners, owner contracts. Ask them to format ID, phone, IBAN and money columns as Text before typing. Owner contract `units` is `ALL` or comma-separated unit codes; dates are `YYYY-MM-DD` or `DD/MM/YYYY`.
```
and change step 2 to:
```markdown
2. `rms:install` with the company's details. Log in as Vendor Support, upload the client's files on Data import and press **Dry run** until it reports no problems (this is the M1 exit check: nothing is saved). Then UAT on this server.
```

- [ ] **Step 8: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Import
"$PHP" artisan test
```
Expected: 8 new tests pass; full suite passes.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the go-live importer with dry run

Buildings, units, owners and owner contracts import from Excel
templates through the normal Actions in one transaction, committed
only when every row passes. Contracts arrive active with an Imported
by approval; imports close at go-live.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 10: The permission matrix, and M1 wrap-up

**Spec:** §14 flow 11 (one data-driven test of every role against every protected action), §14 CI (Pest, Pint, Larastan, `composer audit`)

**Files:**
- Create: `tests/Feature/Permissions/PropertyPermissionMatrixTest.php`
- Modify: whatever Pint and Larastan flag

**Interfaces:**
- Consumes: every route and Action from Tasks 1–9
- Produces: nothing new

- [ ] **Step 1: Write the matrix test**

Create `tests/Feature/Permissions/PropertyPermissionMatrixTest.php`:
```php
<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Buildings\SaveBuilding;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\Import\RunImport;
use App\Actions\OwnerContracts\RequestOwnerContractTermination;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Actions\Owners\SaveOwner;
use App\Actions\Units\SaveUnit;
use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Expense;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

// Spec §14 flow 11 for M1: every role against every property/owner page and Action.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);

    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->owner = Owner::factory()->create();
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance);

    $this->draft = OwnerContract::factory()->create(['building_id' => $this->building->id, 'owner_id' => $this->owner->id]);
    $this->draft->units()->attach($this->unit->id);

    $pendingContract = OwnerContract::factory()->create(['building_id' => $this->building->id, 'start_date' => '2030-01-01', 'end_date' => '2030-12-31']);
    $pendingContract->units()->attach($this->unit->id);
    $this->pending = app(SubmitOwnerContract::class)->handle($this->finance, $pendingContract);

    $this->active = activeOwnerContract(['building_id' => $this->building->id, 'start_date' => '2020-01-01', 'end_date' => '2020-12-31'], []);
    $this->expense = Expense::factory()->create(['building_id' => $this->building->id]);
});

test('pages open for exactly the roles the spec allows', function (string $route, array $allowed) {
    foreach (R::cases() as $role) {
        $user = User::factory()->withTwoFactor()->create()->assignRole($role);
        $status = $this->actingAs($user)->get(route($route))->status();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$route} as {$role->value}");
    }
})->with([
    ['buildings.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['buildings.create', [R::Admin, R::PropertyManager, R::VendorSupport]],
    ['units.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['units.create', [R::Admin, R::PropertyManager, R::VendorSupport]],
    ['owners.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::VendorSupport]],
    ['owners.create', [R::Admin, R::Finance, R::VendorSupport]],
    ['owner-contracts.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::VendorSupport]],
    ['owner-contracts.create', [R::Admin, R::Finance, R::VendorSupport]],
    ['expenses.index', [R::Admin, R::Management, R::Finance, R::PropertyManager, R::VendorSupport]],
    ['expenses.create', [R::Finance, R::PropertyManager]],
    ['approvals.index', [R::Management]],
    ['import.index', [R::VendorSupport]],
]);

test('each protected Action allows exactly the spec roles', function (string $name, Closure $run, array $allowed) {
    foreach (R::cases() as $role) {
        $user = User::factory()->withTwoFactor()->create()->assignRole($role);
        $denied = false;

        try {
            $run->call($this, $user);
        } catch (AuthorizationException) {
            $denied = true;
        } catch (ValidationException) {
            // Authorised; the data was refused (e.g. already decided by an earlier role).
        }

        expect($denied)->toBe(! in_array($role, $allowed, true), "{$name} as {$role->value}");
    }
})->with([
    ['save building', function (User $u) {
        app(SaveBuilding::class)->handle($u, null, ['name' => 'B', 'code' => uniqid(), 'type' => 'residential']);
    }, [R::Admin, R::PropertyManager, R::VendorSupport]],
    ['save unit', function (User $u) {
        app(SaveUnit::class)->handle($u, null, ['building_id' => $this->building->id, 'code' => uniqid(), 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '100']);
    }, [R::Admin, R::PropertyManager, R::VendorSupport]],
    ['save owner', function (User $u) {
        app(SaveOwner::class)->handle($u, null, ['type' => 'person', 'name_en' => 'O', 'id_type' => 'cpr', 'id_number' => uniqid()]);
    }, [R::Admin, R::Finance, R::VendorSupport]],
    ['set owner bank details', function (User $u) {
        app(SaveOwner::class)->handle($u, null, ['type' => 'person', 'name_en' => 'O', 'id_type' => 'cpr', 'id_number' => uniqid(), 'iban' => 'BH67BMAG00001299123456']);
    }, [R::Admin]],
    ['save owner contract draft', function (User $u) {
        app(SaveOwnerContract::class)->handle($u, $this->draft, ['owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'fee_type' => 'fixed', 'fee_value' => '50', 'deposits_held_by' => 'owner', 'unit_ids' => [$this->unit->id]]);
    }, [R::Admin, R::Finance, R::VendorSupport]],
    ['submit owner contract', function (User $u) {
        app(SubmitOwnerContract::class)->handle($u, $this->draft);
    }, [R::Admin, R::Finance, R::VendorSupport]],
    ['request early termination', function (User $u) {
        app(RequestOwnerContractTermination::class)->handle($u, $this->active, '2020-06-30', 'Sold');
    }, [R::Admin, R::Finance, R::VendorSupport]],
    ['decide approval', function (User $u) {
        app(DecideApproval::class)->handle($u, $this->pending, false, 'No');
    }, [R::Management]],
    ['record expense', function (User $u) {
        app(RecordExpense::class)->handle($u, ['building_id' => $this->building->id, 'category' => 'other', 'description' => 'x', 'expense_date' => now()->toDateString(), 'net' => '1', 'charge_to' => 'company']);
    }, [R::Finance, R::PropertyManager]],
    ['reverse expense', function (User $u) {
        app(ReverseExpense::class)->handle($u, $this->expense, 'x');
    }, [R::Finance]],
    ['run import', function (User $u) {
        app(RunImport::class)->handle($u, [], commit: false);
    }, [R::VendorSupport]],
]);
```

- [ ] **Step 2: Run it**

Run: `"$PHP" artisan test tests/Feature/Permissions`
Expected: 23 tests (12 page rows, 11 Action rows) pass. A failure names the route or Action and the role; fix the policy or seeder, never the expectation, unless the spec table in §8.1 says otherwise.

- [ ] **Step 3: Pint, Larastan, audit, full suite**

```bash
"$PHP" vendor/bin/pint
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
$COMPOSER audit
"$PHP" artisan test
```
Expected: Pint clean after its fixes; Larastan level 8 with no new errors (fix them in code — do not add to the baseline); no advisories; every test passes.

- [ ] **Step 4: Check the screens at 375 px**

Start the `rms` preview, log in as a seeded Admin with 2FA, and open Buildings, Units, Owners, Owner contracts (create + show), Expenses (create + show), Approvals (as Management) and Data import (as Vendor Support) at 375 px width. Nothing may scroll horizontally except inside a table's own `overflow-x-auto` wrapper.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the M1 permission matrix

Every role against every property, owner, expense, approval and import
page and Action.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

## M1 exit

- All tasks committed on `m1-property-owners`, CI green.
- The client has the four templates.
- **Exit criterion (spec §15):** the client's real buildings, units, owners and contracts pass a **dry run** on the client's production server (runbook §5 step 2). That needs the server, so it happens when the client's hosting is ready; everything before it can be checked on staging with sample files.
