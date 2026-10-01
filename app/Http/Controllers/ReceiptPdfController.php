<?php

namespace App\Http\Controllers;

use App\Jobs\StoreReceipt;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The stored receipt, through the audited download route; built now if the queued job hasn't run yet. */
final class ReceiptPdfController
{
    public function __invoke(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()?->can('view', $payment) === true, 403);

        if (! StoreReceipt::stored($payment)) {
            StoreReceipt::dispatchSync($payment->id, (int) $request->user()->getKey());
        }

        return redirect()->route('documents.download', StoreReceipt::stored($payment));
    }
}
