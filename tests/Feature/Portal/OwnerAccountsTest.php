<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $this->owner = Owner::factory()->create(['name_en' => 'Ali Owner', 'name_ar' => 'علي', 'email' => 'Ali@Owner.test']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'sara@tenant.test']);
});

test('an account holds a tenant, an owner or both, never neither, and one account per owner', function () {
    $ownerOnly = portalAccount($this->owner);
    expect($ownerOnly->customer_id)->toBeNull()
        ->and($ownerOnly->owner_id)->toBe($this->owner->id)
        ->and($ownerOnly->email)->toBe('ali@owner.test');

    $shared = portalAccount($this->tenant, ['owner_id' => Owner::factory()->create()->id]);
    expect($shared->customer_id)->toBe($this->tenant->id)->and($shared->owner_id)->not->toBeNull();

    expect(fn () => (new PortalAccount)->forceFill(['email' => 'nobody@portal.test'])->save())->toThrow(QueryException::class, 'portal_accounts_holder_chk')
        ->and(fn () => portalAccount(Owner::factory()->create(['email' => 'x@owner.test']), ['owner_id' => $this->owner->id]))->toThrow(QueryException::class, 'Duplicate entry')
        ->and(fn () => portalAccount(Owner::factory()->create(['email' => 'y@owner.test']), ['owner_id' => 999999]))->toThrow(QueryException::class, 'foreign key')
        ->and(Schema::hasColumn('documents', 'shared_with_owner'))->toBeTrue();
});

test('an account is active while it is enabled and at least one of its records is not deleted', function () {
    $secondOwner = Owner::factory()->create();
    $shared = portalAccount($this->tenant, ['owner_id' => $secondOwner->id]);
    $ownerOnly = portalAccount($this->owner);

    expect($ownerOnly->isActive())->toBeTrue()->and($ownerOnly->hasOwner())->toBeTrue()->and($ownerOnly->hasTenant())->toBeFalse()
        ->and($shared->hasTenant())->toBeTrue()->and($shared->hasOwner())->toBeTrue();

    $secondOwner->delete();
    expect($shared->isActive())->toBeTrue()->and($shared->hasOwner())->toBeFalse()->and($shared->hasTenant())->toBeTrue();
    $this->tenant->delete();
    expect($shared->isActive())->toBeFalse();

    $this->owner->delete();
    expect($ownerOnly->isActive())->toBeFalse();
    $this->owner->restore();
    expect($ownerOnly->isActive())->toBeTrue();
    $ownerOnly->forceFill(['disabled_at' => now()])->save();
    expect($ownerOnly->isActive())->toBeFalse();
});

test('the account names its person for audits and emails, the tenant first', function () {
    $ownerOnly = portalAccount($this->owner);
    $shared = portalAccount($this->tenant, ['owner_id' => Owner::factory()->create(['name_en' => 'Omar Owner'])->id]);

    expect($ownerOnly->name)->toBe('Owner portal · Ali Owner')
        ->and($ownerOnly->personName('en'))->toBe('Ali Owner')
        ->and($ownerOnly->personName('ar'))->toBe('علي')
        ->and($shared->name)->toBe('Tenant portal · Sara Tenant')
        ->and($shared->personName('ar'))->toBe('سارة');
});

test('an account finds its record and the other record it shares, while that one is not deleted', function () {
    $account = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);

    expect(PortalAccount::column($this->owner))->toBe('owner_id')
        ->and(PortalAccount::column($this->tenant))->toBe('customer_id')
        ->and(PortalAccount::of($this->owner)?->is($account))->toBeTrue()
        ->and($this->owner->portalAccount?->is($account))->toBeTrue()
        ->and($account->otherThan($this->owner)?->is($this->tenant))->toBeTrue()
        ->and($account->otherThan($this->tenant)?->is($this->owner))->toBeTrue();

    $this->tenant->delete();
    expect($account->otherThan($this->owner))->toBeNull();
});

test('rolling the migration back refuses, before any change, while an owner-only account exists', function () {
    portalAccount($this->owner);
    $migration = require database_path('migrations/2026_12_24_000100_add_owner_portal.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'owner-only')
        ->and(Schema::hasColumn('portal_accounts', 'owner_id'))->toBeTrue()
        ->and(Schema::hasColumn('documents', 'shared_with_owner'))->toBeTrue()
        ->and(fn () => (new PortalAccount)->forceFill(['email' => 'none@portal.test'])->save())->toThrow(QueryException::class, 'portal_accounts_holder_chk');
});
