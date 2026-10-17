<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A recurring email of a saved report or a report package (spec §7; packages: spec 2026-10-17 §5). Weekly `day` is 0-6 (Sunday = 0); monthly is 1-28, or 0 for the last day.
 *
 * @property int $id
 * @property int|null $saved_report_id
 * @property int|null $report_package_id
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
    protected $fillable = ['saved_report_id', 'report_package_id', 'frequency', 'day', 'hour', 'next_run_at', 'consecutive_failures', 'paused_at', 'created_by'];

    protected function casts(): array
    {
        return ['next_run_at' => 'immutable_datetime', 'paused_at' => 'immutable_datetime', 'day' => 'integer', 'hour' => 'integer'];
    }

    /** @return BelongsTo<SavedReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class, 'saved_report_id');
    }

    /** @return BelongsTo<ReportPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ReportPackage::class, 'report_package_id');
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
