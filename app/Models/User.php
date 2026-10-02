<?php

namespace App\Models;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\Models\Concerns\HasActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $active
 * @property bool $is_system
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasActivity, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /** Never written to the audit log (spec §8.4). #[Hidden] does NOT keep attributes out of it. */
    public const array AUDIT_SECRETS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(self::AUDIT_SECRETS)
            ->dontLogIfAttributesChangedOnly([...self::AUDIT_SECRETS, 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Plan ruling 6: the creator of what the nightly jobs create. Inactive, so it can never sign in
     * (FortifyServiceProvider checks `active`); created on first use.
     */
    public static function system(): self
    {
        return self::query()->where('is_system', true)->first()
            ?? tap((new self)->forceFill([
                'name' => 'System',
                'email' => 'system@rms.invalid',
                'password' => Hash::make(Str::random(64)),
                'active' => false,
                'is_system' => true,
            ]))->save();
    }

    /** Spec §8.6: holders of a sensitive permission must use 2FA. Follows roles granted later. */
    public function requiresTwoFactor(): bool
    {
        return $this->hasAnyPermission(PermissionName::twoFactorRequired());
    }

    public function isVendorSupport(): bool
    {
        return $this->hasRole(RoleName::VendorSupport);
    }

    /**
     * Assigned buildings; only limits users without buildings.view-all (spec §8.2).
     *
     * @return BelongsToMany<Building, $this>
     */
    public function buildings(): BelongsToMany
    {
        return $this->belongsToMany(Building::class);
    }

    /** Kill every session of this user (database driver) and invalidate any remember cookie (spec §8.6). */
    public function logoutEverywhere(?string $exceptSessionId = null): void
    {
        DB::table(config('session.table'))
            ->where('user_id', $this->getKey())
            ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();

        $this->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /** Inactive users silently get no reset link (the response still says "link sent"). */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if ($this->active) {
            parent::sendPasswordResetNotification($token);
        }
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
