<?php

namespace App\Models;

use App\Reports\Builder\Registry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A report a user built (spec §3, §9). `definition` is a ReportDefinition array, validated again on every run.
 *
 * @property int $id
 * @property string $name
 * @property string $subject
 * @property array<string, mixed> $definition
 * @property string $visibility private | shared
 * @property int $created_by
 * @property int $updated_by
 * @property CarbonImmutable|null $last_run_at
 */
class SavedReport extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'subject', 'definition', 'visibility', 'created_by', 'updated_by', 'last_run_at'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'last_run_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user's own reports and every shared one whose subject they may use.
     *
     * @param  Builder<SavedReport>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('created_by', $user->id)->orWhere('visibility', 'shared'))
            ->whereIn('subject', array_keys(Registry::forUser($user)));
    }
}
