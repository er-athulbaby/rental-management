<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\SaveCustomer;
use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
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
    public ?int $customerId = null;

    /** @var array<string, mixed> */
    public array $form = ['type' => 'individual', 'id_type' => 'cpr'];

    public function mount(?Customer $customer = null): void
    {
        if ($customer?->exists) {
            abort_unless($this->actor()->can('view', $customer), 403);
            $this->customerId = $customer->id;
            $this->form = [
                ...$customer->only(['name_en', 'name_ar', 'id_number', 'nationality', 'mobile', 'email', 'address', 'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes']),
                'type' => $customer->type->value,
                'id_type' => $customer->id_type->value,
            ];
        } else {
            abort_unless($this->actor()->can('create', Customer::class), 403);
        }
    }

    public function save(SaveCustomer $save): void
    {
        try {
            $customer = $save->handle($this->actor(), $this->customerId ? Customer::findOrFail($this->customerId) : null, $this->form);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Customer saved.'));
        $this->redirectRoute('customers.edit', $customer, navigate: true);
    }

    public function render(): View
    {
        $customer = $this->customerId ? Customer::findOrFail($this->customerId) : null;

        return view('livewire.customers.form', [
            'customer' => $customer,
            'types' => CustomerType::cases(),
            'idTypes' => IdType::cases(),
            'canEdit' => $customer ? $this->actor()->can('update', $customer) : true,
        ])->title($customer ? $customer->name_en : __('New customer'));
    }
}
