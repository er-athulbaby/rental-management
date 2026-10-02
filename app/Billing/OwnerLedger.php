<?php

namespace App\Billing;

use App\Enums\DepositMovementType;
use App\Enums\DepositsHeldBy;
use App\Models\OwnerContract;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec §7.9: a managed contract's owner ledger, a query over rows that carry their owner stamp. + = the company owes
 * the owner. Each entry sits at its posting time (a reversal at its reversal time) and shows its business date.
 *
 * ponytail: loads the contract's whole history and windows it in PHP; push the window into SQL if one contract
 * passes ~10k entries.
 */
final class OwnerLedger
{
    /** @return Collection<int, array{posted_at: CarbonImmutable, date: string, kind: string, reference: string, amount: int}> */
    public static function entries(OwnerContract $contract, ?CarbonImmutable $after = null, ?CarbonImmutable $upTo = null): Collection
    {
        return self::all($contract)
            ->filter(fn (array $e) => ($after === null || $e['posted_at']->greaterThan($after)) && ($upTo === null || $e['posted_at']->lessThanOrEqualTo($upTo)))
            ->sortBy(fn (array $e) => [$e['posted_at']->getTimestamp(), $e['kind'], $e['reference']])
            ->values();
    }

    /** Plan ruling 5: what can be remitted now without approval — the live balance less remittances not yet paid. */
    public static function remittableFils(OwnerContract $contract): int
    {
        $waiting = Fils::fromDecimal((string) (DB::table('disbursements as d')->join('owner_statements as s', 's.id', '=', 'd.source_id')
            ->where('d.source_type', 'owner_statement')->where('d.purpose', 'owner_remittance')->where('s.owner_contract_id', $contract->id)
            ->whereIn('d.status', ['pending_approval', 'approved'])->sum('d.amount') ?: '0'));

        return self::balance($contract) - $waiting;
    }

    public static function balance(OwnerContract $contract, ?CarbonImmutable $upTo = null): int
    {
        return (int) self::entries($contract, null, $upTo)->sum('amount');
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'collection' => __('Rent collected (net of VAT)'),
            'collection_reversal' => __('Collection reversed'),
            'deposit_received' => __('Deposit received'),
            'deposit_applied' => __('Deposit applied'),
            'deposit_refunded' => __('Deposit refunded'),
            'deposit_transfer_in', 'deposit_transfer_out' => __('Deposit transferred'),
            'management_fee' => __('Management fee'),
            'opening_balance' => __('Opening balance'),
            'expense' => __('Expense'),
            'expense_reversal' => __('Expense reversed'),
            'remittance' => __('Paid to you'),
            'remittance_reversal' => __('Payment to you reversed'),
            default => str($kind)->headline()->toString(),
        };
    }

    /** @return Collection<int, array{posted_at: CarbonImmutable, date: string, kind: string, reference: string, amount: int}> */
    private static function all(OwnerContract $contract): Collection
    {
        $at = fn (string $ts) => CarbonImmutable::parse($ts);
        $entries = collect();

        // Allocations and their reversals on the contract's non-deposit lines, net of VAT.
        foreach (DB::table('payment_allocations as a')
            ->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.owner_contract_id', $contract->id)->where('l.charge_type', '<>', 'deposit')
            ->get(['a.amount', 'a.tax_amount', 'a.posted_at', 'p.number as payment', 'p.received_on', 'i.number as invoice']) as $r) {
            $amount = Fils::fromDecimal((string) $r->amount) - Fils::fromDecimal((string) $r->tax_amount);
            $entries->push([
                'posted_at' => $at($r->posted_at),
                'date' => $amount >= 0 ? (string) $r->received_on : $at($r->posted_at)->toDateString(),
                'kind' => $amount >= 0 ? 'collection' : 'collection_reversal',
                'reference' => "{$r->payment} → {$r->invoice}",
                'amount' => $amount,
            ]);
        }

        // Deposits, only when the owner holds them; opening movements never (spec §11).
        if ($contract->deposits_held_by === DepositsHeldBy::Owner) {
            foreach (DB::table('deposit_movements')->where('owner_contract_id', $contract->id)
                ->where('type', '<>', DepositMovementType::Opening->value)->get(['type', 'amount', 'posted_at', 'source_type', 'source_id']) as $r) {
                $entries->push([
                    'posted_at' => $at($r->posted_at),
                    'date' => $at($r->posted_at)->toDateString(),
                    'kind' => 'deposit_'.$r->type,
                    'reference' => "{$r->source_type} {$r->source_id}",
                    'amount' => Fils::fromDecimal((string) $r->amount),
                ]);
            }
        }

        foreach (DB::table('owner_charges')->where('owner_contract_id', $contract->id)->get(['type', 'amount', 'posted_at', 'owner_statement_id']) as $r) {
            $entries->push([
                'posted_at' => $at($r->posted_at),
                'date' => $at($r->posted_at)->toDateString(),
                'kind' => $r->type,
                'reference' => $r->owner_statement_id !== null ? "statement {$r->owner_statement_id}" : '',
                'amount' => Fils::fromDecimal((string) $r->amount),
            ]);
        }

        // Expenses charged to the owner: the total (plan ruling 6); a reversal is the opposite entry at reversed_at.
        foreach (DB::table('expenses')->where('owner_contract_id', $contract->id)->whereNotNull('posted_at')
            ->get(['id', 'description', 'expense_date', 'total', 'posted_at', 'reversed_at']) as $r) {
            $total = Fils::fromDecimal((string) $r->total);
            $entries->push(['posted_at' => $at($r->posted_at), 'date' => (string) $r->expense_date, 'kind' => 'expense', 'reference' => $r->description, 'amount' => -$total]);
            if ($r->reversed_at !== null) {
                $entries->push(['posted_at' => $at($r->reversed_at), 'date' => $at($r->reversed_at)->toDateString(), 'kind' => 'expense_reversal', 'reference' => $r->description, 'amount' => $total]);
            }
        }

        // Payments to the owner (remittances against the contract's statements); a reversal at reversed_at.
        foreach (DB::table('disbursements as d')->join('owner_statements as s', 's.id', '=', 'd.source_id')
            ->where('d.source_type', 'owner_statement')->where('d.purpose', 'owner_remittance')->where('s.owner_contract_id', $contract->id)
            ->whereIn('d.status', ['paid', 'reversed'])->get(['d.number', 'd.amount', 'd.paid_on', 'd.posted_at', 'd.reversed_at']) as $r) {
            $amount = Fils::fromDecimal((string) $r->amount);
            $entries->push(['posted_at' => $at($r->posted_at), 'date' => (string) $r->paid_on, 'kind' => 'remittance', 'reference' => (string) $r->number, 'amount' => -$amount]);
            if ($r->reversed_at !== null) {
                $entries->push(['posted_at' => $at($r->reversed_at), 'date' => $at($r->reversed_at)->toDateString(), 'kind' => 'remittance_reversal', 'reference' => (string) $r->number, 'amount' => $amount]);
            }
        }

        return $entries;
    }
}
