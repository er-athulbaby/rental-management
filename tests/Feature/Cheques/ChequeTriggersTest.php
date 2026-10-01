<?php

use App\Actions\EnsureNumberSequences;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->id = DB::table('cheques')->insertGetId([
        'direction' => 'received', 'customer_id' => Customer::factory()->create()->id, 'cheque_no' => '1', 'bank_name' => 'NBB',
        'cheque_date' => '2026-10-01', 'amount' => '10.000', 'status' => 'held', 'created_by' => User::factory()->create()->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->row = fn () => DB::table('cheques')->where('id', $this->id);
});

test('cheques are never deleted and only move along the lifecycle', function () {
    expect(fn () => ($this->row)()->delete())->toThrow(QueryException::class, 'cheques cannot be deleted');
    expect(fn () => ($this->row)()->update(['status' => 'cleared']))->toThrow(QueryException::class, 'cheques: status change not allowed');

    ($this->row)()->update(['status' => 'deposited', 'deposited_on' => '2026-10-01']);
    expect(fn () => ($this->row)()->update(['amount' => '11.000']))->toThrow(QueryException::class, 'cheques: terms are frozen once deposited');
    expect(fn () => ($this->row)()->update(['status' => 'held']))->toThrow(QueryException::class, 'cheques: status change not allowed');
});

test('a held cheque can be corrected', function () {
    ($this->row)()->update(['amount' => '12.000']);
    expect(Cheque::find($this->id)->amount)->toBe('12.000');
});

test('a cheque\'s target invoice is fixed once it has left held and deposited', function () {
    CompanySetting::factory()->create(); // issuing an invoice reads the company settings and numbers
    app(EnsureNumberSequences::class)(2026);
    $invoiceId = issuedInvoice(Customer::find(DB::table('cheques')->value('customer_id')), [['net' => '1.000']])->id;
    ($this->row)()->update(['invoice_id' => $invoiceId]);           // held: movable
    ($this->row)()->update(['status' => 'cancelled']);
    expect(fn () => ($this->row)()->update(['invoice_id' => null]))->toThrow(QueryException::class, 'cheques: the target is fixed once the cheque has left held and deposited');
});

test('issued cheques move only issued → cleared or cancelled, and need exactly one payee', function () {
    $user = User::factory()->create()->id;
    $base = ['direction' => 'issued', 'cheque_no' => '9', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-01', 'amount' => '1.000', 'status' => 'issued', 'created_by' => $user, 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => DB::table('cheques')->insert($base))->toThrow(QueryException::class); // no payee
    $id = DB::table('cheques')->insertGetId([...$base, 'customer_id' => Customer::factory()->create()->id]);

    expect(fn () => DB::table('cheques')->where('id', $id)->update(['status' => 'deposited']))->toThrow(QueryException::class, 'cheques: status change not allowed');
    DB::table('cheques')->where('id', $id)->update(['status' => 'cancelled']);
});
