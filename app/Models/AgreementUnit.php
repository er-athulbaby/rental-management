<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $agreement_id
 * @property int $unit_id
 * @property string $list_rent
 * @property string $deposit_amount
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable|null $planned_exit_date
 * @property CarbonImmutable|null $move_out_date
 * @property-read Unit $unit
 * @property-read Collection<int, AgreementUnitCharge> $charges
 */
class AgreementUnit extends Model
{
    use LogsActivity;

    protected $fillable = ['agreement_id', 'unit_id', 'list_rent', 'deposit_amount', 'start_date', 'end_date', 'planned_exit_date'];

    protected function casts(): array
    {
        return [
            'list_rent' => 'decimal:3',
            'deposit_amount' => 'decimal:3',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'planned_exit_date' => 'immutable_date',
            'move_out_date' => 'immutable_date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<AgreementUnitCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(AgreementUnitCharge::class);
    }

    /**
     * Spec §5.5 effective_end, as SQL so the overlap check (a locking read) and unit status share one definition.
     * One binding: today as Y-m-d. An overstay (past end_date, no move-out, not carried into a renewal) is open-ended.
     *
     * @param  literal-string  $alias  a table alias written in code, never user input
     * @return literal-string
     */
    public static function effectiveEndSql(string $alias = 'agreement_units'): string
    {
        return "CASE WHEN {$alias}.move_out_date IS NOT NULL THEN GREATEST({$alias}.move_out_date, {$alias}.end_date)
            WHEN {$alias}.end_date >= ? THEN {$alias}.end_date
            WHEN EXISTS (SELECT 1 FROM agreements r JOIN agreement_units ru ON ru.agreement_id = r.id
                WHERE r.previous_agreement_id = {$alias}.agreement_id AND ru.unit_id = {$alias}.unit_id
                  AND r.status IN ('pending_approval', 'active') AND r.deleted_at IS NULL) THEN {$alias}.end_date
            ELSE '9999-12-31' END";
    }
}
