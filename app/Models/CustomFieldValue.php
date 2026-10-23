<?php

namespace App\Models;

use App\Enums\CustomFieldEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec 2026-10-21 custom fields §6: one record's value of one field, in the column of the field's type (plan ruling 1).
 * Written only by App\CustomFields\CustomFields::save(); audited there.
 *
 * @property int $id
 * @property int $custom_field_id
 * @property CustomFieldEntity $entity
 * @property int $entity_id
 * @property string|null $value_text
 * @property string|null $value_number decimal:3, never a float
 * @property CarbonImmutable|null $value_date
 * @property bool|null $value_bool
 * @property int|null $choice_id
 * @property int|null $document_id
 * @property int|null $updated_by
 * @property-read CustomField $field
 * @property-read CustomFieldChoice|null $choice
 * @property-read Document|null $document null once the document is soft-deleted
 */
class CustomFieldValue extends Model
{
    protected function casts(): array
    {
        return ['entity' => CustomFieldEntity::class, 'entity_id' => 'integer', 'value_number' => 'decimal:3', 'value_date' => 'immutable_date',
            'value_bool' => 'boolean', 'choice_id' => 'integer', 'document_id' => 'integer'];
    }

    /** @return BelongsTo<CustomField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /** @return BelongsTo<CustomFieldChoice, $this> */
    public function choice(): BelongsTo
    {
        return $this->belongsTo(CustomFieldChoice::class, 'choice_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
