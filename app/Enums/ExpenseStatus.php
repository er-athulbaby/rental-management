<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Recorded = 'recorded';
    case Reversed = 'reversed';
}
