<?php

namespace App\Livewire\Agreements;

use App\Actions\Agreements\SaveAmendment;
use App\Actions\Agreements\SubmitAmendment;
use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use App\Enums\ChargeType;
use App\Enums\PermissionName;
use App\Enums\TaxCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\AgreementAmendment;
use App\Models\Unit;
use App\Policies\AgreementPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AmendmentForm extends Component
{
    use WithActor;

    #[Locked]
    public int $agreementId;

    #[Locked]
    public ?int $amendmentId = null;

    #[Locked]
    public string $type = 'release_unit';

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?Agreement $agreement = null, ?AgreementAmendment $amendment = null): void
    {
        if ($amendment?->exists) {
            abort_unless($amendment->status === AmendmentStatus::Draft, 404);
            $agreement = $amendment->agreement;
            $this->amendmentId = $amendment->id;
            $this->type = $amendment->type->value;
            $this->form = [
                'effective_date' => $amendment->effective_date->toDateString(), 'reason' => $amendment->reason,
                'agreement_unit_id' => $amendment->agreement_unit_id, ...((array) $amendment->data),
            ];
        } else {
            $this->type = (AmendmentType::tryFrom((string) request()->query('type')) ?? abort(404))->value;
            $this->form = ['effective_date' => '', 'reason' => '', 'deposit_amount' => '0',
                'charges' => [['type' => 'rent', 'description' => '', 'monthly_amount' => '', 'tax_category' => 'exempt']]];
        }

        abort_unless($agreement !== null && $this->actor()->can(PermissionName::AgreementsManage) && AgreementPolicy::allUnitsInScope($this->actor(), $agreement), 403);
        $this->agreementId = $agreement->id;
    }

    public function addCharge(): void
    {
        $this->form['charges'][] = ['type' => 'service_charge', 'description' => '', 'monthly_amount' => '', 'tax_category' => 'exempt'];
    }

    /** @return array<string, mixed> */
    private static function prefixed(ValidationException $e): array
    {
        return collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all();
    }

    private function save(SaveAmendment $save): AgreementAmendment
    {
        try {
            $amendment = $save->handle($this->actor(), Agreement::findOrFail($this->agreementId),
                $this->amendmentId ? AgreementAmendment::findOrFail($this->amendmentId) : null, [...($this->type === 'add_unit' ? $this->form : Arr::except($this->form, ['charges', 'deposit_amount', 'unit_id'])), 'type' => $this->type]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(self::prefixed($e));
        }
        $this->amendmentId = $amendment->id;

        return $amendment;
    }

    public function saveDraft(SaveAmendment $save): void
    {
        $this->save($save);
        $this->redirectRoute('agreements.show', $this->agreementId, navigate: true);
    }

    public function submit(SaveAmendment $save, SubmitAmendment $submit): void
    {
        $amendment = $this->save($save);

        try {
            $submit->handle($this->actor(), $amendment);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(self::prefixed($e));
        }

        $this->redirectRoute('agreements.show', $this->agreementId, navigate: true);
    }

    public function render(): View
    {
        $agreement = Agreement::with('agreementUnits.unit.building')->findOrFail($this->agreementId);

        return view('livewire.agreements.amendment-form', [
            'agreement' => $agreement,
            'typeLabel' => AmendmentType::from($this->type)->label(),
            'units' => $this->type === 'add_unit'
                ? Unit::query()->visibleTo($this->actor())->whereNotIn('id', $agreement->agreementUnits->pluck('unit_id'))->with('building:id,code')->orderBy('code')->get()
                : collect(),
            'chargeTypes' => ChargeType::cases(),
            'taxCategories' => TaxCategory::cases(),
        ])->title($agreement->label());
    }
}
