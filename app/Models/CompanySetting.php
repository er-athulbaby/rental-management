<?php

namespace App\Models;

use App\Enums\ProrationBasis;
use App\Enums\TaxCategory;
use Database\Factories\CompanySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Once;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The single settings row of this install (spec §3).
 *
 * @property string $name_en
 * @property string|null $name_ar
 * @property string|null $logo_path
 * @property bool $vat_registered
 * @property string|null $trn
 * @property string $vat_rate
 * @property TaxCategory $residential_tax_category
 * @property TaxCategory $commercial_tax_category
 * @property string $currency_code
 * @property string $date_format
 * @property int $default_grace_days
 * @property int $invoice_lead_days
 * @property ProrationBasis $proration_basis
 * @property bool $require_different_approver
 * @property Carbon|null $go_live_at
 */
class CompanySetting extends Model
{
    /** @use HasFactory<CompanySettingFactory> */
    use HasFactory, LogsActivity;

    /** Screen-editable fields. require_different_approver and go_live_at are set only by rms:setting. */
    public const array EDITABLE = [
        'name_en', 'name_ar', 'cr_number', 'address_en', 'address_ar', 'phone', 'email', 'website',
        'vat_registered', 'trn', 'vat_rate', 'residential_tax_category', 'commercial_tax_category',
        'currency_code', 'date_format', 'default_grace_days', 'invoice_lead_days', 'proration_basis',
    ];

    protected $guarded = ['id'];

    /** The single row always has id 1 (CHECK company_settings_single_row). */
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'vat_registered' => 'boolean',
            'vat_rate' => 'decimal:2',
            'residential_tax_category' => TaxCategory::class,
            'commercial_tax_category' => TaxCategory::class,
            'default_grace_days' => 'integer',
            'invoice_lead_days' => 'integer',
            'proration_basis' => ProrationBasis::class,
            'require_different_approver' => 'boolean',
            'go_live_at' => 'datetime',
        ];
    }

    /** Memoised per request; the memo is cleared whenever the row is saved (booted() below). */
    public static function current(): self
    {
        return once(fn () => static::query()->findOrFail(1));
    }

    protected static function booted(): void
    {
        static::saved(fn () => Once::flush());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
