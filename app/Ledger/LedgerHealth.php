<?php

namespace App\Ledger;

use App\Billing\OwnerLedger;
use App\Enums\OwnerContractType;
use App\Models\LedgerSetting;
use App\Models\OwnerContract;
use App\Support\Fils;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec §4 "Ledger health" (plan ruling 8): the ledger's balances against the app's own figures, tolerance 0. Each row
 * holds the ledger's figure and the app's, in fils (a count when money is false); a row is healthy when they are equal.
 * The app figures follow CustomerCredit, Customer::outstandingFils, the deposits-held report and OwnerLedger, adjusted
 * where the ledger accrues (deposits and managed charges are recognised when invoiced, spec §4).
 *
 * @phpstan-type Row array{check: string, ledger: int, app: int, money: bool}
 */
final class LedgerHealth
{
    /** Not held by an owner: no contract, a leased contract, or a managed one whose deposits the company holds. */
    private const string COMPANY_HELD = "NOT (c.type <=> 'managed' AND c.deposits_held_by <=> 'owner')";

    /** @return list<Row> empty until the start date is set */
    public static function run(): array
    {
        $settings = LedgerSetting::current();
        if ($settings->start_date === null) {
            return [];
        }
        $start = $settings->start_date->toDateString();
        $today = now('Asia/Bahrain')->toDateString();
        $rules = Rules::load();
        $balance = fn (string $key): int => self::fils(DB::table('journal_lines')->where('ledger_account_id', $rules->account($key))->sum(DB::raw('debit - credit')));

        return [
            ['check' => 'Trial balance', 'ledger' => self::fils(DB::table('journal_lines')->sum(DB::raw('debit - credit'))), 'app' => 0, 'money' => true],
            ['check' => 'Records not posted', 'ledger' => Poster::pendingCount(), 'app' => 0, 'money' => false],
            ['check' => 'Tenant receivables', 'ledger' => $balance('receivables'), 'app' => self::receivables(), 'money' => true],
            ['check' => 'Tenant credit', 'ledger' => -$balance('tenant_credit'), 'app' => self::credit(), 'money' => true],
            ['check' => 'Tenant deposits held', 'ledger' => -$balance('deposits_held'), 'app' => self::deposits(), 'money' => true],
            ['check' => 'Owner payable', 'ledger' => -$balance('owner_payable'), 'app' => self::owners($today), 'money' => true],
            ['check' => 'Vendor payable', 'ledger' => self::vendorLedger($rules), 'app' => self::vendorApp($start, $today), 'money' => true],
            ['check' => 'Invoice lines with no owner contract', 'ledger' => self::unattributed($start), 'app' => 0, 'money' => false],
        ];
    }

    public static function store(): void
    {
        LedgerSetting::current()->forceFill(['health' => self::run(), 'health_checked_at' => now()])->save();
    }

    /** Σ balance of issued invoices, credit notes left out (Customer::outstandingFils, every tenant). */
    private static function receivables(): int
    {
        return self::fils(DB::table('invoices')->where('status', 'issued')->where('type', '<>', 'credit_note')->sum('balance'));
    }

    /** CustomerCredit::filsFor, every tenant: confirmed payments − their allocations − paid credit refunds on them. */
    private static function credit(): int
    {
        return self::fils(DB::table('payments')->where('status', 'confirmed')->sum('amount'))
            - self::fils(DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->where('p.status', 'confirmed')->sum('a.amount'))
            - self::fils(DB::table('disbursements as d')->join('payments as p', 'p.id', '=', 'd.source_id')->where('d.source_type', 'payment')
                ->where('d.purpose', 'credit_refund')->where('d.status', 'paid')->where('p.status', 'confirmed')->sum('d.amount'));
    }

