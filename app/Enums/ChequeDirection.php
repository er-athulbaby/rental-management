<?php

namespace App\Enums;

enum ChequeDirection: string
{
    case Received = 'received';
    case Issued = 'issued'; // M3b: cheques the company writes (spec §7.4)
}
