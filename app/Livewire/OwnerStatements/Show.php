<?php

namespace App\Livewire\OwnerStatements;

use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Billing\OwnerLedger;
use App\Billing\OwnerStatementCalculator;
use App\Enums\DisbursementMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Disbursement;
use App\Models\OwnerStatement;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $statementId;

    /** @var array<string, mixed> */
    public array $remittance = [];

    public function mount(OwnerStatement $statement): void
    {
        abort_unless($this->actor()->can('view', $statement), 403);
        $this->statementId = $statement->id;
        $this->resetRemittance();
    }

    private function resetRemittance(): void
    {
        $this->remittance = [
            'amount' => Fils::toDecimal(max(0, OwnerLedger::remittableFils($this->statement()->contract))),
            'method' => DisbursementMethod::BankTransfer->value,
            'paid_on' => now('Asia/Bahrain')->toDateString(),
        ];
    }

    public function remit(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->remittance, 'purpose' => 'owner_remittance', 'owner_statement_id' => $this->statementId]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["remittance.$k" => $m])->all());
        }

        $this->resetRemittance();
        Flux::toast(variant: 'success', text: $out->status->value === 'paid' ? __('Paid as :n.', ['n' => $out->number]) : __('Sent to Management for approval.'));
    }

    protected function statement(): OwnerStatement
    {
        return OwnerStatement::with(['contract.owner.bankChanger:id,name', 'contract.building:id,code,name'])->findOrFail($this->statementId);
    }

    public function submit(SubmitOwnerStatement $submit): void
    {
        try {
            $submit->handle($this->actor(), $this->statement());
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: __('Submitted for approval.'));
    }

    public function render(): View
    {
        $statement = $this->statement();
        $figures = OwnerStatementCalculator::stored($statement);
        $balance = $figures['opening'];

        return view('livewire.owner-statements.show', [
            'statement' => $statement,
            'figures' => $figures,
            'rows' => $figures['entries']->map(function (array $e) use (&$balance) {
                $balance += $e['amount'];

                return [...$e, 'balance' => $balance];
            }),
            'canRemit' => $statement->status->value === 'finalised' && $this->actor()->can('create', Disbursement::class),
            'live' => OwnerLedger::balance($statement->contract),
            'methods' => DisbursementMethod::cases(),
            'canSubmit' => $statement->status->value === 'draft' && $this->actor()->can('submit', $statement),
            'approvals' => $statement->approvals()->with(['requester:id,name', 'decider:id,name'])->latest('id')->get(),
        ])->title($statement->label());
    }
}
