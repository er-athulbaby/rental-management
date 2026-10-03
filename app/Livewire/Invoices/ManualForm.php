<?php

namespace App\Livewire\Invoices;

use App\Actions\Billing\SaveManualInvoice;
use App\Enums\InvoiceChargeType;
use App\Enums\TaxCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Unit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Manual invoice')]
class ManualForm extends Component
{
    use WithActor;

    #[Locked]
    public ?int $invoiceId = null;

    #[Locked]
    public int $customerId;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    public function mount(?Invoice $invoice = null): void
    {
        if ($invoice?->exists) {
            abort_unless($this->actor()->can('update', $invoice) && $invoice->type->value === 'manual', 403);
            $this->invoiceId = $invoice->id;
            $this->customerId = $invoice->customer_id;
            $this->form = ['due_date' => $invoice->due_date->toDateString()];
            $this->lines = $invoice->lines->map(fn ($l) => [
                'description' => $l->description, 'charge_type' => $l->charge_type->value, 'unit_id' => $l->unit_id,
                'net' => $l->net, 'tax_category' => $l->tax_category->value,
            ])->all();

            return;
        }

        abort_unless($this->actor()->can('create', Invoice::class), 403);
        $customer = Customer::query()->visibleTo($this->actor())->find(request()->integer('customer'));
        if ($customer === null) {
            // No customer chosen yet: the Invoices list's New invoice button asks for one first.
            $this->redirectRoute('invoices.index', navigate: true);

            return;
        }
        $this->customerId = $customer->id;
        $this->form = ['due_date' => now('Asia/Bahrain')->toDateString()];
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->lines[] = ['description' => '', 'charge_type' => 'other', 'unit_id' => null, 'net' => '', 'tax_category' => 'standard'];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(SaveManualInvoice $save): void
    {
        try {
            $invoice = $save->handle($this->actor(), $this->invoiceId ? Invoice::findOrFail($this->invoiceId) : null, [
                'customer_id' => $this->customerId,
                'due_date' => $this->form['due_date'] ?? null,
                'lines' => array_map(fn (array $l) => [...$l, 'unit_id' => filled($l['unit_id'] ?? null) ? (int) $l['unit_id'] : null], $this->lines),
            ]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'lines') ? $k : "form.$k" => $m])->all());
        }

        $this->redirectRoute('invoices.show', $invoice, navigate: true);
    }

    public function render(): View
    {
        $customer = Customer::findOrFail($this->customerId);

        return view('livewire.invoices.manual-form', [
            'customer' => $customer,
            'chargeTypes' => InvoiceChargeType::manual(),
            'taxCategories' => TaxCategory::cases(),
            // Units this customer has rented, for owner attribution (spec §6.5); any unit is accepted by the Action.
            'units' => Unit::query()->whereHas('agreementUnits.agreement', fn ($q) => $q->where('customer_id', $customer->id))
                ->with('building:id,code')->orderBy('code')->get(),
        ]);
    }
}
