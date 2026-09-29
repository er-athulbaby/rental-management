<?php

namespace App\Livewire\Admin;

use App\Actions\Settings\UpdateCompanySettings;
use App\Enums\ProrationBasis;
use App\Enums\TaxCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\CompanySetting;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Company settings')]
class CompanySettings extends Component
{
    use WithActor, WithFileUploads;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var UploadedFile|null */
    public $logo = null;

    public function mount(): void
    {
        $settings = CompanySetting::current();
        $this->form = Arr::only($settings->attributesToArray(), CompanySetting::EDITABLE);
    }

    public function save(UpdateCompanySettings $update): void
    {
        try {
            $update->handle($this->actor(), $this->form, $this->logo);
        } catch (ValidationException $e) {
            // Prefix field names so errors appear next to wire:model="form.x".
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => [$key === 'logo' ? 'logo' : "form.$key" => $messages])->all()
            );
        }

        $this->reset('logo');
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function render(): View
    {
        return view('livewire.admin.company-settings', [
            'taxCategories' => TaxCategory::cases(),
            'prorationBases' => ProrationBasis::cases(),
        ]);
    }
}
