<?php

use App\Actions\Documents\ShareDocumentWithTenant;
use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\DocumentCategory;
use App\Enums\RoleName as R;
use App\Livewire\Documents\Panel;
use App\Livewire\Portal\Statement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
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
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    $this->agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
        [Unit::factory()->for($this->building)->create(['code' => '101'])]);
    $this->account = portalAccount($this->tenant);
    $au = $this->agreement->agreementUnits->first();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00', 'Asia/Bahrain')); // issuing stamps today as the issue date
    issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $au]], '2026-09-01', $this->agreement); // INV-2026-000001
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Asia/Bahrain'));
    issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $au]], '2026-10-01', $this->agreement); // INV-2026-000002
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    $finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance);
    app(RecordPayment::class)->handle($finance, $this->tenant, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '500.000']); // RCP-2026-000001
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->store = fn (mixed $on, string $name, DocumentCategory $category = DocumentCategory::Other) => app(StoreDocument::class)
        ->handle($this->pm, $on, UploadedFile::fake()->create($name, 10, 'application/pdf'), $category);
});

test('the statement shows the account between two dates; bad dates are a message', function () {
    $page = Livewire::actingAs($this->account, 'tenant')->test(Statement::class)
        ->assertSet('from', '2026-01-01')->assertSet('to', '2026-10-18')
        ->assertSeeInOrder(['Opening balance', '0.000', 'INV-2026-000001', '400.000', 'INV-2026-000002', '800.000', 'RCP-2026-000001', '500.000', '300.000']);

    $page->set('from', '2026-10-02')->assertSeeInOrder(['Opening balance', '800.000', 'RCP-2026-000001', '300.000'])->assertDontSee('INV-2026-000001');
    $page->set('to', '2026-01-01')->assertSee('Choose a valid date range.');
    $page->set('from', ['x'])->assertOk()->assertSee('Choose a valid date range.');
    $page->set('from', '2026-02-30')->assertOk()->assertSee('Choose a valid date range.');
});

test('the statement PDF is the staff one, for the signed-in tenant only, audited', function () {
    $this->actingAs($this->account, 'tenant')
        ->get(route('portal.statement.pdf', ['from' => '2026-01-01', 'to' => '2026-10-18']))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $audit = Activity::query()->where('event', 'customer.statement_exported')->sole();
    expect($audit->causer?->is($this->account))->toBeTrue()
        ->and($audit->subject?->is($this->tenant))->toBeTrue();

    $this->get(route('portal.statement.pdf', ['from' => '2026-10-18', 'to' => '2026-01-01']))->assertNotFound();
    $this->get(route('portal.statement.pdf', ['from' => ['x'], 'to' => '2026-01-01']))->assertNotFound();
    $this->get(route('portal.statement.pdf', ['customer' => Customer::factory()->create()->id, 'from' => '2026-01-01', 'to' => '2026-10-18']))->assertOk(); // no customer parameter is read
    expect(Activity::query()->where('event', 'customer.statement_exported')->pluck('subject_id')->unique()->all())->toBe([$this->tenant->id]);
});

test('staff share a document from the tenant page; the toggle is an audit entry', function () {
    $rules = ($this->store)($this->tenant, 'house-rules.pdf');

    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $this->tenant])
        ->assertSee('Share with tenant')
        ->call('toggleShare', $rules->id)->assertHasNoErrors()->assertSee('Shared with tenant')->assertSee('Stop sharing');

    expect($rules->fresh()->shared_with_tenant)->toBeTrue();
    $audit = Activity::query()->where('subject_type', $rules->getMorphClass())->where('subject_id', $rules->id)->where('event', 'updated')->sole();
    expect($audit->causer?->is($this->pm))->toBeTrue()
        ->and($audit->attribute_changes['attributes']['shared_with_tenant'])->toBeTrue()
        ->and($audit->attribute_changes['old']['shared_with_tenant'])->toBeFalse();
});

test('the tenant sees and downloads only shared documents on themselves, their agreements or their invoices', function () {
    $share = fn ($document) => app(ShareDocumentWithTenant::class)->handle($this->pm, $document, true);
    $rules = $share(($this->store)($this->tenant, 'house-rules.pdf'));
    $annex = $share(($this->store)($this->agreement, 'agreement-annex.pdf'));
    $private = ($this->store)($this->tenant, 'id-copy.pdf', DocumentCategory::IdCopy);
    $other = Customer::factory()->create();
    $otherShared = $share(($this->store)($other, 'other-rules.pdf'));

    $this->actingAs($this->account, 'tenant')->get(route('portal.documents'))->assertOk()
        ->assertSee('house-rules.pdf')->assertSee('agreement-annex.pdf')->assertDontSee('id-copy.pdf')->assertDontSee('other-rules.pdf');

    $this->get(route('portal.documents.download', $rules))->assertOk()->assertDownload('house-rules.pdf');
    $this->get(route('portal.documents.download', $annex))->assertOk();
    expect(Activity::query()->where('event', 'document.downloaded')->where('causer_type', $this->account->getMorphClass())->count())->toBe(2);

    $this->get(route('portal.documents.download', $private))->assertNotFound();
    $this->get(route('portal.documents.download', $otherShared))->assertNotFound();

    app(ShareDocumentWithTenant::class)->handle($this->pm, $rules->fresh(), false);
    $this->get(route('portal.documents.download', $rules))->assertNotFound();
});

test('sharing is offered only on tenant, agreement and invoice documents, to staff who can change the record', function () {
    $photo = ($this->store)($this->building, 'lobby.pdf', DocumentCategory::Photo);
    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $this->building])->assertDontSee('Share with tenant');
    expect(fn () => app(ShareDocumentWithTenant::class)->handle($this->pm, $photo, true))->toThrow(ValidationException::class);

    $rules = ($this->store)($this->tenant, 'house-rules.pdf');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    $finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance); // views tenants, cannot change them
    Livewire::actingAs($finance)->test(Panel::class, ['documentable' => $this->tenant])->assertSee('house-rules.pdf')->assertDontSee('Share with tenant');
    expect(fn () => app(ShareDocumentWithTenant::class)->handle($finance, $rules, true))->toThrow(AuthorizationException::class)
        ->and($rules->fresh()->shared_with_tenant)->toBeFalse();
});

test('a tampered or foreign document id is a message, never a 500', function (Closure $id) {
    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $this->tenant])
        ->call('toggleShare', $id->call($this))->assertOk()->assertHasErrors('share');
})->with([
    'text' => [fn () => 'abc'],
    'an array' => [fn () => ['1']],
    'another record\'s document' => [fn () => ($this->store)($this->agreement, 'annex.pdf')->id],
    'no such document' => [fn () => 999999],
]);
