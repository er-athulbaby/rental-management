<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Enums\PermissionName;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property CustomerType $type
 * @property string $name_en
 * @property string|null $name_ar
 * @property IdType $id_type
 * @property string $id_number
 * @property string $mobile
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'type', 'name_en', 'name_ar', 'id_type', 'id_number', 'nationality', 'mobile', 'email', 'address',
        'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes',
    ];

    protected function casts(): array
    {
        return ['type' => CustomerType::class, 'id_type' => IdType::class];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Agreement, $this> */
    public function agreements(): HasMany
    {
        return $this->hasMany(Agreement::class);
    }

    /**
     * Spec §8.2: in scope if it has any agreement with a unit in an assigned building, or no agreement yet.
     *
     * @param  Builder<Customer>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('agreements')
            ->orWhereHas('agreements', fn (Builder $a) => $a->visibleTo($user)));
    }

    /**
     * The first matches for a customer picker; nothing until two characters are typed.
     *
     * @return Collection<int, Customer>
     */
    public static function findFor(User $user, string $term): Collection
    {
        if (mb_strlen(trim($term)) < 2) {
            return new Collection;
        }

        return self::query()->visibleTo($user)->search($term)->orderBy('name_en')->limit(8)->get(['id', 'name_en', 'id_number', 'mobile']);
    }

    /**
     * Tenants with an agreement (any status) on a unit in the building.
     *
     * @param  Builder<Customer>  $query
     */
    #[Scope]
    protected function inBuilding(Builder $query, int $buildingId): void
    {
        $query->whereHas('agreements', fn (Builder $a) => $a->inBuilding($buildingId));
    }

    public function maskedId(): string
    {
        return '••••'.substr($this->id_number, -4);
    }

    /** Contracts use name_ar in Arabic text, falling back to name_en (spec §5.6). */
    public function displayName(string $lang): string
    {
        return $lang === 'ar' && filled($this->name_ar) ? (string) $this->name_ar : $this->name_en;
    }

    /**
     * Finds a customer by part of their name or mobile, their exact ID number, or a unit code they rent.
     *
     * @param  Builder<Customer>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);
        $query->where(fn (Builder $q) => $q
            ->where('name_en', 'like', '%'.$term.'%')
            ->orWhere('name_ar', 'like', '%'.$term.'%')
            ->orWhere('mobile', 'like', '%'.$term.'%')
            ->orWhere('id_number', $term)
            ->orWhereHas('agreements.agreementUnits.unit', fn (Builder $u) => $u->where('code', $term)));
    }
}
