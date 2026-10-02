<?php

namespace App\Livewire\Reports;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Reports\BuildingProfitability;
use App\Support\Fils;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BuildingProfitabilityReport extends Component
{
    use WithActor;

    #[Url]
    public ?int $building = null;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        $this->from = $this->from ?: now('Asia/Bahrain')->startOfMonth()->toDateString();
        $this->to = $this->to ?: now('Asia/Bahrain')->toDateString();
    }

    /** @return Collection<int, array{building: Building, figures: array{collected: int, fees: int, income: int, billed: int, head_lease: int, expenses: int, costs: int, result: int}}> */
    private function rows(): Collection
    {
        $this->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return Building::visibleTo($this->actor())->when($this->building, fn ($q, $b) => $q->whereKey($b))->orderBy('code')->get()
            ->map(fn (Building $b) => ['building' => $b, 'figures' => BuildingProfitability::for($b, $this->from, $this->to)]);
    }

    public function export(): BinaryFileResponse
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        Audit::log('report.exported', properties: ['report' => 'building_profitability', 'from' => $this->from, 'to' => $this->to, 'building' => $this->building], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-profitability-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        foreach ($this->rows() as $r) {
            $writer->addRow(['Building' => $r['building']->code.' '.$r['building']->name, ...collect($r['figures'])->mapWithKeys(fn (int $v, string $k) => [str($k)->headline()->toString() => Fils::toDecimal($v)])->all()]);
        }
        $writer->close();

        return response()->download($path, "building-profitability-{$this->from}-{$this->to}.xlsx")->deleteFileAfterSend();
    }

    public function render(): View
    {
        return view('livewire.reports.building-profitability', [
            'rows' => $this->rows(),
            'buildings' => Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
        ])->title(__('Building profitability'));
    }
}
