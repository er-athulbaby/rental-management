<?php

namespace App\Livewire\Units;

use App\Actions\Units\SaveUnit;
use App\Enums\Furnishing;
use App\Enums\TaxCategory;
use App\Enums\UnitUse;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Unit;
use App\Models\UnitType;
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
    public ?int $unitId = null;

    /** @var array<string, mixed> */
    public array $form = ['use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'blocked' => false];

    public function mount(?Unit $unit = null): void
    {
        if ($unit?->exists) {
            abort_unless($this->actor()->can('view', $unit), 403);
            $this->unitId = $unit->id;
            $this->form = [
                ...$unit->only(['building_id', 'code', 'floor', 'bedrooms', 'bathrooms', 'area_sqm', 'list_rent', 'list_deposit', 'list_service_charge', 'ewa_account_no', 'blocked', 'blocked_reason', 'notes']),
                'use' => $unit->use->value,
                'type' => $unit->type,
                'furnishing' => $unit->furnishing->value,
                'default_tax_category' => $unit->default_tax_category?->value,
            ];
        } else {
            abort_unless($this->actor()->can('create', Unit::class), 403);
        }
    }

    /** Picking a type fills in its usual use (a shop is commercial); the use can still be changed. $key is null when Livewire replaces the whole form. */
    public function updatedForm(mixed $value, ?string $key = null): void
    {
        if ($key === 'type' && ($use = UnitType::query()->where('code', $value)->first()?->default_use)) {
            $this->form['use'] = $use->value;
        }
    }

    public function save(SaveUnit $save): void
    {
        try {
            $unit = $save->handle($this->actor(), $this->unitId ? Unit::findOrFail($this->unitId) : null, $this->form);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Unit saved.'));
        $this->redirectRoute('units.edit', $unit, navigate: true);
    }

    public function render(): View
    {
        $unit = $this->unitId ? Unit::findOrFail($this->unitId) : null;

        return view('livewire.units.form', [
            'unit' => $unit,
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
            'uses' => UnitUse::cases(),
            'types' => UnitType::choices($unit?->type),
            'furnishings' => Furnishing::cases(),
            'taxCategories' => TaxCategory::cases(),
            'canEdit' => $unit ? $this->actor()->can('update', $unit) : true,
        ])->title($unit ? __('Unit :code', ['code' => $unit->code]) : __('New unit'));
    }
}
