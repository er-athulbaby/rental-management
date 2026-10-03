<?php

namespace App\Enums;

enum ParkingType: string
{
    case None = 'none';
    case Open = 'open';
    case Covered = 'covered';
    case Basement = 'basement';
    case Street = 'street';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No parking'),
            self::Open => __('Open parking'),
            self::Covered => __('Covered parking'),
            self::Basement => __('Basement parking'),
            self::Street => __('Street parking'),
        };
    }
}
