<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Agreement;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/** The 01:00 job and activation (spec §6.3, §12). Idempotent; each invoice is its own transaction. Held-back invoices wait. */
final class IssueDueInvoices
{
    public function __construct(private IssueInvoice $issue) {}

    public function __invoke(?Agreement $only = null, ?User $issuer = null): int
    {
        $issued = 0;

        Invoice::query()
            ->where('status', InvoiceStatus::Scheduled)
            ->where('issue_date', '<=', now('Asia/Bahrain')->toDateString())
            ->when($only, fn ($q, Agreement $a) => $q->where('agreement_id', $a->id))
            ->orderBy('issue_date')->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$issued, $issuer, $only) {
                try {
                    if ($this->issue->handle(Invoice::findOrFail($id), $issuer)) {
                        $issued++;
                    }
                } catch (ValidationException) {
                    // Issued or cancelled since the list was read: skip it, keep the run going.
                } catch (Throwable $e) {
                    // Activation runs inside the approval's transaction: a failure there must fail the approval
                    // (a deadlock has already rolled the whole transaction back).
                    if ($only !== null) {
                        throw $e;
                    }
                    report($e); // the 01:00 run: one bad invoice must not hold back the rest
                }
            });

        // ponytail: M3 auto-allocates customer credit here after issuing (spec §12, 01:00).
        return $issued;
    }
}
