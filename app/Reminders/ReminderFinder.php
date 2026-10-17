<?php

namespace App\Reminders;

use App\Enums\AgreementStatus;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\ReminderSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Spec 2026-10-17 §3: the invoices, cheques and agreements due a reminder. Reads only. */
final class ReminderFinder
{
    /** A manual renewal reminder is offered from this many days before the end date (spec §6). */
    public const int RENEWAL_WINDOW = 90;

    /** The date each type counts its rule days from: overdue counts after it (plan ruling 1), the others before. */
    private const array DATE_COLUMN = ['overdue' => 'grace_until', 'rent_due' => 'due_date', 'cheque' => 'cheque_date', 'renewal' => 'end_date'];

    /**
     * Every switched-on type's matches on $today, by tenant id, most urgent type first. An item matches when $today is
     * exactly one of its type's rule days from its date; a missed day is not caught up. Opted-out tenants and tenants
     * without email are included: the sender logs them as skipped.
     *
     * @return array<int, list<ReminderItem>>
     */
    public static function due(CarbonImmutable $today, ?int $customerId = null): array
    {
        $today = $today->setTimezone('Asia/Bahrain')->startOfDay();
        $settings = ReminderSetting::query()->where('enabled', true)->get()->keyBy('type');
        $found = [];
        foreach (ReminderSetting::TYPES as $type) {
            foreach ($settings->get($type)->days ?? [] as $day) {
                $date = $type === 'overdue' ? $today->subDays($day) : $today->addDays($day);
                $records = self::base($type)->where(self::DATE_COLUMN[$type], $date->toDateString())
                    ->when($customerId, fn (Builder $q, int $id) => $q->where('customer_id', $id))
                    ->orderBy('id')->get();
                foreach ($records as $record) {
                    $found[(int) $record->customer_id][] = new ReminderItem($type, $record, $day);
                }
            }
        }

        return $found;
    }

    /**
     * The reminder a person may send now for the record, whatever the settings (spec §6, plan ruling 9); null when the
     * record does not qualify.
     */
    public static function manual(Invoice|Cheque|Agreement $record, CarbonImmutable $today): ?ReminderItem
    {
        $today = $today->setTimezone('Asia/Bahrain')->startOfDay();
        $type = match (true) {
            $record instanceof Invoice => $record->grace_until !== null && $record->grace_until->lt($today) ? 'overdue' : 'rent_due',
            $record instanceof Cheque => 'cheque',
            default => 'renewal',
        };
        $fresh = self::base($type)->whereKey($record->getKey())
            ->when($type === 'renewal', fn (Builder $q) => $q->whereBetween('end_date', [$today->toDateString(), $today->addDays(self::RENEWAL_WINDOW)->toDateString()]))
            ->first();

        return $fresh === null ? null : new ReminderItem($type, $fresh, null);
    }

    /**
     * What qualifies for the type whatever the date, with what the email needs loaded.
     *
     * @return Builder<Invoice>|Builder<Cheque>|Builder<Agreement>
     */
    private static function base(string $type): Builder
    {
        return match ($type) {
            'overdue', 'rent_due' => Invoice::query()->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote)
                ->where('balance', '>', 0)->whereHas('customer')->with(['customer', 'lines.unit.building'])
                // Paid by a received cheque still held or deposited (cheques.invoice_id): no rent reminder until it bounces.
                ->whereNotExists(fn ($q) => $q->from('cheques')->whereColumn('cheques.invoice_id', 'invoices.id')
                    ->where('cheques.direction', ChequeDirection::Received)->whereIn('cheques.status', [ChequeStatus::Held, ChequeStatus::Deposited])),
            'cheque' => Cheque::query()->where('direction', ChequeDirection::Received)->where('status', ChequeStatus::Held)
                ->whereHas('customer')->with('customer'),
            default => Agreement::query()->where('status', AgreementStatus::Active)->whereNull('notice_date')
                ->whereDoesntHave('renewals', fn (Builder $r) => $r->whereIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval, AgreementStatus::Active]))
                ->whereHas('customer')->with(['customer', 'agreementUnits.unit.building']),
        };
    }
}
