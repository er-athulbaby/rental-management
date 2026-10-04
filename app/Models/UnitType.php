<?php

namespace App\Models;

use App\Enums\UnitUse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\In;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A unit type from the Admin-managed list (Flat / Apartment, Villa, Shop, …). Units keep its code, which never changes.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property UnitUse|null $default_use
 * @property bool $active
 */
class UnitType extends Model
{
    use LogsActivity;

    protected $fillable = ['code', 'name', 'default_use', 'active'];

    protected function casts(): array
    {
        return ['default_use' => UnitUse::class, 'active' => 'boolean'];
    }

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'type', 'code');
    }

    /**
     * The types a unit may be given: the active ones, plus the one it already has.
     *
     * @return Collection<int, UnitType>
     */
    public static function choices(?string $current = null): Collection
    {
        return self::query()->where(fn ($q) => $q->where('active', true)->when($current, fn ($q, $c) => $q->orWhere('code', $c)))
            ->orderBy('name')->get();
    }

    public static function rule(?string $current = null): In
    {
        return new In(self::choices($current)->pluck('code')->all());
    }

    /** Matches an import value to a code: by code, by name, or by either half of a name like "Flat / Apartment". */
    public static function canonical(string $value): ?string
    {
        $value = Str::lower(trim($value));

        return self::query()->get(['code', 'name'])->first(fn (UnitType $t) => $value === $t->code
            || collect(explode('/', $t->name))->map(fn ($n) => Str::lower(trim($n)))->push(Str::lower($t->name))->contains($value))?->code;
    }

    /** A short, unique code made from the name, e.g. "Car wash bay" → car_wash_bay. */
    public static function codeFor(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 16, '') ?: 'type';
        $code = $base;
        for ($i = 2; self::query()->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['code', 'name', 'default_use', 'active'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
