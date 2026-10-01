<?php

namespace App\Billing;

use App\Models\Agreement;
use App\Models\Invoice;
use LogicException;

/**
 * The billing period an invoice was raised for, with its regular flag (spec §6.2). Recomputed from the agreement's start
 * up to the invoice's own period end, so a later change of the agreement's end_date never alters a past period.
 */
final class InvoicePeriod
{
    public static function of(Agreement $agreement, Invoice $invoice): BillingPeriod
    {
        if ($invoice->period_start === null || $invoice->period_end === null) {
            throw new LogicException("Invoice {$invoice->id} has no billing period.");
        }

        foreach (BillingPeriods::for($agreement->start_date, $invoice->period_end, $agreement->frequency, $agreement->billing_day) as $period) {
            if ($period->start->equalTo($invoice->period_start->startOfDay())) {
                return $period;
            }
        }

        throw new LogicException("Invoice {$invoice->id} does not match a billing period of agreement {$agreement->id}.");
    }
}
