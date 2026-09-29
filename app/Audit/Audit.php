<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/** Explicit audit entries for changes model events cannot see (spec §8.4). */
final class Audit
{
    /**
     * @param  array<string, mixed>  $old  e.g. ['roles' => ['leasing']]
     * @param  array<string, mixed>  $new  e.g. ['roles' => ['finance', 'leasing']]
     * @param  array<string, mixed>  $properties  extra context — never secrets
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $properties = [],
        ?Model $causer = null,
    ): ?Activity {
        $changes = array_filter(['attributes' => $new, 'old' => $old]);

        return activity('audit')
            ->event($event)
            ->when($subject, fn ($log) => $log->performedOn($subject))
            ->when($causer, fn ($log) => $log->causedBy($causer))
            ->withChanges($changes)
            ->withProperties($properties)
            ->log($event);
    }
}
