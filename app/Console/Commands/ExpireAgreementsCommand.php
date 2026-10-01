<?php

namespace App\Console\Commands;

use App\Actions\Agreements\ExpireAgreements;
use Illuminate\Console\Command;

class ExpireAgreementsCommand extends Command
{
    protected $signature = 'rms:agreements:expire';

    protected $description = 'Expire, close, terminate or renew agreements past their end date, and draft due deposit settlements';

    public function handle(ExpireAgreements $expire): int
    {
        $this->info(sprintf('%d agreement(s) expired, closed, terminated or renewed.', $expire()));

        return self::SUCCESS;
    }
}
