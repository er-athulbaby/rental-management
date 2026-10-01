<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PermissionName;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Fils;

/** Spec §8.1: Admin and Management view; Finance manages. */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::FinanceView) && Invoice::visibleTo($user)->whereKey($invoice->getKey())->exists();
    }

    /** Manual invoices and credit notes (spec §6.5). */
    public function create(User $user): bool
    {
        return $user->can(PermissionName::InvoicesManage);
    }

    /** Draft manual invoices and draft credit notes are edited or cancelled. */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesManage) && $invoice->status === InvoiceStatus::Draft
            && in_array($invoice->type, [InvoiceType::Manual, InvoiceType::CreditNote], true) && $this->view($user, $invoice);
    }

    /** "Issue now" for scheduled invoices (spec §6.3); "Issue" for draft manual invoices (spec §6.5). */
    public function issue(User $user, Invoice $invoice): bool
    {
        $issuable = $invoice->status === InvoiceStatus::Scheduled
            || ($invoice->status === InvoiceStatus::Draft && $invoice->type === InvoiceType::Manual);

        return $user->can(PermissionName::InvoicesManage) && $issuable && $this->view($user, $invoice);
    }

    /** A credit note against an issued invoice with something left to credit (spec §6.5). */
    public function credit(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesManage) && $invoice->status === InvoiceStatus::Issued
            && $invoice->type !== InvoiceType::CreditNote
            && Fils::fromDecimal((string) $invoice->credited) < Fils::fromDecimal($invoice->total) // decimal casts are strings
            && $this->view($user, $invoice);
    }
}
