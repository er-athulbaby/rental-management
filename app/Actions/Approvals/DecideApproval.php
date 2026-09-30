<?php

namespace App\Actions\Approvals;

use App\Audit\Audit;
use App\Enums\ApprovalStatus;
use App\Enums\PermissionName;
use App\Models\Approval;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §8.3. Single step in v1. */
final class DecideApproval
{
    public function handle(User $approver, Approval $approval, bool $approve, ?string $comment = null): Approval
    {
        if (! $approver->can(PermissionName::ApprovalsDecide)) {
            throw new AuthorizationException;
        }

        $comment = filled($comment) ? trim($comment) : null;

        if (! $approve && $comment === null) {
            throw ValidationException::withMessages(['comment' => __('Give a reason for rejecting.')]);
        }

        return DB::transaction(function () use ($approver, $approval, $approve, $comment) {
            $approval = Approval::query()->lockForUpdate()->findOrFail($approval->id);

            if ($approval->status !== ApprovalStatus::Pending) {
                throw ValidationException::withMessages(['approval' => __('This request has already been decided.')]);
            }

            $handler = $approval->handler();

            if (CompanySetting::current()->require_different_approver
                && in_array($approver->id, [$approval->requested_by, $handler->creatorId($approval)], true)) {
                throw ValidationException::withMessages(['approval' => __('You cannot decide a request you requested or a record you created.')]);
            }

            $approve ? $handler->approve($approval, $approver) : $handler->reject($approval, $approver);

            $approval->forceFill([
                'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'comment' => $comment,
                'ip' => request()->ip(),
            ])->save();

            Audit::log($approve ? 'approval.approved' : 'approval.rejected', $approval->approvable,
                properties: ['approval_id' => $approval->id, 'action' => $approval->action->value, 'comment' => $comment],
                causer: $approver,
            );

            return $approval;
        }, attempts: 3);
    }
}
