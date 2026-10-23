<?php

use App\Actions\CustomFields\SaveCustomField;
use App\CustomFields\CustomFields;
use App\Enums\RoleName;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => config(['database.connections.mysql_b' => config('database.connections.mysql')]));

afterEach(fn () => DB::purge('mysql_b'));

// Here, not in Feature: RefreshDatabase wraps every Feature test in a transaction.
it('queues a type change behind a value save that read the field, and refuses it once the value is committed', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create()->assignRole(RoleName::Admin);
    $field = customField('unit', 'text', 'Meter number');
    $unit = Unit::factory()->create();
    $b = DB::connection('mysql_b');
    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    // A value save has read the field under a share lock: a type change's row lock waits for it.
    DB::beginTransaction();
    CustomFields::save($admin, $unit, ['meter_number' => 'EWA-1']);
    expect(fn () => $b->transaction(fn () => $b->table('custom_fields')->where('id', $field->id)->lockForUpdate()->first()))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1205));
    DB::commit();

    // Then the type change sees the committed value and is refused.
    expect(fn () => app(SaveCustomField::class)->handle($admin, $field, ['label_en' => 'Meter number', 'type' => 'number']))->toThrow(ValidationException::class);
    expect($field->refresh()->type->value)->toBe('text');
});
