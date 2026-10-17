<?php

namespace App\Reminders;

use App\Audit\Audit;
use App\Models\Customer;
use App\Models\ReminderSend;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spec 2026-10-17 §5: logs one row per item, then sends the combined message once through the channel. Automatic rows
 * are unique per tenant, type, item, rule day and date, so a rerun finds them and nothing goes twice. Reads money
 * records only; writes only reminder_sends.
 */
final class ReminderSender
{
    public function __construct(private readonly Channel $channel) {}

    /** Why nothing can be sent to this tenant now (a ReminderSend reason code), or null. */
    public function refusal(Customer $tenant): ?string
    {
        return match (true) {
            $tenant->no_reminders => ReminderSend::OPTED_OUT,
            ! $this->channel->ready() => ReminderSend::MAIL_NOT_SET_UP,
            default => $this->channel->unavailableReason($tenant),
        };
    }

    /**
     * @param  list<ReminderItem>  $items  all for $tenant
     * @param  User|null  $by  the person sending by hand; null for the daily run
     * @return string|null 'sent', 'failed' or 'skipped'; null when every item was already logged
     */
    public function send(Customer $tenant, array $items, CarbonImmutable $today, ?User $by = null): ?string
    {
        $reason = $this->refusal($tenant);
        $batch = (string) Str::uuid();
        $kept = [];
        foreach ($items as $item) {
            try {
                ReminderSend::create([
                    'customer_id' => $tenant->id, 'type' => $item->type, 'item_type' => $item->itemType(), 'item_id' => $item->record->getKey(),
                    'rule_day' => $item->ruleDay, 'sent_on' => $today->toDateString(), 'channel' => $this->channel->name(),
                    'status' => $reason === null ? 'sending' : 'skipped', 'reason' => $reason,
                    'manual' => $by !== null, 'sent_by' => $by?->id, 'batch' => $batch,
                ]);
                $kept[] = $item;
            } catch (UniqueConstraintViolationException) {
                // Already logged for this rule today (a rerun): never twice.
            }
        }
        if ($kept === []) {
            return null;
        }
        if ($reason !== null) {
            return 'skipped';
        }

        try {
            $this->channel->send($tenant, $kept);
            $status = 'sent';
            $error = null;
        } catch (Throwable $e) {
            report($e);
            $status = 'failed';
            $error = Str::limit($e->getMessage(), 490);
        }
        ReminderSend::query()->where('batch', $batch)->update(['status' => $status, 'reason' => $error]);

        if ($status === 'sent') { // plan ruling 7
            Audit::log('reminder.sent', $tenant, properties: $by === null
                ? ['batch' => $batch, 'counts' => array_count_values(array_map(fn (ReminderItem $i) => $i->type, $kept))]
                : ['batch' => $batch, 'manual' => true, 'type' => $kept[0]->type, 'item_type' => $kept[0]->itemType(), 'item_id' => $kept[0]->record->getKey()],
                causer: $by);
        }

        return $status;
    }
}
