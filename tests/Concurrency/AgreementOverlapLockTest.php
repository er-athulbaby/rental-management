<?php

use App\Actions\Agreements\EnsureNoAgreementOverlap;
use App\Models\Agreement;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => config(['database.connections.mysql_b' => config('database.connections.mysql')]));

afterEach(fn () => DB::purge('mysql_b'));

// Spec §14 flow 2: concurrent submissions for the same unit queue on the unit row lock.
it('makes a second connection wait on the unit lock until the first commits', function () {
    $unit = Unit::factory()->create();
    $agreement = Agreement::factory()->create();
    $agreement->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => 1, 'deposit_amount' => 0, 'start_date' => $agreement->start_date, 'end_date' => $agreement->end_date]);

    $b = DB::connection('mysql_b');
    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();
    app(EnsureNoAgreementOverlap::class)->handle($agreement);

    expect(fn () => $b->transaction(fn () => $b->table('units')->where('id', $unit->id)->lockForUpdate()->first()))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1205));

    DB::commit();

    expect($b->transaction(fn () => $b->table('units')->where('id', $unit->id)->lockForUpdate()->value('id')))->toBe($unit->id);
});
