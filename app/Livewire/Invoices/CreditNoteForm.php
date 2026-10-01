<?php

namespace App\Livewire\Invoices;

use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Livewire\Concerns\WithActor;
use App\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Credit note')]
class CreditNoteForm extends Component
{
    use WithActor;

    #[Locked]
    public int $targetId;

    #[Locked]
    public ?int $draftId = null;

    public string $reason = '';

    /** @var array<int|string, string|null> target line id => gross BHD to credit */
    public array $amounts = [];

    public function mount(?Invoice $invoice = null, ?Invoice $creditNote = null): void
    {
        if ($creditNote?->exists) {
            abort_unless($this->actor()->can('update', $creditNote) && $creditNote->type->value === 'credit_note', 403);
            $this->draftId = $creditNote->id;
            $this->targetId = (int) $creditNote->related_invoice_id;
            $this->reason = (string) $creditNote->credit_reason;
            $this->amounts = $creditNote->lines->mapWithKeys(fn ($l) => [(int) $l->credited_line_id => $l->total])->all();

            return;
        }

        abort_unless($invoice !== null && $this->actor()->can('credit', $invoice), 403);
        $this->targetId = $invoice->id;
    }

    public function save(SaveCreditNote $save): Invoice
    {
        try {
            return $save->handle($this->actor(), Invoice::findOrFail($this->targetId), $this->draftId ? Invoice::findOrFail($this->draftId) : null, [
                'reason' => $this->reason,
                'lines' => collect($this->amounts)->map(fn ($amount, $lineId) => ['credited_line_id' => (int) $lineId, 'amount' => $amount])->values()->all(),
            ]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [
                preg_match('/^lines\.(\d+)\.amount$/', $k, $mm) ? 'amounts.'.array_keys($this->amounts)[(int) $mm[1]] : $k => $m,
            ])->all());
        }
    }

    public function saveDraft(SaveCreditNote $save): void
    {
        $cn = $this->save($save);
        $this->redirectRoute('invoices.show', $cn, navigate: true);
    }

    public function submit(SaveCreditNote $save, SubmitCreditNote $submit): void
    {
        $cn = $this->save($save);
        $submit->handle($this->actor(), $cn);
        $this->redirectRoute('invoices.show', $cn, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.invoices.credit-note-form', ['target' => Invoice::with('lines')->findOrFail($this->targetId)]);
    }
}
