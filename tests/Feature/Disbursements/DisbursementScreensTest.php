<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Disbursements\Create;
use App\Livewire\Disbursements\Index;
use App\Livewire\Disbursements\Show;
use App\Livewire\Payments\Show as PaymentShow;
use App\Models\Approval;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\Owner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create(['name_en' => 'Noor Aziz']);
    $this->payment = app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '70.000']);
});

test('Finance refunds credit from the payment page', function () {
    Livewire::actingAs($this->finance)->test(PaymentShow::class, ['payment' => $this->payment])
        ->set('refund.amount', '70')
        ->set('refund.method', 'bank_transfer')
        ->set('refund.paid_on', '2026-10-05')
        ->call('refundCredit')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Disbursement::sole()->status->value)->toBe('paid');
});

test('a source-less payment out is requested, approved, then paid from its page', function () {
    $owner = Owner::factory()->create(['name_en' => 'Salman Holdings']);
    Livewire::actingAs($this->finance)->test(Create::class)
        ->set('form.payee_type', 'owner')->set('form.payee_id', $owner->id)
        ->set('form.amount', '25')->set('form.method', 'cash')->set('form.reason', 'Shared repair')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $out = Disbursement::sole();
    app(DecideApproval::class)->handle($this->management, Approval::sole(), true);

    Livewire::actingAs($this->finance)->test(Show::class, ['disbursement' => $out])
        ->assertSee('Salman Holdings')
        ->set('pay.method', 'cash')->set('pay.paid_on', '2026-10-05')
        ->call('payNow')->assertHasNoErrors();
    expect($out->fresh()->status->value)->toBe('paid');

    Livewire::actingAs($this->finance)->test(Show::class, ['disbursement' => $out->fresh()])
        ->set('reversalReason', 'Paid twice')->call('requestReversal')->assertHasNoErrors()
        ->assertSee('Reversal waiting for approval');

    Livewire::actingAs($this->finance)->test(Index::class)->assertSee($out->fresh()->number);
});

test('finance.view holders see payments out; only disbursements.manage creates them', function () {
    $this->actingAs($this->management)->get(route('disbursements.index'))->assertOk();
    $this->actingAs($this->management)->get(route('disbursements.create'))->assertForbidden();
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->actingAs($pm)->get(route('disbursements.index'))->assertForbidden();

    Livewire::actingAs($this->management)->test(PaymentShow::class, ['payment' => $this->payment])
        ->set('refund.amount', '1')->set('refund.method', 'cash')->set('refund.paid_on', '2026-10-05')
        ->call('refundCredit')->assertForbidden();
});
