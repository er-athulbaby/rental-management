<?php

namespace App\Actions\Disbursements;

use App\Enums\DisbursementMethod;
use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §7.5: an approved payment out is recorded as paid by Finance. */
final class PayDisbursement
{
    public function __construct(private MarkDisbursementPaid $paid) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Disbursement $disbursement, array $data): Disbursement
    {
        if (! $actor->can('create', Disbursement::class) || ! $actor->can('view', $disbursement)) {
            throw new AuthorizationException;
        }

        $v = Validator::make($data, [
            'method' => ['required', Rule::enum(DisbursementMethod::class)],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'reference' => ['nullable', 'string', 'max:100'],
        ])->validate();

        return DB::transaction(function () use ($actor, $disbursement, $v) {
            $locked = Disbursement::query()->lockForUpdate()->findOrFail($disbursement->id);
            if ($locked->status !== DisbursementStatus::Approved) {
                throw ValidationException::withMessages(['paid_on' => __('Only an approved payment out can be paid.')]);
            }
            $this->paid->handle($locked, $v, $actor);

            return $locked->refresh();
        }, attempts: 3);
    }
}
