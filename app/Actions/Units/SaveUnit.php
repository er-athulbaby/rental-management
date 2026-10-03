<?php

namespace App\Actions\Units;

use App\Enums\Furnishing;
use App\Enums\TaxCategory;
use App\Enums\UnitType;
use App\Enums\UnitUse;
use App\Models\Building;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveUnit
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Unit $unit, array $data): Unit
    {
        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        $buildingId = $unit ? $unit->building_id : (int) ($data['building_id'] ?? 0);

        $validated = Validator::make([...$data, 'building_id' => $buildingId], [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'code' => [$unit ? 'required' : 'nullable', 'string', 'max:30', Rule::unique('units', 'code')->where('building_id', $buildingId)->ignore($unit?->id)],
            // A new unit with no code is numbered from its floor, so the floor is then needed.
            'floor' => $unit || filled($data['code'] ?? null)
                ? ['nullable', 'string', 'max:10']
                : ['required', 'string', 'max:8', 'regex:/^[A-Za-z0-9]+$/'],
            'use' => ['required', Rule::enum(UnitUse::class)],
            'type' => ['required', Rule::enum(UnitType::class)],
            'bedrooms' => ['nullable', 'integer', 'between:0,20'],
            'bathrooms' => ['nullable', 'integer', 'between:0,20'],
            'area_sqm' => ['nullable', 'numeric', 'between:0,999999'],
            'furnishing' => ['required', Rule::enum(Furnishing::class)],
            'list_rent' => ['required', Fils::rule()],
            'list_deposit' => ['nullable', Fils::rule()],
            'list_service_charge' => ['nullable', Fils::rule()],
            'default_tax_category' => ['nullable', Rule::enum(TaxCategory::class)],
            'ewa_account_no' => ['nullable', 'string', 'max:30'],
            'blocked' => ['boolean'],
            'blocked_reason' => ['nullable', 'required_if:blocked,true', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        // Unit writes need the building in scope (spec §8.2) and buildings.manage.
        if (! $actor->can('update', Building::findOrFail($buildingId))) {
            throw new AuthorizationException;
        }

        foreach (['list_rent', 'list_deposit', 'list_service_charge'] as $money) {
            $validated[$money] = Fils::toDecimal(Fils::fromDecimal((string) ($validated[$money] ?? '0')));
        }

        return DB::transaction(function () use ($unit, $validated) {
            $validated['code'] ??= self::nextCode((int) $validated['building_id'], (string) $validated['floor']);
            $unit ??= new Unit;
            $unit->fill($validated)->save();

            return $unit;
        });
    }

    /** A new unit left without a code gets its floor plus the next two-digit number on that floor: 101, 102, G01, 1201. */
    private static function nextCode(int $buildingId, string $floor): string
    {
        Building::query()->lockForUpdate()->findOrFail($buildingId); // one numbering at a time per building

        $taken = Unit::withTrashed()->where('building_id', $buildingId)->where('code', 'like', $floor.'__')->pluck('code')
            ->filter(fn (string $code) => preg_match('/^'.preg_quote($floor, '/').'\d{2}$/', $code) === 1)
            ->map(fn (string $code) => (int) substr($code, strlen($floor)))->max() ?? 0;

        return $floor.str_pad((string) ($taken + 1), 2, '0', STR_PAD_LEFT);
    }
}
