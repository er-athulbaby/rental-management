<?php

namespace App\Livewire\Admin\ContractTemplates;

use App\Actions\ContractTemplates\SaveContractTemplate;
use App\Livewire\Concerns\WithActor;
use App\Models\ContractTemplate;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Edit extends Component
{
    use WithActor;

    #[Locked]
    public ?int $templateId = null;

    public string $name = '';

    public bool $active = true;

    public bool $isDefault = false;

    /** @var list<array{heading_en: string, heading_ar: string, body_en: string, body_ar: string}> */
    public array $clauses = [];

    public function mount(?ContractTemplate $template = null): void
    {
        if ($template?->exists) {
            $this->templateId = $template->id;
            $this->name = $template->name;
            $this->active = $template->active;
            $this->isDefault = $template->is_default;
            foreach ($template->clauses as $c) {
                $this->clauses[] = ['heading_en' => $c->heading_en, 'heading_ar' => $c->heading_ar, 'body_en' => $c->body_en, 'body_ar' => $c->body_ar];
            }
        } else {
            $this->addClause();
        }
    }

    public function addClause(): void
    {
        $this->clauses[] = ['heading_en' => '', 'heading_ar' => '', 'body_en' => '', 'body_ar' => ''];
    }

    public function moveUp(int $index): void
    {
        if ($index > 0 && isset($this->clauses[$index])) {
            [$this->clauses[$index - 1], $this->clauses[$index]] = [$this->clauses[$index], $this->clauses[$index - 1]];
        }
    }

    public function remove(int $index): void
    {
        array_splice($this->clauses, $index, 1);
    }

    public function save(SaveContractTemplate $save): void
    {
        try {
            $template = $save->handle($this->actor(), $this->templateId ? ContractTemplate::findOrFail($this->templateId) : null, [
                'name' => $this->name, 'active' => $this->active, 'is_default' => $this->isDefault, 'clauses' => $this->clauses,
            ]);
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: __('Template saved.'));
        $this->redirectRoute('admin.templates.edit', $template, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.contract-templates.edit', ['fields' => ContractTemplate::MERGE_FIELDS])
            ->title($this->templateId ? $this->name : __('New template'));
    }
}
