<?php

use App\Actions\Agreements\SaveAgreementDetails;
use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $pdf = fn (string $name) => UploadedFile::fake()->create($name, 5, 'application/pdf');

    // Tenants: Sara and Omar in Seef Tower.
    $building = Building::factory()->create(['name' => 'Seef Tower']);
    $sara = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    $omar = Customer::factory()->create(['name_en' => 'Omar Tenant', 'email' => 'omar@tenant.test']);
    $saraUnit = Unit::factory()->for($building)->create(['code' => '101']);
    $omarUnit = Unit::factory()->for($building)->create(['code' => '102']);
    $saraAgreement = activeAgreement(['customer_id' => $sara->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$saraUnit]);
    $omarAgreement = activeAgreement(['customer_id' => $omar->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$omarUnit]);
    $this->saraAccount = portalAccount($sara);

    $employer = customField('customer', 'text', 'Employer', ['in_portal' => true, 'label_ar' => 'جهة العمل']);
    $rating = customField('customer', 'text', 'Internal rating');
    $scan = customField('customer', 'file', 'ID scan');
    $this->broker = customField('agreement', 'text', 'Broker', ['in_portal' => true]);
    $this->permit = customField('agreement', 'file', 'Parking permit', ['in_portal' => true]);
    $meter = customField('unit', 'text', 'Meter number', ['in_portal' => true]);
    customValue($employer, $sara, ['value_text' => 'Bapco']);
    customValue($employer, $omar, ['value_text' => 'Alba']);
    customValue($rating, $sara, ['value_text' => 'Gold']);
    customValue($meter, $saraUnit, ['value_text' => 'EWA-101']);
    customValue($meter, $omarUnit, ['value_text' => 'EWA-102']);
    $details = app(SaveAgreementDetails::class);
    $details->handle($this->admin, $saraAgreement, ['broker' => 'Gulf Realty', 'parking_permit' => $pdf('old-permit.pdf')]);
    $details->handle($this->admin, $saraAgreement, ['parking_permit' => $pdf('permit.pdf')]); // replaces it
    $details->handle($this->admin, $omarAgreement, ['broker' => 'Omar Broker', 'parking_permit' => $pdf('omar-permit.pdf')]);
    $named = fn (string $name) => Document::query()->where('original_name', $name)->sole();
    [$this->oldPermit, $this->saraPermit, $this->omarPermit] = [$named('old-permit.pdf'), $named('permit.pdf'), $named('omar-permit.pdf')];
    $this->secretScan = app(StoreDocument::class)->handle($this->admin, $sara, $pdf('id-scan.pdf'), DocumentCategory::CustomField);
    customValue($scan, $sara, ['document_id' => $this->secretScan->id]); // not "Show in portal"

    // Owners: Ali and Badr, each with a managed tower.
    $this->ali = portalOwnerFixture('Ali Owner', '7', $this->admin);
    $this->badr = portalOwnerFixture('Badr Owner', '8', $this->admin);
    $plot = customField('owner', 'text', 'Plot number', ['in_portal' => true, 'label_ar' => 'رقم القسيمة']);
    $deed = customField('building', 'text', 'Title deed no', ['in_portal' => true]);
    $note = customField('building', 'text', 'Staff note');
    $sitePlan = customField('building', 'file', 'Site plan', ['in_portal' => true]);
    customValue($plot, $this->ali['owner'], ['value_text' => 'PL-7']);
    customValue($deed, $this->ali['tower'], ['value_text' => 'TD-TOWER-7']);
    customValue($note, $this->ali['tower'], ['value_text' => 'Leaky roof']);
    customValue($deed, $this->badr['tower'], ['value_text' => 'TD-TOWER-8']);
    $this->aliPlan = app(StoreDocument::class)->handle($this->admin, $this->ali['tower'], $pdf('ali-plan.pdf'), DocumentCategory::CustomField);
    $this->badrPlan = app(StoreDocument::class)->handle($this->admin, $this->badr['tower'], $pdf('badr-plan.pdf'), DocumentCategory::CustomField);
    customValue($sitePlan, $this->ali['tower'], ['document_id' => $this->aliPlan->id]);
    customValue($sitePlan, $this->badr['tower'], ['document_id' => $this->badrPlan->id]);
});

