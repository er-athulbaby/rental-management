<?php

namespace App\Actions\Buildings;

use App\Models\Facility;

/** A new install starts with common facilities; the Admin adds, renames or switches them off. Safe to run twice. */
final class EnsureDefaultFacilities
{
    public const array DEFAULTS = [
        'Lift', 'Swimming pool', 'Gym', '24-hour security', 'CCTV', 'Backup generator', 'Central air conditioning',
        "Children's play area", 'Reception', 'Maintenance on site',
    ];

    public function __invoke(): void
    {
        foreach (self::DEFAULTS as $name) {
            Facility::query()->firstOrCreate(['name' => $name]);
        }
    }
}
