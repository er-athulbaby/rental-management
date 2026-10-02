<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Agreement;
use App\Reports\Queries;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec §10: active agreements expiring in the next 30/60/90 days. */
class ExpiringAgreementsReport extends Component
{
    use ReportPage;

    #[Url]
    public string $window = '30';

    protected function title(): string
    {
        return __('Agreements expiring');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function dateMode(): string
    {
        return 'none';
    }

    /** @return array<string, array<array-key, string>> */
    protected function options(): array
    {
        return ['window' => ['30' => __('Next 30 days'), '60' => __('Next 60 days'), '90' => __('Next 90 days')]];
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['number' => __('Agreement'), 'customer' => __('Customer'), 'units' => __('Units'), 'end' => __('Ends'), 'days' => __('Days left')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['days'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $today = now('Asia/Bahrain')->startOfDay();

        return array_values(Queries::expiringAgreements($this->actor(), (int) $this->window, $this->building)
            ->with(['customer:id,name_en', 'agreementUnits.unit:id,code,building_id', 'agreementUnits.unit.building:id,code'])
            ->orderBy('end_date')->orderBy('id')->get()
            ->map(fn (Agreement $a) => [
                '_url' => route('agreements.show', $a),
                'number' => $a->number,
                'customer' => $a->customer->name_en,
                'units' => $a->agreementUnits->map(fn ($au) => $au->unit->building->code.'/'.$au->unit->code)->implode(', '),
                'end' => $a->end_date->format('d/m/Y'),
                'days' => (int) $today->diffInDays($a->end_date, true),
            ])->all());
    }
}
