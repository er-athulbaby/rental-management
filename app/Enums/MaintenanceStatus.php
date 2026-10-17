<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.2. Resolved and cancelled are final (plan ruling 4). */
enum MaintenanceStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::InProgress => __('In progress'),
            self::Resolved => __('Resolved'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Resolved || $this === self::Cancelled;
    }
}
