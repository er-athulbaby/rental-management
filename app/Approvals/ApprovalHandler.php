<?php

namespace App\Approvals;

use App\Models\Approval;
use App\Models\User;

/** What an approval does to its record (spec §8.3). approve() and reject() run inside DecideApproval's transaction. */
interface ApprovalHandler
{
    /** created_by / recorded_by of the underlying record, for require_different_approver. */
    public function creatorId(Approval $approval): ?int;

    public function approve(Approval $approval, User $approver): void;

    public function reject(Approval $approval, User $approver): void;

    public function summary(Approval $approval): string;

    public function url(Approval $approval): ?string;
}
