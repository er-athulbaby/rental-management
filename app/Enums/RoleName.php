<?php

namespace App\Enums;

enum RoleName: string
{
    case Admin = 'admin';
    case Management = 'management';
    case Finance = 'finance';
    case PropertyManager = 'property-manager';
    case Leasing = 'leasing';
    case VendorSupport = 'vendor-support';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Management => 'Management',
            self::Finance => 'Finance',
            self::PropertyManager => 'Property manager',
            self::Leasing => 'Leasing',
            self::VendorSupport => 'Vendor support',
        };
    }
}
