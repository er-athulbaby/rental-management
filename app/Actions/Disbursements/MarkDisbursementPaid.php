<?php

namespace App\Actions\Disbursements;

use App\Actions\NextDocumentNumber;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\DepositMovementType;
use App\Enums\DepositSettlementStatus;
use App\Enums\DisbursementMethod;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\OwnerPayableStatus;
use App\Enums\PayeeType;
use App\Models\Cheque;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\Disbursement;
use App\Models\OwnerPayable;
use App\Models\User;
use App\Support\Fils;
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

        // Spec §7.7: a deposit refund writes refunded movements, unit by unit up to each unit's refund, and completes the
        // settlement once fully refunded.
        if ($locked->purpose === DisbursementPurpose::DepositRefund) {
            $settlement = DepositSettlement::query()->lockForUpdate()->with('units')->findOrFail($locked->source_id);
            $left = Fils::fromDecimal($locked->amount);
            foreach ($settlement->units as $unit) {
                $refundedHere = -Fils::fromDecimal((string) (DepositMovement::query()->where('agreement_unit_id', $unit->agreement_unit_id)
                    ->where('type', DepositMovementType::Refunded)->sum('amount') ?: '0'));
                $take = min($left, Fils::fromDecimal((string) $unit->refund_amount) - $refundedHere);
                if ($take > 0) {
                    DepositMovement::create([
                        'agreement_unit_id' => $unit->agreement_unit_id,
                        'owner_contract_id' => DepositMovement::ownerContractFor($unit->agreement_unit_id),
                        'type' => DepositMovementType::Refunded,
                        'amount' => Fils::toDecimal(-$take),
                        'source_type' => 'disbursement',
                        'source_id' => $locked->id,
                        'posted_at' => now(),
                    ]);
                    $left -= $take;
                }
            }
            if ($left > 0) {
                throw new LogicException("Payment out {$locked->id} refunds more than its settlement's units have left to refund.");
            }
            if ($settlement->refundedFils() >= $settlement->refundFils()) {
                $settlement->forceFill(['status' => DepositSettlementStatus::Completed])->save();
            }
        }

        // Spec §7.5, plan ruling 4: the payable becomes paid when a payment out for exactly its amount is paid. The
        // caller locked the payable before this disbursement (LockOwnerSource / RecordDisbursement).
        if ($locked->purpose === DisbursementPurpose::HeadLease) {
            $payable = OwnerPayable::query()->lockForUpdate()->findOrFail($locked->source_id);
            if ($payable->status === OwnerPayableStatus::Scheduled && Fils::fromDecimal($payable->amount) === Fils::fromDecimal($locked->amount)) {
                $payable->forceFill(['status' => OwnerPayableStatus::Paid, 'disbursement_id' => $locked->id])->save();
            }
        }
    }
}
