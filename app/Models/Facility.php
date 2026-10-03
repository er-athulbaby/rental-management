<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A building facility from the Admin-managed list (Lift, Gym, Swimming pool, …).
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 */
class Facility extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsToMany<Building, $this> */
    public function buildings(): BelongsToMany
    {
        return $this->belongsToMany(Building::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'active'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
