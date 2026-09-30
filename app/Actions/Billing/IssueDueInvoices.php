<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Agreement;
use App\Models\Invoice;

/** The 01:00 job and activation (spec §6.3, §12). Idempotent; each invoice is its own transaction. Held-back invoices wait. */
final class IssueDueInvoices
{
    public function __construct(private IssueInvoice $issue) {}

    public function __invoke(?Agreement $only = null): int
    {
        $issued = 0;

        Invoice::query()
            ->where('status', InvoiceStatus::Scheduled)
            ->where('issue_date', '<=', now('Asia/Bahrain')->toDateString())
            ->when($only, fn ($q, Agreement $a) => $q->where('agreement_id', $a->id))
            ->orderBy('issue_date')->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$issued) {
                if ($this->issue->handle(Invoice::findOrFail($id))) {
                    $issued++;
                }
            });

        // ponytail: M3 auto-allocates customer credit here after issuing (spec §12, 01:00).
        return $issued;
    }
}
