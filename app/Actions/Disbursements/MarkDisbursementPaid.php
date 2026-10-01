<?php

namespace App\Actions\Disbursements;

use App\Actions\NextDocumentNumber;
use App\Enums\DisbursementStatus;
use App\Enums\NumberSequenceKey;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §7.5: the PO number is assigned when the payment out becomes paid. Internal: the caller locked the row. */
final class MarkDisbursementPaid
{
    public function __construct(private NextDocumentNumber $next) {}

    /** @param  array<string, mixed>  $data  method, paid_on, reference? */
    public function handle(Disbursement $locked, array $data, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('MarkDisbursementPaid must run inside the caller\'s transaction.');
        }

        // Task 2 adds: the issued cheque when method = cheque. Task 6 adds: refunded deposit movements.

        $locked->forceFill([
            'status' => DisbursementStatus::Paid,
            'number' => ($this->next)(NumberSequenceKey::PaymentOut),
            'method' => $data['method'],
            'reference' => $data['reference'] ?? $locked->reference,
            'paid_on' => $data['paid_on'],
            'posted_at' => now(),
            'recorded_by' => $actor->id,
        ])->save();
    }
}
