<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// A denied DDL statement still implicitly COMMITs the connection's open transaction,
// so probe on a separate connection, never on the RefreshDatabase one.
beforeEach(function () {
    config(['database.connections.grants_probe' => config('database.connections.mysql')]);
});

afterEach(function () {
    DB::purge('grants_probe');
});

it('runs as the app user', function () {
    expect(DB::selectOne('select current_user() as u')->u)
        ->toStartWith(config('database.connections.mysql.username').'@');
});

it('denies DDL to the app user', function (string $sql) {
    expect(fn () => DB::connection('grants_probe')->unprepared($sql))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1142));
})->with([
    'truncate' => 'TRUNCATE TABLE sessions',
    'drop table' => 'DROP TABLE sessions',
    'create trigger' => 'CREATE TRIGGER x BEFORE DELETE ON users FOR EACH ROW SET @a = 1',
    'alter' => 'ALTER TABLE users ADD COLUMN x INT',
    'create table' => 'CREATE TABLE x (id INT)',
])->skip(
    fn () => config('database.connections.mysql.username') === config('database.connections.migrator.username'),
    'app and migrator are the same user',
);
