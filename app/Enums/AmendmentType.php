<?php

namespace App\Enums;

enum AmendmentType: string
{
    case AddUnit = 'add_unit';
    case ReleaseUnit = 'release_unit';
    case Terminate = 'terminate';

    public function label(): string
    {
        return match ($this) {
            self::AddUnit => __('Add a unit'),
            self::ReleaseUnit => __('Release a unit'),
            self::Terminate => __('Early termination'),
        };
    }

    /** Spec §8.3: items 2 (add or release a unit) and 3 (early termination). */
    public function approvalAction(): ApprovalAction
    {
        return $this === self::Terminate ? ApprovalAction::AgreementTermination : ApprovalAction::AgreementAmendment;
    }
}
