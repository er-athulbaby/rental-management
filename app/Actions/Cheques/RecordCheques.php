<?php

namespace App\Actions\Cheques;

use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Agreement;
use App\Models\Bank;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §7.4: post-dated cheques collected at signing, each matched to its invoice. Entered as held. */
final class RecordCheques
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, Cheque>
     */
    public function handle(User $actor, Customer $customer, ?Agreement $agreement, array $rows): Collection
    {
        if (! $actor->can('manage', Cheque::class) || ! Customer::visibleTo($actor)->whereKey($customer->id)->exists()
            || ($agreement && $agreement->customer_id !== $customer->id)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'min:1', 'max:60'],
            'rows.*.cheque_no' => ['required', 'string', 'max:30'],
            'rows.*.bank_name' => ['required', 'string', 'max:100', Bank::rule()],
            'rows.*.account_holder' => ['nullable', 'string', 'max:150'],
            'rows.*.cheque_date' => ['required', 'date_format:Y-m-d'],
            'rows.*.amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'rows.*.invoice_id' => ['nullable', 'integer'],
            'rows.*.notes' => ['nullable', 'string', 'max:1000'],
        ])->validate()['rows'];

        // A target is an open invoice of this customer (and agreement, when given): scheduled, or issued with a balance.
        $targets = Invoice::query()->where('customer_id', $customer->id)
            ->when($agreement, fn ($q, Agreement $a) => $q->where('agreement_id', $a->id))
            ->where('type', '!=', InvoiceType::CreditNote)
            ->where(fn ($q) => $q->where('status', InvoiceStatus::Scheduled)->orWhere(fn ($q) => $q->where('status', InvoiceStatus::Issued)->where('balance', '>', 0)))
            ->pluck('id')->all();

        foreach ($validated as $i => $row) {
            if (filled($row['invoice_id'] ?? null) && ! in_array((int) $row['invoice_id'], $targets, true)) {
                throw ValidationException::withMessages(["rows.$i.invoice_id" => __('Match the cheque to an open invoice of this tenant.')]);
            }
        }

        return DB::transaction(fn () => new Collection(array_map(function (array $row) use ($actor, $customer, $agreement) {
            $cheque = (new Cheque)->forceFill([
                'direction' => ChequeDirection::Received,
                'customer_id' => $customer->id,
                'agreement_id' => $agreement?->id,
                'invoice_id' => filled($row['invoice_id'] ?? null) ? (int) $row['invoice_id'] : null,
                'cheque_no' => $row['cheque_no'],
                'bank_name' => $row['bank_name'],
                'account_holder' => $row['account_holder'] ?? null,
                'cheque_date' => $row['cheque_date'],
                'amount' => Fils::toDecimal(Fils::fromDecimal((string) $row['amount'])),
                'notes' => $row['notes'] ?? null,
                'status' => ChequeStatus::Held,
                'created_by' => $actor->id,
            ]);
            $cheque->save();

            return $cheque;
        }, array_values($validated))), attempts: 3);
    }
}
