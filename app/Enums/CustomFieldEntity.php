<?php

namespace App\Enums;

use App\Models\Agreement;
use App\Models\Building;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Spec 2026-10-21 custom fields §2: the five record kinds that take custom fields. "customer" is shown as Tenants. */
enum CustomFieldEntity: string
{
    case Customer = 'customer';
    case Unit = 'unit';
    case Agreement = 'agreement';
    case Owner = 'owner';
    case Building = 'building';

    /** The Administration tab name. */
    public function label(): string
    {
        return match ($this) {
            self::Customer => __('Tenants'),
            self::Unit => __('Units'),
            self::Agreement => __('Agreements'),
            self::Owner => __('Owners'),
            self::Building => __('Buildings'),
        };
    }

    public static function of(Model $record): self
    {
        return match (true) {
            $record instanceof Customer => self::Customer,
            $record instanceof Unit => self::Unit,
            $record instanceof Agreement => self::Agreement,
            $record instanceof Owner => self::Owner,
            $record instanceof Building => self::Building,
            default => throw new InvalidArgumentException($record::class.' takes no custom fields.'),
        };
    }
}
