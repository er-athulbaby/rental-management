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
    case Portal = 'portal'; // spec 2026-10-18 tenant portal §4: only LogMaintenanceRequest::forPortal() writes it

    public function label(): string
    {
        return match ($this) {
            self::Phone => __('Phone'),
            self::Whatsapp => __('WhatsApp'),
            self::WalkIn => __('Walk-in'),
            self::Inspection => __('Inspection'),
            self::Email => __('Email'),
            self::Portal => __('Tenant portal'),
        };
    }

    /** @return list<self> the channels staff choose from; "portal" is set only by the portal (plan ruling 14) */
    public static function staffCases(): array
    {
        return [...array_filter(self::cases(), fn (self $c) => $c !== self::Portal)]; // the spread renumbers the keys
    }
}
