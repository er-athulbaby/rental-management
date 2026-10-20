<?php

namespace App\Models;

use App\Enums\AgreementStatus;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\Trade;
use App\Enums\WorkOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec 2026-10-17 maintenance §3.2. Written only by the App\Actions\Maintenance actions; status and number with forceFill.
 *
 * @property int $id
 * @property string $number
 * @property int $building_id
 * @property int|null $unit_id
 * @property int|null $customer_id
 * @property string $reported_by_name
 * @property MaintenanceChannel $channel
 * @property Trade $category
 * @property MaintenancePriority $priority
 * @property string $description
 * @property bool $entry_permitted
 * @property MaintenanceStatus $status
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $cancel_reason
 * @property int|null $created_by
 * @property int|null $portal_account_id
 * @property CarbonImmutable $created_at
 * @property-read Building $building
 * @property-read Unit|null $unit
 * @property-read Customer|null $customer
 */
class MaintenanceRequest extends Model
{
    use LogsActivity;

    protected $fillable = ['building_id', 'unit_id', 'customer_id', 'reported_by_name', 'channel', 'category', 'priority', 'description', 'entry_permitted'];

    protected function casts(): array
    {
        return [
            'channel' => MaintenanceChannel::class,
            'category' => Trade::class,
            'priority' => MaintenancePriority::class,
            'status' => MaintenanceStatus::class,
            'entry_permitted' => 'boolean',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<PortalAccount, $this> set when the tenant logged it in the portal (spec 2026-10-18 tenant portal §5) */
    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class);
    }

    /** Who logged it, for the staff request page. */
    public function creatorName(): string
    {
        return $this->creator->name ?? __('Tenant portal');
    }

    /** @return HasMany<WorkOrder, $this> */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class)->orderBy('id');
    }

    /** @return HasMany<MaintenanceEvent, $this> the timeline, oldest first */
    public function events(): HasMany
    {
        return $this->hasMany(MaintenanceEvent::class)->orderBy('id');
    }

    /**
     * Spec 2026-10-18 tenant portal §4: what the tenant sees of the timeline (plan ruling 15). Status rows only; never
     * notes, reasons, work order numbers, vendors or amounts.
     *
     * @return list<array{at: CarbonImmutable, label: string}>
     */
    public function tenantTimeline(): array
    {
        $steps = [];
        foreach ($this->events()->where('kind', 'status')->get(['work_order_id', 'to_status', 'created_at']) as $event) {
            $label = match (true) {
                $event->work_order_id === null && $event->to_status === MaintenanceStatus::Open->value => __('Request received'),
                $event->work_order_id !== null && $event->to_status === WorkOrderStatus::Assigned->value => __('Work assigned'),
                $event->work_order_id === null && $event->to_status === MaintenanceStatus::Resolved->value => __('Completed'),
                $event->work_order_id === null && $event->to_status === MaintenanceStatus::Cancelled->value => __('Cancelled'),
                default => '',
            };
            if ($label !== '') {
                $steps[] = ['at' => $event->created_at, 'label' => $label];
            }
        }

        return $steps;
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @param  Builder<MaintenanceRequest>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('building', fn (Builder $q) => $q->withoutTrashed()->visibleTo($user)); // the relation is withTrashed()
    }

    /** @param  Builder<MaintenanceRequest>  $query */
    #[Scope]
    protected function inBuilding(Builder $query, int $buildingId): void
    {
        $query->where('building_id', $buildingId);
    }

    /**
     * Spec §5: urgent first, then oldest (plan ruling 14).
     *
     * @param  Builder<MaintenanceRequest>  $query
     */
    #[Scope]
    protected function urgentFirst(Builder $query): void
    {
        $query->orderByRaw("priority = 'urgent' DESC")->orderBy('id');
    }

    /** "Seef Tower 101", or the building alone for a common area. */
    public function place(): string
    {
        return trim($this->building->name.' '.$this->unit?->code);
    }

    /** The description shortened for lists and the expense description. */
    public function summary(): string
    {
        return Str::limit($this->description, 60);
    }

    /** Spec §3.2: the tenant of the unit's active agreement today (Asia/Bahrain), found as RecordExpense finds it. */
    public static function currentTenantId(int $unitId): ?int
    {
        $today = now('Asia/Bahrain')->toDateString();

        return AgreementUnit::query()
            ->with('agreement:id,customer_id')
            ->where('unit_id', $unitId)
            ->whereHas('agreement', fn (Builder $q) => $q->where('status', AgreementStatus::Active))
            ->where('start_date', '<=', $today)
            ->whereRaw('COALESCE(move_out_date, end_date) >= ?', [$today])
            ->first()?->agreement?->customer_id;
    }
}
