<?php

namespace App\Livewire\Approvals;

use App\Actions\Approvals\DecideApproval;
use App\Livewire\Concerns\WithActor;
use App\Models\Approval;
use App\Models\CompanySetting;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** "Pending my approval" (spec §8.3). */
#[Title('Pending approvals')]
class Index extends Component
{
    use WithActor;

    public ?int $rejecting = null;

    public string $comment = '';

    public function approve(int $approvalId, DecideApproval $decide): void
    {
        $this->decide($decide, $approvalId, true);
    }

    public function startRejecting(int $approvalId): void
    {
        $this->rejecting = $approvalId;
        $this->comment = '';
        $this->resetErrorBag();
    }

    public function reject(DecideApproval $decide): void
    {
        $this->decide($decide, (int) $this->rejecting, false, $this->comment);
        $this->rejecting = null;
    }

    /** ValidationExceptions (comment, already decided, same approver) surface as field errors. */
    private function decide(DecideApproval $decide, int $approvalId, bool $approve, ?string $comment = null): void
    {
        $this->resetErrorBag(); // errors from the previous attempt travel in the snapshot

        try {
            $decide->handle($this->actor(), Approval::findOrFail($approvalId), $approve, $comment);
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: $approve ? __('Approved.') : __('Rejected.'));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $different = CompanySetting::current()->require_different_approver;

        // ponytail: one handler lookup per row; fine while the pending list stays short.
        $rows = Approval::query()->pending()->with('requester:id,name')->orderBy('requested_at')->get()
            ->map(function (Approval $approval) use ($actor, $different) {
                $handler = $approval->handler();

                return [
                    'approval' => $approval,
                    'summary' => $handler->summary($approval),
                    'url' => $handler->url($approval),
                    'mine' => $different && in_array($actor->id, [$approval->requested_by, $handler->creatorId($approval)], true),
                ];
            });

        return view('livewire.approvals.index', ['rows' => $rows]);
    }
}
