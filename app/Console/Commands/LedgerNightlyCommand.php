<?php

namespace App\Console\Commands;

use App\Ledger\Poster;
use Illuminate\Console\Command;

/** Plan rulings 1 and 8: posts what fell due (head-lease rent due today) or failed live. */
class LedgerNightlyCommand extends Command
{
    protected $signature = 'rms:ledger:nightly';

    protected $description = 'Post pending ledger sources';

    public function handle(Poster $poster): int
    {
        $tried = $poster->catchUp();
        $this->info("Posted {$tried} pending ledger sources.");

        return self::SUCCESS;
    }
}
