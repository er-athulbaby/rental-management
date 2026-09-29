<?php

namespace App\Models;

use App\Approvals\ApprovalHandler;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Spec §8.3. Decisions are explicit audit entries (DecideApproval), so no LogsActivity here.
 *
 * @property int $id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property ApprovalAction $action
 * @property ApprovalStatus $status
 * @property string|null $reason
 * @property array<string, mixed>|null $payload
 * @property int $requested_by
 * @property CarbonImmutable $requested_at
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $comment
 */
class Approval extends Model
{
    protected $guarded = ['id', 'pending_key'];

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param  Builder<Approval>  $query */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending);
    }

    public function handler(): ApprovalHandler
    {
        return app($this->action->handler());
    }
}
