<?php

namespace App\Enums;

use App\Approvals\ApprovalHandler;
use App\Approvals\OwnerContractActivation;

/** Spec §8.3. Each later approval adds a case here and a handler in app/Approvals. */
enum ApprovalAction: string
{
    case OwnerContractActivation = 'owner_contract.activate';

    public function label(): string
    {
        return match ($this) {
            self::OwnerContractActivation => __('Owner contract activation'),
        };
    }

    /** @return class-string<ApprovalHandler> */
    public function handler(): string
    {
        return match ($this) {
            self::OwnerContractActivation => OwnerContractActivation::class,
        };
    }
}
