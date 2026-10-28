<?php

namespace App\Enums;

enum PayeeType: string
{
    case Owner = 'owner';
    case Customer = 'customer';
    case Vendor = 'vendor'; // ledger money screens plan ruling 1: only with purpose vendor_bill; payee_id is a vendors.id
}
