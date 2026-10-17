<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.3. */
enum WorkOrderStatus: string
{
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => __('Assigned'),
            self::InProgress => __('In progress'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** Still to be done: it can start, be completed or be cancelled. */
    public function isOpen(): bool
    {
        return $this === self::Assigned || $this === self::InProgress;
    }
}
