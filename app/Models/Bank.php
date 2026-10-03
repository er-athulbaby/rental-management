<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A bank from the Admin-managed list. Owners and cheques store the bank's name, not its id.
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 */
class Bank extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return list<string> */
    public static function activeNames(): array
    {
        return array_values(self::query()->where('active', true)->orderBy('name')->get()->map(fn (Bank $bank) => $bank->name)->all());
    }

    /** An active bank, or the record's unchanged existing value, so old records still save. */
    public static function rule(?string $existing = null): In
    {
        return Rule::in(array_values(array_filter([...self::activeNames(), $existing])));
    }

    /** The active bank's own spelling of a name, matched case-insensitively. */
    public static function canonical(string $name): ?string
    {
        return collect(self::activeNames())->first(fn (string $bank) => strcasecmp($bank, trim($name)) === 0);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'active'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
