<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Reports\Builder\ReportDefinition;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->finance = matrixUser(RoleName::Finance, Building::factory()->create());
});

test('a valid definition round-trips with its version', function () {
    $def = ReportDefinition::fromArray(['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'filters' => [['field' => 'balance', 'op' => 'gt', 'value' => '100']], 'period' => ['field' => 'due_date', 'preset' => 'this_month']], $this->finance);

    expect($def->toArray())->toMatchArray(['version' => 1, 'subject' => 'invoices', 'columns' => ['number', 'balance']])
        ->and($def->filters[0])->toBe(['field' => 'balance', 'op' => 'gt', 'value' => '100']);
});

test('unknown subjects, fields and operators, bad values and too many columns are refused', function (array $bad, string $key) {
    expect(fn () => ReportDefinition::fromArray([...['subject' => 'invoices', 'columns' => ['number']], ...$bad], $this->finance))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($key));
})->with([
    'subject' => [['subject' => 'salaries'], 'subject'],
    'column' => [['columns' => ['number', 'drop table']], 'columns'],
    'operator' => [['filters' => [['field' => 'balance', 'op' => 'contains', 'value' => '1']]], 'filters.0.op'],
    'money value' => [['filters' => [['field' => 'balance', 'op' => 'gt', 'value' => '1; DROP']]], 'filters.0.value'],
    'enum value' => [['filters' => [['field' => 'status', 'op' => 'in', 'value' => ['nope']]]], 'filters.0.value'],
    'period field' => [['period' => ['field' => 'balance', 'preset' => 'this_month']], 'period.field'],
    'columns cap' => [['columns' => array_fill(0, 31, 'number')], 'columns'],
]);

test('a subject the user may not use is refused', function () {
    $leasing = matrixUser(RoleName::Leasing, Building::factory()->create());
    expect(fn () => ReportDefinition::fromArray(['subject' => 'invoices', 'columns' => ['number']], $leasing))
        ->toThrow(ValidationException::class);
});
