<?php

namespace App\Models;

use App\Enums\Trade;
use App\Enums\VendorType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec 2026-10-17 maintenance §3.1: an outside vendor or the company's own staff. Company-wide: no building scope.
 * Written only by SaveVendor.
 *
 * @property int $id
 * @property string $name
 * @property VendorType $type
 * @property list<string> $trades Trade values, in Trade::cases() order
 * @property string $mobile
 * @property string|null $email
 * @property string|null $cr_number
 * @property string|null $vat_number
 * @property string|null $notes
 * @property bool $active
 */
class Vendor extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['name', 'type', 'trades', 'mobile', 'email', 'cr_number', 'vat_number', 'notes', 'active'];

    protected function casts(): array
    {
        return ['type' => VendorType::class, 'trades' => 'array', 'active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return HasMany<WorkOrder, $this> */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /** Spec §4 step 2: the vendor's trades include the category, or general. */
    public function handles(Trade $category): bool
    {
        return in_array($category->value, $this->trades, true) || in_array(Trade::General->value, $this->trades, true);
    }

    /** "Air conditioning, General". */
    public function tradeLabels(): string
    {
        return implode(', ', array_map(fn (string $t) => Trade::tryFrom($t)?->label() ?? $t, $this->trades));
    }

    /**
     * Active vendors who can take a work order for $category.
     *
     * @param  Builder<Vendor>  $query
     */
    #[Scope]
    protected function availableFor(Builder $query, Trade $category): void
    {
        $query->where('active', true)->where(fn (Builder $q) => $q
            ->whereJsonContains('trades', $category->value)
            ->orWhereJsonContains('trades', Trade::General->value));
    }
}
