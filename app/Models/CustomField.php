<?php

namespace App\Models;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Spec 2026-10-21 custom fields §3: an Admin-defined field on one record kind. Written only by App\Actions\CustomFields;
 * audited there (plan ruling 13), so no LogsActivity.
 *
 * @property int $id
 * @property CustomFieldEntity $entity
 * @property string $key fixed at creation (plan ruling 9)
 * @property string $label_en
 * @property string|null $label_ar
 * @property CustomFieldType $type
 * @property string|null $help
 * @property bool $required
 * @property bool $in_portal
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property int|null $created_by
 * @property-read Collection<int, CustomFieldChoice> $choices
 */
class CustomField extends Model
{
    /** Spec §3: at most 30 active fields per record kind (plan ruling 10). */
    public const int MAX_ACTIVE = 30;

    protected function casts(): array
    {
        return ['entity' => CustomFieldEntity::class, 'type' => CustomFieldType::class, 'required' => 'boolean', 'in_portal' => 'boolean',
            'position' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CustomFieldChoice, $this> */
    public function choices(): HasMany
    {
        return $this->hasMany(CustomFieldChoice::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<CustomFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /** Spec §3: AR optional, falls back to EN. */
    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' && filled($this->label_ar) ? (string) $this->label_ar : $this->label_en;
    }

    /**
     * Plan ruling 9: the fixed key, made once from the English label: lower-case snake case, at most 40 characters,
     * starting with a letter, with _2, _3, … when the kind already has it (archived fields included).
     */
    public static function keyFor(CustomFieldEntity $entity, string $labelEn): string
    {
        $slug = trim(substr(Str::slug($labelEn, '_'), 0, 40), '_');
        $base = $slug === '' ? 'field' : (ctype_alpha($slug[0]) ? $slug : 'f_'.$slug);
        $taken = self::query()->where('entity', $entity)->pluck('key')->all();
        $key = $base;
        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = "{$base}_{$n}";
        }

        return $key;
    }
}
