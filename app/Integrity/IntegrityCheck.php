<?php

namespace App\Integrity;

use Illuminate\Support\Facades\DB;

/** Spec §7.11. Each query returns the offending rows; an empty result means healthy. */
final class IntegrityCheck
{
    /**
     * Every immutability trigger (spec §8.5). A migration that adds or drops a trigger must change this number;
     * IntegrityCheckTest fails until it does.
     */
    public const int EXPECTED_TRIGGERS = 49; // 38 at the end of M3a + 2 (disbursements) + 8 (deposit settlements) + 1 (invoice line charge)

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

        foreach (DB::select('SELECT agreement_unit_id, SUM(amount) AS held FROM deposit_movements GROUP BY agreement_unit_id HAVING held < 0') as $row) {
            $failures[] = "agreement unit {$row->agreement_unit_id}: deposit held is {$row->held}";
        }

        $triggers = (int) DB::selectOne('SELECT rms_trigger_count() AS n')->n;
        if ($triggers !== self::EXPECTED_TRIGGERS) {
            $failures[] = "{$triggers} immutability triggers exist; ".self::EXPECTED_TRIGGERS.' are expected';
        }

        return $failures;
    }
}
