<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Customer;
use App\Models\Owner;
use App\Reports\Queries;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec §10, plan ruling 7: customer and owner ID or CR copies expiring, by name only — never the ID number. */
class IdDocumentsReport extends Component
{
    use ReportPage;

    #[Url]
    public string $window = '30';

    protected function title(): string
    {
        return __('ID documents expiring');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function buildingFilter(): bool
    {
        return false; // its rows are not filtered by building
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
        return ['name' => __('Name'), 'kind' => __('Kind'), 'document' => __('Document'), 'expires' => __('Expires'), 'days' => __('Days left')];
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
        $rows = [];
        foreach (Queries::expiringIdDocuments($this->actor(), (int) $this->window) as $d) {
            $who = $d->documentable;
            if (! ($who instanceof Customer || $who instanceof Owner) || $d->expires_on === null) {
                continue; // the query only returns dated customer and owner documents
            }
            $rows[] = [
                '_url' => $who instanceof Customer ? route('customers.edit', $who) : route('owners.edit', $who),
                'name' => $who->name_en,
                'kind' => $who instanceof Customer ? __('Customer') : __('Owner'),
                'document' => $d->category->label(),
                'expires' => $d->expires_on->format('d/m/Y'),
                'days' => (int) $today->diffInDays($d->expires_on, true),
            ];
        }

        return $rows;
    }
}
