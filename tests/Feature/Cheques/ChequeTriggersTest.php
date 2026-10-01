<?php

use App\Models\Cheque;
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
