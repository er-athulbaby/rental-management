<?php

namespace App\Models;

use App\Enums\BuildingType;
use App\Enums\PermissionName;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'location', 'address', 'type', 'floors_count', 'parking', 'facilities',
        'property_manager_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['type' => BuildingType::class, 'floors_count' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Spec §8.2 — the one place building scope is decided. Child models reuse it through
     * whereHas('building', fn ($q) => $q->visibleTo($user)).
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return;
        }

        $query->whereIn(
            $query->qualifyColumn('id'),
            fn ($sub) => $sub->select('building_id')->from('building_user')->where('user_id', $user->getKey()),
        );
    }
}
