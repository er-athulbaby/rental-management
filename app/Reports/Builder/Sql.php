<?php

namespace App\Reports\Builder;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Expression;

/** SQL fragments several subjects share. Inputs are column expressions written by us, never user input. */
final class Sql
{
    /**
     * Wraps SQL built from Field definitions (ours, never user input) for the query builder; values stay bound.
     *
     * @return Expression<float|int|literal-string>
     */
    public static function raw(string $sql): Expression
    {
        // @phpstan-ignore argument.type
        return new Expression($sql);
    }

    /** A code-made date literal, safe to embed. */
    public static function date(CarbonImmutable $day): string
    {
        return "'".$day->format('Y-m-d')."'";
    }

    /** The building code of an invoice's units, "Multiple" when they span buildings (spec §4.3). */
    public static function buildingOfInvoice(string $invoiceId): string
    {
        return "(SELECT CASE WHEN COUNT(DISTINCT bu.building_id) > 1 THEN 'Multiple' ELSE MAX(bb.code) END
            FROM invoice_lines bl JOIN units bu ON bu.id = bl.unit_id JOIN buildings bb ON bb.id = bu.building_id
            WHERE bl.invoice_id = {$invoiceId})";
    }

    public static function unitsOfInvoice(string $invoiceId): string
    {
        return "(SELECT GROUP_CONCAT(DISTINCT CONCAT(ub.code, ' / ', uu.code) ORDER BY ub.code, uu.code SEPARATOR ', ')
            FROM invoice_lines ul JOIN units uu ON uu.id = ul.unit_id JOIN buildings ub ON ub.id = uu.building_id
            WHERE ul.invoice_id = {$invoiceId})";
    }

    /** The building code of an agreement's units, "Multiple" when they span buildings. */
    public static function buildingOfAgreement(string $agreementId): string
    {
        return "(SELECT CASE WHEN COUNT(DISTINCT gu.building_id) > 1 THEN 'Multiple' ELSE MAX(gb.code) END
            FROM agreement_units ga JOIN units gu ON gu.id = ga.unit_id JOIN buildings gb ON gb.id = gu.building_id
            WHERE ga.agreement_id = {$agreementId})";
    }

    public static function unitsOfAgreement(string $agreementId): string
    {
        return "(SELECT GROUP_CONCAT(DISTINCT CONCAT(vb.code, ' / ', vu.code) ORDER BY vb.code, vu.code SEPARATOR ', ')
            FROM agreement_units va JOIN units vu ON vu.id = va.unit_id JOIN buildings vb ON vb.id = vu.building_id
            WHERE va.agreement_id = {$agreementId})";
    }
}
