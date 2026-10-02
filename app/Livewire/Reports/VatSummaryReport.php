<?php

namespace App\Livewire\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OwnerChargeType;
use App\Enums\TaxCategory;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Customer;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Spec §10: output VAT by category from issued invoices and credit notes (by issue date), plus VAT on management fees (by posted_at). */
class VatSummaryReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('VAT summary');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.financial');
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['source' => __('Source'), 'category' => __('Tax category'), 'net' => __('Net'), 'vat' => __('VAT')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['net', 'vat'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $lines = fn (bool $credit) => DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.status', InvoiceStatus::Issued->value)->where('i.type', $credit ? '=' : '<>', InvoiceType::CreditNote->value)
            ->whereBetween('i.issue_date', [$this->from, $this->to])
            ->whereIn('i.customer_id', Customer::query()->visibleTo($this->actor())->select('id'))
            ->when($this->building, fn ($q, $b) => $q->whereIn('l.unit_id', Unit::query()->where('building_id', $b)->select('id')))
            ->groupBy('l.tax_category')->selectRaw('l.tax_category AS category, SUM(l.net) AS net, SUM(l.tax_amount) AS vat')->get();

        $fees = DB::table('owner_charges as c')->where('c.type', OwnerChargeType::ManagementFee->value)
            ->whereBetween('c.posted_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->whereIn('c.owner_contract_id', OwnerContract::visibleTo($this->actor())->when($this->building, fn ($q, $b) => $q->where('building_id', $b))->select('id'))
            ->selectRaw('SUM(c.net) AS net, SUM(c.tax_amount) AS vat')->first();

        $rows = [];
        $total = 0;
        foreach ([[__('Invoices'), $lines(false), 1], [__('Credit notes'), $lines(true), -1]] as [$source, $groups, $sign]) {
            foreach ($groups as $g) {
                $vat = $sign * Fils::fromDecimal((string) $g->vat);
                $total += $vat;
                $rows[] = ['source' => $source, 'category' => TaxCategory::from($g->category)->label(),
                    'net' => Fils::toDecimal($sign * Fils::fromDecimal((string) $g->net)), 'vat' => Fils::toDecimal($vat)];
            }
        }
        if ($fees !== null && $fees->net !== null) {
            $total += Fils::fromDecimal((string) $fees->vat);
            $rows[] = ['source' => __('Management fees'), 'category' => TaxCategory::Standard->label(),
                'net' => Fils::toDecimal(Fils::fromDecimal((string) $fees->net)), 'vat' => Fils::toDecimal(Fils::fromDecimal((string) $fees->vat))];
        }
        $rows[] = ['source' => __('Total output VAT'), 'category' => '', 'net' => '', 'vat' => Fils::toDecimal($total)];

        return $rows;
    }
}
