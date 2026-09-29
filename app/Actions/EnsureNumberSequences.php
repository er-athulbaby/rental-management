<?php

namespace App\Actions;

use App\Enums\NumberSequenceKey;
use Illuminate\Support\Facades\DB;

final class EnsureNumberSequences
{
    /** Creates any missing (key, year) rows; returns how many were created. */
    public function __invoke(int $year): int
    {
        $now = now();

        return DB::table('number_sequences')->insertOrIgnore(array_map(fn (NumberSequenceKey $key) => [
            'key' => $key->value,
            'year' => $year,
            'prefix' => $key->prefix(),
            'next_value' => 1,
            'padding' => 6,
            'created_at' => $now,
            'updated_at' => $now,
        ], NumberSequenceKey::cases()));
    }
}
