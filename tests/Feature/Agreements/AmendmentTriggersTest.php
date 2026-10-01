<?php

use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->create()]);
    $this->unit = Unit::factory()->create();
    $this->amendment = DB::table('agreement_amendments')->insertGetId(['agreement_id' => $this->agreement->id, 'type' => 'add_unit', 'effective_date' => '2026-06-01',
        'data' => json_encode(['unit_id' => $this->unit->id, 'deposit_amount' => '0.000', 'charges' => []]), 'reason' => 'x', 'status' => 'draft', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->row = fn (array $over = []) => ['agreement_id' => $this->agreement->id, 'unit_id' => $this->unit->id, 'list_rent' => '1.000', 'deposit_amount' => '0.000',
        'start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'created_at' => now(), 'updated_at' => now(), ...$over];
});

test('a unit enters a submitted agreement only through an approved, unapplied add_unit amendment', function () {
    expect(fn () => DB::table('agreement_units')->insert(($this->row)()))->toThrow(QueryException::class, 'agreement_units are frozen once the agreement is submitted');
    expect(fn () => DB::table('agreement_units')->insert(($this->row)(['amendment_id' => $this->amendment])))->toThrow(QueryException::class); // still draft

    DB::table('agreement_amendments')->where('id', $this->amendment)->update(['status' => 'pending_approval']);
    DB::table('agreement_amendments')->where('id', $this->amendment)->update(['status' => 'approved']);
    DB::table('agreement_units')->insert(($this->row)(['amendment_id' => $this->amendment]));

    DB::table('agreement_amendments')->where('id', $this->amendment)->update(['applied_at' => now()]);
    expect(fn () => DB::table('agreement_units')->insert(($this->row)(['unit_id' => Unit::factory()->create()->id, 'amendment_id' => $this->amendment])))->toThrow(QueryException::class);
});

test('amendments are deleted only as drafts and freeze once submitted', function () {
    DB::table('agreement_amendments')->where('id', $this->amendment)->update(['status' => 'pending_approval']);
    expect(fn () => DB::table('agreement_amendments')->where('id', $this->amendment)->delete())->toThrow(QueryException::class, 'agreement_amendments: only drafts can be deleted');
    expect(fn () => DB::table('agreement_amendments')->where('id', $this->amendment)->update(['effective_date' => '2026-07-01']))->toThrow(QueryException::class, 'agreement_amendments: frozen once submitted');
});
