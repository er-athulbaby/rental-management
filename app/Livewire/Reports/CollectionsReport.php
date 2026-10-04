<?php

namespace App\Livewire\Reports;

use App\Enums\PaymentMethod;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Payment;
use App\Models\PaymentTender;
use App\Reports\Queries;
use App\Support\Fils;
use Livewire\Component;

/** Spec §10: collections by date and method; deposit_applied is excluded from the total and shown separately. */
class CollectionsReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Collections by date and method');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.financial');
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['date' => __('Date'), 'method' => __('Method'), 'count' => __('Payments'), 'amount' => __('Amount')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['count', 'amount'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $payments = Queries::collections($this->actor(), $this->from, $this->to, $this->building)->with('tenders')->get(['id', 'received_on', 'method', 'amount']);
        $applied = $payments->filter(fn ($p) => $p->method === PaymentMethod::DepositApplied);
        $collected = $payments->reject(fn ($p) => $p->method === PaymentMethod::DepositApplied);

        // A split payment counts under each of its parts, so the cash and card totals match the drawer and the card machine.
        $parts = $collected->flatMap(fn (Payment $p): array => $p->method === PaymentMethod::Split
            ? $p->tenders->map(fn (PaymentTender $t): Payment => (new Payment)->forceFill(['received_on' => $p->received_on, 'method' => $t->method, 'amount' => $t->amount]))->all()
            : [$p]);

        $rows = array_values($parts->groupBy(fn (Payment $p) => $p->received_on->toDateString().'|'.$p->method->label())->sortKeys()
            ->map(fn ($group) => [
                'date' => $group->firstOrFail()->received_on->format('d/m/Y'),
                'method' => $group->firstOrFail()->method->label(),
                'count' => $group->count(),
                'amount' => Fils::toDecimal($group->sum(fn ($p) => Fils::fromDecimal($p->amount))),
            ])->all());

        $rows[] = ['date' => '', 'method' => __('Total collected'), 'count' => $collected->count(), 'amount' => Fils::toDecimal($collected->sum(fn ($p) => Fils::fromDecimal($p->amount)))];
        if ($applied->isNotEmpty()) {
            $rows[] = ['date' => '', 'method' => __('Deposit applied (not a collection)'), 'count' => $applied->count(), 'amount' => Fils::toDecimal($applied->sum(fn ($p) => Fils::fromDecimal($p->amount)))];
        }

        return $rows;
    }
}
