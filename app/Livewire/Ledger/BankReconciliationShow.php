<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\DiscardBankReconciliation;
use App\Actions\Ledger\FinishBankReconciliation;
use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\RecordBankOnlyLine;
use App\Actions\Ledger\ReopenBankReconciliation;
use App\Actions\Ledger\UnmatchBankLine;
use App\Audit\Audit;
use App\Enums\CashMovementKind;
use App\Enums\PermissionName;
use App\Ledger\BankEntries;
use App\Ledger\Sources;
use App\Livewire\Concerns\WithActor;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\LedgerAccount;
use App\Support\Fils;
use App\Support\SafeCells;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\SimpleExcel\SimpleExcelWriter;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bank reconciliation spec §3–§4: one reconciliation. Finance matches statement lines to ledger entries (suggestions first),
 * records bank-only lines, finishes or discards; Admin reopens; Finance and Admin view and download the report.
 *
 * @phpstan-import-type Figures from BankEntries
 */
class BankReconciliationShow extends Component
{
    use WithActor;

    #[Locked]
    public int $reconciliationId;

    #[Locked]
    public ?int $lineId = null;

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $picked = [];

    public mixed $search = '';

    public mixed $kind = 'bank_charge';

    public mixed $otherAccount = '';

    public function mount(string $reconciliation): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->reconciliationId = BankReconciliation::query()->findOrFail(ctype_digit($reconciliation) && strlen($reconciliation) < 19 ? (int) $reconciliation : 0)->id;
    }

    public function pick(mixed $id): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerManage), 403);
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);
        $this->lineId = BankStatementLine::query()->where('ledger_account_id', $this->rec()->ledger_account_id)->findOrFail((int) $id)->id;
        $this->picked = [];
        $this->search = '';
        $this->resetErrorBag();
    }

    public function cancelPick(): void
    {
        $this->lineId = null;
        $this->picked = [];
        $this->resetErrorBag();
    }

    public function match(): void
    {
        abort_if($this->lineId === null, 404);
        $this->resetErrorBag();
        app(MatchBankLine::class)->handle($this->actor(), $this->rec(), (string) $this->lineId, $this->picked);
        $this->cancelPick();
        Flux::toast(variant: 'success', text: __('Matched.'));
    }

    public function bankOnly(): void
    {
        abort_if($this->lineId === null, 404);
        $this->resetErrorBag();
        app(RecordBankOnlyLine::class)->handle($this->actor(), $this->rec(), (string) $this->lineId, $this->kind, $this->otherAccount);
        $this->cancelPick();
        Flux::toast(variant: 'success', text: __('Recorded and matched.'));
    }

    public function unmatch(mixed $lineId): void
    {
        $this->resetErrorBag();
        app(UnmatchBankLine::class)->handle($this->actor(), $this->rec(), $lineId);
        Flux::toast(variant: 'success', text: __('Match removed.'));
    }

    public function finish(): void
    {
        $this->resetErrorBag();
        app(FinishBankReconciliation::class)->handle($this->actor(), $this->rec());
        $this->cancelPick();
        Flux::toast(variant: 'success', text: __('Reconciliation finished.'));
    }

    public function reopen(): void
    {
        $this->resetErrorBag();
        app(ReopenBankReconciliation::class)->handle($this->actor(), $this->rec());
        Flux::toast(variant: 'success', text: __('Reconciliation reopened.'));
    }

    public function discard(): void
    {
        $this->resetErrorBag();
        $rec = $this->rec();
        app(DiscardBankReconciliation::class)->handle($this->actor(), $rec);
        Flux::toast(variant: 'success', text: __('Reconciliation discarded.'));
        $this->skipRender(); // the reconciliation is gone
        $this->redirectRoute('ledger.bank-reconciliation', ['account' => (string) $rec->ledger_account_id], navigate: true);
    }

    public function download(): BinaryFileResponse
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $rec = $this->rec();
        Audit::log('report.exported', properties: ['report' => 'bank_reconciliation', 'reconciliation' => $rec->id], causer: $this->actor());

        return response()->download(self::reportFile($rec), 'bank-reconciliation-'.$rec->account->code.'-'.$rec->end_date->toDateString().'.xlsx')->deleteFileAfterSend();
    }

    /** Spec §4: the reconciliation report as a temporary .xlsx, every row through SafeCells. Returns its path. */
    public static function reportFile(BankReconciliation $rec): string
    {
        $path = sys_get_temp_dir().'/rms-bank-rec-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        foreach (self::reportRows($rec) as $row) {
            $writer->addRow(SafeCells::row($row));
        }
        $writer->close();

        return $path;
    }

    /** @return list<list<string>> the heading, the nine figures, then the in-transit and outstanding lists */
    public static function reportRows(BankReconciliation $rec): array
    {
        $f = BankEntries::figures($rec);
        $rows = [[__('Bank reconciliation'), $rec->account->label(), $rec->end_date->format('d/m/Y'), $rec->status === 'open' ? __('Open') : __('Finished')]];
        foreach (self::figureRows($f) as $row) {
            $rows[] = $row;
        }
        foreach ([[__('Deposits in transit'), $f['in']], [__('Outstanding payments'), $f['out']]] as [$title, $entries]) {
            $rows[] = [$title];
            $rows[] = [__('Date'), __('Source'), __('Description'), __('Amount')];
            foreach (self::rows($entries) as $e) {
                $rows[] = [$e['date'], $e['source'], $e['description'], $e['amount']];
            }
        }

        return $rows;
    }

    /**
     * Plan ruling 4's figures, as the page and the report show them.
     *
     * @param  Figures  $f
     * @return list<array{0: string, 1: string}>
     */
    public static function figureRows(array $f): array
    {
        return [
            [__('Statement opening balance'), Fils::toDecimal($f['opening'])],
            [__('Statement closing balance'), Fils::toDecimal($f['closing'])],
            [__('Deposits in transit'), Fils::toDecimal($f['in_transit'])],
            [__('Outstanding payments'), Fils::toDecimal($f['outstanding'])],
            [__('Adjusted balance'), Fils::toDecimal($f['adjusted'])],
            [__('Ledger balance at the end date'), Fils::toDecimal($f['ledger'])],
            [__('Difference'), Fils::toDecimal($f['difference'])],
            [__('Statement lines not matched'), (string) $f['unmatched_lines']],
            [__('Opening balance plus matched statement lines'), Fils::toDecimal($f['opening'] + $f['movement'])],
        ];
    }

    /**
     * @param  list<stdClass>  $entries  rows as BankEntries::query() selects them
     * @return list<array{key: string, date: string, source: string, description: string, amount: string, url: string}>
     */
    private static function rows(array $entries): array
    {
        return array_map(fn (stdClass $e) => [
            'key' => BankEntries::key((string) $e->entry_type, (int) $e->entry_id),
            'date' => CarbonImmutable::parse((string) $e->date)->format('d/m/Y'),
            'source' => Sources::label((string) $e->entry_type),
            'description' => (string) $e->description,
            'amount' => Fils::toDecimal(Fils::fromDecimal((string) $e->amount)),
            'url' => Sources::url((string) $e->kind, $e->source_type === null ? null : (string) $e->source_type, $e->source_id === null ? null : (int) $e->source_id,
                null, (int) $e->journal),
        ], $entries);
    }

    private function rec(): BankReconciliation
    {
        return BankReconciliation::query()->with(['account', 'finisher'])->findOrFail($this->reconciliationId);
    }

    /** @return list<stdClass> up to 100 other unmatched entries by the end date, nearest the line's date first; the search is a description or an amount */
    private function others(BankReconciliation $rec, BankStatementLine $line): array
    {
        $search = is_string($this->search) ? mb_substr(trim($this->search), 0, 100) : '';
        $query = BankEntries::unmatched($rec->ledger_account_id, $rec->end_date->toDateString());
        if (preg_match('/^-?\d{1,12}(\.\d{1,3})?$/', $search) === 1) {
            $query->havingRaw('ABS(SUM(l.debit) - SUM(l.credit)) = ?', [Fils::toDecimal(abs(Fils::fromDecimal($search)))]);
        } elseif ($search !== '') {
            $query->where('j.description', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return array_values($query->orderByRaw('ABS(DATEDIFF(j.date, ?))', [$line->date->toDateString()])->orderBy('j.id')->limit(100)->get()->all());
    }

    public function render(): View
    {
        $rec = $this->rec();
        $end = $rec->end_date->toDateString();
        $open = $rec->status === 'open';
        $f = BankEntries::figures($rec);
        $line = $this->lineId === null || ! $open ? null : BankStatementLine::query()->find($this->lineId);

        return view('livewire.ledger.bank-reconciliation-show', [
            'rec' => $rec,
            'open' => $open,
            'figures' => self::figureRows($f),
            'unmatched' => BankStatementLine::query()->where('ledger_account_id', $rec->ledger_account_id)->where('date', '<=', $end)->whereDoesntHave('matches')
                ->orderBy('date')->orderBy('id')->get(),
            'matched' => BankStatementLine::query()->whereHas('matches', fn (EloquentBuilder $q) => $q->where('bank_reconciliation_id', $rec->id))
                ->with('matches')->orderBy('date')->orderBy('id')->get(),
            'line' => $line,
            'suggestions' => $line === null ? [] : self::rows(BankEntries::suggestions($rec, $line)),
            'others' => $line === null ? [] : self::rows($this->others($rec, $line)),
            'chosen' => Fils::toDecimal(array_sum(array_map(fn (stdClass $e) => Fils::fromDecimal((string) $e->amount),
                BankEntries::find($rec->ledger_account_id, $end, BankEntries::parseKeys($this->picked) ?? [])))),
            'inTransit' => self::rows($f['in']),
            'outstanding' => self::rows($f['out']),
            'kinds' => CashMovementKind::cases(),
            'money' => LedgerAccount::query()->where('is_money', true)->where('active', true)->whereKeyNot($rec->ledger_account_id)->orderBy('code')->get(),
            'canManage' => $this->actor()->can(PermissionName::LedgerManage),
            'canAdmin' => $this->actor()->can(PermissionName::LedgerAdmin),
        ])->title(__('Bank reconciliation'));
    }
}
