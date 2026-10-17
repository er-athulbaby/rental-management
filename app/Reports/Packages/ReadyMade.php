<?php

namespace App\Reports\Packages;

use App\Livewire\Reports\AgeingReport;
use App\Livewire\Reports\ChequesReport;
use App\Livewire\Reports\CollectionsReport;
use App\Livewire\Reports\DepositsHeldReport;
use App\Livewire\Reports\ExpiringAgreementsReport;
use App\Livewire\Reports\IdDocumentsReport;
use App\Livewire\Reports\IncomeStatementReport;
use App\Livewire\Reports\OccupancyReport;
use App\Livewire\Reports\OutstandingReport;
use App\Livewire\Reports\OverstaysReport;
use App\Livewire\Reports\OwnerIncomeStatementReport;
use App\Livewire\Reports\PendingApprovalsReport;
use App\Livewire\Reports\RentRollReport;
use App\Livewire\Reports\VatSummaryReport;
use InvalidArgumentException;

/** Spec 2026-10-17 §3: the 14 reports on ReportPage / StatementPage, keyed as their exports audit them, in Reports page order. */
final class ReadyMade
{
    /** @var array<string, class-string<PackageReport>> */
    public const array REPORTS = [
        'rent_roll' => RentRollReport::class,
        'occupancy' => OccupancyReport::class,
        'expiring_agreements' => ExpiringAgreementsReport::class,
        'overstays' => OverstaysReport::class,
        'pending_approvals' => PendingApprovalsReport::class,
        'id_documents' => IdDocumentsReport::class,
        'cheques' => ChequesReport::class,
        'income_statement' => IncomeStatementReport::class,
        'owner_income_statement' => OwnerIncomeStatementReport::class,
        'outstanding' => OutstandingReport::class,
        'ageing' => AgeingReport::class,
        'collections' => CollectionsReport::class,
        'deposits_held' => DepositsHeldReport::class,
        'vat_summary' => VatSummaryReport::class,
    ];

    /** A fresh, unmounted instance (plan ruling 1): its sheet() sets everything it needs. */
    public static function make(string $key): PackageReport
    {
        $class = self::REPORTS[$key] ?? throw new InvalidArgumentException("Unknown ready-made report [{$key}].");

        return new $class;
    }
}
