<?php

namespace App\Actions\Buildings;

use App\Audit\Audit;
use App\Enums\BuildingType;
use App\Enums\ParkingType;
use App\Models\Building;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveBuilding
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Building $building, array $data): Building
    {
        if (! ($building ? $actor->can('update', $building) : $actor->can('create', Building::class))) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'code' => [$building ? 'required' : 'nullable', 'string', 'max:30', Rule::unique('buildings', 'code')->ignore($building?->id)],
            'location' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::enum(BuildingType::class)],
            'floors_count' => ['nullable', 'integer', 'between:0,200'],
            'parking' => ['nullable', Rule::enum(ParkingType::class)],
            'facility_ids' => ['nullable', 'array'],
            // A switched-off facility can't be newly ticked, but a building that already has it keeps it.
            'facility_ids.*' => ['integer', 'distinct', Rule::exists('facilities', 'id')->where(fn ($q) => $q->where('active', true)
                ->orWhereIn('id', $building ? $building->facilities()->pluck('facilities.id') : []))],
            'property_manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($building, $validated) {
            $validated['code'] ??= self::nextCode();
            $facilityIds = array_key_exists('facility_ids', $validated) ? array_map('intval', $validated['facility_ids'] ?? []) : null;
            unset($validated['facility_ids']);

            $building ??= new Building;
            $building->fill($validated)->save(); // LogsActivity records old/new

            // Only when the caller sent the list: leaving the field out keeps the building's facilities.
            // The pivot isn't an attribute, so its changes are audited here.
            if ($facilityIds !== null && array_filter($building->facilities()->sync($facilityIds)) !== []) {
                Audit::log('building.facilities_changed', $building, properties: ['facilities' => $building->facilities()->pluck('name')->all()]);
            }

            return $building;
        });
    }

    /** A new building left without a code gets the next B-number (B001, B002, …); hand-typed codes are ignored. */
    private static function nextCode(): string
    {
        // Locking the B-codes serialises two people adding buildings at once; the unique index is the backstop.
        $max = Building::withTrashed()->where('code', 'regexp', '^B[0-9]+$')->lockForUpdate()
            ->pluck('code')->map(fn (string $code) => (int) substr($code, 1))->max() ?? 0;

        return sprintf('B%03d', $max + 1);
    }
}
