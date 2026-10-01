<?php

namespace App\Livewire\Invoices;

use App\Actions\Billing\CancelDraftInvoice;
use App\Actions\Billing\IssueInvoice;
use App\Livewire\Concerns\WithActor;
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
    public int $invoiceId;

    public function mount(Invoice $invoice): void
    {
        abort_unless($this->actor()->can('view', $invoice), 403);
        $this->invoiceId = $invoice->id;
    }

    /** "Issue now" (spec §6.3): e.g. when a customer pays several periods ahead. */
    public function issueNow(IssueInvoice $issue): void
    {
        $invoice = Invoice::findOrFail($this->invoiceId);
        abort_unless($this->actor()->can('issue', $invoice), 403);

        if (! $issue->handle($invoice, $this->actor())) {
            throw ValidationException::withMessages(['invoice' => __('Held back: an owner contract covering one of its units is waiting for approval.')]);
        }

        Flux::toast(variant: 'success', text: __('Invoice issued.'));
    }

    public function cancelDraft(CancelDraftInvoice $cancel): void
    {
        try {
            $cancel->handle($this->actor(), Invoice::findOrFail($this->invoiceId));
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(text: __('Draft cancelled.'));
    }

    public function render(): View
    {
        $invoice = Invoice::with(['customer', 'agreement:id,number', 'lines.unit.building', 'lines.ownerContract:id,number', 'issuer:id,name'])->findOrFail($this->invoiceId);

        return view('livewire.invoices.show', [
            'invoice' => $invoice,
            'canEdit' => $this->actor()->can('update', $invoice),
            'canIssue' => $this->actor()->can('issue', $invoice),
        ])->title($invoice->label());
    }
}
