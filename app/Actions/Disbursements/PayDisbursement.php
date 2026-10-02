<?php

namespace App\Actions\Disbursements;

use App\Enums\DisbursementMethod;
use App\Enums\DisbursementStatus;
use App\Enums\OwnerPayableStatus;
use App\Models\Disbursement;
use App\Models\OwnerPayable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §7.5: an approved payment out is recorded as paid by Finance. */
final class PayDisbursement
{
    public function __construct(private MarkDisbursementPaid $paid, private LockOwnerSource $ownerSource) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Disbursement $disbursement, array $data): Disbursement
    {
        if (! $actor->can('create', Disbursement::class) || ! $actor->can('view', $disbursement)) {
            throw new AuthorizationException;
        }

        $cheque = ['required_if:method,cheque', 'nullable'];
        $v = Validator::make($data, [
            'method' => ['required', Rule::enum(DisbursementMethod::class)],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'reference' => ['nullable', 'string', 'max:100'],
            'cheque_no' => [...$cheque, 'string', 'max:30'],
            'bank_name' => [...$cheque, 'string', 'max:100'],
            'cheque_date' => [...$cheque, 'date_format:Y-m-d'],
        ])->validate();

        return DB::transaction(function () use ($actor, $disbursement, $v) {
            $this->ownerSource->handle($disbursement); // owner-side locks come before the disbursement (spec §7.2)
            $locked = Disbursement::query()->lockForUpdate()->findOrFail($disbursement->id);
            if ($locked->status !== DisbursementStatus::Approved) {
                throw ValidationException::withMessages(['paid_on' => __('Only an approved payment out can be paid.')]);
            }
            // A termination or successor re-cut its payable after approval; paying it would pay the period twice.
            // A locking read, so it sees the latest commit rather than the transaction's snapshot.
            if ($locked->source_type === Disbursement::SOURCE_PAYABLE
                && OwnerPayable::query()->whereKey($locked->source_id)->lockForUpdate()->value('status') === OwnerPayableStatus::Cancelled) {
                throw ValidationException::withMessages(['paid_on' => __('This head-lease period was re-cut and its payable cancelled. Reject or reverse this payment out instead of paying it.')]);
            }
            $this->paid->handle($locked, $v, $actor);

            return $locked->refresh();
        }, attempts: 3);
    }
}
