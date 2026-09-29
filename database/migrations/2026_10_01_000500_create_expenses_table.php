<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category', 30);
            $table->string('description', 500);
            $table->date('expense_date');
            $table->decimal('net', 12, 3);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('total', 12, 3);
            $table->string('charge_to', 20);
            $table->foreignId('owner_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('owner_approval_note')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['building_id', 'expense_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expenses
                ADD CONSTRAINT expenses_category_chk CHECK (category IN ('maintenance', 'utilities', 'cleaning', 'security', 'insurance', 'government', 'legal', 'commission', 'other')),
                ADD CONSTRAINT expenses_charge_to_chk CHECK (charge_to IN ('company', 'owner', 'tenant')),
                ADD CONSTRAINT expenses_status_chk CHECK (status IN ('recorded', 'reversed')),
                ADD CONSTRAINT expenses_amounts_chk CHECK (net >= 0 AND tax_amount >= 0 AND total = net + tax_amount AND total > 0),
                ADD CONSTRAINT expenses_owner_chk CHECK ((charge_to = 'owner') = (owner_contract_id IS NOT NULL)),
                ADD CONSTRAINT expenses_reversed_chk CHECK ((status = 'reversed') = (reversed_at IS NOT NULL) AND (status = 'reversed') = (reversed_by IS NOT NULL))
            SQL);

        // Spec §8.5.
        DB::unprepared("CREATE TRIGGER expenses_no_delete BEFORE DELETE ON expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'expenses cannot be deleted'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER expenses_guard BEFORE UPDATE ON expenses FOR EACH ROW
            BEGIN
                IF NOT (NEW.net <=> OLD.net AND NEW.tax_amount <=> OLD.tax_amount AND NEW.total <=> OLD.total
                    AND NEW.charge_to <=> OLD.charge_to AND NEW.owner_contract_id <=> OLD.owner_contract_id
                    AND NEW.building_id <=> OLD.building_id AND NEW.unit_id <=> OLD.unit_id AND NEW.expense_date <=> OLD.expense_date
                    AND NEW.recorded_by <=> OLD.recorded_by AND NEW.posted_at <=> OLD.posted_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'expenses: amounts, charge_to and attribution are frozen';
                END IF;
                IF OLD.status = 'reversed' AND NOT (NEW.status <=> OLD.status AND NEW.reversed_at <=> OLD.reversed_at
                    AND NEW.reversed_by <=> OLD.reversed_by AND NEW.reversal_reason <=> OLD.reversal_reason) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'expenses: a reversal is final';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS expenses_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS expenses_no_delete');
        Schema::dropIfExists('expenses');
    }
};
