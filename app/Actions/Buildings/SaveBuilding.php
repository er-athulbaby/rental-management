<?php

namespace App\Actions\Buildings;

use App\Enums\BuildingType;
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
            'code' => ['required', 'string', 'max:30', Rule::unique('buildings', 'code')->ignore($building?->id)],
            'location' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::enum(BuildingType::class)],
            'floors_count' => ['nullable', 'integer', 'between:0,200'],
            'parking' => ['nullable', 'string', 'max:150'],
            'facilities' => ['nullable', 'string', 'max:1000'],
            'property_manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($building, $validated) {
            $building ??= new Building;
            $building->fill($validated)->save(); // LogsActivity records old/new

            return $building;
        });
    }
}
