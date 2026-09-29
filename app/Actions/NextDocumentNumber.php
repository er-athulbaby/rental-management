<?php

namespace App\Actions;

use App\Enums\NumberSequenceKey;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/** Gapless document numbers (spec §6.8): the caller's transaction holds the row lock until it commits. */
final class NextDocumentNumber
{
    public function __invoke(NumberSequenceKey $key): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('NextDocumentNumber must run inside a DB::transaction().');
        }

        $year = now('Asia/Bahrain')->year;

        // Rows are pre-created (rms:install / 1 December job): a locking read of a missing row takes gap locks and deadlocks.
        $sequence = DB::table('number_sequences')
            ->where('key', $key->value)
            ->where('year', $year)
            ->lockForUpdate()
            ->first()
            ?? throw new RuntimeException("No number_sequences row for [{$key->value}, {$year}].");

        DB::table('number_sequences')->where('id', $sequence->id)->increment('next_value');

        return sprintf('%s-%d-%s', $sequence->prefix, $year, str_pad((string) $sequence->next_value, $sequence->padding, '0', STR_PAD_LEFT));
    }
}
