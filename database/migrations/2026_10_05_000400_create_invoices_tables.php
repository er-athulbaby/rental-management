<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->string('type', 20);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('grace_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('replaced_by_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('tax_total', 12, 3)->default(0);
            $table->decimal('total', 12, 3)->default(0);
            $table->decimal('allocated', 12, 3)->default(0);
            $table->decimal('credited', 12, 3)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'issue_date']);
            $table->index(['customer_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoices
                ADD COLUMN balance DECIMAL(12,3) GENERATED ALWAYS AS (IF(type = 'credit_note', 0, total - allocated - credited)) STORED,
                ADD CONSTRAINT invoices_type_chk CHECK (type IN ('rent', 'deposit', 'manual', 'opening', 'credit_note')),
                ADD CONSTRAINT invoices_status_chk CHECK (status IN ('draft', 'pending_approval', 'scheduled', 'issued', 'cancelled')),
                ADD CONSTRAINT invoices_number_chk CHECK ((status = 'issued') = (number IS NOT NULL)),
                ADD CONSTRAINT invoices_issued_chk CHECK (status <> 'issued' OR (issued_at IS NOT NULL AND grace_until IS NOT NULL)),
                ADD CONSTRAINT invoices_period_chk CHECK (period_end IS NULL OR period_end >= period_start),
                ADD CONSTRAINT invoices_money_chk CHECK (total = subtotal + tax_total AND allocated >= 0 AND credited >= 0
                    AND (type = 'credit_note' OR allocated + credited <= total))
            SQL);

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('charge_type', 20);
            $table->string('description', 255);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('net', 12, 3);
            $table->string('tax_category', 20);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('total', 12, 3);
            $table->foreignId('owner_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('credited_line_id')->nullable()->constrained('invoice_lines')->restrictOnDelete();
            $table->decimal('allocated', 12, 3)->default(0);
            $table->decimal('credited', 12, 3)->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoice_lines
                ADD CONSTRAINT invoice_lines_type_chk CHECK (charge_type IN ('rent', 'service_charge', 'parking', 'other', 'deposit', 'damage', 'cleaning', 'utilities', 'opening_balance')),
                ADD CONSTRAINT invoice_lines_tax_chk CHECK (tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT invoice_lines_total_chk CHECK (total = net + tax_amount),
                ADD CONSTRAINT invoice_lines_alloc_chk CHECK (allocated >= 0 AND allocated + credited <= total)
            SQL);

        // Spec §8.5. One statement per unprepared() call.
        DB::unprepared("CREATE TRIGGER invoices_no_delete BEFORE DELETE ON invoices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices cannot be deleted'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoices_guard BEFORE UPDATE ON invoices FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status IN ('pending_approval', 'scheduled', 'issued', 'cancelled'))
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'issued', 'cancelled'))
                    OR (OLD.status = 'scheduled' AND NEW.status IN ('issued', 'cancelled'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices: status change not allowed';
                END IF;

                IF OLD.status = 'issued' AND NOT (NEW.number <=> OLD.number AND NEW.type <=> OLD.type AND NEW.customer_id <=> OLD.customer_id
                    AND NEW.agreement_id <=> OLD.agreement_id AND NEW.related_invoice_id <=> OLD.related_invoice_id
                    AND NEW.period_start <=> OLD.period_start AND NEW.period_end <=> OLD.period_end
                    AND NEW.issue_date <=> OLD.issue_date AND NEW.due_date <=> OLD.due_date AND NEW.grace_until <=> OLD.grace_until
                    AND NEW.subtotal <=> OLD.subtotal AND NEW.tax_total <=> OLD.tax_total AND NEW.total <=> OLD.total
                    AND NEW.issued_by <=> OLD.issued_by AND NEW.issued_at <=> OLD.issued_at
                    AND NEW.replaced_by_invoice_id <=> OLD.replaced_by_invoice_id AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices: an issued invoice is immutable except allocated and credited';
                END IF;

                IF OLD.status = 'cancelled' AND (OLD.replaced_by_invoice_id IS NOT NULL
                    OR NOT (NEW.total <=> OLD.total AND NEW.allocated <=> OLD.allocated AND NEW.credited <=> OLD.credited
                        AND NEW.issue_date <=> OLD.issue_date AND NEW.due_date <=> OLD.due_date)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices: a cancelled invoice only records its replacement, once';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoice_lines_insert BEFORE INSERT ON invoice_lines FOR EACH ROW
            BEGIN
                IF (SELECT status FROM invoices WHERE id = NEW.invoice_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: lines can only be added to a draft invoice';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoice_lines_guard BEFORE UPDATE ON invoice_lines FOR EACH ROW
            BEGIN
                DECLARE s VARCHAR(20);
                SELECT status INTO s FROM invoices WHERE id = OLD.invoice_id;

                -- A line never moves to another invoice (it would slip past the parent's freeze).
                IF NOT (NEW.invoice_id <=> OLD.invoice_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: a line cannot move to another invoice';
                END IF;

                IF s <> 'draft' AND NOT (NEW.agreement_unit_id <=> OLD.agreement_unit_id
                    AND NEW.unit_id <=> OLD.unit_id AND NEW.charge_type <=> OLD.charge_type AND NEW.description <=> OLD.description
                    AND NEW.period_start <=> OLD.period_start AND NEW.period_end <=> OLD.period_end AND NEW.net <=> OLD.net
                    AND NEW.tax_category <=> OLD.tax_category AND NEW.credited_line_id <=> OLD.credited_line_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: amounts and descriptions are frozen once the invoice is not draft';
                END IF;

                -- The issue Action writes tax and the owner stamp while scheduled or pending, before it flips the status (spec §6.3).
                IF s IN ('scheduled', 'pending_approval') AND NOT (NEW.allocated <=> OLD.allocated AND NEW.credited <=> OLD.credited) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: nothing is allocated before issue';
                END IF;

                IF s IN ('issued', 'cancelled') AND NOT (NEW.tax_rate <=> OLD.tax_rate AND NEW.tax_amount <=> OLD.tax_amount
                    AND NEW.total <=> OLD.total AND NEW.owner_contract_id <=> OLD.owner_contract_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: issued lines change only allocated and credited';
                END IF;

                IF s = 'cancelled' AND NOT (NEW.allocated <=> OLD.allocated AND NEW.credited <=> OLD.credited) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: a cancelled invoice is final';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoice_lines_delete BEFORE DELETE ON invoice_lines FOR EACH ROW
            BEGIN
                IF (SELECT status FROM invoices WHERE id = OLD.invoice_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: lines can only be removed from a draft invoice';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        foreach (['invoice_lines_delete', 'invoice_lines_guard', 'invoice_lines_insert', 'invoices_guard', 'invoices_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
