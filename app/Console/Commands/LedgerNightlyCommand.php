<?php

namespace App\Console\Commands;

use App\Ledger\LedgerHealth;
use App\Ledger\Poster;
use App\Models\LedgerSetting;
use Illuminate\Console\Command;

/** Plan rulings 1 and 8: posts what fell due (head-lease rent due today) or failed live, then stores Ledger health. Fails while anything is still unposted, so the heartbeat stops. */
class LedgerNightlyCommand extends Command
{
    protected $signature = 'rms:ledger:nightly';

    protected $description = 'Post pending ledger sources and check ledger health';

    public function handle(Poster $poster): int
    {
        // A rebuild leg removed from the queue by hand never reports back; every progress write touches updated_at.
        LedgerSetting::query()->whereIn('rebuild_status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(10))
            ->update(['rebuild_status' => 'failed', 'rebuild_message' => 'Rebuild stopped: no progress for 10 minutes.', 'updated_at' => now()]);

        $before = Poster::pendingCount();
        $poster->catchUp();
        $left = Poster::pendingCount();
        $this->info('Posted '.($before - $left)." pending ledger sources, {$left} still unposted.");
        LedgerHealth::store();

        return $left > 0 ? self::FAILURE : self::SUCCESS;
    }
}
