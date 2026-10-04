<?php

namespace App\Livewire\Admin;

use App\Enums\UnitUse;
use App\Livewire\Concerns\WithActor;
use App\Models\UnitType;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** The Admin-managed list of unit types. A type is switched off rather than deleted, so units keep theirs. */
class UnitTypes extends Component
{
    use WithActor;

    public string $name = '';

    public string $defaultUse = 'residential';

    public function mount(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
    }

    public function add(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $this->name = trim($this->name);
        $this->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('unit_types', 'name')],
            'defaultUse' => ['nullable', Rule::enum(UnitUse::class)],
        ]);

        UnitType::create(['code' => UnitType::codeFor($this->name), 'name' => $this->name, 'default_use' => $this->defaultUse ?: null]);
        $this->reset('name');
        Flux::toast(variant: 'success', text: __('Unit type added.'));
    }

    public function rename(int $id, string $name): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $name = trim($name);
        validator(['name' => $name], ['name' => ['required', 'string', 'max:60', Rule::unique('unit_types', 'name')->ignore($id)]])->validate();

        UnitType::query()->findOrFail($id)->update(['name' => $name]);
    }

    public function setUse(int $id, string $use): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        validator(['use' => $use], ['use' => ['nullable', Rule::enum(UnitUse::class)]])->validate();

        UnitType::query()->findOrFail($id)->update(['default_use' => $use ?: null]);
    }

    public function toggle(int $id): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $type = UnitType::query()->findOrFail($id);
        $type->update(['active' => ! $type->active]);
    }

    public function render(): View
    {
        return view('livewire.admin.unit-types', [
            'types' => UnitType::query()->withCount('units')->orderByDesc('active')->orderBy('name')->get(),
            'uses' => UnitUse::cases(),
        ])->title(__('Unit types'));
    }
}
