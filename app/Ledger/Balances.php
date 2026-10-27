<?php

namespace App\Ledger;

use App\Support\Fils;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/** The ledger reports' two queries (plan ruling 9): balances per account, and lines in date order with what their source link needs. */
final class Balances
{
    /** @return array<int, int> ledger account id => Σ debit − Σ credit in fils, journals dated $from..$to ($from null = from the beginning) */
    public static function net(?string $from, string $to): array
    {
        $net = [];
        foreach (DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->when($from, fn (Builder $q, string $f) => $q->where('j.date', '>=', $f))->where('j.date', '<=', $to)
            ->groupBy('l.ledger_account_id')->selectRaw('l.ledger_account_id AS account, SUM(l.debit) - SUM(l.credit) AS net')->get() as $row) {
            $net[(int) $row->account] = Fils::fromDecimal((string) $row->net);
        }

        return $net;
    }

    /** Journal lines dated $from..$to with their journal and account, oldest first. */
    public static function lines(string $from, string $to): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->leftJoin('payment_allocations as pa', fn ($join) => $join->on('pa.id', '=', 'j.source_id')->where('j.source_type', '=', 'allocation'))
            ->whereBetween('j.date', [$from, $to])
            ->orderBy('j.date')->orderBy('j.id')->orderBy('l.id')
            ->select(['j.id AS journal', 'j.date', 'j.kind', 'j.source_type', 'j.source_id', 'j.description', 'a.code', 'a.name_en', 'a.name_ar',
                'l.ledger_account_id', 'l.debit', 'l.credit', 'pa.payment_id']);
    }

    /** A line's click-through (plan ruling 9). */
    public static function url(stdClass $line): string
    {
        return Sources::url((string) $line->kind, $line->source_type === null ? null : (string) $line->source_type, $line->source_id === null ? null : (int) $line->source_id,
            $line->payment_id === null ? null : (int) $line->payment_id, (int) $line->journal);
    }
}
