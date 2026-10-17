<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec 2026-10-17 maintenance §3.3. Written only by the App\Actions\Maintenance actions; status, amounts and the
 * expense link with forceFill. completed_on is the day the work was done (plan ruling 3).
 *
 * @property int $id
 * @property string $number
 * @property int $maintenance_request_id
 * @property int $vendor_id
 * @property CarbonImmutable|null $scheduled_on
 * @property string|null $instructions
 * @property string|null $estimate
 * @property WorkOrderStatus $status
 * @property CarbonImmutable|null $completed_on
 * @property string|null $completion_note
 * @property string|null $actual_net
 * @property string|null $actual_tax
 * @property int|null $expense_id
 * @property string|null $cancel_reason
 * @property int $created_by
 * @property-read MaintenanceRequest $maintenanceRequest
 * @property-read Vendor $vendor
 * @property-read Expense|null $expense
 */
class WorkOrder extends Model
{
    use LogsActivity;

    protected $fillable = ['vendor_id', 'scheduled_on', 'instructions', 'estimate'];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'scheduled_on' => 'immutable_date',
            'completed_on' => 'immutable_date',
            'estimate' => 'decimal:3',
            'actual_net' => 'decimal:3',
            'actual_tax' => 'decimal:3',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<MaintenanceRequest, $this> */
    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return MorphMany<Document, $this> after-photos */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @param  Builder<WorkOrder>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('maintenanceRequest', fn (Builder $q) => $q->visibleTo($user));
    }

    /** Spec §5 (plan ruling 13): scheduled before today and still assigned or in progress. */
    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->scheduled_on !== null && $this->scheduled_on->toDateString() < now('Asia/Bahrain')->toDateString();
    }
}
