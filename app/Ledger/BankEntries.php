<?php

namespace App\Ledger;

use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Bank reconciliation plan rulings 1–2: a ledger entry on a money account is one journal's net on that account, keyed by its
 * source (source type and id; "manual" and the journal id for a manual journal) and dated by its journal. The opening
 * journal is not an entry. Keys survive a rebuild, which re-posts the same sources.
 *
 * @phpstan-type Figures array{closing: int, in_transit: int, outstanding: int, adjusted: int, ledger: int, difference: int, opening: int, movement: int,
 *     covered: bool, unmatched_lines: int, changed: int, in: list<stdClass>, out: list<stdClass>}
 */
final class BankEntries
{
    /** The entry key in SQL, over journals as j. */
    public const string TYPE = "COALESCE(j.source_type, 'manual')";

    public const string ID = 'COALESCE(j.source_id, j.id)';

    public static function key(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    /** @return list<array{0: string, 1: int}>|null the distinct keys of 1–50 "type:id" strings; null for anything else */
    public static function parseKeys(mixed $keys): ?array
    {
        if (! is_array($keys) || $keys === [] || count($keys) > 50) {
            return null;
        }
        $out = [];
        foreach ($keys as $key) {
            if (! is_string($key) || preg_match('/^([a-z_]{1,30}):(\d{1,18})$/', $key, $m) !== 1) {
                return null;
            }
            $out[$m[1].':'.(int) $m[2]] = [$m[1], (int) $m[2]];
        }

        return array_values($out);
    }

    /** Entries on $account dated up to $end. Selects journal, kind, source_type, source_id, date, description, entry_type, entry_id, amount. */
    public static function query(int $account, string $end): Builder
    {
        return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.ledger_account_id', $account)->where('j.date', '<=', $end)->where('j.kind', '<>', 'opening')
            ->groupBy('j.id')->havingRaw('SUM(l.debit) <> SUM(l.credit)')
            ->selectRaw('j.id AS journal, j.kind, j.source_type, j.source_id, j.date, j.description, '.self::TYPE.' AS entry_type, '
                .self::ID.' AS entry_id, SUM(l.debit) - SUM(l.credit) AS amount');
    }

    /** Entries no reconciliation of the account has matched (only its open one, always the latest, still takes matches). */
    public static function unmatched(int $account, string $end): Builder
    {
        return self::query($account, $end)->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('bank_matches as m')
            ->where('m.ledger_account_id', $account)->whereRaw('m.entry_type = '.self::TYPE)->whereRaw('m.entry_id = '.self::ID));
    }

    /**
     * The entries named by $keys, as they stand now (a key with no line on $account by $end is missing from the result).
     *
     * @param  list<array{0: string, 1: int}>  $keys
     * @return array<string, stdClass> key => entry, oldest journal first
     */
    public static function find(int $account, string $end, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return self::query($account, $end)->where(function (Builder $q) use ($keys) {
            foreach ($keys as [$type, $id]) {
                $q->orWhere(fn (Builder $w) => $type === 'manual'
                    ? $w->where('j.id', $id)->where('j.kind', 'manual')
                    : $w->where('j.source_type', $type)->where('j.source_id', $id));
            }
        })->orderBy('j.id')->get()->keyBy(fn (stdClass $e) => self::key((string) $e->entry_type, (int) $e->entry_id))->all();
    }

    /**
     * Plan ruling 2: up to 20 unmatched entries with the line's direction and amount, dated within 7 days of it and by the end
     * date; a reference or number found in the line's text first, then the nearest date.
     *
     * @return list<stdClass>
     */
    public static function suggestions(BankReconciliation $rec, BankStatementLine $line): array
    {
        $text = trim($line->description.' '.($line->reference ?? ''));

        return array_values(self::unmatched($rec->ledger_account_id, $rec->end_date->toDateString())
            ->leftJoin('payments as p', fn (JoinClause $j) => $j->on('p.id', '=', 'j.source_id')->whereIn('j.source_type', ['payment', 'payment_reversal']))
            ->leftJoin('disbursements as d', fn (JoinClause $j) => $j->on('d.id', '=', 'j.source_id')->whereIn('j.source_type', ['disbursement', 'disbursement_reversal']))
            ->leftJoin('cheques as c', 'c.id', '=', DB::raw('COALESCE(p.cheque_id, d.cheque_id)'))
            ->whereBetween('j.date', [$line->date->subDays(7)->toDateString(), $line->date->addDays(7)->toDateString()])
            ->havingRaw('SUM(l.debit) - SUM(l.credit) = ?', [$line->amount])
            ->selectRaw('MAX('.self::hit('j.description').' OR '.self::hit('p.reference').' OR '.self::hit('d.reference').' OR '.self::hit('c.cheque_no').') AS hit',
                [$text, $text, $text, $text])
            ->orderByDesc('hit')->orderByRaw('ABS(DATEDIFF(j.date, ?))', [$line->date->toDateString()])->orderBy('j.id')
            ->limit(20)->get()->all());
    }

