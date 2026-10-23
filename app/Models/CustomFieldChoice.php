<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec 2026-10-21 custom fields §3: one choice of a choice list. A used choice is hidden, never deleted (plan ruling 11).
 *
 * @property int $id
 * @property int $custom_field_id
 * @property string $label_en
 * @property string|null $label_ar
 * @property int $position
 * @property CarbonImmutable|null $hidden_at
 */
class CustomFieldChoice extends Model
{
    protected $fillable = ['label_en', 'label_ar', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'hidden_at' => 'immutable_datetime'];
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' && filled($this->label_ar) ? (string) $this->label_ar : $this->label_en;
    }
}
