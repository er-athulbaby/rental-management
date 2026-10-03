<?php

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SaveManualInvoice;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Cheques\DepositCheques;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Enums\RoleName as R;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Spec §14 flow 11 for M3a. Every user is assigned the fixture building.

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['require_different_approver' => false]);
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);

    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->addYear()->toDateString()], [$this->unit]);
    $finance = matrixUser(R::Finance, $this->building);
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->agreement, $finance));
    $this->scheduled = Invoice::where('agreement_id', $this->agreement->id)->where('status', 'scheduled')->orderBy('due_date')->first();
    $this->invoice = issuedInvoice($this->customer, [['net' => '100.000']], now()->toDateString(), $this->agreement);
    $this->payment = app(RecordPayment::class)->handle($finance, $this->customer, ['received_on' => now('Asia/Bahrain')->toDateString(), 'method' => 'cash', 'amount' => '10']);
    $this->cheque = app(RecordCheques::class)->handle($finance, $this->customer, $this->agreement, [['cheque_no' => '1', 'bank_name' => 'NBB', 'cheque_date' => now()->toDateString(), 'amount' => '400']])->sole();
    $this->draft = app(SaveAgreement::class)->handle(matrixUser(R::Leasing, $this->building), null, [
        'customer_id' => $this->customer->id, 'start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'frequency' => 'monthly',
        'units' => [['unit_id' => $this->unit->id, 'charges' => [['type' => 'rent', 'monthly_amount' => '300', 'tax_category' => 'exempt']]]],
    ]);
    $this->creditNote = app(SubmitCreditNote::class)->handle($finance, app(SaveCreditNote::class)->handle($finance, $this->invoice, null, ['reason' => 'x', 'lines' => [['credited_line_id' => $this->invoice->lines->sole()->id, 'amount' => '1']]]));
});

$view = [R::Admin, R::Management, R::Finance, R::VendorSupport];

test('pages open for exactly the roles the spec allows', function (string $route, Closure $params, array $allowed) {
    foreach (R::cases() as $role) {
        $response = $this->actingAs(matrixUser($role, $this->building))->get(route($route, $params->call($this)));
        $status = $response->status();
        // Stored-document routes redirect to the audited download; every other page must be a plain 200.
        $toDownload = in_array($route, ['payments.receipt', 'agreements.pdf'], true) && $status === 302
            && str_starts_with((string) $response->headers->get('Location'), url('/documents/'));
        $ok = $status === 200 || $toDownload;

        expect($ok)->toBe(in_array($role, $allowed, true), "{$route} as {$role->value} gave {$status}");
    }
})->with([
    ['payments.index', fn () => [], $view],
    ['payments.create', fn () => ['customer' => $this->customer->id], [R::Finance]],
    ['payments.show', fn () => [$this->payment], $view],
    ['payments.receipt', fn () => [$this->payment], $view],
    ['invoices.show', fn () => [$this->invoice], $view],
    ['invoices.pdf', fn () => [$this->invoice], $view],
    ['invoices.create', fn () => ['customer' => $this->customer->id], [R::Finance]],
    ['invoices.credit', fn () => [$this->invoice], [R::Finance]],
    ['cheques.index', fn () => [], $view],
    ['cheques.entry', fn () => ['agreement' => $this->agreement->id], [R::Finance]],
    ['cheques.show', fn () => [$this->cheque], $view],
    ['customers.statement', fn () => [$this->customer], $view],
    // M2 carry-in: show, PDF and edit routes of agreements (edit needs a draft).
    ['agreements.show', fn () => [$this->agreement], [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['agreements.pdf', fn () => [$this->agreement], [R::Admin, R::Management, R::Finance, R::PropertyManager, R::Leasing, R::VendorSupport]],
    ['agreements.edit', fn () => [$this->draft], [R::Admin, R::PropertyManager, R::Leasing, R::VendorSupport]],
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
            // allowed through authorisation; the data was simply refused
        }

        expect($denied)->toBe(! in_array($role, $allowed, true), "{$name} as {$role->value}");
    }
})->with([
    ['record payment', fn (User $u) => app(RecordPayment::class)->handle($u, $this->customer, ['received_on' => now('Asia/Bahrain')->toDateString(), 'method' => 'cash', 'amount' => '1']), [R::Finance]],
    ['request reversal', fn (User $u) => app(RequestPaymentReversal::class)->handle($u, $this->payment, 'x'), [R::Finance]],
    ['manual invoice', fn (User $u) => app(SaveManualInvoice::class)->handle($u, null, ['customer_id' => $this->customer->id, 'due_date' => now()->toDateString(), 'lines' => [['description' => 'x', 'charge_type' => 'other', 'net' => '1', 'tax_category' => 'exempt']]]), [R::Finance]],
    ['credit note', fn (User $u) => app(SaveCreditNote::class)->handle($u, $this->invoice, null, ['reason' => 'x', 'lines' => [['credited_line_id' => $this->invoice->lines->sole()->id, 'amount' => '1']]]), [R::Finance]],
    ['enter cheques', fn (User $u) => app(RecordCheques::class)->handle($u, $this->customer, $this->agreement, [['cheque_no' => '9', 'bank_name' => 'NBB', 'cheque_date' => now()->toDateString(), 'amount' => '1']]), [R::Finance]],
    ['deposit cheques', fn (User $u) => app(DepositCheques::class)->handle($u, [$this->cheque->id], now('Asia/Bahrain')->toDateString()), [R::Finance]],
    ['approve credit note', fn (User $u) => app(DecideApproval::class)->handle($u, $this->creditNote, false, 'matrix'), [R::Management]],
]);
