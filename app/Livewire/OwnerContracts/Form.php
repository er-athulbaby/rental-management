<?php

namespace App\Livewire\OwnerContracts;

use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Enums\DepositsHeldBy;
use App\Enums\FeeType;
use App\Enums\OwnerContractStatus;
use App\Enums\PaymentFrequency;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    use WithActor;

    #[Locked]
    public ?int $contractId = null;

    /** @var array<string, mixed> */
    public array $form = ['type' => 'managed', 'fee_type' => 'percent_collected', 'deposits_held_by' => 'company', 'payment_frequency' => 'monthly', 'unit_ids' => []];

    public function mount(?OwnerContract $contract = null): void
    {
        if ($contract?->exists) {
            abort_unless($this->actor()->can('update', $contract) && $contract->status === OwnerContractStatus::Draft, 403);
            $this->contractId = $contract->id;
            $this->form = $this->fromContract($contract);

            return;
        }

        abort_unless($this->actor()->can('create', OwnerContract::class), 403);

        $previousId = request()->integer('previous');

        if ($previousId && ($prev = OwnerContract::find($previousId)) && $this->actor()->can('view', $prev)) {
            // A successor starts from its predecessor's terms (spec §4.5: rent or fee changes and renewals).
            $this->form = [
                ...$this->fromContract($prev),
                'previous_contract_id' => $prev->id,
                'start_date' => $prev->end_date->addDay()->toDateString(),
                'end_date' => $prev->end_date->addYear()->toDateString(),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function fromContract(OwnerContract $contract): array
    {
        return [
            ...$contract->only(['owner_id', 'building_id', 'previous_contract_id', 'rent_amount', 'fee_value', 'expense_approval_limit', 'notes']),
            'type' => $contract->type->value,
            'start_date' => $contract->start_date->toDateString(),
            'end_date' => $contract->end_date->toDateString(),
            'payment_frequency' => $contract->payment_frequency->value ?? 'monthly',
            'fee_type' => $contract->fee_type->value ?? 'percent_collected',
            'deposits_held_by' => $contract->deposits_held_by->value ?? 'company',
            'unit_ids' => $contract->units()->orderBy('units.id')->pluck('units.id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    public function updatedForm(mixed $value, string $key): void
    {
        if ($key === 'building_id') {
            $this->form['unit_ids'] = [];
        }
    }

    public function selectAllUnits(): void
    {
        $this->form['unit_ids'] = Unit::query()->where('building_id', $this->form['building_id'] ?? 0)->whereHas('building', fn ($q) => $q->visibleTo($this->actor()))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function save(SaveOwnerContract $save): void
    {
        $data = [...$this->form, 'unit_ids' => array_map(intval(...), (array) ($this->form['unit_ids'] ?? []))];

        try {
            $contract = $save->handle($this->actor(), $this->contractId ? OwnerContract::findOrFail($this->contractId) : null, $data);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['form.'.explode('.', $k)[0] => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Contract saved as draft.'));
        $this->redirectRoute('owner-contracts.show', $contract, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.owner-contracts.form', [
            'owners' => Owner::query()->orderBy('name_en')->get(['id', 'name_en']),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
            'units' => Unit::query()->where('building_id', $this->form['building_id'] ?? 0)->whereHas('building', fn ($q) => $q->visibleTo($this->actor()))->orderBy('code')->get(['id', 'code', 'floor']),
            'predecessors' => OwnerContract::query()->visibleTo($this->actor())->where('status', OwnerContractStatus::Active)->orderBy('number')->get(['id', 'number']),
            'frequencies' => PaymentFrequency::cases(),
            'feeTypes' => FeeType::cases(),
            'holders' => DepositsHeldBy::cases(),
        ])->title($this->contractId ? __('Edit draft contract') : __('New owner contract'));
    }
}