    /**
     * Plan ruling 4 (spec §4), in fils. An entry counts as matched when a reconciliation of the account ending on or before
     * this one's end date matched it, so a finished reconciliation reads the same after later ones.
     *
     * @return Figures
     */
    public static function figures(BankReconciliation $rec): array
    {
        $account = $rec->ledger_account_id;
        $end = $rec->end_date->toDateString();
        $matches = DB::table('bank_matches as m')->join('bank_reconciliations as r', 'r.id', '=', 'm.bank_reconciliation_id')
            ->where('m.ledger_account_id', $account)->where('r.end_date', '<=', $end)
            ->get(['m.bank_reconciliation_id', 'm.entry_type', 'm.entry_id', 'm.amount']);
        $matched = [];
        foreach ($matches as $m) {
            $matched[self::key((string) $m->entry_type, (int) $m->entry_id)] = true;
        }

        $live = [];
        $in = [];
        $out = [];
        $inTransit = 0;
        $outstanding = 0;
        foreach (self::query($account, $end)->orderBy('j.date')->orderBy('j.id')->get() as $e) {
            $key = self::key((string) $e->entry_type, (int) $e->entry_id);
            $amount = Fils::fromDecimal((string) $e->amount);
            $live[$key] = $amount;
            if (isset($matched[$key])) {
                continue;
            }
            if ($amount > 0) {
                $in[] = $e;
                $inTransit += $amount;
            } else {
                $out[] = $e;
                $outstanding -= $amount;
            }
        }
        $changed = $matches->filter(fn (stdClass $m) => (int) $m->bank_reconciliation_id === $rec->id
            && ($live[self::key((string) $m->entry_type, (int) $m->entry_id)] ?? null) !== Fils::fromDecimal((string) $m->amount))->count();

        $closing = Fils::fromDecimal($rec->closing_balance);
        $opening = Fils::fromDecimal($rec->opening_balance);
        $ledger = self::sum(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.ledger_account_id', $account)->where('j.date', '<=', $end)->sum(DB::raw('l.debit - l.credit')));
        $movement = self::sum(DB::table('bank_statement_lines')
            ->whereIn('id', DB::table('bank_matches')->where('bank_reconciliation_id', $rec->id)->select('bank_statement_line_id'))->sum('amount'));
        $adjusted = $closing + $inTransit - $outstanding;

        return [
            'closing' => $closing,
            'in_transit' => $inTransit,
            'outstanding' => $outstanding,
            'adjusted' => $adjusted,
            'ledger' => $ledger,
            'difference' => $adjusted - $ledger,
            'opening' => $opening,
            'movement' => $movement,
            'covered' => $opening + $movement === $closing,
            'unmatched_lines' => DB::table('bank_statement_lines as s')->where('s.ledger_account_id', $account)->where('s.date', '<=', $end)
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('bank_matches as m')->whereColumn('m.bank_statement_line_id', 's.id'))->count(),
            'changed' => $changed,
            'in' => $in,
            'out' => $out,
        ];
    }

    /** A SUM() result in fils (null or 0 when there are no rows). */
    private static function sum(mixed $sum): int
    {
        return Fils::fromDecimal((string) ($sum ?: '0'));
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    private static function hit(string $column): string
    {
        return "(CHAR_LENGTH({$column}) >= 3 AND INSTR(?, {$column}) > 0)";
    }

    /**
     * Plan ruling 5 (spec §4 "Protection"): refuses, on $field, a reversal dated $date of the entry ($type, $id) when a finished
     * reconciliation ending on or after $date has matched it. A reversal dated after every such end date is fine. Called by
     * every reversal path with the date its reversal posts on.
     */
    public static function assertReversible(string $type, int $id, string $date, string $field): void
    {
        $end = DB::table('bank_matches as m')->join('bank_reconciliations as r', 'r.id', '=', 'm.bank_reconciliation_id')
            ->where('m.entry_type', $type)->where('m.entry_id', $id)->where('r.status', 'finished')->where('r.end_date', '>=', $date)->max('r.end_date');
        if ($end !== null) {
            throw ValidationException::withMessages([$field => __('This entry is reconciled with the bank statement up to :date, so it cannot be reversed on or before that day.',
                ['date' => CarbonImmutable::parse((string) $end)->format('d/m/Y')])]);
        }
    }
}
