<?php

use App\CustomFields\CustomFieldFilter;
use App\Enums\CustomFieldEntity;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Livewire\Agreements\Index as AgreementsIndex;
use App\Livewire\Buildings\Index as BuildingsIndex;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Owners\Index as OwnersIndex;
use App\Livewire\Units\Index as UnitsIndex;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
});

test('the Tenants list filters on a text field inside the user\'s building scope', function () {
    $employer = customField('customer', 'text', 'Employer');
    $sara = Customer::factory()->create(['name_en' => 'Sara Inscope']);
    $omar = Customer::factory()->create(['name_en' => 'Omar Elsewhere']);
    $lina = Customer::factory()->create(['name_en' => 'Lina Inscope']);
    Customer::factory()->create(['name_en' => 'Nadia Blank']); // no agreement yet: everyone sees her
    activeAgreement(['customer_id' => $sara->id], [Unit::factory()->for($this->a)->create()]);
    activeAgreement(['customer_id' => $omar->id], [Unit::factory()->for($this->b)->create()]);
    activeAgreement(['customer_id' => $lina->id], [Unit::factory()->for($this->a)->create()]);
    customValue($employer, $sara, ['value_text' => 'Bapco Refining']);
    customValue($employer, $omar, ['value_text' => 'Bapco Gas']);
    customValue($employer, $lina, ['value_text' => 'Alba']);

    Livewire::actingAs($this->leasing)->test(CustomersIndex::class)
        ->assertSee('More filters')
        ->set('more', ['field' => 'employer', 'op' => 'contains', 'value' => 'bapco'])
        ->assertSee('Sara Inscope')->assertDontSee('Lina Inscope')->assertDontSee('Nadia Blank')->assertDontSee('Omar Elsewhere')
        ->set('more', ['field' => 'employer', 'op' => 'empty'])
        ->assertSee('Nadia Blank')->assertDontSee('Sara Inscope')->assertDontSee('Lina Inscope')->assertDontSee('Omar Elsewhere');
});

test('number and date fields filter from–to, inclusive at both edges, exactly', function () {
    $area = customField('unit', 'number', 'Balcony area');
    $handover = customField('unit', 'date', 'Handover');
    [$u1, $u2, $u3] = Unit::factory()->for($this->a)->count(3)->create()->all();
    customValue($area, $u1, ['value_number' => '999999999999.998']);
    customValue($area, $u2, ['value_number' => '999999999999.999']);
    customValue($area, $u3, ['value_number' => '12.5']);
    customValue($handover, $u1, ['value_date' => '2026-01-31']);
    customValue($handover, $u3, ['value_date' => '2026-02-01']);
    $ids = fn (array $filter) => CustomFieldFilter::apply(Unit::query(), CustomFieldEntity::Unit, $filter)->orderBy('id')->pluck('id')->all();

    expect($ids(['field' => 'balcony_area', 'op' => 'range', 'from' => '999999999999.999']))->toBe([$u2->id])
        ->and($ids(['field' => 'balcony_area', 'op' => 'range', 'to' => '12.500']))->toBe([$u3->id])
        ->and($ids(['field' => 'handover', 'op' => 'range', 'from' => '2026-02-01', 'to' => '2026-12-31']))->toBe([$u3->id])
        ->and($ids(['field' => 'handover', 'op' => 'empty']))->toBe([$u2->id]);
});

