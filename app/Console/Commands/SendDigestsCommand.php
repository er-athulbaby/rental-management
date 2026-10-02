<?php

namespace App\Console\Commands;

use App\Actions\Digests\SendDigests;
use Illuminate\Console\Command;

class SendDigestsCommand extends Command
{
    protected $signature = 'rms:digests {kind : finance | management | documents}';

    protected $description = 'Send a morning digest email (spec §12)';

    public function handle(SendDigests $send): int
    {
        $this->info(sprintf('%d digest(s) sent.', $send->handle((string) $this->argument('kind'))));

        return self::SUCCESS;
    }
}
