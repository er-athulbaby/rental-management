<?php

namespace App\Models;

use App\Enums\IdType;
use App\Enums\PartyType;
use Database\Factories\OwnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property PartyType $type
 * @property string $name_en
 * @property IdType $id_type
 * @property string $id_number
 * @property string|null $iban
 * @property Carbon|null $bank_changed_at
 * @property int|null $bank_changed_by
 */
class Owner extends Model
{
    /** @use HasFactory<OwnerFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public const array BANK_FIELDS = ['bank_name', 'iban', 'account_name'];

    protected $fillable = [
        'type', 'name_en', 'name_ar', 'id_type', 'id_number', 'nationality', 'phone', 'email', 'address',
        'bank_name', 'iban', 'account_name', 'notes',
    ];

    protected function casts(): array
    {
        return ['type' => PartyType::class, 'id_type' => IdType::class, 'bank_changed_at' => 'datetime'];
    }

    /** Bank fields are audited explicitly with a masked IBAN (SaveOwner), never in full. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept([...self::BANK_FIELDS, 'bank_changed_at', 'bank_changed_by'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<User, $this> */
    public function bankChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bank_changed_by');
    }

    /** Spec §4.4: remittances warn for 30 days after a bank change. */
    public function bankChangedRecently(): bool
    {
        return $this->bank_changed_at !== null && $this->bank_changed_at->greaterThan(now()->subDays(30));
    }

    public function maskedIban(): ?string
    {
        return $this->iban ? '••••'.substr($this->iban, -4) : null;
    }
}
