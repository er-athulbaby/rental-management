<?php

namespace App\Livewire\Reports\Concerns;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\User;
use App\Reports\IncomeStatement;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;
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

    /** @var array<int, array<int, string>> owner list per user id, memoised for the request (never shared between users) */
    private array $ownerOptionsCache = [];

    /** @return array<string, list<mixed>> the screen's own inputs */
    abstract protected function scopeRules(): array;

    /** Clears the screen's own inputs (a bad URL). */
    abstract protected function resetScope(): void;

    abstract protected function title(): string;

    /**
     * The screen's own inputs a package item may set, with their choices; a '' choice means optional.
     *
     * @return array<string, array<array-key, string>>
     */
    abstract protected function scopeOptions(): array;

    /** @return Statement|null */
    abstract protected function compute(): ?array;

    /** A validated id (an int, or a numeric string from a select) as an int; anything else is null. */
    protected function idOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit(trim($value))) ? (int) $value : null;
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
        return $this->ownerOptionsCache[$this->actor()->id] ??= Owner::query()->whereIn('id', OwnerContract::query()->visibleTo($this->actor())->select('owner_id'))
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
        $sheet = $this->statementSheet($statement);
        $writer->addHeader($sheet['columns']);
        foreach ($sheet['rows'] as $row) {
            $writer->addRow($row);
        }
        $writer->close();

        return response()->download($path, Str::slug($report)."-{$this->from}-{$this->to}.xlsx")->deleteFileAfterSend();
    }

    /**
     * The Excel table. A month whose fee is not final carries a " *" on its header, so the figures stay numeric, and the
     * footnote follows the rows.
     *
     * @param  Statement  $statement
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>}
     */
    protected function statementSheet(array $statement): array
    {
        $pending = $statement['fee_pending'] ?? [];
        $columns = [__('Line'), ...array_map(fn (string $m) => CarbonImmutable::parse($m.'-01')->format('m/Y').(in_array($m, $pending, true) ? ' *' : ''), $statement['months']), __('Total')];
        $rows = [];
        foreach ($statement['rows'] as $row) {
            $rows[] = [$row['label'], ...array_map(fn (?int $v) => $v === null ? '–' : $v / 1000, array_values($row['amounts'])), $row['total'] / 1000];
        }
        if ($pending !== []) {
            $rows[] = [__('Fee not final until the owner statement for this month is finalised.')];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * The PackageReport entry points are static on purpose: Livewire dispatches only public instance methods, so the
     * browser can never call them (final review C1). Each runs on a fresh, unmounted instance (plan ruling 1), so the
     * owner cache starts empty.
     *
     * @return array{title: string, dates: string, building: bool, options: array<string, array<array-key, string>>, allowed: bool}
     */
    public static function packageForm(User $as): array
    {
        return (new self)->formFor($as);
    }

    /** @param  array<array-key, mixed>  $settings */
    public static function checkSettings(User $as, array $settings): void
    {
        (new self)->checkFor($as, $settings);
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}
     */
    public static function sheet(User $as, array $settings): array
    {
        return (new self)->sheetFor($as, $settings);
    }

    /** @return array{title: string, dates: string, building: bool, options: array<string, array<array-key, string>>, allowed: bool} */
    private function formFor(User $as): array
    {
        return $this->asActor($as, fn () => ['title' => $this->title(), 'dates' => 'months', 'building' => array_key_exists('building', $this->scopeRules()),
            'options' => ['basis' => [IncomeStatement::COLLECTED => __('Collected'), IncomeStatement::BILLED => __('Billed')], ...$this->scopeOptions()],
            'allowed' => $as->can('reports.financial')]);
    }

    /** @param  array<array-key, mixed>  $settings */
    private function checkFor(User $as, array $settings): void
    {
        $this->asActor($as, function () use ($settings) {
            $this->loadSettings($settings);
            $this->packageValidator()->validate();
        });
    }

    /**
     * Spec 2026-10-17 §4: what download() writes, for $settings, run as $as.
     *
     * @param  array<array-key, mixed>  $settings
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>, headline: ?string}
     */
    private function sheetFor(User $as, array $settings): array
    {
        return $this->asActor($as, function () use ($as, $settings) {
            if (! $as->can('reports.financial')) {
                throw new AuthorizationException(__('You do not have access to this report.'));
            }
            $this->loadSettings($settings);
            $this->packageValidator()->validate();
            $statement = $this->compute() ?? throw ValidationException::withMessages(['owner' => __('Choose an owner.')]);
            $headline = null;
            foreach ($statement['rows'] as $row) {
                if ($row['kind'] === 'total') {
                    $headline = $row['label'].': '.Fils::grouped($row['total']); // the last total row: Result / Net income to owner
                }
            }

            return [...$this->statementSheet($statement), 'headline' => $headline];
        });
    }

    /**
     * The props are untyped (mixed), so any value is assigned and then validated.
     *
     * @param  array<array-key, mixed>  $settings
     */
    private function loadSettings(array $settings): void
    {
        foreach (['from', 'to', 'basis', ...array_keys($this->scopeRules())] as $key) {
            $this->{$key} = $settings[$key] ?? null;
        }
    }

    /** The screen's rules; an option offered without a '' choice is required (the owner of an owner statement). */
    private function packageValidator(): ValidationValidator
    {
        $rules = $this->statementRules();
        foreach ($this->scopeOptions() as $key => $choices) {
            if (! array_key_exists('', $choices)) {
                $rules[$key] = ['required', ...$rules[$key]];
            }
        }

        return Validator::make($this->all(), $rules);
    }
}
