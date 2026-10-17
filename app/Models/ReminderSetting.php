<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One reminder type's settings (spec 2026-10-17 §3). The migration creates the four rows, all switched off.
 *
 * @property int $id
 * @property string $type
 * @property bool $enabled
 * @property list<int> $days
 * @property string $intro_en
 * @property string $intro_ar
 */
class ReminderSetting extends Model
{
    use LogsActivity;

    /** The types, most urgent first: the email's subject and section order (spec §4). */
    public const array TYPES = ['overdue', 'rent_due', 'cheque', 'renewal'];

    protected $fillable = ['enabled', 'days', 'intro_en', 'intro_ar'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'days' => 'array'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['enabled', 'days', 'intro_en', 'intro_ar'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    public static function label(string $type): string
    {
        return match ($type) {
            'overdue' => __('Overdue'),
            'rent_due' => __('Rent due'),
            'cheque' => __('Cheque'),
            'renewal' => __('Renewal'),
            default => $type,
        };
    }

    /**
     * "14, 1 7" → [1, 7, 14]; null unless it is 1 to 5 different whole numbers from 1 to 365 (spec §3).
     *
     * @return list<int>|null
     */
    public static function parseDays(string $text): ?array
    {
        $parts = preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === [] || count($parts) > 5) {
            return null;
        }
        $days = [];
        foreach ($parts as $part) {
            if (! ctype_digit($part) || (int) $part < 1 || (int) $part > 365) {
                return null;
            }
            $days[] = (int) $part;
        }
        if (count(array_unique($days)) !== count($days)) {
            return null;
        }
        sort($days);

        return $days;
    }
}
