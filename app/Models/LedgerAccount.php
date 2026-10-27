<?php

namespace App\Models;

use App\Enums\LedgerAccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * General ledger spec §3, §7. Written only by the ledger Actions (SaveLedgerAccount, SetLedgerAccountActive,
 * DeleteLedgerAccount). An account with lines cannot be deleted (foreign key).
 *
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string $name_ar
 * @property LedgerAccountType $type
 * @property string|null $system_key
 * @property bool $active
 */
class LedgerAccount extends Model
{
    protected $fillable = ['code', 'name_en', 'name_ar', 'type'];

    protected function casts(): array
    {
        return ['type' => LedgerAccountType::class, 'active' => 'boolean'];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    /** "2100 Owner payable": pickers and reports. */
    public function label(): string
    {
        return $this->code.' '.$this->name();
    }
}
