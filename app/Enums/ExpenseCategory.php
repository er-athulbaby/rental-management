<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Maintenance = 'maintenance';
    case Utilities = 'utilities';
    case Cleaning = 'cleaning';
    case Security = 'security';
    case Insurance = 'insurance';
    case Government = 'government'; // municipality and other official fees
    case Legal = 'legal';
    case Commission = 'commission';
    case Other = 'other';
}
