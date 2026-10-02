<?php

namespace App\Billing;

use App\Enums\FeeType;
use App\Enums\TaxCategory;
use App\Models\CompanySetting;
use App\Models\OwnerStatement;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §7.9: one statement's figures. Its window is (previous statement's cutoff_at, its own cutoff_at]; the opening
 * balance is the previous statement's closing (0 for a contract's first). Entries in a window never change once the
 * cutoff has passed (late postings land in the next window), so drafts, submission and finalisation agree.
 */
final class OwnerStatementCalculator
{
    /** Spec C4: the fee base is rent only; on managed contracts imported opening_balance lines count as rent. */
    private const array FEE_CHARGES = ['rent', 'opening_balance'];

    /** @return array{opening: int, entries: Collection<int, array{posted_at: CarbonImmutable, date: string, kind: string, reference: string, amount: int}>, fee_base: int, fee: int, fee_tax: int, closing: int} */
    public static function compute(OwnerStatement $s): array
    {
        $contract = $s->contract;
        $previous = $s->previous();
        $after = $previous?->cutoff_at;
        $settings = CompanySetting::current();
        $type = $contract->fee_type ?? throw new LogicException("Owner contract {$contract->id} has no fee type; only managed contracts get statements.");

        // The window's only possible fee row is this statement's own (earlier ones sit at earlier cutoffs).
        $entries = OwnerLedger::entries($contract, $after, $s->cutoff_at)->reject(fn (array $e) => $e['kind'] === 'management_fee')->values();
        $opening = $previous !== null ? Fils::fromDecimal($previous->closing_balance) : 0;

        if ($type === FeeType::Fixed) {
            $from = $contract->start_date->max($s->period_start);
            if ($settings->go_live_at !== null) { // the old system charged the days before go-live
                $from = $from->max(CarbonImmutable::instance($settings->go_live_at)->startOfDay());
            }
            $to = $contract->end_date->min($s->period_end);
            $base = $from->greaterThan($to) ? 0 : Proration::partial(Fils::fromDecimal((string) $contract->fee_value), $from, $to, $settings->proration_basis);
            $fee = $base;
        } else {
            $carry = $previous !== null ? min(0, Fils::fromDecimal($previous->fee_base)) : 0;
            $base = self::windowBase($s, $type, $after) + $carry;
            $fee = $base > 0 ? Fils::divRound($base * Fils::fromDecimal((string) $contract->fee_value), 100_000) : 0; // fee_value % with 3 decimals
        }

        $feeTax = Tax::amount($fee, TaxCategory::Standard, (bool) $settings->vat_registered, (string) $settings->vat_rate);

        return [
            'opening' => $opening,
            'entries' => $entries,
            'fee_base' => $base,
            'fee' => $fee,
            'fee_tax' => $feeTax,
            'closing' => $opening + (int) $entries->sum('amount') - $fee - $feeTax,
        ];
    }

    /** Spec §7.9: the entries come from the ledger, every figure from the statement's stored columns, so a finalised statement never changes. */
    /** @return array{opening: int, entries: Collection<int, array{posted_at: CarbonImmutable, date: string, kind: string, reference: string, amount: int}>, fee_base: int, fee: int, fee_tax: int, closing: int} */
    public static function stored(OwnerStatement $s): array
    {
        return [
            ...self::compute($s),
            'opening' => Fils::fromDecimal($s->opening_balance),
            'fee_base' => Fils::fromDecimal($s->fee_base),
            'fee' => Fils::fromDecimal($s->fee_amount),
            'fee_tax' => Fils::fromDecimal($s->fee_tax),
            'closing' => Fils::fromDecimal($s->closing_balance),
        ];
    }

    public static function apply(OwnerStatement $s): void
    {
        $f = self::compute($s);
        $s->forceFill([
            'opening_balance' => Fils::toDecimal($f['opening']),
            'fee_base' => Fils::toDecimal($f['fee_base']),
            'fee_amount' => Fils::toDecimal($f['fee']),
            'fee_tax' => Fils::toDecimal($f['fee_tax']),
            'closing_balance' => Fils::toDecimal($f['closing']),
        ]);
    }

    private static function windowBase(OwnerStatement $s, FeeType $type, ?CarbonImmutable $after): int
    {
        $window = fn ($q, string $column) => $q->when($after, fn ($q) => $q->where($column, '>', $after))->where($column, '<=', $s->cutoff_at);

        if ($type === FeeType::PercentCollected) {
            $q = DB::table('payment_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')
                ->where('a.owner_contract_id', $s->owner_contract_id)->whereIn('l.charge_type', self::FEE_CHARGES);

            return Fils::fromDecimal((string) ($window($q, 'a.posted_at')->sum(DB::raw('a.amount - a.tax_amount')) ?: '0'));
        }

        // percent_billed: net rent lines issued in the window minus rent credit notes issued in the window.
        $billed = fn (bool $credit) => Fils::fromDecimal((string) ($window(DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('l.owner_contract_id', $s->owner_contract_id)->whereIn('l.charge_type', self::FEE_CHARGES)
            ->where('i.status', 'issued')->where('i.type', $credit ? '=' : '<>', 'credit_note'), 'i.issued_at')->sum('l.net') ?: '0'));

        return $billed(false) - $billed(true);
    }
}
