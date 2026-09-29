<?php

use App\Actions\Owners\SaveOwner;
use App\Enums\RoleName;
use App\Livewire\Owners\Form;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
});

function ownerData(array $overrides = []): array
{
    return [
        'type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '880101234',
        'phone' => '+97333000000', 'iban' => 'BH67 BMAG 0000 1299 1234 56', 'bank_name' => 'NBB', 'account_name' => 'Ali Hassan',
        ...$overrides,
    ];
}

test('Finance can create owners but not set bank details', function () {
    expect(fn () => app(SaveOwner::class)->handle($this->finance, null, ownerData()))->toThrow(AuthorizationException::class);

    $owner = app(SaveOwner::class)->handle($this->finance, null, ownerData(['iban' => null, 'bank_name' => null, 'account_name' => null]));
    expect($owner->name_en)->toBe('Ali Hassan');
});

test('Admin sets bank details; the IBAN is normalised and the change is stamped and audited with a masked value', function () {
    $owner = app(SaveOwner::class)->handle($this->admin, null, ownerData());

    expect($owner->iban)->toBe('BH67BMAG00001299123456')
        ->and($owner->bank_changed_by)->toBe($this->admin->id)
        ->and($owner->bankChangedRecently())->toBeTrue();

    $row = Activity::query()->where('event', 'owner.bank.changed')->latest('id')->firstOrFail();
    expect($row->attribute_changes['attributes']['iban'])->toBe('••••3456');
    expect(DB::table('activity_log')->where('properties', 'like', '%BH67BMAG%')->orWhere('attribute_changes', 'like', '%BH67BMAG%')->exists())->toBeFalse();
});

test('ID numbers are unique per ID type and IBANs are validated', function () {
    app(SaveOwner::class)->handle($this->admin, null, ownerData());

    expect(fn () => app(SaveOwner::class)->handle($this->admin, null, ownerData()))->toThrow(ValidationException::class);
    expect(fn () => app(SaveOwner::class)->handle($this->admin, null, ownerData(['id_number' => '990202345', 'iban' => 'not-an-iban'])))->toThrow(ValidationException::class);
});

test('Finance sees bank details read-only; Leasing cannot open owners', function () {
    $owner = app(SaveOwner::class)->handle($this->admin, null, ownerData());

    Livewire::actingAs($this->finance)->test(Form::class, ['owner' => $owner])
        ->assertSet('form.iban', 'BH67BMAG00001299123456') // wire:model values are not in the server HTML
        ->assertSee('Bank details changed');

    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($leasing)->get(route('owners.index'))->assertForbidden();
});

test('editing non-bank fields does not touch the bank stamp', function () {
    $owner = app(SaveOwner::class)->handle($this->admin, null, ownerData());
    $this->travel(40)->days();

    app(SaveOwner::class)->handle($this->finance, $owner->fresh(), [...ownerData(), 'phone' => '+97339999999']);

    expect($owner->fresh()->bankChangedRecently())->toBeFalse()
        ->and($owner->fresh()->phone)->toBe('+97339999999');
});
