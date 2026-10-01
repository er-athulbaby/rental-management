<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->id = DB::table('disbursements')->insertGetId([
        'payee_type' => 'customer', 'payee_id' => Customer::factory()->create()->id, 'purpose' => 'other', 'amount' => '10.000',
        'method' => 'cash', 'status' => 'pending_approval', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->row = fn () => DB::table('disbursements')->where('id', $this->id);
});

test('payments out are never deleted, their amount never changes, and the status only moves forward', function () {
    expect(fn () => ($this->row)()->delete())->toThrow(QueryException::class, 'disbursements cannot be deleted');
    expect(fn () => ($this->row)()->update(['amount' => '11.000']))->toThrow(QueryException::class, 'disbursements: terms are frozen');
    expect(fn () => ($this->row)()->update(['status' => 'paid']))->toThrow(QueryException::class, 'disbursements: status change not allowed');

    ($this->row)()->update(['status' => 'approved']);
    ($this->row)()->update(['status' => 'paid', 'number' => 'PO-T-1', 'paid_on' => '2026-10-05', 'posted_at' => now(), 'recorded_by' => User::factory()->create()->id]);
    expect(fn () => ($this->row)()->update(['reference' => 'changed']))->toThrow(QueryException::class, 'disbursements: a paid payment out is frozen');
    expect(fn () => ($this->row)()->update(['status' => 'approved']))->toThrow(QueryException::class, 'disbursements: status change not allowed');
});
