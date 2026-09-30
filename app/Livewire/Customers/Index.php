<?php

namespace App\Livewire\Customers;

use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Customers')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = trim($this->search);

        $customers = Customer::query()
            ->visibleTo($this->actor())
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name_en', 'like', '%'.$term.'%')
                ->orWhere('id_number', $term)
                ->orWhere('mobile', $term)))
            ->orderBy('name_en')
            ->paginate(25);

        // Spec §8.2: a scoped user's exact ID or mobile match outside their scope shows only "Already exists".
        $hint = null;
        if ($term !== '' && ! $this->actor()->can(PermissionName::BuildingsViewAll)) {
            $hidden = Customer::query()
                ->where(fn ($q) => $q->where('id_number', $term)->orWhere('mobile', $term))
                ->whereNotIn('id', Customer::query()->visibleTo($this->actor())->select('id'))
                ->first();
            if ($hidden) {
                $hint = __('Already exists: :name, ID :id', ['name' => $hidden->name_en, 'id' => $hidden->maskedId()]);
            }
        }

        return view('livewire.customers.index', ['customers' => $customers, 'hint' => $hint]);
    }
}
