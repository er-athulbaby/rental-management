<?php

namespace App\Livewire\Portal;

use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\Trade;
use App\Livewire\Concerns\InPortal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Spec 2026-10-18 tenant portal §4: the tenant's new request, saved through LogMaintenanceRequest::forPortal (plan ruling 14). */
#[Layout('layouts::portal')]
class NewMaintenanceRequest extends Component
{
    use InPortal, WithFileUploads;

    private const array NEW_FORM = ['place' => '', 'category' => 'general', 'description' => '', 'entry_permitted' => false];

    private const array FORM_KEYS = ['place', 'category', 'description', 'entry_permitted'];

    // Untyped on purpose: a tampered payload reaches forPortal's validation instead of a TypeError (a 500).
    public mixed $form = self::NEW_FORM;

    public mixed $photos = [];

    public function save(LogMaintenanceRequest $log): void
    {
        $this->customerId(); // an owner-only account has no places: a 404, never a 500 (owner portal plan ruling 1)

        try {
            $request = $log->forPortal($this->account(), is_array($this->form) ? $this->form : [], $this->photos);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn ($m, $k) => [in_array($k, self::FORM_KEYS, true) ? "form.{$k}" : $k => $m])->all());
        }

        $this->redirectRoute('portal.maintenance.show', ['maintenance' => $request->id]);
    }

    public function render(): View
    {
        return view('livewire.portal.new-maintenance-request', [
            'places' => LogMaintenanceRequest::portalPlaces($this->customerId()),
            'categories' => Trade::cases(),
        ])->title(__('New maintenance request'));
    }
}
