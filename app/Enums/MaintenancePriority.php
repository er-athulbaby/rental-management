<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.2. Urgent requests are listed first and shown on the dashboard. */
enum MaintenancePriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Urgent => __('Urgent'),
            self::High => __('High'),
            self::Normal => __('Normal'),
            self::Low => __('Low'),
        };
    }

    /** A Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Urgent => 'red',
            self::High => 'amber',
            default => 'zinc',
        };
    }
}
