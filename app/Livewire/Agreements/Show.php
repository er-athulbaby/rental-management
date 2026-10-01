<?php

namespace App\Livewire\Agreements;

use App\Actions\Agreements\DeleteDraftAgreement;
use App\Actions\Agreements\RecordNotice;
use App\Actions\Agreements\SubmitAgreement;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\Cheque;
use App\Models\DepositSettlement;
use App\Models\Invoice;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $agreementId;

    public string $noticeTarget = '';

    public string $noticeDate = '';

    public string $plannedExit = '';

    public function mount(Agreement $agreement): void
    {
        abort_unless($this->actor()->can('view', $agreement), 403);
        $this->agreementId = $agreement->id;
    }

    protected function agreement(): Agreement
    {
        return Agreement::with(['customer', 'creator:id,name', 'agreementUnits.unit.building', 'agreementUnits.charges'])->findOrFail($this->agreementId);
    }

    public function deleteDraft(DeleteDraftAgreement $delete): void
    {
        try {
            $delete->handle($this->actor(), $this->agreement());
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->redirectRoute('agreements.index', navigate: true);
    }

    public function submit(SubmitAgreement $submit): void
    {
        try {
            $submit->handle($this->actor(), $this->agreement());
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: __('Submitted for approval.'));
    }

    public function recordNotice(RecordNotice $notice): void
    {
        $agreement = $this->agreement();
        $unit = $this->noticeTarget !== '' ? AgreementUnit::query()->where('agreement_id', $agreement->id)->findOrFail((int) $this->noticeTarget) : null;

        try {
            $notice->handle($this->actor(), $agreement, $unit, $this->noticeDate, $this->plannedExit);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(array_filter([
                'noticeDate' => $e->errors()['notice_date'] ?? [],
                'plannedExit' => $e->errors()['planned_exit_date'] ?? [],
            ]));
        }

        $this->reset('noticeTarget', 'noticeDate', 'plannedExit');
        Flux::toast(variant: 'success', text: __('Notice recorded.'));
    }

    public function render(): View
    {
        $agreement = $this->agreement();

        return view('livewire.agreements.show', [
            'agreement' => $agreement,
            'canManage' => $this->actor()->can('update', $agreement),
            'canEnterCheques' => $this->actor()->can('manage', Cheque::class) && $agreement->status->value === 'active',
            'invoices' => $this->actor()->can('viewAny', Invoice::class)
                ? $agreement->invoices()->orderBy('due_date')->orderBy('id')->get()
                : collect(),
            'settlements' => $this->actor()->can('viewAny', DepositSettlement::class)
                ? DepositSettlement::query()->where('agreement_id', $agreement->id)->latest('id')->get()
                : collect(),
            'approvals' => $agreement->approvals()->with(['requester:id,name', 'decider:id,name'])->latest('id')->get(),
        ])->title($agreement->label());
    }
}
