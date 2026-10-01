<?php

namespace App\Livewire\Cheques;

use App\Actions\Cheques\RecordCheques;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Invoice;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Spec §7.4: the post-dated cheques collected at signing, entered on one screen. Rows left without a number are skipped. */
#[Title('Enter cheques')]
class Entry extends Component
{
    use WithActor;

    #[Locked]
    public int $agreementId;

    public string $bank_name = '';

    public string $account_holder = '';

    /** @var list<array{invoice_id: int|null, label: string, cheque_no: string, cheque_date: string, amount: string}> */
    public array $rows = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can('manage', Cheque::class), 403);
        $agreement = Agreement::query()->visibleTo($this->actor())->findOrFail(request()->integer('agreement'));
        $this->agreementId = $agreement->id;

        $taken = Cheque::query()->whereNotNull('invoice_id')->whereIn('status', ['held', 'deposited'])->pluck('invoice_id')->all();
        $this->rows = array_values(Invoice::query()->where('agreement_id', $agreement->id)->where('type', '!=', InvoiceType::CreditNote)
            ->where(fn ($q) => $q->where('status', InvoiceStatus::Scheduled)->orWhere(fn ($q) => $q->where('status', InvoiceStatus::Issued)->where('balance', '>', 0)))
            ->whereNotIn('id', $taken)
            ->orderBy('due_date')->orderBy('id')->get()
            ->map(fn (Invoice $i) => [
                'invoice_id' => $i->id,
                'label' => $i->label().' · '.$i->due_date->format('d/m/Y'),
                'cheque_no' => '',
                'cheque_date' => $i->due_date->toDateString(),
                'amount' => $i->status === InvoiceStatus::Issued ? $i->balance : $i->total,
            ])->all());
    }

    public function save(RecordCheques $record): void
    {
        $filled = array_filter($this->rows, fn (array $r) => trim($r['cheque_no']) !== '');
        if ($filled === []) {
            throw ValidationException::withMessages(['rows' => __('Enter at least one cheque number.')]);
        }

        $agreement = Agreement::with('customer')->findOrFail($this->agreementId);

        try {
            $record->handle($this->actor(), $agreement->customer, $agreement, array_map(fn (array $r) => [
                'cheque_no' => $r['cheque_no'], 'bank_name' => $this->bank_name, 'account_holder' => $this->account_holder ?: null,
                'cheque_date' => $r['cheque_date'], 'amount' => $r['amount'], 'invoice_id' => $r['invoice_id'],
            ], array_values($filled)));
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            $keys = array_keys($filled); // map rows.N back to the screen's row index
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(function ($m, $k) use ($keys) {
                if (preg_match('/^rows\.(\d+)\.(\w+)$/', $k, $mm)) {
                    return $mm[2] === 'bank_name' ? ['bank_name' => $m] : ['rows.'.$keys[(int) $mm[1]].'.'.$mm[2] => $m];
                }

                return [$k => $m];
            })->all());
        }

        Flux::toast(variant: 'success', text: __(':n cheque(s) entered.', ['n' => count($filled)]));
        $this->redirectRoute('cheques.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.cheques.entry', ['agreement' => Agreement::with('customer')->findOrFail($this->agreementId)]);
    }
}
