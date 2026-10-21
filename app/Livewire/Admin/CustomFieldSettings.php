<?php

namespace App\Livewire\Admin;

use App\Actions\CustomFields\DeleteCustomField;
use App\Actions\CustomFields\MoveCustomField;
use App\Actions\CustomFields\SaveCustomField;
use App\Actions\CustomFields\SetCustomFieldArchived;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\CustomField;
use App\Models\CustomFieldChoice;
use App\Models\CustomFieldValue;
use Closure;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec 2026-10-21 custom fields §3: Administration → Custom fields, one tab per record kind. Ids are looked up inside the tab's kind (plan ruling 14). */
class CustomFieldSettings extends Component
{
    use WithActor;

    #[Url]
    public string $entity = 'customer';

    #[Locked]
    public ?int $editingId = null;

    public bool $editing = false;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<int, array<string, mixed>> */
    public array $choices = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::SettingsManage), 403);
    }

    public function updatedEntity(): void
    {
        $this->cancel();
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->form = ['label_en' => '', 'label_ar' => '', 'type' => CustomFieldType::Text->value, 'help' => '', 'required' => false, 'in_portal' => false];
        $this->choices = [];
        $this->editing = true;
    }

    public function edit(mixed $id): void
    {
        $field = $this->field($id);
        $this->resetErrorBag();
        $this->editingId = $field->id;
        $this->form = ['label_en' => $field->label_en, 'label_ar' => $field->label_ar ?? '', 'type' => $field->type->value, 'help' => $field->help ?? '',
            'required' => $field->required, 'in_portal' => $field->in_portal];
        $this->choices = $field->choices->map(fn (CustomFieldChoice $c) => ['id' => $c->id, 'label_en' => $c->label_en, 'label_ar' => $c->label_ar ?? '',
            'hidden' => $c->hidden_at !== null])->values()->all();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'editing', 'form', 'choices');
        $this->resetErrorBag();
    }

    public function addChoice(): void
    {
        $this->choices[] = ['id' => null, 'label_en' => '', 'label_ar' => '', 'hidden' => false];
    }

    public function removeChoice(mixed $index): void
    {
        if (is_int($index) || (is_string($index) && ctype_digit($index))) {
            unset($this->choices[(int) $index]);
            $this->choices = array_values($this->choices);
        }
    }

    public function save(SaveCustomField $save): void
    {
        abort_unless($this->editing, 404);
        try {
            $save->handle($this->actor(), $this->editingId !== null ? $this->field($this->editingId) : null,
                [...$this->form, 'entity' => $this->kind()->value, 'choices' => $this->choices]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'choices') ? $k : "form.$k" => $m])->all());
        }

        $this->cancel();
        Flux::toast(variant: 'success', text: __('Field saved.'));
    }

    public function move(mixed $id, mixed $by, MoveCustomField $move): void
    {
        abort_unless(in_array($by, [-1, 1, '-1', '1'], true), 404);
        $this->run(fn () => $move->handle($this->actor(), $this->field($id), (int) $by));
    }

    public function archive(mixed $id, SetCustomFieldArchived $set): void
    {
        $this->run(fn () => $set->handle($this->actor(), $this->field($id), true), __('Field archived.'));
    }

    public function restore(mixed $id, SetCustomFieldArchived $set): void
    {
        $this->run(fn () => $set->handle($this->actor(), $this->field($id), false), __('Field restored.'));
    }

    public function delete(mixed $id, DeleteCustomField $delete): void
    {
        $this->run(fn () => $delete->handle($this->actor(), $this->field($id)), __('Field deleted.'));
    }

    private function run(Closure $action, ?string $done = null): void
    {
        try {
            $action();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            $this->addError('list', (string) collect($e->errors())->flatten()->first());

            return;
        }

        if ($done !== null) {
            Flux::toast(variant: 'success', text: $done);
        }
    }

    private function kind(): CustomFieldEntity
    {
        return CustomFieldEntity::tryFrom($this->entity) ?? abort(404);
    }

    private function field(mixed $id): CustomField
    {
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 404);

        return CustomField::query()->where('entity', $this->kind())->with('choices')->findOrFail((int) $id);
    }

    public function render(): View
    {
        $kind = $this->kind();

        return view('livewire.admin.custom-field-settings', [
            'kinds' => CustomFieldEntity::cases(),
            'kind' => $kind,
            'types' => CustomFieldType::cases(),
            'fields' => CustomField::query()->where('entity', $kind)->withExists('values')
                ->orderByRaw('archived_at IS NOT NULL')->orderBy('position')->orderBy('id')->get(),
            'typeLocked' => $this->editingId !== null && CustomFieldValue::query()->where('custom_field_id', $this->editingId)->exists(),
        ])->title(__('Custom fields'));
    }
}
