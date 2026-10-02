<?php

namespace App\Console\Commands;

use App\Actions\OwnerStatements\DraftOwnerStatements;
use Carbon\CarbonImmutable;
use DomainException;
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

        try {
            ['created' => $created, 'failed' => $failed] = $draft->handle($month);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($failed > 0) { // the failures are reported; FAILURE keeps the Forge heartbeat silent
            $this->error(sprintf('%d owner statement(s) drafted for %s; %d failed.', $created, $month->format('Y-m'), $failed));

            return self::FAILURE;
        }

        $this->info(sprintf('%d owner statement(s) drafted for %s.', $created, $month->format('Y-m')));

        return self::SUCCESS;
    }
}
