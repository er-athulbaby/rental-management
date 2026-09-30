<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The contract text frozen at submit (spec §5.6). Written only by SubmitAgreement and the rejection handler.
 *
 * @property int $position
 * @property string $heading_en
 * @property string $heading_ar
 * @property string $body_en
 * @property string $body_ar
 */
class AgreementClause extends Model
{
    protected $fillable = ['position', 'heading_en', 'heading_ar', 'body_en', 'body_ar'];
}
