<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\NextDocumentNumber;
use App\Enums\NumberSequenceKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now('Asia/Bahrain')->setDate(2026, 9, 28));
    config(['database.connections.mysql_b' => config('database.connections.mysql')]);
    app(EnsureNumberSequences::class)(2026);
});

afterEach(fn () => DB::purge('mysql_b'));

// Here, not in Feature: RefreshDatabase wraps every Feature test in a transaction.
it('refuses to run outside a transaction', function () {
    expect(fn () => app(NextDocumentNumber::class)(NumberSequenceKey::Invoice))->toThrow(LogicException::class);
});

it('blocks a second connection until the first commits', function () {
    $b = DB::connection('mysql_b');
    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();
    expect(app(NextDocumentNumber::class)(NumberSequenceKey::Invoice))->toBe('INV-2026-000001');

    expect(fn () => $b->transaction(fn () => $b->table('number_sequences')->where('key', 'invoice')->where('year', 2026)->lockForUpdate()->first()))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1205));

    DB::commit();

    expect($b->table('number_sequences')->where('key', 'invoice')->where('year', 2026)->value('next_value'))->toBe(2);
});
