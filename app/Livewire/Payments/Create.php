<?php

namespace App\Livewire\Payments;

use App\Actions\Payments\RecordPayment;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Record payment')]
class Create extends Component
{
    use WithActor;

    #[Locked]
    public int $customerId;

    /** @var array<string, mixed> */
    public array $form = ['method' => 'bank_transfer'];

    /** @var array<int|string, string|null> invoice id => BHD typed; all blank = oldest first */
    public array $split = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', Payment::class), 403);
        $customer = Customer::query()->visibleTo($this->actor())->findOrFail(request()->integer('customer'));
        $this->customerId = $customer->id;
        $this->form['received_on'] = now('Asia/Bahrain')->toDateString();
    }

    public function save(RecordPayment $record): void
    {
        $allocations = collect($this->split)
            ->filter(fn ($amount) => filled($amount))
            ->map(fn ($amount, $invoiceId) => ['invoice_id' => (int) $invoiceId, 'amount' => (string) $amount])
            ->values()->all();

        try {
            $payment = $record->handle($this->actor(), Customer::findOrFail($this->customerId), [...$this->form, 'allocations' => $allocations]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'allocations') ? 'allocations' : "form.$k" => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Payment :n recorded.', ['n' => $payment->number]));
        $this->redirectRoute('payments.show', $payment, navigate: true);
    }

    public function render(): View
    {
        $customer = Customer::findOrFail($this->customerId);

        return view('livewire.payments.create', [
            'customer' => $customer,
            'methods' => PaymentMethod::manual(),
            'open' => Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Issued)
                ->where('type', '!=', InvoiceType::CreditNote)->where('balance', '>', 0)->orderBy('due_date')->orderBy('id')->get(),
        ]);
    }
}
