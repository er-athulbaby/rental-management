<?php

namespace App\Livewire\Concerns;

use App\Models\Building;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/** A "Building" filter for list pages (alongside WithActor): pair with <x-building-filter /> and the model's inBuilding scope. */
trait FiltersByBuilding
{
    #[Url]
    public ?int $building = null;

    /** @return Collection<int, Building> */
    #[Computed]
    public function buildings(): Collection
    {
        return Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']);
    }
}
