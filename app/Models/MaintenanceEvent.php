<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\WorkOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec 2026-10-17 maintenance §3.4: one row per status change or note on a request or its work orders. Each row is
 * also an audit entry (LogsActivity, plan ruling 8).
 *
 * @property int $id
 * @property int $maintenance_request_id
 * @property int|null $work_order_id
 * @property string $kind 'status' | 'note'
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note
 * @property int|null $user_id
 * @property int|null $portal_account_id
 * @property CarbonImmutable $created_at
 * @property-read WorkOrder|null $workOrder
 * @property-read User|null $user
 */
class MaintenanceEvent extends Model
{
    use LogsActivity;

    public const UPDATED_AT = null; // rows are never edited

    protected $fillable = ['maintenance_request_id', 'work_order_id', 'kind', 'from_status', 'to_status', 'note', 'user_id', 'portal_account_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class);
    }

    /** A status change when $to is given, otherwise a note. $by is staff, or the tenant's portal account (plan ruling 4). */
    public static function record(MaintenanceRequest $request, User|PortalAccount $by, ?WorkOrder $workOrder = null, ?string $from = null, ?string $to = null, ?string $note = null): self
    {
        return self::create([
            'maintenance_request_id' => $request->id, 'work_order_id' => $workOrder?->id, 'kind' => $to === null ? 'note' : 'status',
            'from_status' => $from, 'to_status' => $to, 'note' => $note,
            'user_id' => $by instanceof User ? $by->id : null, 'portal_account_id' => $by instanceof PortalAccount ? $by->id : null,
        ]);
    }

    /** Who wrote the row, for the staff timeline. */
    public function byName(): string
    {
        return $this->user->name ?? __('Tenant portal');
    }

    /** "WO-2026-000001: Assigned → In progress · reason", or the note. */
    public function describe(): string
    {
        if ($this->kind === 'note') {
            return (string) $this->note;
        }
        $label = fn (?string $s) => $s === null ? '' : ($this->work_order_id !== null ? WorkOrderStatus::from($s)->label() : MaintenanceStatus::from($s)->label());
        $change = $this->from_status === null ? $label($this->to_status) : $label($this->from_status).' → '.$label($this->to_status);

        return ($this->workOrder ? $this->workOrder->number.': ' : '').$change.($this->note ? ' · '.$this->note : '');
    }
}
