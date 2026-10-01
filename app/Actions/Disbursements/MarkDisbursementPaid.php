<?php

namespace App\Actions\Disbursements;

use App\Actions\NextDocumentNumber;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\DisbursementMethod;
use App\Enums\DisbursementStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\PayeeType;
use App\Models\Cheque;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §7.5: the PO number is assigned when the payment out becomes paid. Internal: the caller locked the row. */
final class MarkDisbursementPaid
{
    public function __construct(private NextDocumentNumber $next) {}

    /** @param  array<string, mixed>  $data  method, paid_on, reference?, and cheque_no, bank_name, cheque_date when method = cheque */
    public function handle(Disbursement $locked, array $data, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('MarkDisbursementPaid must run inside the caller\'s transaction.');
        }

        $chequeId = null;
        if ($data['method'] === DisbursementMethod::Cheque->value) {
            $cheque = (new Cheque)->forceFill([
                'direction' => ChequeDirection::Issued,
                'customer_id' => $locked->payee_type === PayeeType::Customer ? $locked->payee_id : null,
                'owner_id' => $locked->payee_type === PayeeType::Owner ? $locked->payee_id : null,
                'cheque_no' => (string) $data['cheque_no'],
                'bank_name' => (string) $data['bank_name'],
                'cheque_date' => (string) $data['cheque_date'],
                'amount' => $locked->amount,
                'status' => ChequeStatus::Issued,
                'disbursement_id' => $locked->id,
                'created_by' => $actor->id,
            ]);
            $cheque->save();
            $chequeId = $cheque->id;
        }

        // Task 6 adds: refunded deposit movements.

        $locked->forceFill([
            'status' => DisbursementStatus::Paid,
            'number' => ($this->next)(NumberSequenceKey::PaymentOut),
            'method' => $data['method'],
            'cheque_id' => $chequeId,
            'reference' => $data['reference'] ?? $locked->reference,
            'paid_on' => $data['paid_on'],
            'posted_at' => now(),
            'recorded_by' => $actor->id,
        ])->save();
    }
}
