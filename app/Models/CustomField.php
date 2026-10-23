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
        // Same digit/letter split as the import reader's header snake-casing, so a key is already the column name it is imported under.
        $slug = (string) preg_replace('/(?<=\d)(?=[A-Za-z])|(?<=[A-Za-z])(?=\d)/', '_', Str::slug($labelEn, '_'));
        $prefix = $slug !== '' && ! ctype_alpha($slug[0]) ? 'f_' : '';
        $cut = fn (int $room) => trim(substr($slug, 0, max(0, $room)), '_');
        $taken = self::query()->where('entity', $entity)->pluck('key')->all();
        $key = $slug === '' ? 'field' : $prefix.$cut(40 - strlen($prefix));
        // the base is cut again so that base + suffix stays within 40 characters
        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = ($slug === '' ? 'field' : $prefix.$cut(40 - strlen($prefix) - strlen("_{$n}"))).'_'.$n;
        }

        return $key;
    }
}
