<?php

namespace App\Console\Commands;

use App\Jobs\SendTenantReminders;
use App\Models\ReminderSetting;
use App\Reminders\Channel;
use App\Reminders\ReminderFinder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Spec 2026-10-17 §5: queue one reminder job per tenant with something due today. Reads only; the jobs log and send. */
class SendTenantRemindersCommand extends Command
{
    protected $signature = 'rms:reminders:send';

    protected $description = 'Email tenants today\'s rent due, overdue, cheque and renewal reminders.';

    public function handle(Channel $channel): int
    {
        if (! ReminderSetting::query()->where('enabled', true)->exists()) {
            $this->info('No reminder type is switched on; nothing sent.');

            return self::SUCCESS;
        }
        if (! $channel->ready()) {
            $this->warn('Mail is not set up (MAIL_MAILER is log or array); nothing sent.');

            return self::SUCCESS;
        }

        $today = CarbonImmutable::today('Asia/Bahrain');
        // ponytail: loads every match once just to learn the tenants; fine for one company's records, query ids only if it grows.
        $tenants = array_keys(ReminderFinder::due($today));
        foreach ($tenants as $id) {
            SendTenantReminders::dispatch($id, $today->toDateString());
        }
        $this->info(sprintf('%d tenant(s) to remind.', count($tenants)));

        return self::SUCCESS;
    }
}
