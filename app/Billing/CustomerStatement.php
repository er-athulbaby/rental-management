<?php

namespace App\Billing;

use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Disbursement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Fils;
use Illuminate\Support\Collection;

/**
 * Spec §7.10: ledgers are queries. Receivables balance = debits − credits (positive: the customer owes; negative: credit).
 * ponytail: If statements get slow, add a monthly snapshot (spec §7.10).
 */
final class CustomerStatement
{
    /** @return array{opening: int, rows: list<array{date: string, kind: string, reference: string, url: string|null, debit: int, credit: int, balance: int}>, closing: int} */
    public static function receivables(Customer $customer, string $from, string $to): array
    {
        $entries = self::entries($customer)->sortBy(fn (array $e) => [$e['date'], $e['order'], $e['id']])->values();

        $opening = $entries->filter(fn (array $e) => $e['date'] < $from)->sum(fn (array $e) => $e['debit'] - $e['credit']);
        $balance = $opening;
        $rows = [];
        foreach ($entries->filter(fn (array $e) => $e['date'] >= $from && $e['date'] <= $to) as $e) {
            $balance += $e['debit'] - $e['credit'];
            $rows[] = [...array_intersect_key($e, array_flip(['date', 'kind', 'reference', 'url', 'debit', 'credit'])), 'balance' => $balance];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $balance];
    }

    /** @return Collection<int, array{date: string, order: int, id: int, kind: string, reference: string, url: string|null, debit: int, credit: int}> */
    private static function entries(Customer $customer): Collection
    {
        $invoices = Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Issued)->get()
            ->map(fn (Invoice $i) => $i->type === InvoiceType::CreditNote
                ? ['date' => $i->issue_date->toDateString(), 'order' => 3, 'id' => $i->id, 'kind' => 'Credit note', 'reference' => (string) $i->number, 'url' => route('invoices.show', $i), 'debit' => 0, 'credit' => Fils::fromDecimal($i->total)]
                : ['date' => $i->issue_date->toDateString(), 'order' => 1, 'id' => $i->id, 'kind' => 'Invoice', 'reference' => (string) $i->number, 'url' => route('invoices.show', $i), 'debit' => Fils::fromDecimal($i->total), 'credit' => 0]);

        $payments = Payment::query()->where('customer_id', $customer->id)->get();
        $paid = $payments->map(fn (Payment $p) => ['date' => $p->received_on->toDateString(), 'order' => 4, 'id' => $p->id, 'kind' => 'Payment', 'reference' => $p->number, 'url' => route('payments.show', $p), 'debit' => 0, 'credit' => Fils::fromDecimal($p->amount)]);
        $reversed = $payments->flatMap(fn (Payment $p) => $p->reversed_at === null ? [] : [['date' => $p->reversed_at->timezone('Asia/Bahrain')->toDateString(), 'order' => 2, 'id' => $p->id, 'kind' => 'Payment reversal', 'reference' => $p->number, 'url' => route('payments.show', $p), 'debit' => Fils::fromDecimal($p->amount), 'credit' => 0]]);

        // Spec §7.10: credit refunds are debits; a reversed refund shows both its payment and its reversal.
        $refunds = Disbursement::query()
            ->where('purpose', DisbursementPurpose::CreditRefund)->where('payee_type', 'customer')->where('payee_id', $customer->id)
            ->whereIn('status', [DisbursementStatus::Paid, DisbursementStatus::Reversed])->get();
        $refunded = $refunds->map(fn (Disbursement $d) => ['date' => $d->paid_on?->toDateString() ?? '', 'order' => 5, 'id' => $d->id, 'kind' => 'Credit refund', 'reference' => (string) $d->number, 'url' => null, 'debit' => Fils::fromDecimal($d->amount), 'credit' => 0]);
        $refundReversed = $refunds->flatMap(fn (Disbursement $d) => $d->reversed_at === null ? [] : [['date' => $d->reversed_at->timezone('Asia/Bahrain')->toDateString(), 'order' => 6, 'id' => $d->id, 'kind' => 'Credit refund reversal', 'reference' => (string) $d->number, 'url' => null, 'debit' => 0, 'credit' => Fils::fromDecimal($d->amount)]]);

        return $invoices->concat($paid)->concat($reversed)->concat($refunded)->concat($refundReversed);
    }

    /** @return array<int, array{unit: string, opening: int, rows: list<array{date: string, kind: string, amount: int, held: int}>, closing: int}> */
    public static function deposits(Customer $customer, string $from, string $to): array
    {
        return DepositMovement::query()
            ->whereHas('agreementUnit.agreement', fn ($q) => $q->where('customer_id', $customer->id))
            ->with('agreementUnit.unit.building', 'agreementUnit.agreement:id,number')
            ->orderBy('posted_at')->orderBy('id')->get()
            ->groupBy('agreement_unit_id')
            ->map(function (Collection $movements) use ($from, $to) {
                $au = $movements->firstOrFail()->agreementUnit;
                $date = fn (DepositMovement $m) => $m->posted_at->timezone('Asia/Bahrain')->toDateString();
                $held = $opening = $movements->filter(fn ($m) => $date($m) < $from)->sum(fn ($m) => Fils::fromDecimal($m->amount));
                $rows = [];
                foreach ($movements->filter(fn ($m) => $date($m) >= $from && $date($m) <= $to) as $m) {
                    $held += Fils::fromDecimal($m->amount);
                    $rows[] = ['date' => $date($m), 'kind' => str($m->type->value)->headline()->toString(), 'amount' => Fils::fromDecimal($m->amount), 'held' => $held];
                }

                return ['unit' => $au->agreement->number.' · '.$au->unit->building->code.' / '.$au->unit->code, 'opening' => $opening, 'rows' => $rows, 'closing' => $held];
            })->values()->all();
    }
}
