<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Reports\Builder\FieldType;
use App\Reports\Builder\Registry;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->building = Building::factory()->create();
});

test('subjects are offered by report permission', function () {
    $finance = matrixUser(RoleName::Finance, $this->building);
    $leasing = matrixUser(RoleName::Leasing, $this->building);

    expect(array_keys(Registry::forUser($finance)))->toContain('invoices')
        ->and(array_keys(Registry::forUser($leasing)))->not->toContain('invoices');
});

test('the invoices subject declares typed fields with labels and a default column set', function () {
    $finance = matrixUser(RoleName::Finance, $this->building);
    $subject = Registry::get('invoices');
    $fields = $subject->fields($finance, CarbonImmutable::parse('2026-10-15'));

    expect($fields['balance']->type)->toBe(FieldType::Money)
        ->and($fields['due_date']->type)->toBe(FieldType::Date)
        ->and($fields['status']->options)->toHaveKey('overdue')
        ->and($fields['building']->filterable)->toBeFalse()
        ->and($subject->periodFields())->toBe(['issue_date', 'due_date'])
        ->and(array_diff($subject->defaultColumns(), array_keys($fields)))->toBe([]);
    expect(fn () => Registry::get('nope'))->toThrow(InvalidArgumentException::class);
});
