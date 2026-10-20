<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec 2026-10-18 tenant portal §3: a tenant's sign-in to the portal, the `tenant` guard's user. Never a staff User.
 * Written only by App\Actions\Portal (and SaveCustomer's email sync); no password, sign-in is by emailed code.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $email lower case (plan ruling 5)
 * @property string $locale 'en' | 'ar'
 * @property CarbonImmutable|null $invited_at
 * @property int|null $invited_by
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $disabled_at
 * @property-read Customer $customer
 * @property-read string $name
 */
class PortalAccount extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public const array LOCALES = ['en', 'ar'];

    protected $fillable = ['customer_id', 'email', 'locale'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return ['invited_at' => 'immutable_datetime', 'last_login_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Customer, $this> with trashed: a deleted tenant's account still names them in the audit log */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** Spec §3: checked on every request. Not disabled, and the tenant not soft-deleted (read fresh, not from a loaded relation). */
    public function isActive(): bool
    {
        return $this->disabled_at === null && Customer::query()->whereKey($this->customer_id)->exists();
    }

    /**
     * How the audit log viewer names this causer (plan ruling 9).
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => __('Tenant portal').' · '.$this->customer->name_en);
    }

    /** No password: sign-in is by emailed code (spec §3). */
    public function getAuthPassword(): string
    {
        return '';
    }
}
