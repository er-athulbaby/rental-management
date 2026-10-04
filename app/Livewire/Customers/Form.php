<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\SaveCustomer;
use App\Billing\CustomerCredit;
use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Fils;
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

        Flux::toast(variant: 'success', text: __('Tenant saved.'));
        $this->redirectRoute('customers.edit', $customer, navigate: true);
    }

    public function render(): View
    {
        $customer = $this->customerId ? Customer::findOrFail($this->customerId) : null;

        return view('livewire.customers.form', [
            'customer' => $customer,
            'types' => CustomerType::cases(),
            'idTypes' => IdType::cases(),
            'account' => $customer && $this->actor()->can('viewAny', Payment::class) ? [
                'outstanding' => Fils::toDecimal(Fils::fromDecimal((string) (Invoice::query()->where('customer_id', $customer->id)
                    ->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote)->sum('balance') ?: '0'))),
                'credit' => Fils::toDecimal(CustomerCredit::fils($customer->id)),
                'canRecord' => $this->actor()->can('create', Payment::class),
            ] : null,
            'canEdit' => $customer ? $this->actor()->can('update', $customer) : true,
        ])->title($customer ? $customer->name_en : __('New tenant'));
    }
}
