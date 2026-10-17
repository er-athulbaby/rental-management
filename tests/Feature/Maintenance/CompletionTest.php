<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Enums\ChargeTo;
use App\Enums\DocumentCategory;
use App\Enums\ExpenseCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MaintenanceEvent;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101', 'default_tax_category' => 'standard']);
    $this->tenant = Customer::factory()->create();
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->contract = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01',
        'end_date' => '2026-12-31', 'expense_approval_limit' => '200.000'], [$this->unit]);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->request = maintenanceRequest($this->pm, $this->unit);
    $this->workOrder = workOrderFor($this->pm, $this->request, maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]));
    $this->done = fn (array $over = []) => ['completion_note' => 'Trap replaced', 'completed_on' => '2026-10-17', 'actual_net' => '50', 'actual_tax' => '5',
        'charge_to' => 'company', 'category' => 'maintenance', 'description' => CompleteWorkOrder::expenseDescription($this->workOrder), ...$over];
    $this->complete = fn (array $over = [], ?UploadedFile $file = null) => app(CompleteWorkOrder::class)->handle($this->pm, $this->workOrder, ($this->done)($over), $file);
});

test('with a cost, RecordExpense records the expense, the work order links it, and the request resolves', function () {
    $wo = ($this->complete)();

    $expense = Expense::query()->sole();
    expect($wo->status)->toBe(WorkOrderStatus::Completed)
        ->and($wo->completed_on->format('d/m/Y'))->toBe('17/10/2026')
        ->and($wo->completion_note)->toBe('Trap replaced')
        ->and([$wo->actual_net, $wo->actual_tax])->toBe(['50.000', '5.000'])
        ->and($wo->expense_id)->toBe($expense->id)
        ->and($expense->description)->toBe('WO-2026-000001 · Ali Plumbing · Kitchen sink leaking')
        ->and([$expense->building_id, $expense->unit_id])->toBe([$this->building->id, $this->unit->id])
        ->and($expense->category)->toBe(ExpenseCategory::Maintenance)
        ->and($expense->expense_date->toDateString())->toBe('2026-10-17')
        ->and([$expense->net, $expense->tax_amount, $expense->total])->toBe(['50.000', '5.000', '55.000'])
        ->and($expense->charge_to)->toBe(ChargeTo::Company)
        ->and($expense->recorded_by)->toBe($this->pm->id)
        ->and($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved)
        ->and(MaintenanceEvent::query()->where('work_order_id', $wo->id)->orderByDesc('id')->first()->describe())->toBe('WO-2026-000001: Assigned → Completed');
});

test('charged to the owner: above the contract limit RecordExpense refuses, and nothing at all is completed', function () {
    $big = ['charge_to' => 'owner', 'actual_net' => '250', 'actual_tax' => '0'];

    try {
        ($this->complete)($big);
        $this->fail('Accepted above the owner limit without approval.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('owner_approval_note');
    }
    expect(Expense::count())->toBe(0)
        ->and($this->workOrder->fresh()->status)->toBe(WorkOrderStatus::Assigned)
        ->and($this->workOrder->fresh()->expense_id)->toBeNull()
        ->and($this->request->fresh()->status)->toBe(MaintenanceStatus::InProgress)
        ->and(MaintenanceEvent::query()->where('to_status', 'completed')->exists())->toBeFalse();

    $wo = ($this->complete)([...$big, 'owner_approval_note' => 'Owner agreed by phone'], UploadedFile::fake()->create('owner-ok.pdf', 20, 'application/pdf'));

    expect($wo->expense->owner_contract_id)->toBe($this->contract->id)
        ->and($wo->expense->total)->toBe('250.000')
        ->and(Document::query()->where('documentable_type', Expense::class)->where('documentable_id', $wo->expense_id)->sole()->category)->toBe(DocumentCategory::OwnerApproval);
});

test('charged to the tenant: refused without invoices.manage, then RecordExpense invoices the unit\'s tenant', function () {
    expect(fn () => ($this->complete)(['charge_to' => 'tenant']))->toThrow(AuthorizationException::class)
        ->and($this->workOrder->fresh()->status)->toBe(WorkOrderStatus::Assigned)
        ->and(Invoice::count())->toBe(0);

    $this->pm->givePermissionTo('invoices.manage'); // no default role has both maintenance.manage and invoices.manage (plan ruling 7)
    $wo = ($this->complete)(['charge_to' => 'tenant']);

    expect($wo->expense->invoice->customer_id)->toBe($this->tenant->id)
        ->and($wo->expense->invoice->status->value)->toBe('issued')
        ->and($wo->expense->invoice->lines->sole()->net)->toBe('50.000');
});

test('a common-area request cannot charge a tenant: the message points at the payer', function () {
    $this->pm->givePermissionTo('invoices.manage');
    $common = maintenanceRequest($this->pm, $this->unit, ['unit_id' => null, 'customer_id' => null]);
    $wo = workOrderFor($this->pm, $common, maintenanceVendor());

    try {
        app(CompleteWorkOrder::class)->handle($this->pm, $wo, ($this->done)(['charge_to' => 'tenant']));
        $this->fail('A tenant was charged for a common area.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charge_to');
    }
});

test('with no cost (warranty, own staff) there is no expense; Admin, without expenses.manage, can only complete that way', function () {
    $admin = matrixUser(R::Admin, $this->building);
    expect(fn () => app(CompleteWorkOrder::class)->handle($admin, $this->workOrder, ($this->done)()))
        ->toThrow(AuthorizationException::class, 'Recording the cost needs expenses.manage.');

    $wo = app(CompleteWorkOrder::class)->handle($admin, $this->workOrder, ($this->done)(['actual_net' => '', 'actual_tax' => '0']));

    expect($wo->status)->toBe(WorkOrderStatus::Completed)
        ->and([$wo->expense_id, $wo->actual_net, $wo->actual_tax])->toBe([null, null, null])
        ->and(Expense::count())->toBe(0)
        ->and($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved);
});

test('the request resolves only when its last open work order is done, and a completed work order cannot be completed again', function () {
    $second = workOrderFor($this->pm, $this->request, maintenanceVendor());
    ($this->complete)();
    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::InProgress)
        ->and(fn () => ($this->complete)())->toThrow(ValidationException::class, 'This work order is completed.');

    app(CompleteWorkOrder::class)->handle($this->pm, $second, ($this->done)(['actual_net' => '0', 'actual_tax' => '0']));
    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved)->and(Expense::count())->toBe(1);
});

