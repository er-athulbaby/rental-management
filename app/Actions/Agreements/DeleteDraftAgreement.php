<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §5.2: soft deletes, drafts only. */
final class DeleteDraftAgreement
{
    public function handle(User $actor, Agreement $agreement): void
    {
        if (! $actor->can('update', $agreement)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($agreement) {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if ($agreement->status !== AgreementStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('Only a draft can be deleted.')]);
            }

            $agreement->delete();
        }, attempts: 3);
    }
}
