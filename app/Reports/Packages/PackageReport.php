<?php

namespace App\Reports\Packages;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * A ready-made report a package can run headless (spec 2026-10-17 §2, §4). Implemented by the ReportPage and
 * StatementPage traits, so the sheet is exactly what the report's own Excel export writes.
 *
 * @phpstan-type Form array{title: string, dates: string, building: bool, options: array<string, array<array-key, string>>, allowed: bool}
 */
interface PackageReport
{
    /**
     * What a package item may set: dates 'range' | 'single' | 'none' | 'months', whether there is a building filter,
     * each option's choices (a '' choice = optional), and whether $as may run the report at all.
     *
     * @return Form
     */
    public function packageForm(User $as): array;

    /**
     * @param  array<array-key, mixed>  $settings  the report's own settings (dates already resolved)
     *
     * @throws ValidationException
     */
    public function checkSettings(User $as, array $settings): void;

    /**
     * @param  array<array-key, mixed>  $settings
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}
     *
     * @throws AuthorizationException when $as may not run the report
     * @throws ValidationException when the settings do not validate for $as
     */
    public function sheet(User $as, array $settings): array;
}
