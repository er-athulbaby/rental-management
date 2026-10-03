<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\WithActor;
use App\Models\Facility;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** The Admin-managed list of building facilities. A facility is switched off rather than deleted, so buildings keep their history. */
class Facilities extends Component
{
    use WithActor;

    public string $name = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
    }

    public function add(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $this->name = trim($this->name);
        $this->validate(['name' => ['required', 'string', 'max:80', Rule::unique('facilities', 'name')]]);

        Facility::create(['name' => $this->name]);
        $this->reset('name');
        Flux::toast(variant: 'success', text: __('Facility added.'));
    }

    public function rename(int $id, string $name): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $name = trim($name);
        validator(['name' => $name], ['name' => ['required', 'string', 'max:80', Rule::unique('facilities', 'name')->ignore($id)]])->validate();

        Facility::query()->findOrFail($id)->update(['name' => $name]);
    }

    public function toggle(int $id): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $facility = Facility::query()->findOrFail($id);
        $facility->update(['active' => ! $facility->active]);
    }

    public function render(): View
    {
        return view('livewire.admin.facilities', [
            'facilities' => Facility::query()->withCount('buildings')->orderByDesc('active')->orderBy('name')->get(),
        ])->title(__('Facilities'));
    }
}
