<?php

namespace App\Models;

use App\Enums\Furnishing;
use App\Enums\TaxCategory;
use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Enums\UnitUse;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $building_id
 * @property string $code
 * @property UnitUse $use
 * @property UnitType $type
 * @property Furnishing $furnishing
 * @property TaxCategory|null $default_tax_category
 * @property bool $blocked
 * @property string $list_rent
 * @property string $list_deposit
 * @property string $list_service_charge
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
            'type' => UnitType::class,
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

    /** Spec §4.3. ponytail: agreements (M2) add Occupied, Reserved and Notice given before these two. */
    public function status(): UnitStatus
    {
        return $this->blocked ? UnitStatus::Blocked : UnitStatus::Available;
    }

    public function effectiveTaxCategory(): TaxCategory
    {
        $settings = CompanySetting::current();

        return $this->default_tax_category ?? ($this->use === UnitUse::Commercial
            ? $settings->commercial_tax_category
            : $settings->residential_tax_category);
    }
}
