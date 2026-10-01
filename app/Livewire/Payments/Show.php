<?php

namespace App\Livewire\Payments;

use App\Livewire\Concerns\WithActor;
use App\Models\Payment;
use App\Support\Fils;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $paymentId;

    public function mount(Payment $payment): void
    {
        abort_unless($this->actor()->can('view', $payment), 403);
        $this->paymentId = $payment->id;
    }

    protected function payment(): Payment
    {
        return Payment::with(['customer', 'recorder:id,name', 'allocations.line.invoice'])->findOrFail($this->paymentId);
    }

    public function render(): View
    {
        $payment = $this->payment();

        return view('livewire.payments.show', ['payment' => $payment, 'credit' => Fils::toDecimal($payment->unallocatedFils())])
            ->title($payment->number);
    }
}
