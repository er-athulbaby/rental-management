<?php

namespace App\Actions\Cheques;

use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §7.4: "deposit selected". */
final class DepositCheques
{
    /** @param  list<int>  $chequeIds */
    public function handle(User $actor, array $chequeIds, string $depositedOn): int
    {
        if (! $actor->can('manage', Cheque::class)) {
            throw new AuthorizationException;
        }
        Validator::make(['deposited_on' => $depositedOn, 'ids' => $chequeIds], [
            'deposited_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'ids' => ['required', 'array', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $chequeIds, $depositedOn) {
            $cheques = Cheque::query()->visibleTo($actor)->whereKey($chequeIds)->orderBy('id')->lockForUpdate()->get();

            if ($cheques->count() !== count(array_unique($chequeIds)) || $cheques->contains(fn (Cheque $c) => $c->status !== ChequeStatus::Held)) {
                throw ValidationException::withMessages(['selected' => __('Only held cheques can be deposited.')]);
            }

            foreach ($cheques as $cheque) {
                $cheque->forceFill(['status' => ChequeStatus::Deposited, 'deposited_on' => $depositedOn])->save();
            }

            return $cheques->count();
        }, attempts: 3);
    }
}
