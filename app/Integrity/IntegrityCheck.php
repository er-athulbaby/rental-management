<?php

namespace App\Integrity;

use App\Billing\OwnerLedger;
use App\Enums\OwnerStatementStatus;
use App\Models\OwnerStatement;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Spec §7.11. Each query returns the offending rows; an empty result means healthy. */
final class IntegrityCheck
{
    /**
     * Every immutability trigger (spec §8.5). A migration that adds or drops a trigger must change this number;
     * IntegrityCheckTest fails until it does.
     */
    public const int EXPECTED_TRIGGERS = 61; // 38 at the end of M3a + 2 (disbursements) + 8 (deposit settlements) + 1 (invoice line charge) + 3 (amendments; the two insert triggers are replaced, not added) + 1 (credit source) + 2 (owner payables) + 2 (owner charges) + 2 (owner statements) + 2 (payment tenders)

    /** @return list<string> */
    public function run(): array
    {
        $failures = [];

        foreach (DB::select(<<<'SQL'
            SELECT l.id, l.allocated, COALESCE(a.s, 0) AS source
            FROM invoice_lines l
            LEFT JOIN (SELECT invoice_line_id, SUM(amount) AS s FROM payment_allocations GROUP BY invoice_line_id) a ON a.invoice_line_id = l.id
            WHERE l.allocated <> COALESCE(a.s, 0)
            SQL) as $row) {
            $failures[] = "invoice line {$row->id}: allocated {$row->allocated} but its allocations sum to {$row->source}";
        }

        foreach (DB::select(<<<'SQL'
            SELECT l.id, l.credited, COALESCE(c.s, 0) AS source
            FROM invoice_lines l
            LEFT JOIN (SELECT cl.credited_line_id, SUM(cl.total) AS s FROM invoice_lines cl
                       JOIN invoices cn ON cn.id = cl.invoice_id AND cn.type = 'credit_note' AND cn.status = 'issued'
                       GROUP BY cl.credited_line_id) c ON c.credited_line_id = l.id
            WHERE l.credited <> COALESCE(c.s, 0)
            SQL) as $row) {
            $failures[] = "invoice line {$row->id}: credited {$row->credited} but its issued credit notes sum to {$row->source}";
        }

        foreach (DB::select(<<<'SQL'
            SELECT i.id, i.number FROM invoices i
            JOIN (SELECT invoice_id, SUM(net) AS net, SUM(tax_amount) AS tax, SUM(total) AS total, SUM(allocated) AS allocated, SUM(credited) AS credited
                  FROM invoice_lines GROUP BY invoice_id) l ON l.invoice_id = i.id
            WHERE i.status = 'issued'
              AND (i.subtotal <> l.net OR i.tax_total <> l.tax OR i.total <> l.total OR i.allocated <> l.allocated OR i.credited <> l.credited)
            SQL) as $row) {
            $failures[] = "invoice {$row->number}: cached totals differ from its lines";
        }

        foreach (DB::select(<<<'SQL'
            SELECT p.customer_id, SUM(p.amount) - COALESCE(SUM(a.s), 0) - COALESCE(SUM(r.s), 0) AS credit
            FROM payments p
            LEFT JOIN (SELECT payment_id, SUM(amount) AS s FROM payment_allocations GROUP BY payment_id) a ON a.payment_id = p.id
            LEFT JOIN (SELECT source_id, SUM(amount) AS s FROM disbursements
                       WHERE source_type = 'payment' AND purpose = 'credit_refund' AND status = 'paid' GROUP BY source_id) r ON r.source_id = p.id
            WHERE p.status = 'confirmed'
            GROUP BY p.customer_id
            HAVING credit < 0
            SQL) as $row) {
            $failures[] = "customer {$row->customer_id}: credit is {$row->credit}";
        }

        foreach (DB::select(<<<'SQL'
            SELECT p.number, p.amount, COALESCE(t.s, 0) AS parts FROM payments p
            LEFT JOIN (SELECT payment_id, SUM(amount) AS s, COUNT(*) AS n FROM payment_tenders GROUP BY payment_id) t ON t.payment_id = p.id
            WHERE (p.method = 'split' AND (t.n IS NULL OR t.n < 2 OR t.s <> p.amount)) OR (p.method <> 'split' AND t.n IS NOT NULL)
            SQL) as $row) {
            $failures[] = "payment {$row->number}: amount {$row->amount} but its split parts sum to {$row->parts}";
        }

        foreach (DB::select('SELECT agreement_unit_id, SUM(amount) AS held FROM deposit_movements GROUP BY agreement_unit_id HAVING held < 0') as $row) {
            $failures[] = "agreement unit {$row->agreement_unit_id}: deposit held is {$row->held}";
        }

        foreach (DB::select(<<<'SQL'
            SELECT p.id FROM owner_payables p
            LEFT JOIN disbursements d ON d.id = p.disbursement_id
            WHERE p.status = 'paid' AND (d.id IS NULL OR d.status <> 'paid' OR d.purpose <> 'head_lease'
                OR d.source_type <> 'owner_payable' OR d.source_id <> p.id OR d.amount <> p.amount)
            SQL) as $row) {
            $failures[] = "owner payable {$row->id}: not paid by its own head-lease payment out";
        }

        // ponytail: walks every finalised statement nightly; limit to the last 13 months if it gets slow.
        // Stored figures only: fee and VAT are what was finalised, so a later settings change never flags old statements.
        foreach (OwnerStatement::query()->where('status', OwnerStatementStatus::Finalised)->with('contract')->orderBy('id')->get() as $s) {
            try {
                $previous = $s->previous();
                $expectedOpening = $previous !== null ? Fils::fromDecimal($previous->closing_balance) : 0;
                if (Fils::fromDecimal($s->opening_balance) !== $expectedOpening) {
                    $failures[] = "owner statement {$s->number}: opening {$s->opening_balance} but the previous statement closed at ".Fils::toDecimal($expectedOpening);
                }
                $entries = OwnerLedger::entries($s->contract, $previous?->cutoff_at, $s->cutoff_at)->reject(fn (array $e) => $e['kind'] === 'management_fee');
                $closing = Fils::fromDecimal($s->opening_balance) + (int) $entries->sum('amount') - Fils::fromDecimal($s->fee_amount) - Fils::fromDecimal($s->fee_tax);
                if ($closing !== Fils::fromDecimal($s->closing_balance)) {
                    $failures[] = "owner statement {$s->number}: closing {$s->closing_balance} but its entries now give ".Fils::toDecimal($closing);
                }
            } catch (Throwable $e) {
                $failures[] = "owner statement {$s->number}: could not be checked ({$e->getMessage()})";
            }
        }

        $triggers = (int) DB::selectOne('SELECT rms_trigger_count() AS n')->n;
        if ($triggers !== self::EXPECTED_TRIGGERS) {
            $failures[] = "{$triggers} immutability triggers exist; ".self::EXPECTED_TRIGGERS.' are expected';
        }

        return $failures;
    }
}
