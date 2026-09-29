<?php

use App\Actions\Documents\DeleteDocument;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Enums\DocumentCategory;
use App\Enums\ExpenseStatus;
use App\Enums\RoleName;
use App\Livewire\Expenses\Form;
use App\Models\Building;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Owner;
use App\Models\OwnerContract;
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
use Livewire\Livewire;

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
    activeOwnerContract(OwnerContract::factory()->leased()->raw(['building_id' => $leasedBuilding->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']), [$leasedUnit]);
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

test('the owner approval document behind an owner expense cannot be deleted by anyone', function () {
    Storage::fake('local');
    $expense = app(RecordExpense::class)->handle($this->pm, [...$this->base, 'charge_to' => 'owner', 'net' => '250', 'tax_amount' => '0', 'owner_approval_note' => 'Owner OK'],
        UploadedFile::fake()->create('owner-ok.pdf', 20, 'application/pdf'));
    $document = Document::query()->where('documentable_type', $expense->getMorphClass())->where('documentable_id', $expense->id)->sole();

    foreach ([$this->pm, $this->finance] as $user) {
        expect($user->can('delete', $document))->toBeFalse();
        expect(fn () => app(DeleteDocument::class)->handle($user, $document))->toThrow(AuthorizationException::class);
    }
    expect(DB::table('documents')->where('id', $document->id)->whereNull('deleted_at')->exists())->toBeTrue();
});

test('the expense form only offers units of buildings the user can see', function () {
    $other = Building::factory()->create();
    Unit::factory()->for($other)->create();
    $scoped = User::factory()->create()->assignRole(RoleName::Leasing);
    $scoped->givePermissionTo('expenses.manage');
    $scoped->buildings()->attach($this->building->id);

    $component = Livewire::actingAs($scoped)->test(Form::class)->set('form.building_id', $other->id);
    expect($component->viewData('units'))->toHaveCount(0);
});
