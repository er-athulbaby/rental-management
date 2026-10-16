<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\StatementPage;
use App\Models\Building;
use App\Reports\IncomeStatement;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spec 2026-10-16 §4: the company income statement. Scope: all of the user's buildings, one building, one owner, or
 * the owner's contracts in one building (plan ruling 9).
 *
 * @phpstan-import-type Statement from IncomeStatement
 */
class IncomeStatementReport extends Component
{
    use StatementPage;

    #[Url]
    public mixed $building = null;

    #[Url]
    public mixed $owner = null;

    /** @return array<string, list<mixed>> */
    protected function scopeRules(): array
    {
        return [
            'building' => ['nullable', 'integer', Rule::in(Building::visibleTo($this->actor())->pluck('id')->all())],
            'owner' => ['nullable', 'integer', Rule::in(array_keys($this->ownerOptions()))],
        ];
    }

    protected function resetScope(): void
    {
        $this->building = null;
        $this->owner = null;
    }

    /** @return Statement */
    private function compute(): array
    {
        [$from, $to] = $this->period();

        return IncomeStatement::company($from, $to, $this->basis, $this->idOrNull($this->building) !== null ? [$this->idOrNull($this->building)] : $this->scopeBuildingIds(), $this->idOrNull($this->owner));
    }

    public function export(): BinaryFileResponse
    {
        $this->validate($this->statementRules());

        return $this->download('income_statement', $this->compute(), ['building' => $this->idOrNull($this->building), 'owner' => $this->idOrNull($this->owner)]);
    }

    public function render(): View
    {
        return view('livewire.reports.income-statement', [
            'statement' => $this->inputsValid() ? $this->compute() : null,
            'months' => $this->monthOptions(),
            'buildings' => Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'owners' => $this->ownerOptions(),
        ])->title(__('Income statement'));
    }
}
