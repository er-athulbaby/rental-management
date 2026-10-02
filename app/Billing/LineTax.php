<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\InvoiceLine;
use App\Models\PaymentAllocation;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;

/**
 * Plan ruling 1: a line's tax is shared between its credit notes and its allocations so that a settled line carries
 * exactly its tax whatever the order. Allocations aim at a target computed on what the credit notes left; each row
 * takes the difference from what allocations already carry, so rounding never accumulates.
 */
final class LineTax
{
    public static function creditedTax(InvoiceLine $line): int
    {
        return Fils::fromDecimal((string) (DB::table('invoice_lines as cl')
            ->join('invoices as cn', 'cn.id', '=', 'cl.invoice_id')
            ->where('cl.credited_line_id', $line->id)
            ->where('cn.type', InvoiceType::CreditNote->value)->where('cn.status', InvoiceStatus::Issued->value)
            ->sum('cl.tax_amount') ?: '0'));
    }

    public static function allocatedTax(InvoiceLine $line): int
    {
        return Fils::fromDecimal((string) (PaymentAllocation::query()->where('invoice_line_id', $line->id)->sum('tax_amount') ?: '0'));
    }

    /** The tax allocations totalling $allocated should carry on this line, given its credit notes so far. */
    public static function allocationTarget(InvoiceLine $line, int $allocated): int
    {
        $baseTotal = Fils::fromDecimal($line->total) - Fils::fromDecimal((string) $line->credited);
        $baseTax = Fils::fromDecimal($line->tax_amount) - self::creditedTax($line);

        if ($allocated <= 0 || $baseTotal <= 0 || $baseTax <= 0) {
            return 0;
        }

        return $allocated >= $baseTotal ? $baseTax : intdiv($allocated * $baseTax, $baseTotal);
    }

    /** Tax for one allocation row of $amount (negative for a reversal) that brings the line to $allocatedAfter. */
    public static function allocationShare(InvoiceLine $line, int $allocatedAfter, int $amount): int
    {
        $share = self::allocationTarget($line, $allocatedAfter) - self::allocatedTax($line);

        // The payment_allocations CHECK keeps a row's tax between 0 and its amount (same sign); a rare clamp is
        // absorbed by the next row on the line.
        return $amount > 0 ? max(0, min($amount, $share)) : max($amount, min(0, $share));
    }
}
