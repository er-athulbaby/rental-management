<?php

namespace App\Livewire\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Livewire\Concerns\FiltersByBuilding;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\Fils;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Payments')]
class Index extends Component
{
    use FiltersByBuilding, WithActor, WithPagination;

    #[Url]
    public string $search = '';

    public string $customerSearch = '';

    public function updating(string $property): void
    {
        if ($property !== 'customerSearch') {
            $this->resetPage();
        }
    }

    /** A payment is received from one customer: pick them, then record it against their invoices. */
    public function startPayment(int $customerId): void
    {
        abort_unless($this->actor()->can('create', Payment::class), 403);
        $customer = Customer::query()->visibleTo($this->actor())->findOrFail($customerId);

        $this->redirectRoute('payments.create', ['customer' => $customer->id], navigate: true);
    }

    /** @return Builder<Payment> the list's filters: visible tenants, building and search */
    private function filtered(): Builder
    {
        return Payment::query()
            ->whereIn('customer_id', Customer::query()->visibleTo($this->actor())->select('id'))
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhere('reference', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))));
    }

    /**
     * Design pass §5: collected this month as the dashboard counts it (confirmed, deposit-applied left out, from the 1st to
     * today), under the list's filters. One aggregate query.
     *
     * @return array{collected: string, receipts: int}
     */
    private function stats(): array
    {
        $row = (array) ($this->filtered()
            ->where('status', PaymentStatus::Confirmed)->where('method', '!=', PaymentMethod::DepositApplied)
            ->whereBetween('received_on', [now('Asia/Bahrain')->startOfMonth()->toDateString(), now('Asia/Bahrain')->toDateString()])
            ->toBase()->selectRaw('COUNT(*) AS receipt_count, COALESCE(SUM(amount), 0) AS collected')->first() ?? []);

        return [
            'collected' => Fils::toDecimal(Fils::fromDecimal((string) ($row['collected'] ?? '0'))),
            'receipts' => (int) ($row['receipt_count'] ?? 0),
        ];
    }

    public function render(): View
    {
        $payments = $this->filtered()
            ->with(['customer:id,name_en', 'tenders'])
            ->latest('received_on')->latest('id')
            ->paginate(50);

        return view('livewire.payments.index', [
            'payments' => $payments,
            'stats' => $this->stats(),
            'canCreate' => $canCreate = $this->actor()->can('create', Payment::class),
            'customerResults' => $canCreate ? Customer::findFor($this->actor(), $this->customerSearch) : collect(),
        ]);
    }
}
