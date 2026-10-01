<?php

namespace App\Livewire\DepositSettlements;

use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Enums\DeductionType;
use App\Enums\DisbursementMethod;
use App\Enums\InvoiceStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\DepositSettlement;
use App\Models\Disbursement;
use App\Models\InvoiceLine;
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
    public int $settlementId;

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    /** @var array<string, string> */
    public array $refund = ['amount' => '', 'method' => 'bank_transfer', 'paid_on' => '', 'reference' => '', 'cheque_no' => '', 'bank_name' => '', 'cheque_date' => ''];

    public function mount(DepositSettlement $settlement): void
    {
        abort_unless($this->actor()->can('view', $settlement), 403);
        $this->settlementId = $settlement->id;
        $this->lines = $settlement->lines()->get()->map(fn ($l) => [
            'agreement_unit_id' => $l->agreement_unit_id, 'type' => $l->type->value, 'description' => $l->description,
            'amount' => $l->amount, 'invoice_line_id' => $l->invoice_line_id,
        ])->all();
        $this->refund['paid_on'] = $this->refund['cheque_date'] = now('Asia/Bahrain')->toDateString();
    }

    private function settlement(): DepositSettlement
    {
        return DepositSettlement::with(['agreement.customer', 'units.agreementUnit.unit.building', 'deductionsInvoice', 'payment'])->findOrFail($this->settlementId);
    }

    public function addLine(): void
    {
        $this->lines[] = ['agreement_unit_id' => '', 'type' => 'damage', 'description' => '', 'amount' => '', 'invoice_line_id' => null];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function saveLines(SaveSettlementDeductions $save): void
    {
        try {
            $save->handle($this->actor(), $this->settlement(), array_values($this->lines));
        } catch (AuthorizationException) {
            abort(403);
        }
        Flux::toast(variant: 'success', text: __('Deductions saved.'));
    }

    public function submit(SaveSettlementDeductions $save, SubmitDepositSettlement $submit): void
    {
        try {
            $save->handle($this->actor(), $this->settlement(), array_values($this->lines));
            $submit->handle($this->actor(), $this->settlement());
        } catch (AuthorizationException) {
            abort(403);
        }
        Flux::toast(variant: 'success', text: __('Sent for approval.'));
    }

    public function payRefund(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->refund, 'purpose' => 'deposit_refund', 'deposit_settlement_id' => $this->settlementId]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["refund.$k" => $m])->all());
        }
        $this->redirectRoute('disbursements.show', $out, navigate: true);
    }

    public function render(): View
    {
        $s = $this->settlement();
        $auIds = $s->units->pluck('agreement_unit_id');

        return view('livewire.deposit-settlements.show', [
            's' => $s,
            'canEdit' => $this->actor()->can('update', $s),
            'canRefund' => $this->actor()->can('create', Disbursement::class) && $s->status->value === 'approved',
            'left' => Fils::toDecimal($s->refundFils() - $s->refundedFils()),
            'types' => DeductionType::cases(),
            'methods' => DisbursementMethod::cases(),
            'rentLines' => InvoiceLine::query()->whereIn('agreement_unit_id', $auIds)
                ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatus::Issued)->where('type', '!=', 'credit_note'))
                ->get()->filter(fn (InvoiceLine $l) => $l->balanceFils() > 0)->values(),
        ])->title($s->label());
    }
}
