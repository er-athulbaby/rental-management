<?php

namespace App\Enums;

enum PayeeType: string
{
    case Owner = 'owner';
    case Customer = 'customer';
}
