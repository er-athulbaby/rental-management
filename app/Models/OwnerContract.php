<?php

namespace App\Models;

use App\Enums\DepositsHeldBy;
use App\Enums\FeeType;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\PaymentFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OwnerContractFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string|null $number
 * @property int $owner_id
 * @property int $building_id
 * @property OwnerContractType $type
 * @property OwnerContractStatus $status
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable|null $terminated_on
 * @property string|null $termination_reason
 * @property int|null $previous_contract_id
 * @property string|null $rent_amount
 * @property PaymentFrequency|null $payment_frequency
 * @property FeeType|null $fee_type
 * @property string|null $fee_value
 * @property string|null $expense_approval_limit
 * @property DepositsHeldBy|null $deposits_held_by
 * @property int $created_by
 */
class OwnerContract extends Model
{
    /** @use HasFactory<OwnerContractFactory> */
    use HasFactory, LogsActivity;

    /** Statuses whose dates attribute income and expenses to the owner (spec §4.5, §4.6). */
    public const array ATTRIBUTED = [OwnerContractStatus::Active, OwnerContractStatus::Ended, OwnerContractStatus::Terminated];

    public const array LEASED_TERMS = ['rent_amount', 'payment_frequency'];

    public const array MANAGED_TERMS = ['fee_type', 'fee_value', 'expense_approval_limit', 'deposits_held_by'];

    protected $fillable = [
        'owner_id', 'building_id', 'type', 'start_date', 'end_date', 'previous_contract_id',
        ...self::LEASED_TERMS, ...self::MANAGED_TERMS, 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'type' => OwnerContractType::class,
            'status' => OwnerContractStatus::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'terminated_on' => 'immutable_date',
            'rent_amount' => 'decimal:3',
            'payment_frequency' => PaymentFrequency::class,
            'fee_type' => FeeType::class,
            'fee_value' => 'decimal:3',
            'expense_approval_limit' => 'decimal:3',
            'deposits_held_by' => DepositsHeldBy::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Owner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_contract_id');
    }

    /** @return MorphMany<Approval, $this> */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /** @return HasMany<OwnerPayable, $this> */
    public function payables(): HasMany
    {
        return $this->hasMany(OwnerPayable::class);
    }

    /** @return BelongsToMany<Unit, $this> */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'owner_contract_units');
    }

    /** @param  Builder<OwnerContract>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('building', fn (Builder $q) => $q->visibleTo($user));
    }

    /**
     * Attributed contracts whose dates cover $date. Always compares DATE to a Y-m-d string:
     * a DATE compared to a datetime string drops the last day after midnight.
     *
     * @param  Builder<OwnerContract>  $query
     */
    #[Scope]
    protected function effectiveOn(Builder $query, CarbonInterface|string $date): void
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        $query->whereIn($query->qualifyColumn('status'), array_map(fn (OwnerContractStatus $s) => $s->value, self::ATTRIBUTED))
            ->where($query->qualifyColumn('start_date'), '<=', $day)
            ->where($query->qualifyColumn('end_date'), '>=', $day);
    }

    public function label(): string
    {
        return $this->number ?? __('Draft #:id', ['id' => $this->id]);
    }
}
