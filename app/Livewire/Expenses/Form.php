<?php

namespace App\Livewire\Expenses;

use App\Actions\Expenses\RecordExpense;
use App\Enums\ExpenseCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Expense;
use App\Models\Unit;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Record expense')]
class Form extends Component
{
    use WithActor, WithFileUploads;

    /** @var array<string, mixed> */
    public array $form = ['category' => 'maintenance', 'charge_to' => 'company', 'tax_amount' => '0'];

    /** @var UploadedFile|null */
    public $ownerApproval = null;

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', Expense::class), 403);
        $this->form['expense_date'] = now('Asia/Bahrain')->toDateString();
    }

    public function updatedForm(mixed $value, string $key): void
    {
        if ($key === 'building_id') {
            $this->form['unit_id'] = null;
        }
    }

    public function save(RecordExpense $record): void
    {
        try {
            $expense = $record->handle($this->actor(), $this->form, $this->ownerApproval);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [in_array($k, ['owner_approval', 'file'], true) ? 'ownerApproval' : "form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Expense recorded.'));
        $this->redirectRoute('expenses.show', $expense, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.expenses.form', [
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
            'units' => Unit::query()->where('building_id', $this->form['building_id'] ?? 0)->orderBy('code')->get(['id', 'code']),
            'categories' => ExpenseCategory::cases(),
        ]);
    }
}
