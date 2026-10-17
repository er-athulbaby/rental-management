<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Several reports bundled into one workbook (spec 2026-10-17 §3).
 *
 * @property int $id
 * @property string $name
 * @property string $visibility private | shared
 * @property int $created_by
 * @property int $updated_by
 * @property int|null $items_count
 */
class ReportPackage extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'visibility', 'created_by', 'updated_by'];

    /** @return HasMany<ReportPackageItem, $this> in package order */
    public function items(): HasMany
    {
        return $this->hasMany(ReportPackageItem::class)->orderBy('position');
    }

    /** @return HasMany<ReportSchedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user's own packages and every shared one.
     *
     * @param  Builder<ReportPackage>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('created_by', $user->id)->orWhere('visibility', 'shared'));
    }
}
