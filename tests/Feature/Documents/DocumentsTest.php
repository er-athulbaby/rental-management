<?php

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\StoreDocument;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Livewire\Documents\Panel;
use App\Models\Building;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->building = Building::factory()->create();
    $this->manager = User::factory()->create()->assignRole(RoleName::PropertyManager);
});

function upload(User $actor, Building $building, UploadedFile $file): Document
{
    return app(StoreDocument::class)->handle($actor, $building, $file, DocumentCategory::Photo);
}

test('an allowed file is stored privately under a random name', function () {
    $document = upload($this->manager, $this->building, UploadedFile::fake()->create('CPR 880101234 Ahmed.pdf', 100, 'application/pdf'));

    expect($document->path)->toMatch('#^documents/\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#')
        ->and($document->path)->not->toContain('Ahmed')
        ->and($document->original_name)->toBe('CPR 880101234 Ahmed.pdf')
        ->and($document->uploaded_by)->toBe($this->manager->id);
    Storage::disk('local')->assertExists($document->path);
    expect(Activity::query()->where('event', 'document.uploaded')->exists())->toBeTrue();
});

test('disallowed types and files over 10 MB are rejected', function () {
    expect(fn () => upload($this->manager, $this->building, UploadedFile::fake()->create('run.exe', 10, 'application/octet-stream')))
        ->toThrow(ValidationException::class);
    expect(fn () => upload($this->manager, $this->building, UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')))
        ->toThrow(ValidationException::class);
});

test('users who cannot update the building cannot upload', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    expect(fn () => upload($leasing, $this->building, UploadedFile::fake()->image('a.jpg')))->toThrow(AuthorizationException::class);
});

test('a user who can view the building downloads it with its original name, audited', function () {
    $document = upload($this->manager, $this->building, UploadedFile::fake()->create('lease.pdf', 10, 'application/pdf'));
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->building->id);

    $this->actingAs($leasing)->get(route('documents.download', $document))
        ->assertOk()
        ->assertDownload('lease.pdf');

    expect(Activity::query()->where('event', 'document.downloaded')->where('causer_id', $leasing->id)->exists())->toBeTrue();
});

test('a user outside the building gets 403', function () {
    $document = upload($this->manager, $this->building, UploadedFile::fake()->create('lease.pdf', 10, 'application/pdf'));

    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))
        ->get(route('documents.download', $document))
        ->assertForbidden();
});

test('delete soft-deletes and audits', function () {
    $document = upload($this->manager, $this->building, UploadedFile::fake()->create('lease.pdf', 10, 'application/pdf'));

    app(DeleteDocument::class)->handle($this->manager, $document);

    expect(Document::find($document->id))->toBeNull()
        ->and(Document::withTrashed()->find($document->id))->not->toBeNull()
        ->and(Activity::query()->where('event', 'document.deleted')->exists())->toBeTrue();
});

test('categories read in proper English in the panel', function () {
    upload($this->manager, $this->building, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
    $this->manager->buildings()->attach($this->building->id);

    Livewire::actingAs($this->manager)->test(Panel::class, ['documentable' => $this->building])
        ->assertSee('ID copy')->assertSee('CR copy')->assertSee('Move-out photo')->assertDontSee('Id Copy');
    expect(array_map(fn (DocumentCategory $c) => $c->label(), DocumentCategory::cases()))
        ->toBe(['Photo', 'ID copy', 'CR copy', 'Signed contract', 'Cheque image', 'Move-out photo', 'Owner approval', 'Generated PDF', 'Other']);
});

test('PDFs and pictures open in the browser with a fixed type; Word and Excel files still download', function () {
    $viewer = User::factory()->create()->assignRole(RoleName::Leasing);
    $viewer->buildings()->attach($this->building->id);
    $pdf = upload($this->manager, $this->building, UploadedFile::fake()->create('lease.pdf', 10, 'application/pdf'));
    $photo = upload($this->manager, $this->building, UploadedFile::fake()->image('flat.png'));
    $sheet = upload($this->manager, $this->building, UploadedFile::fake()->create('rent.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));

    $this->actingAs($viewer)->get(route('documents.view', $pdf))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline; filename=lease.pdf');
    $this->actingAs($viewer)->get(route('documents.view', $photo))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs($viewer)->get(route('documents.view', $sheet))->assertDownload('rent.xlsx');
    expect(Activity::query()->where('event', 'document.viewed')->count())->toBe(2);

    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))->get(route('documents.view', $pdf))->assertForbidden();

    $html = stripslashes(Livewire::actingAs($this->manager)->test(Panel::class, ['documentable' => $this->building])->html());
    expect($html)->toContain(route('documents.view', $pdf))->not->toContain(route('documents.view', $sheet));
});
