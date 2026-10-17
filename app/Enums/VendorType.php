<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.1. Own staff are listed as vendors, so they need no app login. */
enum VendorType: string
{
    case Company = 'company';
    case Individual = 'individual';
    case OwnStaff = 'own_staff';

    public function label(): string
    {
        return match ($this) {
            self::Company => __('Company'),
            self::Individual => __('Individual'),
            self::OwnStaff => __('Own staff'),
        };
    }
}
