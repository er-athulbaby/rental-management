<?php

namespace App\Enums;

enum NumberSequenceKey: string
{
    case Agreement = 'agreement';
    case OwnerContract = 'owner_contract';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Receipt = 'receipt';
    case PaymentOut = 'payment_out';
    case OwnerStatement = 'owner_statement';
    case DepositSettlement = 'deposit_settlement';
    case MaintenanceRequest = 'maintenance_request';
    case WorkOrder = 'work_order';

    public function prefix(): string
    {
        return match ($this) {
            self::Agreement => 'AGR',
            self::OwnerContract => 'OC',
            self::Invoice => 'INV',
            self::CreditNote => 'CN',
            self::Receipt => 'RCP',
            self::PaymentOut => 'PO',
            self::OwnerStatement => 'OS',
            self::DepositSettlement => 'DS',
            self::MaintenanceRequest => 'MR',
            self::WorkOrder => 'WO',
        };
    }
}
