<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Portal\SetPortalAccess;
use App\Enums\DocumentCategory;
use App\Enums\RoleName as R;
use App\Livewire\Customers\Form;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    $this->agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->account = portalAccount($this->tenant);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->leasing = matrixUser(R::Leasing, $this->building);
    $this->setAccess = fn (bool $on) => app(SetPortalAccess::class)->handle($this->leasing, $this->tenant->fresh(), $on);
    $this->sessionLogin = fn () => $this->withSession(['login_tenant_'.sha1(SessionGuard::class) => $this->account->id]);
    $this->recaller = function () {
        $name = 'remember_tenant_'.sha1(SessionGuard::class);

        return $this->withCookie($name, $this->account->id.'|old-token|');
    };
});

test('an old remember cookie is dead after disable then enable, and writes no portal.login audit while disabled', function () {
    $this->account->forceFill(['remember_token' => 'old-token'])->save();
    ($this->recaller)()->get(route('portal.home'))->assertOk();
    expect(Activity::query()->where('event', 'portal.login')->count())->toBe(1);
    Auth::forgetGuards();

    ($this->setAccess)(false);
    expect($this->account->fresh()->remember_token)->toBeNull();
    ($this->recaller)()->get(route('portal.home'))->assertRedirect(route('portal.login'));
    expect(Activity::query()->where('event', 'portal.login')->count())->toBe(1);
    Auth::forgetGuards();

    ($this->setAccess)(true);
    ($this->recaller)()->get(route('portal.home'))->assertRedirect(route('portal.login'));
    expect(Auth::guard('tenant')->check())->toBeFalse();
});

test('a live session does not come back after disable then enable', function () {
    ($this->sessionLogin)()->get(route('portal.home'))->assertOk();
    Auth::forgetGuards();

    ($this->setAccess)(false);
    ($this->setAccess)(true);

    $this->get(route('portal.home'))->assertRedirect(route('portal.login'));
    expect(Auth::guard('tenant')->check())->toBeFalse();
});

test('a live session survives untouched when nothing changed', function () {
    ($this->sessionLogin)()->get(route('portal.home'))->assertOk();
    Auth::forgetGuards();
    $this->get(route('portal.maintenance'))->assertOk();
});

test('changing the tenant email ends remembered devices', function () {
    $this->account->forceFill(['remember_token' => 'old-token'])->save();
    Livewire::actingAs($this->leasing)->test(Form::class, ['customer' => $this->tenant->fresh()])
        ->set('form.email', 'sara.new@tenant.test')->call('save')->assertHasNoErrors();

    expect($this->account->fresh()->email)->toBe('sara.new@tenant.test')->and($this->account->fresh()->remember_token)->toBeNull();
    ($this->recaller)()->get(route('portal.home'))->assertRedirect(route('portal.login'));
});

test('a draft or pending agreement and a draft invoice keep their shared documents out of the portal', function () {
    $store = function ($on, string $name) {
        Storage::disk('local')->put("docs/$name", '%PDF-1.4');

        $doc = Document::query()->forceCreate(['documentable_type' => $on->getMorphClass(), 'documentable_id' => $on->id, 'category' => DocumentCategory::Other, 'disk' => 'local', 'path' => "docs/$name", 'original_name' => $name, 'mime' => 'application/pdf', 'size' => 8, 'uploaded_by' => $this->pm->id]);
        $doc->forceFill(['shared_with_tenant' => true])->save();

        return $doc;
    };
    $draft = Agreement::factory()->create(['customer_id' => $this->tenant->id]);
    $pending = Agreement::factory()->create(['customer_id' => $this->tenant->id]);
    $pending->forceFill(['status' => 'pending_approval'])->save();
    $invoice = issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $this->agreement->agreementUnits->first()]], '2026-10-01', $this->agreement);
    $docs = [
        'draft-agr.pdf' => $store($draft, 'draft-agr.pdf'), 'pending-agr.pdf' => $store($pending, 'pending-agr.pdf'),
        'live-agr.pdf' => $store($this->agreement, 'live-agr.pdf'), 'live-inv.pdf' => $store($invoice, 'live-inv.pdf'),
    ];
    $draftInvoice = (new Invoice)->forceFill(['type' => 'manual', 'customer_id' => $this->tenant->id, 'issue_date' => '2026-10-01', 'due_date' => '2026-10-01', 'status' => 'draft', 'subtotal' => '0.000', 'tax_total' => '0.000', 'total' => '0.000']);
    $draftInvoice->save();
    $docs['draft-inv.pdf'] = $store($draftInvoice, 'draft-inv.pdf');

    $page = $this->actingAs($this->account, 'tenant')->get(route('portal.documents'))->assertOk();
    foreach (['live-agr.pdf', 'live-inv.pdf'] as $ok) {
        $page->assertSee($ok);
        $this->get(route('portal.documents.download', $docs[$ok]))->assertOk();
    }
    foreach (['draft-agr.pdf', 'pending-agr.pdf', 'draft-inv.pdf'] as $hidden) {
        $page->assertDontSee($hidden);
        $this->get(route('portal.documents.download', $docs[$hidden]))->assertNotFound();
    }
});

test('a soft-deleted building and unit still show by name on the portal maintenance and agreements pages', function () {
    $request = maintenanceRequest($this->pm, $this->unit);
    $this->unit->delete();
    $this->building->delete();

    $this->actingAs($this->account, 'tenant');
    $this->get(route('portal.maintenance'))->assertOk()->assertSee('Seef Tower 101');
    $this->get(route('portal.maintenance.show', $request))->assertOk()->assertSee('Seef Tower 101');
    $this->get(route('portal.agreements'))->assertOk()->assertSee('Seef Tower 101');
});

test('home lists an agreement by the move-out date when it falls inside 90 days', function () {
    $this->agreement->agreementUnits()->update(['move_out_date' => '2026-11-20']);

    $this->actingAs($this->account, 'tenant')->get(route('portal.home'))->assertOk()
        ->assertSeeInOrder(['Agreements ending soon', $this->agreement->number, 'Ends 20/11/2026'])->assertDontSee('31/12/2026');
});
