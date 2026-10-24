<?php

namespace App\Console\Commands;

use App\Ledger\Poster;
use Illuminate\Console\Command;

/** Plan rulings 1 and 8: posts what fell due (head-lease rent due today) or failed live. Fails while anything is still unposted, so the heartbeat stops. */
class LedgerNightlyCommand extends Command
{
    protected $signature = 'rms:ledger:nightly';

    protected $description = 'Post pending ledger sources';

    public function handle(Poster $poster): int
    {
        $before = Poster::pendingCount();
        $poster->catchUp();
        $left = Poster::pendingCount();
        $this->info('Posted '.($before - $left)." pending ledger sources, {$left} still unposted.");

        return $left > 0 ? self::FAILURE : self::SUCCESS;
    }
}
