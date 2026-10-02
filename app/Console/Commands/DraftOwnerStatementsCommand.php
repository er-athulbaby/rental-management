<?php

namespace App\Console\Commands;

use App\Actions\OwnerStatements\DraftOwnerStatements;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DraftOwnerStatementsCommand extends Command
{
    protected $signature = 'rms:owner-statements:draft {--month= : YYYY-MM; defaults to the previous month}';

    protected $description = 'Draft owner statements for a month (spec §7.9)';

    public function handle(DraftOwnerStatements $draft): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'), 'Asia/Bahrain')
            : CarbonImmutable::now('Asia/Bahrain')->subMonthNoOverflow();

        if ($month === null) {
            $this->error('--month must be YYYY-MM.');

            return self::INVALID;
        }

        $this->info(sprintf('%d owner statement(s) drafted for %s.', $draft->handle($month), $month->format('Y-m')));

        return self::SUCCESS;
    }
}
