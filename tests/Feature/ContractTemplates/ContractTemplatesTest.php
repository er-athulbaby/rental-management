<?php

use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\ContractTemplates\SaveContractTemplate;
use App\Enums\RoleName;
use App\Livewire\Admin\ContractTemplates\Edit;
use App\Models\ContractTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->clause = ['heading_en' => 'Rent', 'heading_ar' => 'الإيجار', 'body_en' => 'Rent is BHD {total_monthly_rent}.', 'body_ar' => 'الإيجار {total_monthly_rent} دينار.'];
});

test('the default template is bilingual, uses only known merge fields and is created once', function () {
    $template = app(EnsureDefaultContractTemplate::class)();
    app(EnsureDefaultContractTemplate::class)();

    expect(ContractTemplate::count())->toBe(1)
        ->and(ContractTemplate::defaultTemplate()?->id)->toBe($template->id)
        ->and($template->clauses()->count())->toBeGreaterThanOrEqual(8);

    foreach ($template->clauses as $clause) {
        expect($clause->heading_ar)->not->toBe('')
            ->and($clause->body_ar)->toMatch('/\p{Arabic}|\{units_table\}/u');
        preg_match_all('/\{(\w+)\}/', $clause->body_en.$clause->body_ar, $m);
        expect(array_diff($m[1], ContractTemplate::MERGE_FIELDS))->toBe([]);
    }
});

test('Admin saves a template with ordered clauses; the change is audited', function () {
    $template = app(SaveContractTemplate::class)->handle($this->admin, null, [
        'name' => 'Commercial lease', 'active' => true, 'is_default' => false,
        'clauses' => [$this->clause, [...$this->clause, 'heading_en' => 'Deposit', 'body_en' => 'Deposit {total_deposit}.', 'body_ar' => 'التأمين {total_deposit}.']],
    ]);

    expect($template->clauses->pluck('heading_en')->all())->toBe(['Rent', 'Deposit'])
        ->and($template->clauses->pluck('position')->all())->toBe([1, 2])
        ->and(Activity::query()->where('event', 'contract_template.clauses_saved')->exists())->toBeTrue();
});

test('one default template at a time', function () {
    $first = app(EnsureDefaultContractTemplate::class)();
    $second = app(SaveContractTemplate::class)->handle($this->admin, null, ['name' => 'New default', 'active' => true, 'is_default' => true, 'clauses' => [$this->clause]]);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and(ContractTemplate::defaultTemplate()?->id)->toBe($second->id);
});

test('clauses are validated: known merge fields, matching paragraphs, paragraph length', function (array $clause) {
    expect(fn () => app(SaveContractTemplate::class)->handle($this->admin, null, ['name' => 'Bad', 'active' => true, 'is_default' => false, 'clauses' => [$clause]]))
        ->toThrow(ValidationException::class);
})->with([
    'unknown field' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => 'Pay {bank_iban}.', 'body_ar' => 'ادفع.']],
    'paragraphs differ' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => "One.\n\nTwo.", 'body_ar' => 'واحد.']],
    'too long' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => str_repeat('x', 1201), 'body_ar' => str_repeat('س', 10)]],
    'units table mixed' => [['heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => 'See {units_table} below', 'body_ar' => '{units_table}']],
]);

test('only templates.manage holders edit templates', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    expect(fn () => app(SaveContractTemplate::class)->handle($pm, null, ['name' => 'X', 'active' => true, 'is_default' => false, 'clauses' => [$this->clause]]))
        ->toThrow(AuthorizationException::class);
    $this->actingAs($pm)->get(route('admin.templates.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.templates.index'))->assertOk();
});

test('the editor adds, reorders and removes clauses', function () {
    $template = app(EnsureDefaultContractTemplate::class)();
    $count = $template->clauses()->count();

    Livewire::actingAs($this->admin)->test(Edit::class, ['template' => $template])
        ->call('addClause')
        ->set("clauses.{$count}.heading_en", 'Parking')
        ->set("clauses.{$count}.heading_ar", 'المواقف')
        ->set("clauses.{$count}.body_en", 'One parking space is included.')
        ->set("clauses.{$count}.body_ar", 'يشمل العقد موقفاً واحداً.')
        ->call('moveUp', $count)
        ->call('remove', 0)
        ->call('save')
        ->assertHasNoErrors();

    $headings = $template->fresh()->clauses->pluck('heading_en');
    expect($headings)->toHaveCount($count)
        ->and($headings[$count - 2])->toBe('Parking');
});
