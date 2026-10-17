<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\StatementPage;
use App\Reports\IncomeStatement;
use App\Reports\Packages\PackageReport;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spec 2026-10-16 §5: one owner's income statement over their contracts in the user's buildings. The owner picker is a
 * search box filtering a select (plan ruling 15).
 *
 * @phpstan-import-type Statement from IncomeStatement
 */
class OwnerIncomeStatementReport extends Component implements PackageReport
{
    use StatementPage;

    // Untyped on purpose, like the trait's props: a tampered payload must reach validation, not throw a TypeError.
    #[Url]
    public mixed $owner = null;

    public mixed $ownerSearch = '';

    /** @return array<string, list<mixed>> */
    protected function scopeRules(): array
    {
        return ['owner' => ['nullable', 'integer', Rule::in(array_keys($this->ownerOptions()))]];
    }

    protected function resetScope(): void
    {
        $this->owner = null;
    }

    protected function title(): string
    {
        return __('Owner income statement');
    }

    /** @return array<string, array<array-key, string>> */
    protected function scopeOptions(): array
    {
        return ['owner' => $this->ownerOptions()];
    }

    /** @return Statement|null */
    protected function compute(): ?array
    {
        $owner = $this->idOrNull($this->owner);
        if ($owner === null) {
            return null;
        }
        [$from, $to] = $this->period();

        return IncomeStatement::owner($owner, $from, $to, $this->basis, $this->scopeBuildingIds());
    }

    public function export(): ?BinaryFileResponse
    {
        $this->validate($this->statementRules());
        $statement = $this->compute();
        if ($statement === null) {
            $this->addError('owner', __('Choose an owner.'));

            return null;
        }

        return $this->download('owner_income_statement', $statement, ['owner' => $this->idOrNull($this->owner)]);
    }

    public function render(): View
    {
        $owners = $this->ownerOptions();
        $term = mb_strtolower(trim(is_string($this->ownerSearch) ? $this->ownerSearch : ''));
        $chosen = $this->idOrNull($this->owner);
        $valid = $this->inputsValid();

        return view('livewire.reports.owner-income-statement', [
            'chosen' => $valid ? $chosen : null,
            'statement' => $valid ? $this->compute() : null,
            'months' => $this->monthOptions(),
            'owners' => $term === '' ? $owners : array_filter($owners,
                fn (string $name, int $id) => $id === $chosen || str_contains(mb_strtolower($name), $term), ARRAY_FILTER_USE_BOTH),
        ])->title(__('Owner income statement'));
    }
}