test('an expense links to one work order only', function () {
    $wo = ($this->complete)();
    $other = workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unit), maintenanceVendor());

    expect(fn () => $other->forceFill(['status' => 'completed', 'completed_on' => '2026-10-17', 'expense_id' => $wo->expense_id])->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('completion input is checked, and a refused completion changes nothing', function (array $change, string $error) {
    try {
        ($this->complete)($change);
        $this->fail('The completion was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($error);
    }
    expect(Expense::count())->toBe(0)->and($this->workOrder->fresh()->status)->toBe(WorkOrderStatus::Assigned);
})->with([
    'no completion note' => [['completion_note' => ''], 'completion_note'],
    'note too long' => [['completion_note' => str_repeat('x', 2001)], 'completion_note'],
    'completed tomorrow' => [['completed_on' => '2026-10-18'], 'completed_on'],
    'completed before the request was logged' => [['completed_on' => '2026-10-16'], 'completed_on'],
    'date not Y-m-d' => [['completed_on' => '17/10/2026'], 'completed_on'],
    'date as an array' => [['completed_on' => ['2026-10-17']], 'completed_on'],
    'negative cost' => [['actual_net' => '-1'], 'actual_net'],
    'four decimals' => [['actual_tax' => '0.0001'], 'actual_tax'],
    'cost as an array' => [['actual_net' => ['50']], 'actual_net'],
    'no payer' => [['charge_to' => null], 'charge_to'],
    'unknown payer' => [['charge_to' => 'vendor'], 'charge_to'],
    'unknown category' => [['category' => 'food'], 'category'],
    'no description' => [['description' => ''], 'description'],
]);

test('VAT defaults from the company rate when VAT-registered, rounded to the fil', function () {
    expect(CompleteWorkOrder::defaultVat('50'))->toBe('5.000')
        ->and(CompleteWorkOrder::defaultVat('0.125'))->toBe('0.013')
        ->and(CompleteWorkOrder::defaultVat('abc'))->toBe('0.000')
        ->and(CompleteWorkOrder::defaultVat(['50']))->toBe('0.000')
        ->and(CompleteWorkOrder::costFils('50', '5.5'))->toBe(55500)
        ->and(CompleteWorkOrder::costFils('x', null))->toBe(0);

    CompanySetting::current()->forceFill(['vat_registered' => false])->save();
    expect(CompleteWorkOrder::defaultVat('50'))->toBe('0.000');
});

test('a user with maintenance.manage and expenses.manage for another building cannot complete, with or without a cost', function (array $change) {
    $elsewhere = User::factory()->withTwoFactor()->create()->givePermissionTo('maintenance.view', 'maintenance.manage', 'expenses.manage'); // no view-all: scoped to its own building
    $elsewhere->buildings()->attach(Building::factory()->create()->id);

    expect(fn () => app(CompleteWorkOrder::class)->handle($elsewhere, $this->workOrder, ($this->done)($change)))->toThrow(AuthorizationException::class)
        ->and($elsewhere->can('create', Expense::class))->toBeTrue()
        ->and(Expense::count())->toBe(0)
        ->and($this->workOrder->fresh()->status)->toBe(WorkOrderStatus::Assigned)
        ->and($this->workOrder->fresh()->expense_id)->toBeNull()
        ->and($this->request->fresh()->status)->toBe(MaintenanceStatus::InProgress);
})->with(['with a cost' => [[]], 'without a cost' => [['actual_net' => '', 'actual_tax' => '0']]]);

test('VAT with no net amount is refused', function () {
    try {
        ($this->complete)(['actual_net' => '', 'actual_tax' => '5']);
        $this->fail('A VAT-only cost was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('actual_net');
    }
    expect(Expense::count())->toBe(0);
});

test('Leasing and users outside the building cannot complete', function () {
    expect(fn () => app(CompleteWorkOrder::class)->handle(matrixUser(R::Leasing, $this->building), $this->workOrder, ($this->done)(['actual_net' => ''])))
        ->toThrow(AuthorizationException::class)
        ->and($this->workOrder->fresh()->status)->toBe(WorkOrderStatus::Assigned);
});
