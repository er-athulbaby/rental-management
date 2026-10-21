<?php

use App\Actions\Documents\ShareDocument;
use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Enums\DocumentCategory;
use App\Enums\RoleName as R;
use App\Livewire\Documents\Panel;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerContract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->admin = matrixUser(R::Admin, Building::factory()->create());
    $this->a = portalOwnerFixture('Ali Owner', '101', $this->admin);
    $this->b = portalOwnerFixture('Badr Owner', '202', $this->admin);
    $this->store = fn (mixed $on, string $name) => app(StoreDocument::class)
        ->handle($this->admin, $on, UploadedFile::fake()->create($name, 10, 'application/pdf'), DocumentCategory::Other);
    $this->share = fn (mixed $document) => app(ShareDocument::class)->handle($this->admin, $document, 'owner', true);
});

test('staff share a document from the owner page; it is not shared until then, and the toggle is an audit entry', function () {
    $deed = ($this->store)($this->a['owner'], 'title-deed.pdf');
    expect($deed->fresh()->shared_with_owner)->toBeFalse();

    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $this->a['owner']])
        ->assertSee('Share with owner')->assertDontSee('Share with tenant')
        ->call('toggleOwnerShare', $deed->id)->assertHasNoErrors()->assertSee('Shared with owner');

    expect($deed->fresh()->shared_with_owner)->toBeTrue()->and($deed->fresh()->shared_with_tenant)->toBeFalse();
    $audit = Activity::query()->where('subject_type', $deed->getMorphClass())->where('subject_id', $deed->id)->where('event', 'updated')->sole();
    expect($audit->causer?->is($this->admin))->toBeTrue()
        ->and($audit->attribute_changes['attributes']['shared_with_owner'])->toBeTrue();
});

test('the owner sees and downloads only documents shared on them, their contracts or their buildings', function () {
    $deed = ($this->share)(($this->store)($this->a['owner'], 'title-deed.pdf'));
    $annex = ($this->share)(($this->store)($this->a['managed'], 'contract-annex.pdf'));
    $plan = ($this->share)(($this->store)($this->a['tower'], 'floor-plan.pdf'));
    $private = ($this->store)($this->a['owner'], 'id-copy.pdf');
    $draft = OwnerContract::factory()->create(['owner_id' => $this->a['owner']->id]);
    $draftTerms = ($this->share)(($this->store)($draft, 'draft-terms.pdf'));
    $other = ($this->share)(($this->store)($this->b['owner'], 'other-deed.pdf'));

    $this->flushSession();
    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.documents'))->assertOk()
        ->assertSee('title-deed.pdf')->assertSee('contract-annex.pdf')->assertSee('floor-plan.pdf')
        ->assertDontSee('id-copy.pdf')->assertDontSee('draft-terms.pdf')->assertDontSee('other-deed.pdf');

    $this->get(route('portal.owner.documents.download', $deed))->assertOk()->assertDownload('title-deed.pdf');
    $this->get(route('portal.owner.documents.download', $plan))->assertOk();
    expect(Activity::query()->where('event', 'document.downloaded')->where('causer_type', $this->a['account']->getMorphClass())->count())->toBe(2);

    foreach ([$private, $draftTerms, $other] as $document) {
        $this->get(route('portal.owner.documents.download', $document))->assertNotFound();
    }
    app(ShareDocument::class)->handle($this->admin, $deed->fresh(), 'owner', false);
    $this->get(route('portal.owner.documents.download', $deed))->assertNotFound();
    $this->get(route('portal.owner.documents.download', $annex))->assertOk();
});

test('a document shared with the owner is not shown to the tenant, and the reverse', function () {
    $renter = Customer::query()->where('name_en', 'Renter 101')->sole();
    $plan = ($this->share)(($this->store)($this->a['tower'], 'floor-plan.pdf'));
    $rules = app(ShareDocument::class)->handle($this->admin, ($this->store)($renter, 'house-rules.pdf'), 'tenant', true);

    $this->actingAs(portalAccount($renter), 'tenant')->get(route('portal.documents'))->assertOk()
        ->assertSee('house-rules.pdf')->assertDontSee('floor-plan.pdf');
    $this->flushSession();
    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.documents'))->assertOk()
        ->assertSee('floor-plan.pdf')->assertDontSee('house-rules.pdf');
    expect($plan->fresh()->shared_with_tenant)->toBeFalse()->and($rules->fresh()->shared_with_owner)->toBeFalse();
});

test('"Share with owner" is offered only on owner, owner contract and building documents, to staff who can change the record', function () {
    ($this->store)($this->a['tower'], 'floor-plan.pdf');
    ($this->store)($this->a['managed'], 'contract-annex.pdf');
    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $this->a['tower']])->assertSee('Share with owner')->assertDontSee('Share with tenant');
    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $this->a['managed']])->assertSee('Share with owner');
    $renter = Customer::query()->where('name_en', 'Renter 101')->sole();
    ($this->store)($renter, 'receipt-copy.pdf');
    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $renter])->assertSee('Share with tenant')->assertDontSee('Share with owner');
    expect(fn () => app(ShareDocument::class)->handle($this->admin, $renter->documents()->sole(), 'owner', true))->toThrow(ValidationException::class);

    $deed = ($this->store)($this->a['owner'], 'title-deed.pdf');
    $pm = User::factory()->withTwoFactor()->create()->assignRole(R::PropertyManager); // views owners, cannot change them
    Livewire::actingAs($pm)->test(Panel::class, ['documentable' => $this->a['owner']])->assertSee('title-deed.pdf')->assertDontSee('Share with owner');
    expect(fn () => app(ShareDocument::class)->handle($pm, $deed, 'owner', true))->toThrow(AuthorizationException::class)
        ->and($deed->fresh()->shared_with_owner)->toBeFalse();
});

test('a tampered or foreign document id is a message, never a 500', function (Closure $id) {
    Livewire::actingAs($this->admin)->test(Panel::class, ['documentable' => $this->a['owner']])
        ->call('toggleOwnerShare', $id->call($this))->assertOk()->assertHasErrors('share');
})->with([
    'text' => [fn () => 'abc'],
    'an array' => [fn () => ['1']],
    'another record\'s document' => [fn () => ($this->store)($this->a['managed'], 'annex.pdf')->id],
    'no such document' => [fn () => 999999],
]);
