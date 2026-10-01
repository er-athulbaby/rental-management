<?php

namespace App\Livewire\Customers;

use App\Billing\CustomerStatement;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Statement extends Component
{
    use WithActor;

    #[Locked]
    public int $customerId;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(Customer $customer): void
    {
        abort_unless($this->actor()->can('finance.view') && Customer::visibleTo($this->actor())->whereKey($customer->id)->exists(), 403);
        $this->customerId = $customer->id;
        $this->from = $this->from ?: now('Asia/Bahrain')->startOfYear()->toDateString();
        $this->to = $this->to ?: now('Asia/Bahrain')->toDateString();
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public function render(): View
    {
        $customer = Customer::findOrFail($this->customerId);
        $valid = $this->isDate($this->from) && $this->isDate($this->to) && $this->from <= $this->to;

        return view('livewire.customers.statement', [
            'customer' => $customer,
            'valid' => $valid,
            'receivables' => $valid ? CustomerStatement::receivables($customer, $this->from, $this->to) : null,
            'deposits' => $valid ? CustomerStatement::deposits($customer, $this->from, $this->to) : [],
        ])->title(__('Statement — :n', ['n' => $customer->name_en]));
    }
}
