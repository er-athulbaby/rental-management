<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A recurring email of a saved report (spec §7). Weekly `day` is 0-6 (Sunday = 0); monthly is 1-28, or 0 for the last day.
 *
 * @property int $id
 * @property int $saved_report_id
 * @property string $frequency
 * @property int|null $day
 * @property int $hour
 * @property CarbonImmutable $next_run_at
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $paused_at
 * @property int $created_by
 */
class ReportSchedule extends Model
{
    protected $fillable = ['saved_report_id', 'frequency', 'day', 'hour', 'next_run_at', 'consecutive_failures', 'paused_at', 'created_by'];

    protected function casts(): array
    {
        return ['next_run_at' => 'immutable_datetime', 'paused_at' => 'immutable_datetime', 'day' => 'integer', 'hour' => 'integer'];
    }

    /**
     * Columns hold app-timezone wall time; NextRun hands back UTC, so convert before storing (else it shifts by the offset).
     *
     * @return Attribute<mixed, mixed>
     */
    protected function nextRunAt(): Attribute
    {
        return Attribute::set(fn (mixed $v) => $v instanceof DateTimeInterface
            ? CarbonImmutable::instance($v)->timezone(config('app.timezone'))->format('Y-m-d H:i:s')
            : $v);
    }

    /** @return BelongsTo<SavedReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class, 'saved_report_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'report_schedule_user');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
