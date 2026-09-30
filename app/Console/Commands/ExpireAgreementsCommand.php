<?php

namespace App\Console\Commands;

use App\Actions\Agreements\ExpireAgreements;
use Illuminate\Console\Command;

class ExpireAgreementsCommand extends Command
{
    protected $signature = 'rms:agreements:expire';

    protected $description = 'Move active agreements past their end date to expired';

    public function handle(ExpireAgreements $expire): int
    {
        $this->info(sprintf('%d agreement(s) expired.', $expire()));

        return self::SUCCESS;
    }
}
