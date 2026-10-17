<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.2: how the problem was reported. */
enum MaintenanceChannel: string
{
    case Phone = 'phone';
    case Whatsapp = 'whatsapp';
    case WalkIn = 'walk_in';
    case Inspection = 'inspection';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Phone => __('Phone'),
            self::Whatsapp => __('WhatsApp'),
            self::WalkIn => __('Walk-in'),
            self::Inspection => __('Inspection'),
            self::Email => __('Email'),
        };
    }
}