    /** Deposits held by the company (the deposits-held report's movements) + what is still unpaid on its deposit lines. */
    private static function deposits(): int
    {
        return self::fils(DB::table('deposit_movements as m')->leftJoin('owner_contracts as c', 'c.id', '=', 'm.owner_contract_id')->whereRaw(self::COMPANY_HELD)->sum('m.amount'))
            + self::fils(self::depositLines()->whereRaw(self::COMPANY_HELD)->sum(DB::raw('l.total - l.allocated - l.credited')));
    }

    /**
     * The owner statements' balances (cash basis, OwnerLedger) + the net of managed charges billed but not yet collected
     * + unpaid owner-held deposits + leased rent due and not yet paid.
     */
    private static function owners(string $today): int
    {
        $owed = 0;
        foreach (OwnerContract::query()->where('type', OwnerContractType::Managed)->get() as $contract) {
            $owed += OwnerLedger::balance($contract);
        }
        $billed = self::fils(DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->join('owner_contracts as c', 'c.id', '=', 'l.owner_contract_id')
            ->where('i.status', 'issued')->where('i.type', '<>', 'credit_note')->where('c.type', 'managed')->where('l.charge_type', '<>', 'deposit')->sum('l.net'));
        $credited = self::fils(DB::table('invoice_lines as cl')->join('invoices as cn', 'cn.id', '=', 'cl.invoice_id')
            ->join('invoice_lines as l', 'l.id', '=', 'cl.credited_line_id')->join('owner_contracts as c', 'c.id', '=', 'l.owner_contract_id')
            ->where('cn.status', 'issued')->where('cn.type', 'credit_note')->where('c.type', 'managed')->where('l.charge_type', '<>', 'deposit')->sum('cl.net'));
        $collected = self::fils(DB::table('payment_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')
            ->join('owner_contracts as c', 'c.id', '=', 'a.owner_contract_id')
            ->where('c.type', 'managed')->where('l.charge_type', '<>', 'deposit')->sum(DB::raw('a.amount - a.tax_amount')));
        $ownerDeposits = self::fils(self::depositLines()->where('c.type', 'managed')->where('c.deposits_held_by', 'owner')->sum(DB::raw('l.total - l.allocated - l.credited')));
        $leaseDue = self::fils(DB::table('owner_payables')->where('status', '<>', 'cancelled')->where('due_date', '<=', $today)->sum('amount'));
        $leasePaid = self::fils(DB::table('disbursements')->where('purpose', 'head_lease')->where('status', 'paid')->sum('amount'));

        return $owed + $billed - $credited - $collected + $ownerDeposits + $leaseDue - $leasePaid;
    }

    /** What expenses credited to Vendor payable (manual vendor payments left out, plan ruling 11). */
    private static function vendorLedger(Rules $rules): int
    {
        return -self::fils(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.ledger_account_id', $rules->account('vendor_payable'))->whereIn('j.source_type', ['expense', 'expense_reversal'])
            ->sum(DB::raw('l.debit - l.credit')));
    }

    private static function vendorApp(string $start, string $today): int
    {
        return self::fils(DB::table('expenses')->whereBetween('expense_date', [$start, $today])->sum('total'))
            - self::fils(DB::table('expenses')->where('status', 'reversed')->whereBetween(DB::raw('DATE(reversed_at)'), [$start, $today])->sum('total'));
    }

    /** Plan ruling 2: issued lines on a unit that had no owner contract on their date, posted to company income. */
    private static function unattributed(string $start): int
    {
        return DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.status', 'issued')->where('i.type', '<>', 'credit_note')->where('i.issue_date', '>=', $start)
            ->whereNotNull('l.unit_id')->whereNull('l.owner_contract_id')->count();
    }

    private static function depositLines(): Builder
    {
        return DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->leftJoin('owner_contracts as c', 'c.id', '=', 'l.owner_contract_id')
            ->where('i.status', 'issued')->where('i.type', '<>', 'credit_note')->where('l.charge_type', 'deposit');
    }

    private static function fils(mixed $sum): int
    {
        $value = (string) ($sum ?? '0');

        return Fils::fromDecimal($value === '' ? '0' : $value);
    }
}
