<?php

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\EnsureNumberSequences;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Jobs\StoreApprovedContract;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Unit;
use App\Models\User;
use App\Pdf\ContractPdf;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureDefaultContractTemplate::class)();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    config(['app.url' => 'https://rms.example.test']);

    $this->pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->unit = Unit::factory()->for(Building::factory()->create(['name' => 'Marina Tower']))->create(['code' => '1204']);
    $this->draft = app(SaveAgreement::class)->handle($this->pm, null, [
        'customer_id' => Customer::factory()->create(['name_en' => 'Layla Karim', 'name_ar' => 'ليلى كريم'])->id,
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'frequency' => 'monthly',
        'units' => [['unit_id' => $this->unit->id, 'deposit_amount' => '600', 'charges' => [['type' => 'rent', 'monthly_amount' => '600', 'tax_category' => 'exempt']]]],
    ]);
});

test('a draft contract renders live from the template with a DRAFT watermark and no QR', function () {
    $built = app(ContractPdf::class)->build($this->draft);

    expect($built['view'])->toBe('pdf.contract')
        ->and($built['chrome']['watermark'])->toBe('DRAFT')
        ->and($built['data']['verifyUrl'])->toBeNull()
        ->and($built['data']['number'])->toBe('DRAFT')
        ->and($built['data']['clauses'][0]['paragraphs'][0][0])->toContain('No. DRAFT')->toContain('Layla Karim')
        ->and($built['data']['clauses'][0]['paragraphs'][0][1])->toContain('ليلى كريم')
        ->and($built['data']['clauses'][1]['units'])->toBeTrue()
        ->and($built['data']['units'][0]['unit'])->toBe('1204');
});

test('approval stores the frozen contract once, with the number and the QR link', function () {
    $approval = app(SubmitAgreement::class)->handle($this->pm, $this->draft);
    app(DecideApproval::class)->handle($this->management, $approval, true); // sync queue in tests: the job runs after commit

    $agreement = $this->draft->fresh();
    $built = app(ContractPdf::class)->build($agreement);
    expect($built['chrome']['watermark'])->toBeNull()
        ->and($built['data']['verifyUrl'])->toBe('https://rms.example.test/v/'.$agreement->verify_token)
        ->and($built['data']['clauses'][0]['paragraphs'][0][0])->toContain('No. AGR-2026-000001');

    $document = Document::query()->where('documentable_id', $agreement->id)->where('category', DocumentCategory::GeneratedPdf)->sole();
    expect(Storage::disk('local')->get($document->path))->toStartWith('%PDF')
        ->and($document->uploaded_by)->toBe($this->management->id);

    (new StoreApprovedContract($agreement->id, $this->management->id))->handle(app(ContractPdf::class));
    expect(Document::query()->where('documentable_id', $agreement->id)->where('category', DocumentCategory::GeneratedPdf)->count())->toBe(1);
});

test('the PDF route streams a watermarked draft and serves the stored copy once approved', function () {
    $this->actingAs($this->pm)->get(route('agreements.pdf', $this->draft))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(Activity::query()->where('event', 'agreement.draft_pdf_viewed')->exists())->toBeTrue();

    $approval = app(SubmitAgreement::class)->handle($this->pm, $this->draft);
    app(DecideApproval::class)->handle($this->management, $approval, true);

    $document = Document::query()->where('category', DocumentCategory::GeneratedPdf)->sole();
    $this->actingAs($this->pm)->get(route('agreements.pdf', $this->draft))->assertRedirect(route('documents.download', $document));

    $outsider = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($outsider)->get(route('agreements.pdf', $this->draft))->assertForbidden();
});

test('the contract is stored exactly once however often the job runs, and a repeat run writes no file', function () {
    $approval = app(SubmitAgreement::class)->handle($this->pm, $this->draft);
    app(DecideApproval::class)->handle($this->management, $approval, true);
    $agreement = $this->draft->fresh();
    $files = count(Storage::disk('local')->allFiles());

    (new StoreApprovedContract($agreement->id, $this->management->id))->handle(app(ContractPdf::class));
    (new StoreApprovedContract($agreement->id, $this->management->id))->handle(app(ContractPdf::class));

    expect(Document::query()->where('documentable_id', $agreement->id)->where('category', DocumentCategory::GeneratedPdf)->count())->toBe(1)
        ->and(count(Storage::disk('local')->allFiles()))->toBe($files);
});

test('a failed insert leaves no orphan file behind', function () {
    $approval = app(SubmitAgreement::class)->handle($this->pm, $this->draft);
    app(DecideApproval::class)->handle($this->management, $approval, true); // the job stores it
    Document::query()->delete();
    $before = Storage::disk('local')->allFiles();
    Document::creating(fn () => throw new RuntimeException('boom'));
    expect(fn () => (new StoreApprovedContract($this->draft->id, $this->management->id))->handle(app(ContractPdf::class)))->toThrow(RuntimeException::class, 'boom');
    expect(Storage::disk('local')->allFiles())->toBe($before);
});

test('the Arabic cell of a clause keeps its own rtl block so digits stay in order', function () {
    $built = app(ContractPdf::class)->build($this->draft);
    $html = view($built['view'], $built['data'])->render();

    // Heading and first paragraph are each an rtl block (a bare text node after the heading block reversed digits in mPDF).
    expect($html)->toContain('<div class="hd" dir="rtl">1. ')->toContain('</div><div dir="rtl">');
});
