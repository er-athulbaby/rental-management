<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt (Task 9)
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $customer = Customer::factory()->create();
    issuedInvoice($customer, [['net' => '100.000']]);
    $this->payment = app(RecordPayment::class)->handle(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance), $customer,
        ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '60.000']);
});

test('allocations and deposit movements are write-once', function () {
    $id = DB::table('payment_allocations')->value('id');

    expect(fn () => DB::table('payment_allocations')->where('id', $id)->update(['amount' => '1.000']))->toThrow(QueryException::class, 'payment_allocations are write-once');
    expect(fn () => DB::table('payment_allocations')->where('id', $id)->delete())->toThrow(QueryException::class, 'payment_allocations are write-once');
});

test('payments are never deleted and their terms never change; reversal is one-way', function () {
    $row = fn () => DB::table('payments')->where('id', $this->payment->id);

    expect(fn () => $row()->delete())->toThrow(QueryException::class, 'payments cannot be deleted');
    expect(fn () => $row()->update(['amount' => '1.000']))->toThrow(QueryException::class, 'payments: terms are frozen');

    $row()->update(['status' => 'reversed', 'reversed_at' => now()]);
    expect(fn () => $row()->update(['status' => 'confirmed', 'reversed_at' => null]))->toThrow(QueryException::class, 'payments: a reversal is final');
});

test('draft and scheduled invoices and their lines cannot be paid or credited', function () {
    $draft = (new Invoice)->forceFill(['type' => 'manual', 'customer_id' => $this->payment->customer_id, 'issue_date' => '2026-10-05', 'due_date' => '2026-10-05', 'status' => 'draft', 'subtotal' => '10.000', 'tax_total' => '0.000', 'total' => '10.000']);
    $draft->save();
    $line = $draft->lines()->create(['charge_type' => 'other', 'description' => 'x', 'net' => '10.000', 'tax_category' => 'exempt', 'tax_rate' => '0.00', 'tax_amount' => '0.000', 'total' => '10.000']);

    expect(fn () => DB::table('invoice_lines')->where('id', $line->id)->update(['allocated' => '1.000']))->toThrow(QueryException::class, 'only lines of an issued invoice');
    expect(fn () => DB::table('invoices')->where('id', $draft->id)->update(['credited' => '1.000']))->toThrow(QueryException::class, 'only an issued invoice');
});

test('an allocation must be non-zero, and negative exactly when it reverses another', function () {
    $line = DB::table('invoice_lines')->value('id');
    $base = ['payment_id' => $this->payment->id, 'invoice_line_id' => $line, 'tax_amount' => 0, 'posted_at' => now(), 'created_at' => now()];

    expect(fn () => DB::table('payment_allocations')->insert([...$base, 'amount' => 0]))->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
    expect(fn () => DB::table('payment_allocations')->insert([...$base, 'amount' => -1]))->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});
