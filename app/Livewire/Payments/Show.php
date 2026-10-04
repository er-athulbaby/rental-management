<?php

namespace App\Livewire\Payments;

use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Payments\RequestPaymentReversal;
use App\Enums\ApprovalAction;
use App\Livewire\Concerns\WithActor;
use App\Models\Approval;
use App\Models\Disbursement;
use App\Models\Payment;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $paymentId;

    public string $reversalReason = '';

    /** @var array<string, string> */
    public array $refund = ['amount' => '', 'method' => 'bank_transfer', 'paid_on' => '', 'reference' => '', 'cheque_no' => '', 'bank_name' => '', 'cheque_date' => ''];

    public function mount(Payment $payment): void
    {
        abort_unless($this->actor()->can('view', $payment), 403);
        $this->paymentId = $payment->id;
        $this->refund['paid_on'] = $this->refund['cheque_date'] = now('Asia/Bahrain')->toDateString();
    }

    protected function payment(): Payment
    {
        return Payment::with(['customer', 'recorder:id,name', 'allocations.line.invoice', 'tenders'])->findOrFail($this->paymentId);
    }

    public function requestReversal(RequestPaymentReversal $request): void
    {
        try {
            $request->handle($this->actor(), $this->payment(), $this->reversalReason);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            // The modal only shows the reason field, so surface every message there.
            throw ValidationException::withMessages(['reversalReason' => Arr::flatten($e->errors())]);
        }

        $this->reset('reversalReason');
        Flux::modal('reverse-payment')->close();
        Flux::toast(variant: 'success', text: __('Reversal sent for approval.'));
    }

    public function refundCredit(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->refund, 'purpose' => 'credit_refund', 'payment_id' => $this->paymentId]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["refund.$k" => $m])->all());
        }

        $this->redirectRoute('disbursements.show', $out, navigate: true);
    }

    public function render(): View
    {
        $payment = $this->payment();

        return view('livewire.payments.show', [
            'payment' => $payment,
            'credit' => Fils::toDecimal($payment->unallocatedFils()),
            'canRefund' => $this->actor()->can('create', Disbursement::class) && $payment->unallocatedFils() > 0,
            'canReverse' => $this->actor()->can('reverse', $payment) && $payment->status->value === 'confirmed' && $payment->method->value !== 'deposit_applied' && $payment->method->value !== 'cheque',
            'pendingReversal' => Approval::query()->pending()->where('approvable_type', $payment->getMorphClass())
                ->where('approvable_id', $payment->id)->where('action', ApprovalAction::PaymentReversal)->exists(),
        ])
            ->title($payment->number);
    }
}
