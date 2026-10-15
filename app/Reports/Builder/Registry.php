<?php

namespace App\Reports\Builder;

use App\Models\User;
use InvalidArgumentException;

/** The 12 subjects (spec §4.2), in picker order. */
final class Registry
{
    /** @var array<string, Subject>|null */
    private static ?array $subjects = null;

    /** @return array<string, Subject> */
    public static function all(): array
    {
        if (self::$subjects === null) {
            self::$subjects = [];
            foreach ([
                Subjects\Invoices::class, Subjects\Payments::class, Subjects\Cheques::class,
                Subjects\Agreements::class, Subjects\Units::class, Subjects\Tenants::class,
                Subjects\Owners::class, Subjects\OwnerContracts::class, Subjects\OwnerStatements::class,
                Subjects\Expenses::class, Subjects\PaymentsOut::class, Subjects\DepositsHeld::class,
            ] as $class) {
                $subject = new $class;
                self::$subjects[$subject->key()] = $subject;
            }
        }

        return self::$subjects;
    }

    public static function get(string $key): Subject
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown report subject [{$key}].");
    }

    /** @return array<string, Subject> */
    public static function forUser(User $user): array
    {
        return array_filter(self::all(), fn (Subject $s) => $user->can($s->permission()));
    }
}
