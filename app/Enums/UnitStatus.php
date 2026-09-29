<?php

namespace App\Enums;

/** Computed, never stored (spec §4.3). */
enum UnitStatus: string
{
    case Occupied = 'occupied';
    case NoticeGiven = 'notice_given';
    case Reserved = 'reserved';
    case Blocked = 'blocked';
    case Available = 'available';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
