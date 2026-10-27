<?php

namespace App\Console\Commands;

use App\Ledger\LedgerHealth;
use App\Ledger\Poster;
use App\Models\LedgerSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Plan rulings 1 and 8: posts what fell due (head-lease rent due today) or failed live, then stores Ledger health. Fails while anything is still unposted, so the heartbeat stops. */
class LedgerNightlyCommand extends Command
{
    protected $signature = 'rms:ledger:nightly';

    protected $description = 'Post pending ledger sources and check ledger health';

    public function handle(Poster $poster): int
    {
        // A rebuild leg removed from the queue by hand never reports back; every progress write touches updated_at.
        // Under the settings lock, so it never lands between a leg's check and its write; a late leg then sees "failed" and exits.
        DB::transaction(function () {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            if ($settings->rebuilding() && $settings->updated_at?->lt(now()->subMinutes(10))) {
                $settings->forceFill(['rebuild_status' => 'failed', 'rebuild_message' => LedgerSetting::STALE_MESSAGE])->save();
            }
        });

        $before = Poster::pendingCount();
        $poster->catchUp();
        $left = Poster::pendingCount();
        $this->info('Posted '.($before - $left)." pending ledger sources, {$left} still unposted.");
        LedgerHealth::store();

        return $left > 0 ? self::FAILURE : self::SUCCESS;
    }
}
