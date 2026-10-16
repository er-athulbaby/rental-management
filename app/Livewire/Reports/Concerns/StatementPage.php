<?php

namespace App\Livewire\Reports\Concerns;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Reports\IncomeStatement;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spec 2026-10-16 §4–§5: the income statements' period (whole months, at most 12), basis, building scope and Excel
 * file. Inputs are whitelisted; a bad one shows a message and no statement, never a 500.
 *
 * @phpstan-import-type Statement from IncomeStatement
 */
trait StatementPage
{
    use WithActor;

    // Untyped on purpose: a tampered payload (an array, "abc") must reach validation, not throw a TypeError (a 500).
    #[Url]
    public mixed $from = '';

    #[Url]
    public mixed $to = '';

    #[Url]
    public mixed $basis = IncomeStatement::COLLECTED;

    /** @return array<string, list<mixed>> the screen's own inputs */
    /** @var array<int, string>|null owner list, memoised for the request */
    private ?array $ownerOptionsCache = null;

    abstract protected function scopeRules(): array;

    /** Clears the screen's own inputs (a bad URL). */
    abstract protected function resetScope(): void;

    /** A validated id (an int, or a numeric string from a select) as an int; anything else is null. */
    protected function idOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    public function mountStatementPage(): void
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        $month = CarbonImmutable::now('Asia/Bahrain')->startOfMonth();
        $this->from = $this->from ?: $month->subMonths(12)->format('Y-m'); // the last 12 complete months
        $this->to = $this->to ?: $month->subMonth()->format('Y-m');
        if (! $this->inputsValid()) { // a bad URL: fall back to the defaults rather than a blank page
            $this->from = $month->subMonths(12)->format('Y-m');
            $this->to = $month->subMonth()->format('Y-m');
            $this->basis = IncomeStatement::COLLECTED;
            $this->resetScope();
        }
    }

    /** A trait hook (Livewire calls updated{TraitName}). */
    public function updatedStatementPage(): void
    {
        $this->validate($this->statementRules());
    }

    /**
     * Plan ruling 14. ponytail: a fixed 36-month window; widen it if anyone needs older statements.
     *
     * @return array<string, string> Y-m => "March 2026", newest first
     */
    protected function monthOptions(): array
    {
        $options = [];
        $month = CarbonImmutable::now('Asia/Bahrain')->startOfMonth();
        for ($i = 0; $i < 36; $i++, $month = $month->subMonth()) {
            $options[$month->format('Y-m')] = $month->translatedFormat('F Y');
        }

        return $options;
    }

    /** @return array<string, list<mixed>> */
    protected function statementRules(): array
    {
        $months = array_keys($this->monthOptions());

        return [
            'from' => ['required', 'string', Rule::in($months)],
            'to' => ['required', 'string', Rule::in($months), function (string $attribute, mixed $value, Closure $fail) use ($months) {
                if (! is_string($value) || ! in_array($value, $months, true) || ! in_array($this->from, $months, true)) {
                    return;
                }
                if ($value < $this->from) {
                    $fail(__('The last month cannot be before the first.'));
                } elseif (count(IncomeStatement::months($this->from.'-01', $value.'-01')) > 12) {
                    $fail(__('Choose at most 12 months.'));
                }
            }],
            'basis' => ['required', 'string', Rule::in([IncomeStatement::COLLECTED, IncomeStatement::BILLED])],
            ...$this->scopeRules(),
        ];
    }

    protected function inputsValid(): bool
    {
        return Validator::make($this->all(), $this->statementRules())->passes();
    }

    /** @return array{0: string, 1: string} the period's first and last day */
    protected function period(): array
    {
        return [$this->from.'-01', CarbonImmutable::parse($this->to.'-01')->endOfMonth()->toDateString()];
    }

    /** @return list<int>|null the user's buildings; null = every building (buildings.view_all, plan ruling 8) */
    protected function scopeBuildingIds(): ?array
    {
        $user = $this->actor();

        return $user->can(PermissionName::BuildingsViewAll) ? null
            : array_values(Building::visibleTo($user)->pluck('id')->map(fn (mixed $id) => (int) $id)->all());
    }

    /** @return array<int, string> owners with at least one contract in a building the user can see (spec §5) */
    protected function ownerOptions(): array
    {
        return $this->ownerOptionsCache ??= Owner::query()->whereIn('id', OwnerContract::query()->visibleTo($this->actor())->select('owner_id'))
            ->orderBy('name_en')->pluck('name_en', 'id')->all();
    }

    /**
     * Spec §4: the same table, one row per line, months as columns. Audited like the other reports.
     *
     * @param  Statement  $statement
     * @param  array<string, int|null>  $properties
     */
    protected function download(string $report, array $statement, array $properties): BinaryFileResponse
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        Audit::log('report.exported', properties: ['report' => $report, 'from' => $this->from, 'to' => $this->to, 'basis' => $this->basis, ...$properties], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-'.$report.'-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        // A month whose fee is not final carries a " *" on its header, so the figures stay numeric (the screen's marker).
        $pending = $statement['fee_pending'] ?? [];
        $writer->addHeader([__('Line'), ...array_map(fn (string $m) => CarbonImmutable::parse($m.'-01')->format('m/Y').(in_array($m, $pending, true) ? ' *' : ''), $statement['months']), __('Total')]);
        foreach ($statement['rows'] as $row) {
            $writer->addRow([$row['label'], ...array_map(fn (?int $v) => $v === null ? '–' : $v / 1000, array_values($row['amounts'])), $row['total'] / 1000]);
        }
        if ($pending !== []) {
            $writer->addRow([__('Fee not final until the owner statement for this month is finalised.')]);
        }
        $writer->close();

        return response()->download($path, Str::slug($report)."-{$this->from}-{$this->to}.xlsx")->deleteFileAfterSend();
    }
}
