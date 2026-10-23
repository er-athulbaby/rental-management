<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\SaveCustomer;
use App\Actions\Portal\InviteToPortal;
use App\Actions\Portal\SetPortalAccess;
use App\Billing\CustomerCredit;
use App\CustomFields\CustomFields;
use App\Enums\CustomerType;
use App\Enums\CustomFieldEntity;
use App\Enums\IdType;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\PortalAccount;
use App\Reminders\EmailChannel;
use App\Support\Fils;
use Closure;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithActor, WithFileUploads;

    #[Locked]
    public ?int $customerId = null;

    /** @var array<string, mixed> */
    public array $form = ['type' => 'individual', 'id_type' => 'cpr', 'no_reminders' => false];

    /** @var array<string, mixed> spec 2026-10-21 custom fields §4: "More details", field key => value (files are uploads) */
    public array $custom = [];

    public function mount(?Customer $customer = null): void
    {
        if ($customer?->exists) {
            abort_unless($this->actor()->can('view', $customer), 403);
            $this->customerId = $customer->id;
            $this->form = [
                ...$customer->only(['name_en', 'name_ar', 'id_number', 'nationality', 'mobile', 'email', 'address', 'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes']),
                'type' => $customer->type->value,
                'id_type' => $customer->id_type->value,
                'no_reminders' => (bool) $customer->no_reminders,
            ];
        } else {
            abort_unless($this->actor()->can('create', Customer::class), 403);
        }
        $this->custom = CustomFields::formValues(CustomFieldEntity::Customer, $this->customerId !== null ? $customer : null);
    }

    public function save(SaveCustomer $save): void
    {
        try {
            $customer = $save->handle($this->actor(), $this->customerId ? Customer::findOrFail($this->customerId) : null, [...$this->form, 'custom' => $this->custom]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'custom') ? $k : "form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Tenant saved.'));
        $this->redirectRoute('customers.edit', $customer, navigate: true);
    }

    /** Spec 2026-10-18 tenant portal §3: Invite / Re-send. No arguments: always this page's tenant. */
    public function invitePortal(InviteToPortal $invite): void
    {
        $this->portal(fn (Customer $c) => $invite->handle($this->actor(), $c), __('Portal invite sent.'));
    }

    public function disablePortal(SetPortalAccess $access): void
    {
        $this->portal(fn (Customer $c) => $access->handle($this->actor(), $c, false), __('Portal access disabled.'));
    }

    public function enablePortal(SetPortalAccess $access): void
    {
        $this->portal(fn (Customer $c) => $access->handle($this->actor(), $c, true), __('Portal access enabled.'));
    }

    /** @param  Closure(Customer): PortalAccount  $run */
    private function portal(Closure $run, string $done): void
    {
        abort_if($this->customerId === null, 404);
        try {
            $run(Customer::findOrFail($this->customerId));
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: $done);
    }

    public function render(): View
    {
        $customer = $this->customerId ? Customer::findOrFail($this->customerId) : null;
        $account = $customer ? PortalAccount::of($customer) : null;

        return view('livewire.customers.form', [
            'customer' => $customer,
            'types' => CustomerType::cases(),
            'idTypes' => IdType::cases(),
            'account' => $customer && $this->actor()->can('viewAny', Payment::class) ? [
                'outstanding' => Fils::toDecimal($customer->outstandingFils()),
                'credit' => Fils::toDecimal(CustomerCredit::fils($customer->id)),
                'canRecord' => $this->actor()->can('create', Payment::class),
            ] : null,
            'maintenance' => $customer && $this->actor()->can('maintenance.view')
                ? MaintenanceRequest::query()->visibleTo($this->actor())->where('customer_id', $customer->id)->latest('id')->limit(20)->get() : null,
            'canEdit' => $customer ? $this->actor()->can('update', $customer) : true,
            'customFields' => CustomFields::active(CustomFieldEntity::Customer),
            'customDocuments' => CustomFields::documents($customer),
            'customDetails' => $customer ? CustomFields::display($customer) : [],
            'portal' => $customer && $this->actor()->can('update', $customer) ? [
                'account' => $account, 'other' => $account?->otherThan($customer), 'otherSeen' => ($other = $account?->otherThan($customer)) !== null && $this->actor()->can('view', $other), 'mailReady' => (new EmailChannel)->ready(),
            ] : null,
        ])->title($customer ? $customer->name_en : __('New tenant'));
    }
}
