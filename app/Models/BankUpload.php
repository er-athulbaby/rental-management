<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank reconciliation spec §2: one uploaded statement file and its counts. Written by ImportBankStatement; deleted with its
 * lines by DeleteBankUpload while none of them is matched.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property string $file_name
 * @property int $imported
 * @property int $skipped
 * @property int $failed
 * @property int $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read User $uploader
 */
class BankUpload extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['imported' => 'integer', 'skipped' => 'integer', 'failed' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<BankStatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
