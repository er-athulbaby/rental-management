<?php

namespace App\Livewire\Expenses;

use App\Actions\Expenses\ReverseExpense;
use App\Enums\ExpenseStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Expense;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $expenseId;

    public string $reason = '';

    public function mount(Expense $expense): void
    {
        abort_unless($this->actor()->can('view', $expense), 403);
        $this->expenseId = $expense->id;
    }

    public function reverse(ReverseExpense $reverse): void
    {
        try {
            $reverse->handle($this->actor(), Expense::findOrFail($this->expenseId), $this->reason);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->reset('reason');
        Flux::toast(variant: 'success', text: __('Expense reversed.'));
    }

    public function render(): View
    {
        $expense = Expense::with(['building:id,code,name', 'unit:id,code', 'ownerContract:id,number', 'recorder:id,name', 'invoice:id,number,status'])->findOrFail($this->expenseId);

        return view('livewire.expenses.show', [
            'expense' => $expense,
            'canReverse' => $expense->status === ExpenseStatus::Recorded && $this->actor()->can('reverse', $expense),
        ])->title(__('Expense #:id', ['id' => $expense->id]));
    }
}
