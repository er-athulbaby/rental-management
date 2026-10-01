<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Spec §6.1: a draft is cancelled, never deleted (invoices have no DELETE). */
final class CancelDraftInvoice
{
    public function handle(User $actor, Invoice $invoice): void
    {
        DB::transaction(function () use ($actor, $invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $actor->can('update', $invoice)) {
                throw new AuthorizationException;
            }

            $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();
        }, attempts: 3);
    }
}
