<?php

namespace App\Livewire\Admin\ContractTemplates;

use App\Models\ContractTemplate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Contract templates')]
class Index extends Component
{
    public function render(): View
    {
        return view('livewire.admin.contract-templates.index', [
            'templates' => ContractTemplate::query()->withCount('clauses')->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }
}
