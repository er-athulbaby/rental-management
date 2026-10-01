<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->string('method', 20);
            $table->decimal('amount', 12, 3);
            $table->string('reference', 100)->nullable();
            $table->unsignedBigInteger('cheque_id')->nullable(); // FK added with the cheques table (Task 7)
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('confirmed');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_method_chk CHECK (method IN ('cash', 'bank_transfer', 'cheque', 'card', 'deposit_applied')),
                ADD CONSTRAINT payments_status_chk CHECK (status IN ('confirmed', 'reversed')),
                ADD CONSTRAINT payments_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT payments_reversed_chk CHECK ((status = 'reversed') = (reversed_at IS NOT NULL))
            SQL);

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_line_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 3);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->foreignId('reverses_allocation_id')->nullable()->constrained('payment_allocations')->restrictOnDelete();
            $table->foreignId('owner_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['invoice_line_id']);
            $table->index(['owner_contract_id', 'posted_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_allocations
                ADD CONSTRAINT payment_allocations_amount_chk CHECK (amount <> 0),
                ADD CONSTRAINT payment_allocations_sign_chk CHECK ((amount < 0) = (reverses_allocation_id IS NOT NULL)),
                ADD CONSTRAINT payment_allocations_tax_chk CHECK ((amount > 0 AND tax_amount >= 0 AND tax_amount <= amount) OR (amount < 0 AND tax_amount <= 0 AND tax_amount >= amount))
            SQL);

        Schema::create('deposit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 12, 3);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->timestamp('posted_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['agreement_unit_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deposit_movements
                ADD CONSTRAINT deposit_movements_type_chk CHECK (type IN ('received', 'applied', 'refunded', 'transfer_in', 'transfer_out', 'opening')),
                ADD CONSTRAINT deposit_movements_amount_chk CHECK (amount <> 0)
            SQL);

        // Spec §8.5. One statement per unprepared() call.
        DB::unprepared("CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments cannot be deleted'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payments_guard BEFORE UPDATE ON payments FOR EACH ROW
            BEGIN
                IF NOT (NEW.number <=> OLD.number AND NEW.customer_id <=> OLD.customer_id AND NEW.received_on <=> OLD.received_on
                    AND NEW.method <=> OLD.method AND NEW.amount <=> OLD.amount AND NEW.cheque_id <=> OLD.cheque_id
                    AND NEW.recorded_by <=> OLD.recorded_by AND NEW.posted_at <=> OLD.posted_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments: terms are frozen';
                END IF;
                IF OLD.status = 'reversed' AND NOT (NEW.status <=> OLD.status AND NEW.reversed_at <=> OLD.reversed_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments: a reversal is final';
                END IF;
            END
            SQL);

        foreach (['payment_allocations', 'deposit_movements'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = $table.'_no_'.strtolower($event);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} are write-once'");
            }
        }

        // Spec §6.1: only issued invoices are paid or credited. M2's guards left draft rows open; these close them.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoices_paid_only_issued BEFORE UPDATE ON invoices FOR EACH ROW FOLLOWS invoices_guard
            BEGIN
                IF NOT (NEW.allocated <=> OLD.allocated AND NEW.credited <=> OLD.credited) AND NOT (OLD.status = 'issued' AND NEW.status = 'issued') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices: only an issued invoice is paid or credited';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoice_lines_paid_only_issued BEFORE UPDATE ON invoice_lines FOR EACH ROW FOLLOWS invoice_lines_guard
            BEGIN
                IF NOT (NEW.allocated <=> OLD.allocated AND NEW.credited <=> OLD.credited)
                    AND (SELECT status FROM invoices WHERE id = OLD.invoice_id) <> 'issued' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: only lines of an issued invoice are paid or credited';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        foreach (['invoice_lines_paid_only_issued', 'invoices_paid_only_issued', 'deposit_movements_no_delete', 'deposit_movements_no_update', 'payment_allocations_no_delete', 'payment_allocations_no_update', 'payments_guard', 'payments_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('deposit_movements');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
