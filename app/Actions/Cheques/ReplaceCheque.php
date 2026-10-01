<?php

namespace App\Actions\Cheques;

use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §7.4: a bounced cheque is replaced by a new held cheque for the same invoice. */
final class ReplaceCheque
{
    public function __construct(private RecordCheques $record) {}

    /** @param  array<string, mixed>  $row */
    public function handle(User $actor, Cheque $bounced, array $row): Cheque
    {
        return DB::transaction(function () use ($actor, $bounced, $row) {
            $bounced = Cheque::query()->lockForUpdate()->with(['customer', 'agreement'])->findOrFail($bounced->id);

            if ($bounced->status !== ChequeStatus::Bounced) {
                throw ValidationException::withMessages(['cheque_no' => __('Only a bounced cheque can be replaced.')]);
            }

            // RecordCheques refuses the target if it is no longer open; Finance then replaces without one (invoice_id => null).
            $new = $this->record->handle($actor, Customer::query()->findOrFail($bounced->customer_id), $bounced->agreement, [[...$row, 'invoice_id' => array_key_exists('invoice_id', $row) ? $row['invoice_id'] : $bounced->invoice_id]])->sole();
            $bounced->forceFill(['status' => ChequeStatus::Replaced, 'replaced_by_cheque_id' => $new->id])->save();

            return $new;
        }, attempts: 3);
    }
}
