<?php

use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->create()]);
    $this->auId = $agreement->agreementUnits()->value('id');
    $this->id = DB::table('deposit_settlements')->insertGetId(['agreement_id' => $agreement->id, 'status' => 'draft', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('deposit_settlement_units')->insert(['deposit_settlement_id' => $this->id, 'agreement_unit_id' => $this->auId, 'held_amount' => '0.000', 'created_at' => now(), 'updated_at' => now()]);
});

test('settlements are never deleted; units and lines freeze once submitted; status moves along', function () {
    expect(fn () => DB::table('deposit_settlements')->where('id', $this->id)->delete())->toThrow(QueryException::class, 'deposit_settlements cannot be deleted');
    expect(fn () => DB::table('deposit_settlements')->where('id', $this->id)->update(['status' => 'completed']))
        ->toThrow(QueryException::class, 'deposit_settlements: status change not allowed'); // a draft cannot jump to completed

    DB::table('deposit_settlements')->where('id', $this->id)->update(['status' => 'pending_approval']);
    expect(fn () => DB::table('deposit_settlement_lines')->insert(['deposit_settlement_id' => $this->id, 'agreement_unit_id' => $this->auId, 'type' => 'damage', 'description' => 'x', 'amount' => '1.000', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class, 'deposit_settlement_lines are frozen once submitted');
    expect(fn () => DB::table('deposit_settlement_units')->where('deposit_settlement_id', $this->id)->delete())
        ->toThrow(QueryException::class, 'deposit_settlement_units are frozen once submitted');
});
