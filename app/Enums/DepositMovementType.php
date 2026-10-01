<?php

namespace App\Enums;

enum DepositMovementType: string
{
    case Received = 'received';
    case Applied = 'applied';
    case Refunded = 'refunded';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Opening = 'opening';
}
