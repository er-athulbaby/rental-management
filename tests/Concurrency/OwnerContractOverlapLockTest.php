<?php

use App\Actions\OwnerContracts\EnsureNoOverlap;
use App\Models\OwnerContract;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => config(['database.connections.mysql_b' => config('database.connections.mysql')]));

afterEach(fn () => DB::purge('mysql_b'));

// Spec §14 flow 2: two submissions for the same unit on two connections queue on the unit row lock.
it('makes a second connection wait on the unit lock until the first commits', function () {
    $contract = OwnerContract::factory()->create();
    $unit = Unit::factory()->create(['building_id' => $contract->building_id]);
    $contract->units()->attach($unit->id);

    $b = DB::connection('mysql_b');
    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();
    app(EnsureNoOverlap::class)->handle($contract);

    expect(fn () => $b->transaction(fn () => $b->table('units')->where('id', $unit->id)->lockForUpdate()->first()))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1205));

    DB::commit();

    expect($b->transaction(fn () => $b->table('units')->where('id', $unit->id)->lockForUpdate()->value('id')))->toBe($unit->id);
});
