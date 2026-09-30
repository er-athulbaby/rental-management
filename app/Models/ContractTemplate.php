<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_default
 * @property bool $active
 */
class ContractTemplate extends Model
{
    use LogsActivity;

    /** Spec §5.6. `units_table` must be a clause's whole body. `agreement_number` is filled in when the PDF renders. */
    public const array MERGE_FIELDS = [
        'company_name', 'customer_name', 'customer_id_number', 'agreement_number', 'start_date', 'end_date', 'frequency',
        'total_monthly_rent', 'total_deposit', 'notice_period_days', 'grace_days', 'units_table',
    ];

    /** Characters per paragraph: mPDF never splits a table row, so a paragraph must fit on a page in a half-width column (spec §9.2). */
    public const int MAX_PARAGRAPH = 1200;

    protected $fillable = ['name', 'is_default', 'active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'is_default', 'active'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return HasMany<ContractTemplateClause, $this> */
    public function clauses(): HasMany
    {
        return $this->hasMany(ContractTemplateClause::class)->orderBy('position');
    }

    public static function defaultTemplate(): ?self
    {
        return self::query()->where('is_default', true)->where('active', true)->first();
    }

    /** @return list<string> paragraphs separated by a blank line; one PDF row each */
    public static function paragraphs(string $body): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R\s*\R/u', $body) ?: []), fn (string $p) => $p !== ''));
    }
}
