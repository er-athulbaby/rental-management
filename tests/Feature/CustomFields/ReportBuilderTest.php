<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Reports\Builder\FieldType;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $this->today = CarbonImmutable::today('Asia/Bahrain');
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
});

test('unit and building fields become typed Units columns: numbers total, building fields in a Building section, files left out, scope kept', function () {
    $area = customField('unit', 'number', 'Balcony area');
    customField('unit', 'file', 'Floor plan');
    $zone = customField('building', 'choice', 'Zone', choices: ['North', 'South']);
    [$north, $south] = $zone->choices->all();
    customValue($zone, $this->a, ['choice_id' => $north->id]);
    customValue($zone, $this->b, ['choice_id' => $south->id]);
    $u1 = Unit::factory()->for($this->a)->create(['code' => 'A1']);
    $u2 = Unit::factory()->for($this->a)->create(['code' => 'A2']);
    $u3 = Unit::factory()->for($this->b)->create(['code' => 'B1']);
    customValue($area, $u1, ['value_number' => '10.125']);
    customValue($area, $u2, ['value_number' => '2.5']);
    customValue($area, $u3, ['value_number' => '1000']);

    $fields = Registry::get('units')->fields($this->leasing, $this->today);
    expect($fields['cf_balcony_area']->type)->toBe(FieldType::Number)
        ->and($fields['cf_balcony_area']->section)->toBe('More details')
        ->and($fields['cfb_zone']->section)->toBe('Building')
        ->and($fields['cfb_zone']->options)->toBe([(string) $north->id => 'North', (string) $south->id => 'South'])
        ->and(array_key_exists('cf_floor_plan', $fields))->toBeFalse();

    $result = runReport($this->leasing, ['subject' => 'units', 'columns' => ['code', 'cf_balcony_area', 'cfb_zone'],
        'totals' => ['cf_balcony_area' => 'sum'], 'sort' => ['field' => 'code', 'dir' => 'asc']]);
    expect(array_map(fn (array $r) => [$r['code'], (string) $r['cf_balcony_area'], (string) $r['cfb_zone']], $result->rows))
        ->toBe([['A1', '10.125', (string) $north->id], ['A2', '2.500', (string) $north->id]]) // BBB is not Leasing's
        ->and((string) $result->grandTotals['cf_balcony_area:sum'])->toBe('12.625');
});

test('dates group by month, yes/no groups, and Agreements carry tenant fields in a Tenant section', function () {
    $moved = customField('customer', 'date', 'Moved to Bahrain');
    $pets = customField('customer', 'bool', 'Has pets');
    $t1 = Customer::factory()->create(['name_en' => 'T1']);
    $t2 = Customer::factory()->create(['name_en' => 'T2']);
    $t3 = Customer::factory()->create(['name_en' => 'T3']);
    customValue($moved, $t1, ['value_date' => '2024-03-05']);
    customValue($moved, $t2, ['value_date' => '2024-03-28']);
    customValue($moved, $t3, ['value_date' => '2024-07-01']);
    customValue($pets, $t1, ['value_bool' => true]);
    customValue($pets, $t2, ['value_bool' => false]);
    activeAgreement(['customer_id' => $t1->id], [Unit::factory()->for($this->a)->create()]);

    $byMonth = runReport($this->leasing, ['subject' => 'tenants', 'columns' => ['name', 'cf_moved_to_bahrain'], 'groups' => [['field' => 'cf_moved_to_bahrain', 'bucket' => 'month']]]);
    $byPets = runReport($this->leasing, ['subject' => 'tenants', 'columns' => ['name'], 'groups' => [['field' => 'cf_has_pets']]]);
    $agreements = runReport($this->leasing, ['subject' => 'agreements', 'columns' => ['number', 'cft_has_pets']]);

    expect(array_map(fn (array $g) => [$g['label'], $g['totals']['count']], $byMonth->groups))->toBe([['Mar 2024', 2], ['Jul 2024', 1]])
        ->and(array_map(fn (array $g) => [$g['label'], $g['totals']['count']], $byPets->groups))->toBe([['No', 1], ['Yes', 1], ['—', 1]])
        ->and(Registry::get('agreements')->fields($this->leasing, $this->today)['cft_has_pets']->section)->toBe('Tenant')
        ->and(array_map(fn (array $r) => (int) $r['cft_has_pets'], $agreements->rows))->toBe([1]);
});

test('saved reports use the key: a renamed field keeps working, an archived one is an empty "(archived)" column, a deleted one is left out with a note', function () {
    $employer = customField('customer', 'text', 'Employer');
    $badge = customField('customer', 'text', 'Badge');
    customValue($employer, Customer::factory()->create(['name_en' => 'Sara']), ['value_text' => 'Bapco']);
    $saved = ['subject' => 'tenants', 'columns' => ['name', 'cf_employer', 'cf_badge'],
        'filters' => [['field' => 'cf_employer', 'op' => 'contains', 'value' => 'bap']], 'sort' => ['field' => 'name', 'dir' => 'asc']];

    $employer->forceFill(['label_en' => 'Employer name'])->save();
    $renamed = runReport($this->leasing, $saved);
    expect($renamed->columns[1]->label)->toBe('Employer name')->and($renamed->rows[0]['cf_employer'])->toBe('Bapco');

    $employer->forceFill(['archived_at' => now()])->save();
    $archived = runReport($this->leasing, $saved);
    expect($archived->columns[1]->label)->toBe('Employer name (archived)')
        ->and($archived->rows)->toHaveCount(1) // its filter no longer narrows
        ->and($archived->rows[0]['cf_employer'])->toBeNull();

    $badge->delete();
    [$data, $dropped] = ReportDefinition::forViewer($saved, $this->leasing);
    expect($data['columns'])->toBe(['name', 'cf_employer'])->and($dropped)->toBe(['cf_badge'])
        ->and(runReport($this->leasing, $data)->rows)->toHaveCount(1);
});

test('the builder offers active fields and keeps an archived one only where a report already uses it', function () {
    customField('customer', 'text', 'Employer');
    customField('customer', 'text', 'Old code', ['archived_at' => now()]);

    Livewire::actingAs($this->admin)->test(Builder::class)->call('setSubject', 'tenants')
        ->assertSee('Employer')->assertDontSee('Old code (archived)');
});