test('choices, yes/no and "is empty" filter Agreements, Owners and Buildings; bad filter values are ignored', function () {
    $source = customField('agreement', 'choice', 'Source', choices: ['Walk-in', 'Agent', 'Website']);
    [$walkIn, $agent] = $source->choices->all();
    $ag1 = activeAgreement([], [Unit::factory()->for($this->a)->create()]);
    $ag2 = activeAgreement([], [Unit::factory()->for($this->a)->create()]);
    customValue($source, $ag1, ['choice_id' => $agent->id]);
    $agent->forceFill(['hidden_at' => now()])->save(); // still filters: records keep hidden choices
    $vat = customField('owner', 'bool', 'VAT registered');
    [$o1, $o2, $o3] = Owner::factory()->count(3)->create()->all();
    customValue($vat, $o1, ['value_bool' => true]);
    customValue($vat, $o2, ['value_bool' => false]);
    $deed = customField('building', 'text', 'Title deed');
    customValue($deed, $this->a, ['value_text' => 'TD-1']);

    $agreements = fn (mixed $f) => CustomFieldFilter::apply(Agreement::query(), CustomFieldEntity::Agreement, $f)->orderBy('id')->pluck('id')->all();
    $owners = fn (mixed $f) => CustomFieldFilter::apply(Owner::query(), CustomFieldEntity::Owner, $f)->orderBy('id')->pluck('id')->all();
    expect($agreements(['field' => 'source', 'op' => 'in', 'choices' => [(string) $agent->id, (string) $walkIn->id]]))->toBe([$ag1->id])
        ->and($owners(['field' => 'vat_registered', 'op' => 'yes']))->toBe([$o1->id])
        ->and($owners(['field' => 'vat_registered', 'op' => 'no']))->toBe([$o2->id])
        ->and($owners(['field' => 'vat_registered', 'op' => 'empty']))->toBe([$o3->id])
        ->and(CustomFieldFilter::apply(Building::query(), CustomFieldEntity::Building, ['field' => 'title_deed', 'op' => 'empty'])->pluck('id')->all())->toBe([$this->b->id]);

    customField('agreement', 'number', 'Commission');
    $all = [$ag1->id, $ag2->id];
    foreach ([
        'abc', [], ['field' => 'nope', 'op' => 'empty'], ['field' => ['x'], 'op' => 'in'], ['field' => 'source', 'op' => 'contains', 'value' => 'x'],
        ['field' => 'source', 'op' => 'in', 'choices' => 'x'], ['field' => 'source', 'op' => 'in', 'choices' => ['abc', 999999]],
        ['field' => 'commission', 'op' => 'range', 'from' => '1e3', 'to' => ['x']],
    ] as $bad) {
        expect($agreements($bad))->toBe($all);
    }
});

test('every list offers "More filters" and shrugs off a tampered filter', function (string $component, string $entity) {
    customField($entity, 'text', 'Reference');

    Livewire::actingAs($this->admin)->test($component)->assertSee('More filters')
        ->set('more', 'abc')->assertOk()
        ->set('more', ['field' => ['x'], 'op' => ['y']])->assertOk()
        ->set('more', ['field' => 'reference', 'op' => 'contains', 'value' => 'x'])->assertOk()->assertSee('Contains');
})->with([
    'tenants' => [CustomersIndex::class, 'customer'],
    'units' => [UnitsIndex::class, 'unit'],
    'buildings' => [BuildingsIndex::class, 'building'],
    'owners' => [OwnersIndex::class, 'owner'],
    'agreements' => [AgreementsIndex::class, 'agreement'],
]);

test('a file field is empty once its document is soft-deleted', function () {
    $scan = customField('owner', 'file', 'Scan');
    [$live, $gone, $none] = Owner::factory()->count(3)->create()->all();
    $doc = fn (Owner $o) => tap((new Document(['category' => DocumentCategory::CustomField, 'disk' => 'local', 'path' => 'documents/'.$o->id.'-x.pdf',
        'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => $this->admin->id]))->documentable()->associate($o))->save();
    $d1 = $doc($live);
    $d2 = $doc($gone);
    customValue($scan, $live, ['document_id' => $d1->id]);
    customValue($scan, $gone, ['document_id' => $d2->id]);
    $d2->delete();

    $empty = CustomFieldFilter::apply(Owner::query(), CustomFieldEntity::Owner, ['field' => 'scan', 'op' => 'empty'])->orderBy('id')->pluck('id')->all();
    expect($empty)->toBe([$gone->id, $none->id]);
});
