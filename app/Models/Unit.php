<?php

namespace App\Models;

use App\Enums\AgreementStatus;
use App\Enums\Furnishing;
use App\Enums\TaxCategory;
use App\Enums\UnitStatus;
use App\Enums\UnitUse;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $building_id
 * @property string $code
 * @property UnitUse $use
 * @property string $type code of a UnitType
 * @property Furnishing $furnishing
 * @property TaxCategory|null $default_tax_category
 * @property bool $blocked
 * @property string $list_rent
 * @property string $list_deposit
 * @property string $list_service_charge
 * @property-read Building $building
 */
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'building_id', 'code', 'floor', 'use', 'type', 'bedrooms', 'bathrooms', 'area_sqm', 'furnishing',
        'list_rent', 'list_deposit', 'list_service_charge', 'default_tax_category', 'ewa_account_no',
        'blocked', 'blocked_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'use' => UnitUse::class,
            'furnishing' => Furnishing::class,
            'default_tax_category' => TaxCategory::class,
            'blocked' => 'boolean',
            'list_rent' => 'decimal:3',
            'list_deposit' => 'decimal:3',
            'list_service_charge' => 'decimal:3',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return HasMany<AgreementUnit, $this> */
    public function agreementUnits(): HasMany
    {
        return $this->hasMany(AgreementUnit::class);
    }

    /** @return BelongsTo<UnitType, $this> */
    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'type', 'code');
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @param  Builder<Unit>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('building', fn (Builder $q) => $q->visibleTo($user));
    }

    /** @return HasMany<AgreementUnit, $this> the rows of agreementUnits(), eager-loaded with effective_end by withOccupancy() */
    public function occupancy(): HasMany
    {
        return $this->hasMany(AgreementUnit::class);
    }

    /** @param  Builder<Unit>  $query */
    #[Scope]
    protected function withOccupancy(Builder $query, ?CarbonInterface $today = null): void
    {
        $day = ($today ?? now('Asia/Bahrain'))->toDateString();
        $query->with(['occupancy' => fn (Relation $q) => self::constrainOccupancy($q, $day)]);
    }

    /**
     * Spec §4.3, for today (Asia/Bahrain).
     */
    public function status(?CarbonInterface $today = null): UnitStatus
    {
        $day = ($today ?? now('Asia/Bahrain'))->toDateString();
        $lines = $this->occupancyLines($day);

        $current = self::currentLine($lines, $day);
        if ($current) {
            return $current->planned_exit_date !== null || $current->agreement->planned_exit_date !== null
                ? UnitStatus::NoticeGiven
                : UnitStatus::Occupied;
        }

        if ($lines->contains(fn (AgreementUnit $au) => $au->agreement->status === AgreementStatus::PendingApproval
            || ($au->agreement->status === AgreementStatus::Active && $au->start_date->toDateString() > $day))) {
            return UnitStatus::Reserved;
        }

        return $this->blocked ? UnitStatus::Blocked : UnitStatus::Available;
    }

    /** "Occupied · next tenant from {date}" (spec §4.3). */
    public function nextTenantFrom(?CarbonInterface $today = null): ?CarbonImmutable
    {
        $day = ($today ?? now('Asia/Bahrain'))->toDateString();
        $lines = $this->occupancyLines($day);

        if (! self::currentLine($lines, $day)) {
            return null;
        }

        return $lines
            ->filter(fn (AgreementUnit $au) => in_array($au->agreement->status, [AgreementStatus::Active, AgreementStatus::PendingApproval], true)
                && $au->start_date->toDateString() > $day)
            ->sortBy(fn (AgreementUnit $au) => $au->start_date->toDateString())
            ->first()?->start_date;
    }

    /** @param  Collection<int, AgreementUnit>  $lines */
    private static function currentLine(Collection $lines, string $day): ?AgreementUnit
    {
        return $lines->first(fn (AgreementUnit $au) => $au->agreement->status !== AgreementStatus::PendingApproval
            && $au->start_date->toDateString() <= $day
            && (string) $au->getAttribute('effective_end') >= $day);
    }

    /** @return Collection<int, AgreementUnit> */
    private function occupancyLines(string $day): Collection
    {
        if ($this->relationLoaded('occupancy')) {
            return $this->getRelation('occupancy');
        }

        $lines = $this->occupancy();
        self::constrainOccupancy($lines, $day);

        return $lines->get();
    }

    /**
     * Non-draft agreement units with effective_end computed by the same SQL the overlap check uses.
     *
     * @param  Relation<AgreementUnit, *, *>  $query
     */
    private static function constrainOccupancy(Relation $query, string $day): void
    {
        $query->select('agreement_units.*')
            ->selectRaw('('.AgreementUnit::effectiveEndSql().') as effective_end', [$day])
            ->whereHas('agreement', fn (Builder $a) => $a->where('status', '<>', AgreementStatus::Draft->value))
            ->with('agreement:id,status,planned_exit_date');
    }

    public function effectiveTaxCategory(): TaxCategory
    {
        $settings = CompanySetting::current();

        return $this->default_tax_category ?? ($this->use === UnitUse::Commercial
            ? $settings->commercial_tax_category
            : $settings->residential_tax_category);
    }
}
