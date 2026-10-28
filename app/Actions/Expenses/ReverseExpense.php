<?php

namespace App\Actions\Expenses;

use App\Enums\ExpenseStatus;
use App\Models\Disbursement;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReverseExpense
{
    public function handle(User $actor, Expense $expense, string $reason): Expense
    {
        if (! $actor->can('reverse', $expense)) {
            throw new AuthorizationException;
        }

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason (up to 500 characters).')]);
        }

        return DB::transaction(function () use ($actor, $expense, $reason) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status === ExpenseStatus::Reversed) {
                throw ValidationException::withMessages(['reason' => __('This expense is already reversed.')]);
            }

            // Ledger money screens plan ruling 2: a vendor bill holding it would pay a reversed expense.
            $on = DB::table('disbursement_expenses as de')->join('disbursements as d', 'd.id', '=', 'de.disbursement_id')
                ->where('de.expense_id', $expense->id)->whereIn('d.status', Disbursement::ACTIVE)->lockForUpdate()->value('d.id');
            if ($on !== null) {
                throw ValidationException::withMessages(['reason' => __('This expense is on payment out :n. Reject or reverse that payment out first.', [
                    'n' => Disbursement::query()->lockForUpdate()->findOrFail((int) $on)->label(),
                ])]);
            }

            $expense->forceFill([
                'status' => ExpenseStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();
            // ponytail: M4 shows the opposite owner-ledger entry at reversed_at (spec §7.9).

            return $expense;
        });
    }
}
