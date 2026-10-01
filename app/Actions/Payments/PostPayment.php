<?php

namespace App\Actions\Payments;

use App\Actions\NextDocumentNumber;
use App\Enums\NumberSequenceKey;
use App\Enums\PaymentStatus;
use App\Jobs\StoreReceipt;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Numbers, creates and allocates a payment (spec §7.1). Internal: the caller authorised and locked the customer. */
final class PostPayment
{
    public function __construct(private NextDocumentNumber $next, private ApplyToInvoices $apply) {}

    /**
     * @param  array<string, mixed>  $attributes  received_on, method, amount (BHD string), reference, notes, cheque_id
     * @param  list<array{invoice_id: int, amount: int}>  $plan
     */
    public function handle(Customer $lockedCustomer, array $attributes, array $plan, User $actor): Payment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PostPayment must run inside the caller\'s transaction.');
        }

        $payment = (new Payment)->forceFill([
            ...$attributes,
            'number' => ($this->next)(NumberSequenceKey::Receipt),
            'customer_id' => $lockedCustomer->id,
            'status' => PaymentStatus::Confirmed,
            'recorded_by' => $actor->id,
            'posted_at' => now(),
        ]);
        $payment->save();

        $this->apply->handle($payment, $plan, $actor);

        // After commit: a rolled-back payment must not leave a receipt behind.
        $paymentId = $payment->id;
        $actorId = $actor->id;
        DB::afterCommit(fn () => StoreReceipt::dispatch($paymentId, $actorId));

        return $payment;
    }
}
