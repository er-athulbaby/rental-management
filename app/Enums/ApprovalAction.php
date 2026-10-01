<?php

namespace App\Enums;

use App\Approvals\AgreementActivation;
use App\Approvals\ApprovalHandler;
use App\Approvals\CreditNote as CreditNoteHandler;
use App\Approvals\OwnerContractActivation;
use App\Approvals\OwnerContractTermination;
use App\Approvals\PaymentOut as PaymentOutHandler;
use App\Approvals\PaymentReversal;

/** Spec §8.3. Each later approval adds a case here and a handler in app/Approvals. */
enum ApprovalAction: string
{
    case OwnerContractActivation = 'owner_contract.activate';
    case OwnerContractTermination = 'owner_contract.terminate';
    case AgreementActivation = 'agreement.activate';
    case PaymentReversal = 'payment.reverse';
    case CreditNote = 'credit_note.issue';
    case PaymentOut = 'payment_out.approve';

    public function label(): string
    {
        return match ($this) {
            self::OwnerContractActivation => __('Owner contract activation'),
            self::OwnerContractTermination => __('Owner contract early termination'),
            self::AgreementActivation => __('Agreement activation'),
            self::PaymentReversal => __('Payment reversal'),
            self::CreditNote => __('Credit note'),
            self::PaymentOut => __('Payment out'),
        };
    }

    /** @return class-string<ApprovalHandler> */
    public function handler(): string
    {
        return match ($this) {
            self::OwnerContractActivation => OwnerContractActivation::class,
            self::OwnerContractTermination => OwnerContractTermination::class,
            self::AgreementActivation => AgreementActivation::class,
            self::PaymentReversal => PaymentReversal::class,
            self::CreditNote => CreditNoteHandler::class,
            self::PaymentOut => PaymentOutHandler::class,
        };
    }
}
