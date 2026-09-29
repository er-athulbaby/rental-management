<?php

namespace App\Enums;

enum OwnerContractType: string
{
    case Leased = 'leased';   // the company rents the property from the owner
    case Managed = 'managed'; // the company manages it for the owner
}
