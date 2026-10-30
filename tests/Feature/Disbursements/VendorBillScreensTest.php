<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Enums\RoleName;
use App\Livewire\Disbursements\Create;
use App\Livewire\Disbursements\Show;
use App\Livewire\Expenses\Index as ExpenseIndex;
use App\Livewire\Expenses\Show as ExpenseShow;
use App\Models\Approval;
use App\Models\Disbursement;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = vendorBillFixture();
});

test('choosing a vendor lists its work-order expenses first; others are added by hand; the total is the sum of the ticked ones', function () {
    $f = $this->f;
    $company = $f['companyExpense']->id;
    $owner = $f['ownerExpense']->id;

    Livewire::actingAs($f['finance'])->test(Create::class)
        ->set('form.payee_type', 'vendor')->set('form.payee_id', (string) $f['vendor']->id)
        ->assertViewHas('candidates', fn ($c) => $c->modelKeys() === [$company])
        ->assertViewHas('others', fn ($c) => $c->modelKeys() === [$owner])
        ->set('extra', (string) $owner)->call('addExpense')->assertHasNoErrors()
        ->assertViewHas('candidates', fn ($c) => $c->modelKeys() === [$company, $owner])
        ->assertViewHas('others', fn ($c) => $c->modelKeys() === [])
        ->assertSet('form.expense_ids', [(string) $owner])
        ->set('form.expense_ids', [(string) $company, (string) $owner])
        ->assertSee('Total: 80.000 BHD')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $out = Disbursement::query()->where('purpose', 'vendor_bill')->sole();
    expect([$out->amount, $out->status->value, $out->payeeName()])->toBe(['80.000', 'pending_approval', 'Fix-It Co']);
});

test('the payment lists its expenses; each expense shows In payment, then Paid with its number', function () {
    $f = $this->f;
    $out = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'vendor_bill', 'payee_type' => 'vendor', 'payee_id' => $f['vendor']->id,
        'expense_ids' => [$f['companyExpense']->id], 'method' => 'bank_transfer']);
    Livewire::actingAs($f['finance'])->test(ExpenseShow::class, ['expense' => $f['companyExpense']])->assertSee('In payment');

    app(DecideApproval::class)->handle($f['management'], Approval::query()->latest('id')->firstOrFail(), true);
    $paid = app(PayDisbursement::class)->handle($f['finance'], $out->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-10-20']);

    Livewire::actingAs($f['finance'])->test(Show::class, ['disbursement' => $paid])
        ->assertSee('Fix-It Co (vendor)')->assertSeeInOrder(['08/10/2026', 'Lobby lights', '50.000']);
    Livewire::actingAs($f['finance'])->test(ExpenseShow::class, ['expense' => $f['companyExpense']])->assertSee('Paid · '.$paid->number);
    Livewire::actingAs($f['finance'])->test(ExpenseIndex::class)->assertSeeInOrder(['T1 tap', 'Unpaid', 'Lobby lights', 'Paid · '.$paid->number]);
});

test('the owner and tenant forms work as before, and switching to a vendor clears the payee', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(Create::class)
        ->set('form.payee_type', 'owner')->set('form.payee_id', (string) $f['ali']->id)
        ->set('form.payee_type', 'vendor')->assertSet('form.payee_id', null)
        ->set('form.payee_type', 'owner')->set('form.payee_id', (string) $f['ali']->id)
        ->set('form.amount', '5')->set('form.method', 'cash')->set('form.reason', 'Keys')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    expect(Disbursement::query()->where('purpose', 'other')->sole()->payeeName())->toBe('Ali Owner');
});

test('tampered input on the vendor form gives a message, never a 500', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(Create::class)
        ->set('form.payee_type', 'vendor')->set('form.payee_id', (string) $f['vendor']->id)
        ->set('extra', ['x'])->call('addExpense')->assertHasErrors('extra')
        ->set('extra', '999999')->call('addExpense')->assertHasErrors('extra')
        ->set('form.expense_ids', 'oops')->call('save')->assertHasErrors('form.expense_ids')
        ->set('form.expense_ids', [['x']])->call('save')->assertHasErrors()
        ->set('form.expense_ids', [])->call('save')->assertHasErrors('form.expense_ids')
        ->set('form.payee_id', ['x'])->call('save')->assertHasErrors('form.payee_id');

    expect(Disbursement::query()->where('purpose', 'vendor_bill')->count())->toBe(0);
});

test('the payment link shows only to those who may view the payment; others see plain text', function () {
    $f = $this->f;
    $out = app(RecordDisbursement::class)->handle($f['finance'], ['purpose' => 'vendor_bill', 'payee_type' => 'vendor', 'payee_id' => $f['vendor']->id,
        'expense_ids' => [$f['companyExpense']->id], 'method' => 'bank_transfer']);
    $href = route('disbursements.show', $out);
    $pm = matrixUser(RoleName::PropertyManager, $f['companyExpense']->building);

    Livewire::actingAs($pm)->test(ExpenseShow::class, ['expense' => $f['companyExpense']])->assertSee('In payment · '.$out->label())->assertDontSeeHtml($href);
    Livewire::actingAs($pm)->test(ExpenseIndex::class)->assertSee('In payment · '.$out->label())->assertDontSeeHtml($href);
    Livewire::actingAs($f['finance'])->test(ExpenseShow::class, ['expense' => $f['companyExpense']])->assertSeeHtml($href);
    Livewire::actingAs($f['finance'])->test(ExpenseIndex::class)->assertSeeHtml($href);
});

test('the vendor list offers only active vendors', function () {
    $f = $this->f;
    maintenanceVendor(['name' => 'Gone Plumbing', 'active' => false]);

    Livewire::actingAs($f['finance'])->test(Create::class)->set('form.payee_type', 'vendor')
        ->assertViewHas('payees', fn ($p) => $p->pluck('name_en')->all() === ['Fix-It Co']);
});
