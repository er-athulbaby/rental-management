<?php

namespace App\Actions\Approvals;

use App\Audit\Audit;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Support\Approvers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Opens a pending approval (spec §8.3). The caller's Action has already authorised and locked its record. */
final class RequestApproval
{
    /** @param  array<string, mixed>  $payload */
    public function handle(User $requester, Model $approvable, ApprovalAction $action, ?string $reason = null, array $payload = []): Approval
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RequestApproval must run inside the caller\'s transaction.');
        }

        try {
            $approval = Approval::create([
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'action' => $action,
                'status' => ApprovalStatus::Pending,
                'reason' => $reason,
                'payload' => $payload ?: null,
                'requested_by' => $requester->id,
                'requested_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['approval' => __('This request is already waiting for approval.')]);
        }

        Audit::log('approval.requested', $approvable, properties: ['approval_id' => $approval->id, 'action' => $action->value], causer: $requester);

        // After commit: a rolled-back request must not email anyone.
        DB::afterCommit(fn () => Notification::send(Approvers::notifiable($requester), new ApprovalRequested($approval)));

        return $approval;
    }
}
