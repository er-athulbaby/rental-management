<?php

namespace App\Livewire\Disbursements;

use App\Actions\Disbursements\RecordDisbursement;
use App\Enums\DisbursementMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\Owner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('New payment out')]
class Create extends Component
{
    use WithActor;

    /** @var array<string, mixed> */
    public array $form = ['payee_type' => 'owner', 'method' => 'bank_transfer'];

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', Disbursement::class), 403);
    }

    public function updatedFormPayeeType(): void
    {
        unset($this->form['payee_id']);
    }

    public function save(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->form, 'purpose' => 'other']);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        $this->redirectRoute('disbursements.show', $out, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.disbursements.create', [
            'payees' => ($this->form['payee_type'] ?? 'owner') === 'customer'
                ? Customer::query()->visibleTo($this->actor())->orderBy('name_en')->get(['id', 'name_en'])
                : Owner::query()->orderBy('name_en')->get(['id', 'name_en']),
            'methods' => DisbursementMethod::cases(),
        ]);
    }
}
