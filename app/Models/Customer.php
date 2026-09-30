<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Enums\PermissionName;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    public function maskedId(): string
    {
        return '••••'.substr($this->id_number, -4);
    }

    /** Contracts use name_ar in Arabic text, falling back to name_en (spec §5.6). */
    public function displayName(string $lang): string
    {
        return $lang === 'ar' && filled($this->name_ar) ? (string) $this->name_ar : $this->name_en;
    }
}
