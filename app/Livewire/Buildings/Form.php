<?php

namespace App\Livewire\Buildings;

use App\Actions\Buildings\SaveBuilding;
use App\Enums\BuildingType;
use App\Enums\ParkingType;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Facility;
use App\Models\User;
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
    public ?int $buildingId = null;

    /** @var array<string, mixed> */
    public array $form = ['type' => 'residential', 'parking' => null, 'facility_ids' => []];

    public function mount(?Building $building = null): void
    {
        if ($building?->exists) {
            abort_unless($this->actor()->can('view', $building), 403);
            $this->buildingId = $building->id;
            $this->form = $building->only(['name', 'code', 'location', 'address', 'floors_count', 'property_manager_user_id', 'notes'])
                + ['type' => $building->type->value, 'parking' => $building->parking?->value,
                    'facility_ids' => $building->facilities()->pluck('facilities.id')->map(fn ($id) => (string) $id)->all()];
        } else {
            abort_unless($this->actor()->can('create', Building::class), 403);
        }
    }

    public function save(SaveBuilding $save): void
    {
        try {
            $building = $save->handle($this->actor(), $this->buildingId ? Building::findOrFail($this->buildingId) : null, $this->form);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Building saved.'));
        $this->redirectRoute('buildings.edit', $building, navigate: true);
    }

    public function render(): View
    {
        $building = $this->buildingId ? Building::findOrFail($this->buildingId) : null;

        return view('livewire.buildings.form', [
            'building' => $building,
            'types' => BuildingType::cases(),
            'parkingTypes' => ParkingType::cases(),
            // Active facilities, plus any switched-off one this building already has.
            'facilities' => Facility::query()->where('active', true)
                ->orWhereIn('id', $building ? $building->facilities()->pluck('facilities.id') : [])
                ->orderBy('name')->get(['id', 'name', 'active']),
            'managers' => User::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'canEdit' => $building ? $this->actor()->can('update', $building) : true,
        ])->title($building ? $building->name : __('New building'));
    }
}
