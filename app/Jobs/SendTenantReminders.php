<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\ReminderSend;
use App\Reminders\ReminderFinder;
use App\Reminders\ReminderSender;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/** Spec 2026-10-17 §5: one tenant's reminders for one run date. A failure here never touches another tenant's job. */
class SendTenantReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    /** One attempt only: a retry could email the tenant twice. A failure stays `failed` in the log (spec §5). */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $customerId, public string $date) {}

    public function handle(ReminderSender $sender): void
    {
        $tenant = Customer::query()->find($this->customerId);
        if ($tenant === null) {
            return;
        }
        $today = CarbonImmutable::parse($this->date, 'Asia/Bahrain');
        // Found again now: items paid, deposited, renewed or given notice since the command ran drop out (plan ruling 18).
        $items = ReminderFinder::due($today, $tenant->id)[$tenant->id] ?? [];
        if ($items !== []) {
            $sender->send($tenant, $items, $today);
        }
    }

    /** A killed or timed-out job must not leave its rows in `sending`. */
    public function failed(Throwable $e): void
    {
        ReminderSend::query()->where('customer_id', $this->customerId)->where('sent_on', $this->date)->where('status', 'sending')
            ->update(['status' => 'failed', 'reason' => Str::limit($e->getMessage(), 490)]);
    }
}
