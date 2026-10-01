<?php

namespace App\Actions\Billing;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\InvoiceStatus;
use App\Models\Approval;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Spec §6.5, §8.3 item 5: draft → pending_approval; Management's approval issues it. */
final class SubmitCreditNote
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, Invoice $creditNote): Approval
    {
        return DB::transaction(function () use ($actor, $creditNote) {
            $cn = Invoice::query()->lockForUpdate()->findOrFail($creditNote->id);

            if (! $actor->can('update', $cn) || $cn->type->value !== 'credit_note') {
                throw new AuthorizationException;
            }

            $cn->forceFill(['status' => InvoiceStatus::PendingApproval])->save();

            return $this->request->handle($actor, $cn, ApprovalAction::CreditNote, $cn->credit_reason);
        }, attempts: 3);
    }
}
