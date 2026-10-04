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
use Illuminate\Validation\ValidationException;

/**
 * Spec §7.1–§7.2: Finance records a payment; no approval. Cheque payments come only from ClearCheque.
 * A split payment (card 300 + cash 200) passes `tenders` instead of `method`: one payment, one receipt, its parts kept as tenders.
 */
final class RecordPayment
{
    public function __construct(private PostPayment $post) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Customer $customer, array $data): Payment
    {
        if (! $actor->can('create', Payment::class) || ! Customer::visibleTo($actor)->whereKey($customer->id)->exists()) {
            throw new AuthorizationException;
        }

        $manual = array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::manual());
        // Blank part rows are ignored; a single part is just a payment by that method.
        $data['tenders'] = array_values(array_filter((array) ($data['tenders'] ?? []), fn ($t) => filled($t['method'] ?? null) || filled($t['amount'] ?? null)));
        if (count($data['tenders']) === 1) {
            $data = [...$data, 'method' => $data['tenders'][0]['method'] ?? null, 'amount' => $data['tenders'][0]['amount'] ?? null,
                'reference' => filled($data['tenders'][0]['reference'] ?? null) ? $data['tenders'][0]['reference'] : ($data['reference'] ?? null), 'tenders' => []];
        }
        $split = $data['tenders'] !== [];

        $validated = Validator::make($data, [
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'method' => $split ? ['nullable'] : ['required', Rule::in($manual)],
            'tenders' => ['array'],
            'tenders.*.method' => ['required', Rule::in($manual)],
            'tenders.*.amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
            'amount' => [$split ? 'nullable' : 'required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoice_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', Fils::rule()],
        ])->validate();

        $tenders = $validated['tenders'] ?? [];
        $amount = $split ? array_sum(array_map(fn ($t) => Fils::fromDecimal((string) $t['amount']), $tenders)) : Fils::fromDecimal((string) $validated['amount']);
        if ($split && filled($validated['amount'] ?? null) && Fils::fromDecimal((string) $validated['amount']) !== $amount) {
            throw ValidationException::withMessages(['tenders' => __('The parts add up to :sum, not the amount :amount.', ['sum' => Fils::toDecimal($amount), 'amount' => Fils::toDecimal(Fils::fromDecimal((string) $validated['amount']))])]);
        }

        return DB::transaction(function () use ($actor, $customer, $validated, $amount, $split, $tenders) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id); // first lock (spec §7.2)

            $entries = array_values(array_filter((array) ($validated['allocations'] ?? []), fn ($e) => Fils::fromDecimal((string) $e['amount']) > 0));
            $plan = $entries === [] ? AllocationPlan::oldestFirst($locked->id, $amount) : AllocationPlan::explicit($locked->id, $entries);

            $payment = $this->post->handle($locked, [
                'received_on' => $validated['received_on'],
                'method' => $split ? PaymentMethod::Split->value : $validated['method'],
                'amount' => Fils::toDecimal($amount),
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ], $plan, $actor);

            // Written before commit, so the receipt job (dispatched after commit) sees every part.
            foreach ($tenders as $t) {
                $payment->tenders()->create(['method' => $t['method'], 'amount' => Fils::toDecimal(Fils::fromDecimal((string) $t['amount'])), 'reference' => $t['reference'] ?? null]);
            }

            return $payment;
        }, attempts: 3);
    }
}
