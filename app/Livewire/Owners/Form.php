<?php

namespace App\Livewire\Owners;

use App\Actions\Owners\SaveOwner;
use App\Enums\IdType;
use App\Enums\PartyType;
use App\Livewire\Concerns\WithActor;
use App\Models\Owner;
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
    public ?int $ownerId = null;

    /** @var array<string, mixed> */
    public array $form = ['type' => 'person', 'id_type' => 'cpr'];

    public function mount(?Owner $owner = null): void
    {
        if ($owner?->exists) {
            abort_unless($this->actor()->can('view', $owner), 403);
            $this->ownerId = $owner->id;
            $this->form = [
                ...$owner->only(['name_en', 'name_ar', 'id_number', 'nationality', 'phone', 'email', 'address', 'notes']),
                'type' => $owner->type->value,
                'id_type' => $owner->id_type->value,
            ];
            if ($this->actor()->can('viewBank', $owner)) {
                $this->form += $owner->only(Owner::BANK_FIELDS);
            }
        } else {
            abort_unless($this->actor()->can('create', Owner::class), 403);
        }
    }

    public function save(SaveOwner $save): void
    {
        $existing = $this->ownerId ? Owner::findOrFail($this->ownerId) : null;
        $data = $this->form;

        // Users who cannot see bank details must not wipe them: keep the stored values.
        if ($existing && ! $this->actor()->can('viewBank', $existing)) {
            $data = [...$data, ...$existing->only(Owner::BANK_FIELDS)];
        }

        try {
            $owner = $save->handle($this->actor(), $existing, $data);
        } catch (AuthorizationException $e) {
            $this->addError('form.iban', $e->getMessage() ?: __('This action is not allowed.'));

            return;
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Owner saved.'));
        $this->redirectRoute('owners.edit', $owner, navigate: true);
    }

    public function render(): View
    {
        $owner = $this->ownerId ? Owner::with('bankChanger:id,name')->findOrFail($this->ownerId) : null;

        return view('livewire.owners.form', [
            'owner' => $owner,
            'types' => PartyType::cases(),
            'idTypes' => IdType::cases(),
            'canEdit' => $owner ? $this->actor()->can('update', $owner) : true,
            'canViewBank' => $owner ? $this->actor()->can('viewBank', $owner) : $this->actor()->can('owners.bank.manage'),
            'canEditBank' => $owner ? $this->actor()->can('updateBank', $owner) : $this->actor()->can('owners.bank.manage'),
        ])->title($owner ? $owner->name_en : __('New owner'));
    }
}
