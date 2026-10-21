<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: one person's sign-in to the portal, the `tenant`
 * guard's user, holding a tenant, an owner or both (one email, one sign-in). Never a staff User. Written only by
 * App\Actions\Portal (and the tenant and owner saves' email sync); no password, sign-in is by emailed code.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $owner_id
 * @property string $email lower case (tenant plan ruling 5)
 * @property string $locale 'en' | 'ar'
 * @property CarbonImmutable|null $invited_at
 * @property int|null $invited_by
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $disabled_at
 * @property string|null $remember_token nulled on disable and on an email change; PortalSession also stamps live sessions with it
 * @property-read Customer|null $customer
 * @property-read Owner|null $owner
 * @property-read string $name
 */
class PortalAccount extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public const array LOCALES = ['en', 'ar'];

    protected $fillable = ['customer_id', 'owner_id', 'email', 'locale'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return ['invited_at' => 'immutable_datetime', 'last_login_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime'];
    }

    /**
     * Stored in lower case for every writer (tenant plan ruling 5).
     *
     * @return Attribute<never, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => Str::lower($value));
    }

    /** @return BelongsTo<Customer, $this> with trashed: a deleted tenant's account still names them in the audit log */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<Owner, $this> with trashed: a deleted owner's account still names them in the audit log */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class)->withTrashed();
    }

    /** Owner portal plan ruling 2: a linked tenant that is not soft-deleted, read fresh (not from a loaded relation). */
    public function hasTenant(): bool
    {
        return $this->customer_id !== null && Customer::query()->whereKey($this->customer_id)->exists();
    }

    /** Owner portal plan ruling 2: a linked owner that is not soft-deleted, read fresh. */
    public function hasOwner(): bool
    {
        return $this->owner_id !== null && Owner::query()->whereKey($this->owner_id)->exists();
    }

    /** Checked on every request (owner portal plan ruling 2): not disabled, and at least one linked record not soft-deleted. */
    public function isActive(): bool
    {
        return $this->disabled_at === null && ($this->hasTenant() || $this->hasOwner());
    }

    /** The account column that links $record: customer_id for a tenant, owner_id for an owner. */
    public static function column(Customer|Owner $record): string
    {
        return $record instanceof Customer ? 'customer_id' : 'owner_id';
    }

    /** $record's own account, if it has one. */
    public static function of(Customer|Owner $record): ?self
    {
        return self::query()->where(self::column($record), $record->getKey())->first();
    }

    /** The other record on a shared account, while it is not soft-deleted ("Also used by …", owner portal plan ruling 6). */
    public function otherThan(Customer|Owner $record): Customer|Owner|null
    {
        return $record instanceof Customer
            ? ($this->owner_id !== null ? Owner::query()->find($this->owner_id) : null)
            : ($this->customer_id !== null ? Customer::query()->find($this->customer_id) : null);
    }

    /** The person the emails greet: the tenant when there is one, else the owner (owner portal plan ruling 16). */
    public function personName(string $lang): string
    {
        return $this->customer !== null ? $this->customer->displayName($lang) : (string) $this->owner?->displayName($lang);
    }

    /**
     * How the audit log viewer names this causer (tenant plan ruling 9).
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->customer !== null
            ? __('Tenant portal').' · '.$this->customer->name_en
            : __('Owner portal').' · '.$this->owner?->name_en);
    }

    /** No password: sign-in is by emailed code (spec §3). */
    public function getAuthPassword(): string
    {
        return '';
    }
}
