<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger money screens spec §2–§5 (plan rulings 1–9): vendor payees and vendor bills on payments out, issued cheques to a
 * vendor, the expenses a vendor bill pays, money accounts, Bank charges and Bank interest income with their rules, cash and
 * bank movements, and VAT returns. Six triggers keep the three new tables write-once (EXPECTED_TRIGGERS 67 → 73); the
 * cheques guard is replaced, not added. The accounts and rules are inserted additively, so an installed chart keeps Admin's edits.
 */
return new class extends Migration
{
    /** system_key => [preferred code, English, Arabic, type, rule key] (plan ruling 7) */
    private const array ACCOUNTS = [
        'bank_charges' => ['5180', 'Bank charges', 'الرسوم المصرفية', 'expense', 'bank_charges'],
        'bank_interest' => ['4300', 'Bank interest income', 'إيرادات الفوائد البنكية', 'income', 'bank_interest'],
    ];

    private const array TRIGGERS = ['cash_movements_no_delete', 'cash_movements_no_update', 'disbursement_expenses_no_delete', 'disbursement_expenses_no_update',
        'vat_returns_no_delete', 'vat_returns_no_update'];

    public function up(): void
    {
        // Plan ruling 1: a vendor is a payee only of a vendor bill. One statement: atomic.
        DB::statement(<<<'SQL'
            ALTER TABLE disbursements
                DROP CHECK disbursements_payee_chk,
                DROP CHECK disbursements_purpose_chk,
                ADD CONSTRAINT disbursements_payee_chk CHECK (payee_type IN ('owner', 'customer', 'vendor')),
                ADD CONSTRAINT disbursements_purpose_chk CHECK (purpose IN ('owner_remittance', 'head_lease', 'deposit_refund', 'credit_refund', 'other', 'vendor_bill')),
                ADD CONSTRAINT disbursements_vendor_chk CHECK ((payee_type = 'vendor') = (purpose = 'vendor_bill'))
            SQL);

        // Plan ruling 1: an issued cheque goes to exactly one of a tenant, an owner or a vendor.
        Schema::table('cheques', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('owner_id')->constrained()->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE cheques DROP CHECK cheques_party_chk, ADD CONSTRAINT cheques_party_chk CHECK (
                (direction = 'received' AND customer_id IS NOT NULL AND owner_id IS NULL AND vendor_id IS NULL)
                OR (direction = 'issued' AND (customer_id IS NOT NULL) + (owner_id IS NOT NULL) + (vendor_id IS NOT NULL) = 1))
            SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS cheques_guard');
        DB::unprepared(<<<'DDL'
            CREATE TRIGGER cheques_guard BEFORE UPDATE ON cheques FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.direction = 'received' AND (
                        (OLD.status = 'held' AND NEW.status IN ('deposited', 'returned', 'cancelled'))
                        OR (OLD.status = 'deposited' AND NEW.status IN ('cleared', 'bounced'))
                        OR (OLD.status = 'cleared' AND NEW.status = 'bounced')
                        OR (OLD.status = 'bounced' AND NEW.status IN ('replaced', 'returned'))))
                    OR (OLD.direction = 'issued' AND OLD.status = 'issued' AND NEW.status IN ('cleared', 'cancelled'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: status change not allowed';
                END IF;
                IF NOT (NEW.direction <=> OLD.direction AND NEW.customer_id <=> OLD.customer_id AND NEW.owner_id <=> OLD.owner_id
                    AND NEW.vendor_id <=> OLD.vendor_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: the party never changes';
                END IF;
                IF OLD.status NOT IN ('held', 'issued') AND NOT (NEW.cheque_no <=> OLD.cheque_no AND NEW.bank_name <=> OLD.bank_name
                    AND NEW.cheque_date <=> OLD.cheque_date AND NEW.amount <=> OLD.amount AND NEW.agreement_id <=> OLD.agreement_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: terms are frozen once deposited';
                END IF;
                IF OLD.direction = 'issued' AND NOT (NEW.amount <=> OLD.amount AND NEW.cheque_no <=> OLD.cheque_no) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: an issued cheque matches its payment out';
                END IF;
                IF OLD.status NOT IN ('held', 'deposited') AND NOT (NEW.invoice_id <=> OLD.invoice_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: the target is fixed once the cheque has left held and deposited';
                END IF;
                IF (OLD.payment_id IS NOT NULL AND NOT (NEW.payment_id <=> OLD.payment_id))
                    OR (OLD.disbursement_id IS NOT NULL AND NOT (NEW.disbursement_id <=> OLD.disbursement_id))
                    OR (OLD.replaced_by_cheque_id IS NOT NULL AND NOT (NEW.replaced_by_cheque_id <=> OLD.replaced_by_cheque_id)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: payment and replacement are set once';
                END IF;
            END
            DDL);

        // Plan ruling 2: which expenses a vendor bill pays. Write-once; whether a link is active comes from the bill's status.
        Schema::create('disbursement_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disbursement_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['disbursement_id', 'expense_id']);
            $table->index(['expense_id', 'disbursement_id']);
        });
        DB::unprepared("CREATE TRIGGER disbursement_expenses_no_update BEFORE UPDATE ON disbursement_expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursement_expenses are write-once'");
        DB::unprepared("CREATE TRIGGER disbursement_expenses_no_delete BEFORE DELETE ON disbursement_expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursement_expenses cannot be deleted'");

        // Plan ruling 6: money accounts are assets.
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->boolean('is_money')->default(false)->after('system_key');
        });
        DB::table('ledger_accounts')->whereIn('system_key', ['cash', 'bank', 'card_clearing'])->where('type', 'asset')->update(['is_money' => true]);
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_money_chk CHECK (is_money = 0 OR type = 'asset')");

        // Plan ruling 7: added only where missing; a code already used on an installed chart moves to the next free number.
        $now = now();
        foreach (self::ACCOUNTS as $key => [$code, $en, $ar, $type, $rule]) {
            $id = DB::table('ledger_accounts')->where('system_key', $key)->value('id');
            if ($id === null) {
                while (DB::table('ledger_accounts')->where('code', $code)->exists()) {
                    $code = (string) ((int) $code + 1);
                }
                $id = DB::table('ledger_accounts')->insertGetId(['code' => $code, 'name_en' => $en, 'name_ar' => $ar, 'type' => $type, 'system_key' => $key,
                    'is_money' => false, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            if (! DB::table('ledger_rules')->where('key', $rule)->exists()) {
                DB::table('ledger_rules')->insert(['key' => $rule, 'ledger_account_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Plan ruling 8. A reversal is its own row (reverses_id, unique: once).
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->foreignId('from_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->decimal('amount', 12, 3);
            $table->date('date');
            $table->string('reference', 100);
            $table->string('note', 1000)->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['date', 'id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE cash_movements
                ADD CONSTRAINT cash_movements_kind_chk CHECK (kind IN ('transfer', 'bank_charge', 'bank_interest')),
                ADD CONSTRAINT cash_movements_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT cash_movements_accounts_chk CHECK (
                    (kind = 'transfer' AND from_account_id IS NOT NULL AND to_account_id IS NOT NULL AND from_account_id <> to_account_id)
                    OR (kind = 'bank_charge' AND from_account_id IS NOT NULL AND to_account_id IS NULL)
                    OR (kind = 'bank_interest' AND from_account_id IS NULL AND to_account_id IS NOT NULL))
            SQL);
        DB::unprepared("CREATE TRIGGER cash_movements_no_update BEFORE UPDATE ON cash_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_movements are write-once: correct one by reversal'");
        DB::unprepared("CREATE TRIGGER cash_movements_no_delete BEFORE DELETE ON cash_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_movements cannot be deleted'");

        // Plan ruling 9.
        Schema::create('vat_returns', function (Blueprint $table) {
            $table->id();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('direction', 10);
            $table->string('method', 20);
            $table->decimal('amount', 12, 3);
            $table->date('date');
            $table->string('reference', 100);
            $table->string('note', 1000)->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('vat_returns')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['period_from', 'period_to']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE vat_returns
                ADD CONSTRAINT vat_returns_direction_chk CHECK (direction IN ('payment', 'refund')),
                ADD CONSTRAINT vat_returns_method_chk CHECK (method IN ('bank_transfer', 'cheque', 'cash')),
                ADD CONSTRAINT vat_returns_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT vat_returns_period_chk CHECK (period_to >= period_from)
            SQL);
        DB::unprepared("CREATE TRIGGER vat_returns_no_update BEFORE UPDATE ON vat_returns FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vat_returns are write-once: correct one by reversal'");
        DB::unprepared("CREATE TRIGGER vat_returns_no_delete BEFORE DELETE ON vat_returns FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vat_returns cannot be deleted'");
    }

    public function down(): void
    {
        // Rolls back to the M3a-era shapes (the vendors migration needs cheques.vendor_id gone before it can drop vendors). Vendor
        // rows on disbursements or cheques make this fail, which is right. The accounts and rules inserted by up() stay: they are additive.
        if (DB::table('disbursements')->where('payee_type', 'vendor')->orWhere('purpose', 'vendor_bill')->exists()
            || DB::table('cheques')->whereNotNull('vendor_id')->exists()
            || DB::table('cash_movements')->exists() || DB::table('vat_returns')->exists()) {
            throw new RuntimeException('Cannot roll back: vendor bills, vendor cheques, cash movements or VAT returns exist. Nothing was changed.');
        }
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('vat_returns');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('disbursement_expenses');
        DB::statement('ALTER TABLE ledger_accounts DROP CHECK ledger_accounts_money_chk');
        Schema::table('ledger_accounts', fn (Blueprint $table) => $table->dropColumn('is_money'));

        DB::statement(<<<'SQL'
            ALTER TABLE disbursements
                DROP CHECK disbursements_vendor_chk,
                DROP CHECK disbursements_payee_chk,
                DROP CHECK disbursements_purpose_chk,
                ADD CONSTRAINT disbursements_payee_chk CHECK (payee_type IN ('owner', 'customer')),
                ADD CONSTRAINT disbursements_purpose_chk CHECK (purpose IN ('owner_remittance', 'head_lease', 'deposit_refund', 'credit_refund', 'other'))
            SQL);
        DB::statement('ALTER TABLE cheques DROP CHECK cheques_party_chk');
        DB::unprepared('DROP TRIGGER IF EXISTS cheques_guard');
        Schema::table('cheques', fn (Blueprint $table) => $table->dropConstrainedForeignId('vendor_id'));
        DB::statement(<<<'SQL'
            ALTER TABLE cheques ADD CONSTRAINT cheques_party_chk CHECK (
                (direction = 'received' AND customer_id IS NOT NULL AND owner_id IS NULL)
                OR (direction = 'issued' AND ((customer_id IS NULL) <> (owner_id IS NULL))))
            SQL);
        DB::unprepared(<<<'DDL'
            CREATE TRIGGER cheques_guard BEFORE UPDATE ON cheques FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.direction = 'received' AND (
                        (OLD.status = 'held' AND NEW.status IN ('deposited', 'returned', 'cancelled'))
                        OR (OLD.status = 'deposited' AND NEW.status IN ('cleared', 'bounced'))
                        OR (OLD.status = 'cleared' AND NEW.status = 'bounced')
                        OR (OLD.status = 'bounced' AND NEW.status IN ('replaced', 'returned'))))
                    OR (OLD.direction = 'issued' AND OLD.status = 'issued' AND NEW.status IN ('cleared', 'cancelled'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: status change not allowed';
                END IF;
                IF NOT (NEW.direction <=> OLD.direction AND NEW.customer_id <=> OLD.customer_id AND NEW.owner_id <=> OLD.owner_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: the party never changes';
                END IF;
                IF OLD.status NOT IN ('held', 'issued') AND NOT (NEW.cheque_no <=> OLD.cheque_no AND NEW.bank_name <=> OLD.bank_name
                    AND NEW.cheque_date <=> OLD.cheque_date AND NEW.amount <=> OLD.amount AND NEW.agreement_id <=> OLD.agreement_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: terms are frozen once deposited';
                END IF;
                IF OLD.direction = 'issued' AND NOT (NEW.amount <=> OLD.amount AND NEW.cheque_no <=> OLD.cheque_no) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: an issued cheque matches its payment out';
                END IF;
                IF OLD.status NOT IN ('held', 'deposited') AND NOT (NEW.invoice_id <=> OLD.invoice_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: the target is fixed once the cheque has left held and deposited';
                END IF;
                IF (OLD.payment_id IS NOT NULL AND NOT (NEW.payment_id <=> OLD.payment_id))
                    OR (OLD.disbursement_id IS NOT NULL AND NOT (NEW.disbursement_id <=> OLD.disbursement_id))
                    OR (OLD.replaced_by_cheque_id IS NOT NULL AND NOT (NEW.replaced_by_cheque_id <=> OLD.replaced_by_cheque_id)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: payment and replacement are set once';
                END IF;
            END
            DDL);
    }
};
