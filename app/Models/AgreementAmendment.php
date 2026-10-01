<?php

namespace App\Models;

use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §5.7.
 *
 * @property int $id
 * @property int $agreement_id
 * @property AmendmentType $type
 * @property CarbonImmutable $effective_date
 * @property int|null $agreement_unit_id
 * @property array<string, mixed>|null $data
 * @property string $reason
 * @property AmendmentStatus $status
 * @property CarbonImmutable|null $applied_at
 * @property int $created_by
 */
class AgreementAmendment extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AmendmentType::class,
            'status' => AmendmentStatus::class,
            'effective_date' => 'immutable_date',
            'applied_at' => 'immutable_datetime',
            'data' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['type', 'effective_date', 'agreement_unit_id', 'data', 'reason', 'status', 'applied_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
