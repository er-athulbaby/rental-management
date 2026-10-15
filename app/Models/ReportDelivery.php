<?php

namespace App\Models;

use Carbon\CarbonImmutable;
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
}
