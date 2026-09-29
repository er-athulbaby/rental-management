<?php

namespace App\Console\Commands;

use App\Actions\OwnerContracts\CloseEndedOwnerContracts;
use Illuminate\Console\Command;

class CloseOwnerContracts extends Command
{
    protected $signature = 'rms:owner-contracts:close';

    protected $description = 'Move active owner contracts past their end date to ended or terminated';

    public function handle(CloseEndedOwnerContracts $close): int
    {
        $this->info(sprintf('%d owner contract(s) closed.', $close()));

        return self::SUCCESS;
    }
}