test('the tenant portal shows filled "Show in portal" fields of their own records, in their language', function () {
    $this->actingAs($this->saraAccount, 'tenant')->get(route('portal.home'))->assertOk()
        ->assertSeeInOrder(['More details', 'Employer', 'Bapco'])
        ->assertDontSee('Internal rating')->assertDontSee('Gold')->assertDontSee('ID scan')->assertDontSee('Alba');
    $this->get(route('portal.agreements'))->assertOk()
        ->assertSeeInOrder(['Broker', 'Gulf Realty', 'Parking permit', 'permit.pdf', 'Meter number', 'EWA-101'])
        ->assertDontSee('old-permit.pdf')->assertDontSee('Omar Broker')->assertDontSee('EWA-102');

    $this->saraAccount->forceFill(['locale' => 'ar'])->save();
    $this->get(route('portal.home'))->assertOk()->assertSeeInOrder(['تفاصيل إضافية', 'جهة العمل', 'Bapco'])->assertDontSee('Employer');
});

test('the owner portal shows owner fields on Home and their own buildings\' fields on the building cards', function () {
    $this->actingAs($this->ali['account'], 'tenant')->get(route('portal.owner.home'))->assertOk()->assertSeeInOrder(['More details', 'Plot number', 'PL-7']);
    $this->get(route('portal.owner.buildings'))->assertOk()
        ->assertSeeInOrder(['Ali Owner Tower', 'Title deed no', 'TD-TOWER-7', 'Site plan', 'ali-plan.pdf'])
        ->assertDontSee('TD-TOWER-8')->assertDontSee('Leaky roof')->assertDontSee('badr-plan.pdf');

    $this->ali['account']->forceFill(['locale' => 'ar'])->save();
    $this->get(route('portal.owner.home'))->assertOk()->assertSee('رقم القسيمة')->assertDontSee('Plot number');
});

test('portal file downloads go through the portal\'s own ownership checks', function () {
    $this->actingAs($this->saraAccount, 'tenant')->get(route('portal.custom-fields.file', $this->saraPermit))->assertOk()->assertDownload('permit.pdf');
    expect(Activity::query()->where('event', 'document.downloaded')->latest('id')->first()?->causer_id)->toBe($this->saraAccount->id);
    foreach ([$this->omarPermit, $this->oldPermit, $this->secretScan, $this->aliPlan] as $document) {
        $this->get(route('portal.custom-fields.file', $document))->assertNotFound();
    }
    $this->get('/portal/custom-fields/123456789012345678901234567890')->assertNotFound();
    $this->get(route('portal.owner.custom-fields.file', $this->aliPlan))->assertNotFound(); // a tenant-only account has no owner side

    $this->flushSession(); // the portal session is stamped per account, so a second account starts a fresh one
    $this->actingAs($this->ali['account'], 'tenant')->get(route('portal.owner.custom-fields.file', $this->aliPlan))->assertOk()->assertDownload('ali-plan.pdf');
    $this->get(route('portal.owner.custom-fields.file', $this->badrPlan))->assertNotFound();
    $this->get(route('portal.custom-fields.file', $this->saraPermit))->assertNotFound(); // an owner-only account has no tenant side
});

test('archived fields leave the portal, and their files with them', function () {
    $this->broker->forceFill(['archived_at' => now()])->save();
    $this->permit->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->saraAccount, 'tenant')->get(route('portal.agreements'))->assertOk()
        ->assertDontSee('Gulf Realty')->assertDontSee('permit.pdf')->assertSee('EWA-101');
    $this->get(route('portal.custom-fields.file', $this->saraPermit))->assertNotFound();
});

test('made-up ids and deleted documents are a 404 on both portal file routes', function () {
    $this->actingAs($this->saraAccount, 'tenant');
    foreach (['abc', '1e3', '-1', '99999999', '123456789012345678901234567890'] as $id) {
        $this->get('/portal/custom-fields/'.$id)->assertNotFound();
    }
    $this->saraPermit->delete(); // a soft-deleted document no longer downloads
    $this->get(route('portal.custom-fields.file', $this->saraPermit))->assertNotFound();

    $this->flushSession();
    $this->actingAs($this->ali['account'], 'tenant');
    foreach (['abc', '1e3', '-1', '99999999', '123456789012345678901234567890'] as $id) {
        $this->get('/portal/owner/custom-fields/'.$id)->assertNotFound();
    }
    $this->aliPlan->delete();
    $this->get(route('portal.owner.custom-fields.file', $this->aliPlan))->assertNotFound();
});
