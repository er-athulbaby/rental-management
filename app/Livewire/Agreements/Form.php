<?php

namespace App\Livewire\Agreements;

use App\Actions\Agreements\SaveAgreement;
use App\Enums\AgreementStatus;
use App\Enums\ChargeType;
use App\Enums\PaymentFrequency;
use App\Enums\TaxCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\AgreementUnitCharge;
use App\Models\Building;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Unit;
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
    public ?int $agreementId = null;

    /** @var array<string, mixed> header fields */
    public array $form = ['frequency' => 'monthly'];

    /** @var array<int, array<string, mixed>> unit lines: unit_id, label, deposit_amount, start_date, end_date, charges */
    public array $units = [];

    public string $customerSearch = '';

    public string $customerLabel = '';

    public ?int $pickBuilding = null;

    public ?int $pickUnit = null;

    public function mount(?Agreement $agreement = null): void
    {
        if (! $agreement?->exists) {
            abort_unless($this->actor()->can('create', Agreement::class), 403);

            return;
        }

        abort_unless($this->actor()->can('update', $agreement) && $agreement->status === AgreementStatus::Draft, 403);

        $agreement->load(['customer', 'agreementUnits.unit.building', 'agreementUnits.charges']);
        $this->agreementId = $agreement->id;
        $this->customerLabel = $agreement->customer->name_en.' ('.$agreement->customer->maskedId().')';
        $this->form = [
            'customer_id' => $agreement->customer_id,
            'start_date' => $agreement->start_date->toDateString(),
            'end_date' => $agreement->end_date->toDateString(),
            'frequency' => $agreement->frequency->value,
            'billing_day' => $agreement->billing_day,
            'grace_days' => $agreement->grace_days,
            'notice_period_days' => $agreement->notice_period_days,
            'contract_template_id' => $agreement->contract_template_id,
        ];
        $this->units = $agreement->agreementUnits->map(fn (AgreementUnit $au) => [
            'unit_id' => $au->unit_id,
            'label' => $au->unit->building->code.' / '.$au->unit->code,
            'deposit_amount' => $au->deposit_amount,
            'start_date' => $au->start_date->toDateString(),
            'end_date' => $au->end_date->toDateString(),
            'charges' => $au->charges->map(fn (AgreementUnitCharge $c) => [
                'type' => $c->type->value, 'description' => $c->description, 'monthly_amount' => $c->monthly_amount, 'tax_category' => $c->tax_category->value,
            ])->values()->all(),
        ])->values()->all();
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::query()->visibleTo($this->actor())->findOrFail($customerId);
        $this->form['customer_id'] = $customer->id;
        $this->customerLabel = $customer->name_en.' ('.$customer->maskedId().')';
        $this->customerSearch = '';
    }

    public function updatedPickBuilding(): void
    {
        $this->pickUnit = null;
    }

    /** Adds the picked unit with its list terms: rent, deposit and service charge, taxed per its use (spec §5.3). */
    public function addUnit(): void
    {
        $unit = Unit::query()->visibleTo($this->actor())->with('building')->find($this->pickUnit);

        if (! $unit || collect($this->units)->contains('unit_id', $unit->id)) {
            return;
        }

        $tax = $unit->effectiveTaxCategory()->value;
        $charges = [['type' => ChargeType::Rent->value, 'description' => null, 'monthly_amount' => $unit->list_rent, 'tax_category' => $tax]];
        if (Fils::fromDecimal($unit->list_service_charge) > 0) {
            $charges[] = ['type' => ChargeType::ServiceCharge->value, 'description' => null, 'monthly_amount' => $unit->list_service_charge, 'tax_category' => $tax];
        }

        $this->units[] = [
            'unit_id' => $unit->id,
            'label' => $unit->building->code.' / '.$unit->code,
            'deposit_amount' => $unit->list_deposit,
            'start_date' => null,
            'end_date' => null,
            'charges' => $charges,
        ];
        $this->pickUnit = null;
    }

    public function removeUnit(int $index): void
    {
        unset($this->units[$index]);
        $this->units = array_values($this->units);
    }

    public function addCharge(int $index): void
    {
        $this->units[$index]['charges'][] = ['type' => ChargeType::Parking->value, 'description' => null, 'monthly_amount' => '0', 'tax_category' => TaxCategory::Exempt->value];
    }

    public function removeCharge(int $index, int $charge): void
    {
        unset($this->units[$index]['charges'][$charge]);
        $this->units[$index]['charges'] = array_values($this->units[$index]['charges']);
    }

    public function save(SaveAgreement $save): void
    {
        $data = [...$this->form, 'units' => array_map(fn (array $u) => collect($u)->except('label')->all(), $this->units)];

        try {
            $agreement = $save->handle($this->actor(), $this->agreementId ? Agreement::findOrFail($this->agreementId) : null, $data);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            // units.* keys match this component's $units property; header keys live under form.*.
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'units') ? $k : "form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('agreements.show', $agreement, navigate: true);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $term = trim($this->customerSearch);

        return view('livewire.agreements.form', [
            'customerResults' => mb_strlen($term) < 2 ? collect() : Customer::query()->visibleTo($actor)
                ->where(fn ($q) => $q->where('name_en', 'like', '%'.$term.'%')->orWhere('id_number', $term)->orWhere('mobile', $term))
                ->orderBy('name_en')->limit(8)->get(['id', 'name_en', 'id_number', 'mobile']),
            'buildings' => Building::query()->visibleTo($actor)->orderBy('name')->get(['id', 'code', 'name']),
            'pickableUnits' => $this->pickBuilding
                ? Unit::query()->visibleTo($actor)->where('building_id', $this->pickBuilding)->orderBy('code')->get(['id', 'code', 'list_rent'])
                : collect(),
            'frequencies' => PaymentFrequency::cases(),
            'chargeTypes' => ChargeType::cases(),
            'taxCategories' => TaxCategory::cases(),
            'templates' => ContractTemplate::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ])->title($this->agreementId ? __('Edit draft agreement') : __('New agreement'));
    }
}
