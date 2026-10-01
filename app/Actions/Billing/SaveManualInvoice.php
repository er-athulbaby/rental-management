<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceChargeType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TaxCategory;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Spec §6.5: a draft manual invoice; IssueInvoice issues it (tax is written there). */
final class SaveManualInvoice
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Invoice $draft, array $data): Invoice
    {
        if ($draft ? ! $actor->can('update', $draft) || $draft->type !== InvoiceType::Manual : ! $actor->can('create', Invoice::class)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make($data, [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.charge_type' => ['required', Rule::in(array_map(fn (InvoiceChargeType $t) => $t->value, InvoiceChargeType::manual()))],
            'lines.*.unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'lines.*.net' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.tax_category' => ['required', Rule::enum(TaxCategory::class)],
        ])->validate();

        if (! Customer::visibleTo($actor)->whereKey($validated['customer_id'])->exists()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $draft, $validated) {
            $invoice = $draft ? Invoice::query()->lockForUpdate()->findOrFail($draft->id) : new Invoice;

            if ($draft && $invoice->status !== InvoiceStatus::Draft) {
                throw new AuthorizationException; // issued or cancelled while the form was open
            }

            $subtotal = array_sum(array_map(fn (array $l) => Fils::fromDecimal((string) $l['net']), $validated['lines']));

            $invoice->forceFill([
                'type' => InvoiceType::Manual,
                'customer_id' => $validated['customer_id'],
                'issue_date' => now('Asia/Bahrain')->toDateString(), // rewritten to the real day by IssueInvoice
                'due_date' => $validated['due_date'],
                'status' => InvoiceStatus::Draft,
                'subtotal' => Fils::toDecimal($subtotal), // net only until issue writes tax
                'tax_total' => '0.000',
                'total' => Fils::toDecimal($subtotal),
                'created_by' => $invoice->created_by ?? $actor->id,
            ])->save();

            $invoice->lines()->delete(); // draft: the trigger allows it
            foreach ($validated['lines'] as $line) {
                $invoice->lines()->create([
                    'unit_id' => $line['unit_id'] ?? null,
                    'charge_type' => $line['charge_type'],
                    'description' => $line['description'],
                    'net' => Fils::toDecimal(Fils::fromDecimal((string) $line['net'])),
                    'tax_category' => $line['tax_category'],
                    'tax_rate' => '0.00',
                    'tax_amount' => '0.000',
                    'total' => Fils::toDecimal(Fils::fromDecimal((string) $line['net'])),
                ]);
            }

            return $invoice->refresh()->load('lines');
        }, attempts: 3);
    }
}
