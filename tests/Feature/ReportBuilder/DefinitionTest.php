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

test('malformed input gives validation errors, never server errors', function (array $bad, string $key) {
    expect(fn () => ReportDefinition::fromArray([...['subject' => 'invoices', 'columns' => ['number']], ...$bad], $this->finance))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($key));
})->with([
    'garbage as_at' => [['as_at' => 'not-a-date'], 'as_at'],
    'impossible as_at' => [['as_at' => '2026-02-30'], 'as_at'],
    'array as_at' => [['as_at' => ['x']], 'as_at'],
    'array filter field' => [['filters' => [['field' => ['x'], 'op' => 'is', 'value' => 'a']]], 'filters.0.field'],
    'array group field' => [['groups' => [['field' => ['x']]]], 'groups.0.field'],
    'nested column' => [['columns' => [['x']]], 'columns'],
    'duplicate columns' => [['columns' => ['number', 'number']], 'columns'],
    'nested enum value' => [['filters' => [['field' => 'status', 'op' => 'in', 'value' => [['x']]]]], 'filters.0.value'],
    'array preset' => [['period' => ['field' => 'due_date', 'preset' => ['x']]], 'period.preset'],
    'impossible custom date' => [['period' => ['field' => 'due_date', 'preset' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01']], 'period.from'],
]);

test('period and bucket are stored only where they mean something', function () {
    $def = ReportDefinition::fromArray(['subject' => 'invoices', 'columns' => ['number'],
        'period' => ['field' => 'due_date', 'preset' => 'this_month', 'from' => '2026-01-01', 'to' => '2026-01-31'],
        'groups' => [['field' => 'status', 'bucket' => 'month']]], $this->finance);

    expect($def->periodFrom)->toBeNull()->and($def->periodTo)->toBeNull()
        ->and($def->groups[0]['bucket'])->toBeNull();
});
