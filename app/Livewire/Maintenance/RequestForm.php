<?php

namespace App\Livewire\Maintenance;

use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\Trade;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Spec 2026-10-17 maintenance §3.2 and §4 step 1: log a request; the unit's current tenant is filled in and can be changed. */
#[Title('New maintenance request')]
class RequestForm extends Component
{
    use WithActor;

    private const array NEW_FORM = ['channel' => 'phone', 'category' => 'general', 'priority' => 'normal', 'entry_permitted' => false];

    // Untyped on purpose: a tampered payload reaches LogMaintenanceRequest's validation instead of a TypeError (a 500).
    public mixed $form = self::NEW_FORM;

    public mixed $customerSearch = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', MaintenanceRequest::class), 403);
    }

    /** A new building clears the unit and tenant; a unit of the chosen building fills in its tenant. $key is null when the whole form is replaced. */
    public function updatedForm(mixed $value, ?string $key = null): void
    {
        if (! is_array($this->form)) {
            $this->form = self::NEW_FORM; // a tampered payload starts the form again

            return;
        }
        if ($key === 'building_id') {
            $this->form['unit_id'] = null;
            $this->form['customer_id'] = null;
        }
        if ($key === 'unit_id') {
            $unit = is_numeric($value) ? $this->units()->firstWhere('id', (int) $value) : null;
            $this->form['customer_id'] = $unit ? MaintenanceRequest::currentTenantId($unit->id) : null;
        }
    }

    /** The tenant picker's choice: only a tenant this user can see (plan ruling 18). */
    public function pickTenant(mixed $customerId): void
    {
        $customer = is_int($customerId) || (is_string($customerId) && ctype_digit($customerId))
            ? Customer::query()->visibleTo($this->actor())->find((int) $customerId) : null;
        if ($customer === null) {
            $this->addError('form.customer_id', __('Choose a tenant from the list.'));

            return;
        }
        $this->form = [...(is_array($this->form) ? $this->form : self::NEW_FORM), 'customer_id' => $customer->id];
        $this->customerSearch = '';
    }

    public function clearTenant(): void
    {
        $this->form = [...(is_array($this->form) ? $this->form : self::NEW_FORM), 'customer_id' => null];
    }

    public function save(LogMaintenanceRequest $log): void
    {
        try {
            $request = $log->handle($this->actor(), is_array($this->form) ? $this->form : []);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.{$k}" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Request :number logged.', ['number' => $request->number]));
        $this->redirectRoute('maintenance.requests.show', $request, navigate: true);
    }

    /** @return Collection<int, Unit> the units of the chosen building, if the user can see it */
    private function units(): Collection
    {
        $buildingId = is_array($this->form) && is_numeric($this->form['building_id'] ?? null) ? (int) $this->form['building_id'] : 0;

        return Unit::query()->where('building_id', $buildingId)->whereHas('building', fn ($q) => $q->visibleTo($this->actor()))->orderBy('code')->get(['id', 'code']);
    }

    public function render(): View
    {
        $customerId = is_array($this->form) && is_numeric($this->form['customer_id'] ?? null) ? (int) $this->form['customer_id'] : null;
        $term = is_string($this->customerSearch) ? $this->customerSearch : '';

        return view('livewire.maintenance.request-form', [
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'units' => $this->units(),
            'tenant' => $customerId ? Customer::query()->visibleTo($this->actor())->find($customerId, ['id', 'name_en', 'mobile']) : null,
            'term' => $term,
            'customerResults' => Customer::findFor($this->actor(), $term),
            'channels' => MaintenanceChannel::cases(),
            'categories' => Trade::cases(),
            'priorities' => MaintenancePriority::cases(),
        ]);
    }
}
