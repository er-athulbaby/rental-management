<?php

namespace App\Approvals;

use App\Actions\Billing\IssueCreditNote;
use App\Enums\InvoiceStatus;
use App\Models\Approval;
use App\Models\Invoice;
use App\Models\User;

/** Spec §8.3 item 5. */
final class CreditNote implements ApprovalHandler
{
    public function __construct(private IssueCreditNote $issue) {}

    public function creatorId(Approval $approval): ?int
    {
        return Invoice::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->issue->handle(Invoice::query()->findOrFail($approval->approvable_id), $approver);
    }

    /** Back to draft with the comment on the approval (spec §8.3). */
    public function reject(Approval $approval, User $approver): void
    {
        $cn = Invoice::query()->lockForUpdate()->findOrFail($approval->approvable_id);
        $cn->forceFill(['status' => InvoiceStatus::Draft])->save();
    }

    public function summary(Approval $approval): string
    {
        $cn = Invoice::with(['customer', 'relatedInvoice'])->findOrFail($approval->approvable_id);

        return __('Credit note of :total BHD (tax :tax) on :invoice for :customer. Reason: :reason', [
            'total' => $cn->total, 'tax' => $cn->tax_total, 'invoice' => $cn->relatedInvoice?->label(),
            'customer' => $cn->customer?->name_en, 'reason' => $cn->credit_reason,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('invoices.show', $approval->approvable_id);
    }
}
