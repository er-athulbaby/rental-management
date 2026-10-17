<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report in a package (spec 2026-10-17 §3, §7): a saved report, or a ready-made report key with its settings
 * (building, period preset, the report's own options). Settings are validated again on every run.
 *
 * @property int $id
 * @property int $report_package_id
 * @property int $position
 * @property string $kind saved | ready_made
 * @property int|null $saved_report_id
 * @property string|null $report
 * @property array<string, mixed> $settings
 */
class ReportPackageItem extends Model
{
    protected $fillable = ['report_package_id', 'position', 'kind', 'saved_report_id', 'report', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'position' => 'integer'];
    }

    /** @return BelongsTo<ReportPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ReportPackage::class, 'report_package_id');
    }
}
