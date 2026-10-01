<?php

namespace App\Actions\Payments;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Spec §7.1–§7.2: Finance records a payment; no approval. Cheque payments come only from ClearCheque. */
final class RecordPayment
{
    public function __construct(private PostPayment $post) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Customer $customer, array $data): Payment
    {
        if (! $actor->can('create', Payment::class) || ! Customer::visibleTo($actor)->whereKey($customer->id)->exists()) {
            throw new AuthorizationException;
        }

        $validated = Validator::make($data, [
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::manual()))],
            'amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoice_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', Fils::rule()],
        ])->validate();

        $amount = Fils::fromDecimal((string) $validated['amount']);

        return DB::transaction(function () use ($actor, $customer, $validated, $amount) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id); // first lock (spec §7.2)

            $entries = array_values(array_filter((array) ($validated['allocations'] ?? []), fn ($e) => Fils::fromDecimal((string) $e['amount']) > 0));
            $plan = $entries === [] ? AllocationPlan::oldestFirst($locked->id, $amount) : AllocationPlan::explicit($locked->id, $entries);

            return $this->post->handle($locked, [
                'received_on' => $validated['received_on'],
                'method' => $validated['method'],
                'amount' => Fils::toDecimal($amount),
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ], $plan, $actor);
        }, attempts: 3);
    }
}
