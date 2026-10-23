<?php

namespace App\Livewire\Customers;

use App\CustomFields\CustomFieldFilter;
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use App\Enums\PermissionName;
use App\Livewire\Concerns\FiltersByBuilding;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Tenants')]
class Index extends Component
{
    use FiltersByBuilding, WithActor, WithPagination;

    #[Url]
    public string $search = '';

    /** Spec 2026-10-21 custom fields §5 "More filters": field, op, value, from, to, choices. Mixed and not in the URL, so a tampered value is ignored, never a TypeError (plan ruling 6). */
    public mixed $more = [];

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = trim($this->search);

        $customers = Customer::query()
            ->visibleTo($this->actor())
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($term !== '', fn ($q) => $q->search($term))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Customer, $this->more))
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

        return view('livewire.customers.index', ['customers' => $customers, 'hint' => $hint, 'customFields' => CustomFields::active(CustomFieldEntity::Customer)]);
    }
}
