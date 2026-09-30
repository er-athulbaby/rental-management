<?php

namespace App\Console\Commands;

use App\Actions\Billing\IssueDueInvoices;
use Illuminate\Console\Command;

class IssueInvoices extends Command
{
    protected $signature = 'rms:invoices:issue';

    protected $description = 'Issue scheduled invoices whose issue date has come (held-back invoices wait)';

    public function handle(IssueDueInvoices $issue): int
    {
        $this->info(sprintf('%d invoice(s) issued.', $issue()));

        return self::SUCCESS;
    }
}
