<?php

namespace App\Livewire\Agreements;

use App\Actions\Agreements\DeleteDraftAgreement;
use App\Actions\Agreements\RecordMoveOut;
use App\Actions\Agreements\RecordNotice;
use App\Actions\Agreements\RenewAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Enums\AmendmentStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\AgreementAmendment;
use App\Models\AgreementUnit;
use App\Models\Cheque;
use App\Models\DepositSettlement;
use App\Models\Invoice;
use App\Reports\Queries;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
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

    public string $moveOutTarget = '';

    public string $moveOutDate = '';

    public string $moveOutReadings = '';

    public string $moveOutNotes = '';

    public function recordMoveOut(RecordMoveOut $moveOut): void
    {
        $agreement = $this->agreement();
        $unit = $this->moveOutTarget !== '' ? AgreementUnit::query()->where('agreement_id', $agreement->id)->findOrFail((int) $this->moveOutTarget) : null;

        try {
            $moveOut->handle($this->actor(), $agreement, $unit, $this->moveOutDate, $this->moveOutReadings ?: null, $this->moveOutNotes ?: null);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['moveOutDate' => Arr::flatten($e->errors())]);
        }

        $this->reset('moveOutTarget', 'moveOutDate', 'moveOutReadings', 'moveOutNotes');
        Flux::toast(variant: 'success', text: __('Move-out recorded.'));
    }

    /** @var list<int|string> */
    public array $renewUnits = [];

    public string $renewEnd = '';

    public function renew(RenewAgreement $renew): void
    {
        try {
            $draft = $renew->handle($this->actor(), $this->agreement(), array_map('intval', $this->renewUnits), $this->renewEnd);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['renewUnits' => Arr::flatten($e->errors())]);
        }

        $this->redirectRoute('agreements.edit', $draft, navigate: true);
    }

    public function deleteAmendmentDraft(int $amendmentId): void
    {
        $amendment = AgreementAmendment::query()->where('agreement_id', $this->agreementId)->findOrFail($amendmentId);
        abort_unless($this->actor()->can('update', $this->agreement()) && $amendment->status === AmendmentStatus::Draft, 403);
        $amendment->delete(); // drafts only: the trigger refuses anything else

        Flux::toast(text: __('Draft amendment deleted.'));
    }

    public function render(): View
    {
        $agreement = $this->agreement();

        return view('livewire.agreements.show', [
            'agreement' => $agreement,
            'canManage' => $this->actor()->can('update', $agreement),
            'amendments' => $agreement->amendments()->with('creator:id,name')->latest('id')->get(),
            'canAmend' => $this->actor()->can('update', $agreement) && $agreement->status->value === 'active',
            'canRenew' => $this->actor()->can('create', Agreement::class) && $this->actor()->can('update', $agreement) && in_array($agreement->status->value, ['active', 'expired'], true),
            'renewal' => Agreement::query()->where('previous_agreement_id', $agreement->id)->latest('id')->first(),
            'canEnterCheques' => $this->actor()->can('manage', Cheque::class) && $agreement->status->value === 'active',
            'invoices' => $this->actor()->can('viewAny', Invoice::class)
                ? $agreement->invoices()->orderBy('due_date')->orderBy('id')->get()
                : collect(),
            'settlements' => $this->actor()->can('viewAny', DepositSettlement::class)
                ? DepositSettlement::query()->where('agreement_id', $agreement->id)->latest('id')->get()
                : collect(),
            // Held cheques that re-billing left without an invoice: to hand back to the customer (spec §6.3, plan ruling 5).
            'chequesToReturn' => $this->actor()->can('viewAny', Cheque::class)
                ? Queries::chequesToReturn($this->actor())->where('agreement_id', $agreement->id)->orderBy('cheque_date')->orderBy('id')->get()
                : collect(),
            'approvals' => $agreement->approvals()->with(['requester:id,name', 'decider:id,name'])->latest('id')->get(),
        ])->title($agreement->label());
    }
}
