<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per schedule per due time (unique), so a retry never sends twice (spec §7).
 *
 * @property int $id
 * @property int $report_schedule_id
 * @property CarbonImmutable $due_at
 * @property string $status sending | sent | failed | skipped
 * @property int $recipients_count
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 */
class ReportDelivery extends Model
{
    protected $fillable = ['report_schedule_id', 'due_at', 'status', 'recipients_count', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    /**
     * Same app-timezone conversion as ReportSchedule::next_run_at, so the unique key compares like with like.
     *
     * @return Attribute<mixed, mixed>
     */
    protected function dueAt(): Attribute
    {
        return Attribute::set(fn (mixed $v) => $v instanceof DateTimeInterface
            ? CarbonImmutable::instance($v)->timezone(config('app.timezone'))->format('Y-m-d H:i:s')
            : $v);
    }
}
