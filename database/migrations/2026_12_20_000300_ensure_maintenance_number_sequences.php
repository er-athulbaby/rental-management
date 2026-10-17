<?php

use App\Enums\NumberSequenceKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The MR and WO number rows for this year and next, so an installed system can log a request right after deploying
 * without running rms:number-sequences. Same row shape as EnsureNumberSequences; existing rows are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $year = (int) now('Asia/Bahrain')->format('Y');

        foreach ([$year, $year + 1] as $y) {
            foreach ([NumberSequenceKey::MaintenanceRequest, NumberSequenceKey::WorkOrder] as $key) {
                DB::table('number_sequences')->insertOrIgnore([
                    'key' => $key->value, 'year' => $y, 'prefix' => $key->prefix(),
                    'next_value' => 1, 'padding' => 6, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // The rows are counters that may already be in use; they are never removed.
    }
};
