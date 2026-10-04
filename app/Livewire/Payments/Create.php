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
use App\Support\Fils;
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

    /** @var list<array<string, string|null>> the parts when Method is Split (card + cash on one receipt) */
    public array $tenders = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', Payment::class), 403);
        $customer = Customer::query()->visibleTo($this->actor())->find(request()->integer('customer'));
        if ($customer === null) {
            // No customer chosen yet: the Payments list's New payment button asks for one first.
            $this->redirectRoute('payments.index', navigate: true);

            return;
        }
        $this->customerId = $customer->id;
        $this->form['received_on'] = now('Asia/Bahrain')->toDateString();
    }

    /** Choosing Split starts with card + cash rows. $key is null when Livewire replaces the whole form. */
    public function updatedForm(mixed $value, ?string $key = null): void
    {
        if ($key === 'method' && $value === PaymentMethod::Split->value && $this->tenders === []) {
            $this->tenders = [['method' => 'card', 'amount' => '', 'reference' => ''], ['method' => 'cash', 'amount' => '', 'reference' => '']];
        }
    }

    public function addTender(): void
    {
        $this->tenders[] = ['method' => 'bank_transfer', 'amount' => '', 'reference' => ''];
    }

    public function removeTender(int $index): void
    {
        $tenders = $this->tenders;
        unset($tenders[$index]);
        $this->tenders = array_values($tenders);
    }

    public function save(RecordPayment $record): void
    {
        $allocations = collect($this->split)
            ->filter(fn ($amount) => filled($amount))
            ->map(fn ($amount, $invoiceId) => ['invoice_id' => (int) $invoiceId, 'amount' => (string) $amount])
            ->values()->all();

        try {
            $payment = $record->handle($this->actor(), Customer::findOrFail($this->customerId), $this->isSplit()
                ? [...$this->form, 'method' => null, 'amount' => null, 'reference' => null, 'tenders' => $this->tenders, 'allocations' => $allocations]
                : [...$this->form, 'allocations' => $allocations]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn ($m, $k) => [match (true) {
                    str_starts_with($k, 'allocations') => 'allocations', str_starts_with($k, 'tenders') => $k, default => "form.$k"
                } => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Payment :n recorded.', ['n' => $payment->number]));
        $this->redirectRoute('payments.show', $payment, navigate: true);
    }

    private function isSplit(): bool
    {
        return ($this->form['method'] ?? null) === PaymentMethod::Split->value;
    }

    /** The running total of the parts, or null while an amount is not a valid BHD figure. */
    private function tendersTotal(): ?string
    {
        $sum = 0;
        foreach ($this->tenders as $t) {
            $amount = trim((string) ($t['amount'] ?? ''));
            if ($amount === '') {
                continue;
            }
            if (! preg_match('/^\d+(\.\d{1,3})?$/', $amount)) {
                return null;
            }
            $sum += Fils::fromDecimal($amount);
        }

        return Fils::toDecimal($sum);
    }

    public function render(): View
    {
        $customer = Customer::findOrFail($this->customerId);

        return view('livewire.payments.create', [
            'customer' => $customer,
            'methods' => PaymentMethod::manual(),
            'isSplit' => $this->isSplit(),
            'tendersTotal' => $this->isSplit() ? $this->tendersTotal() : null,
            'open' => Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Issued)
                ->where('type', '!=', InvoiceType::CreditNote)->where('balance', '>', 0)->orderBy('due_date')->orderBy('id')->get(),
        ]);
    }
}
