<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $position
 * @property string $heading_en
 * @property string $heading_ar
 * @property string $body_en
 * @property string $body_ar
 */
class ContractTemplateClause extends Model
{
    protected $fillable = ['position', 'heading_en', 'heading_ar', 'body_en', 'body_ar'];

    /** @return BelongsTo<ContractTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }
}
