<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * General ledger spec §7: the one settings row (the migration inserts it). Plan rulings 4–6 and 8. Every posting, lock
 * change, start-date move and rebuild chunk takes `lockForUpdate()` on this row.
 *
 * @property int $id
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $locked_until
 * @property list<array{check: string, ledger: int, app: int, money: bool}>|null $health
 * @property CarbonImmutable|null $health_checked_at
 * @property string|null $rebuild_status queued | running | done | failed
 * @property CarbonImmutable|null $rebuild_from
 * @property CarbonImmutable|null $rebuild_to
 * @property int $rebuild_done
 * @property int $rebuild_total
 * @property string|null $rebuild_message
 */
class LedgerSetting extends Model
{
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'locked_until' => 'immutable_date',
            'health' => 'array',
            'health_checked_at' => 'immutable_datetime',
            'rebuild_from' => 'immutable_date',
            'rebuild_to' => 'immutable_date',
            'rebuild_done' => 'integer',
            'rebuild_total' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrFail();
    }

    /** The first day a posting may land on: the start date, or the day after the lock. Null before the ledger is set up. */
    public function firstOpenDay(): ?CarbonImmutable
    {
        if ($this->start_date === null) {
            return null;
        }

        return $this->locked_until === null ? $this->start_date : $this->locked_until->addDay()->max($this->start_date);
    }

    public function rebuilding(): bool
    {
        return in_array($this->rebuild_status, ['queued', 'running'], true);
    }
}
