<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * General ledger spec §7. Written only through Poster::write() (plan ruling 1); never updated (journals_no_update).
 * kind: auto (from a source record) | manual (Finance) | opening (Admin's opening balances).
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property string $kind
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $description
 * @property bool $late_posting
 * @property int|null $reverses_journal_id
 * @property int|null $created_by
 * @property-read Collection<int, JournalLine> $lines
 */
class Journal extends Model
{
    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'late_posting' => 'boolean'];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
