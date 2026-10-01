<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §6.5: a draft credit note against one issued invoice; amounts are gross per credited line. */
final class SaveCreditNote
{
    public function __construct(private BuildCreditNote $build) {}

    /** @param  array{reason?: string, lines?: array<int, array{credited_line_id: int|string, amount: string|null}>}  $data */
    public function handle(User $actor, Invoice $target, ?Invoice $draft, array $data): Invoice
    {
        if (! ($draft ? $actor->can('update', $draft) : $actor->can('credit', $target))) {
            throw new AuthorizationException;
        }
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => __('Give the reason for the credit note.')]);
        }

        return DB::transaction(function () use ($actor, $target, $draft, $data) {
            $target = Invoice::query()->lockForUpdate()->with('lines')->findOrFail($target->id);
            $cn = $draft ? Invoice::query()->lockForUpdate()->findOrFail($draft->id) : new Invoice;

            if ($draft && ($cn->status !== InvoiceStatus::Draft || $cn->related_invoice_id !== $target->id)) {
                throw new AuthorizationException;
            }

            $entries = [];
            foreach ((array) ($data['lines'] ?? []) as $i => $entry) {
                if (blank($entry['amount'] ?? null) || preg_match('/^0+(\.0+)?$/', (string) $entry['amount'])) {
                    continue;
                }
                if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', (string) $entry['amount'])) {
                    throw ValidationException::withMessages(["lines.$i.amount" => __('Enter an amount with at most 3 decimals.')]);
                }
                /** @var InvoiceLine|null $line */
                $line = $target->lines->firstWhere('id', (int) $entry['credited_line_id']);
                $amount = Fils::fromDecimal((string) $entry['amount']);

                if ($line === null) {
                    throw ValidationException::withMessages(["lines.$i.amount" => __('Credit only lines of :n.', ['n' => $target->label()])]);
                }
                $left = Fils::fromDecimal($line->total) - Fils::fromDecimal((string) $line->credited);
                if ($amount > $left) {
                    throw ValidationException::withMessages(["lines.$i.amount" => __('At most :left BHD is left to credit on this line.', ['left' => Fils::toDecimal($left)])]);
                }
                $entries[] = [$line, $amount];
            }

            if ($entries === []) {
                throw ValidationException::withMessages(['lines' => __('Enter an amount on at least one line.')]);
            }

            return $this->build->handle($target, $entries, trim((string) ($data['reason'] ?? '')), $actor, $draft ? $cn : null);
        }, attempts: 3);
    }
}
