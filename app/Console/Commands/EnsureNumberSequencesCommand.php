<?php

namespace App\Console\Commands;

use App\Actions\EnsureNumberSequences;
use Illuminate\Console\Command;

class EnsureNumberSequencesCommand extends Command
{
    protected $signature = 'rms:number-sequences {--year= : Defaults to next year (Asia/Bahrain)}';

    protected $description = 'Create the number_sequences rows for a year (spec §6.8)';

    public function handle(EnsureNumberSequences $ensure): int
    {
        $year = (int) ($this->option('year') ?: now('Asia/Bahrain')->year + 1);

        $this->info("Created {$ensure($year)} number sequence rows for {$year}.");

        return self::SUCCESS;
    }
}
