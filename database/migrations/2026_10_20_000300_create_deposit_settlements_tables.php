<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('deductions_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deposit_settlements
                ADD CONSTRAINT deposit_settlements_status_chk CHECK (status IN ('draft', 'pending_approval', 'approved', 'completed')),
                ADD CONSTRAINT deposit_settlements_number_chk CHECK ((status IN ('approved', 'completed')) = (number IS NOT NULL)
                    AND (status IN ('approved', 'completed')) = (approved_at IS NOT NULL))
            SQL);

        Schema::create('deposit_settlement_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_unit_id')->unique()->constrained()->restrictOnDelete(); // one settlement per agreement unit
            $table->decimal('held_amount', 12, 3);
            $table->decimal('applied_amount', 12, 3)->nullable();
            $table->decimal('refund_amount', 12, 3)->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deposit_settlement_units
                ADD CONSTRAINT deposit_settlement_units_money_chk CHECK (held_amount >= 0
                    AND (applied_amount IS NULL OR applied_amount >= 0) AND (refund_amount IS NULL OR refund_amount >= 0)
                    AND (applied_amount IS NULL) = (refund_amount IS NULL))
            SQL);

        Schema::create('deposit_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_unit_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('description', 255);
            $table->decimal('amount', 12, 3);
            $table->foreignId('invoice_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deposit_settlement_lines
                ADD CONSTRAINT deposit_settlement_lines_type_chk CHECK (type IN ('damage', 'cleaning', 'utilities', 'unpaid_rent', 'other')),
                ADD CONSTRAINT deposit_settlement_lines_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT deposit_settlement_lines_rent_chk CHECK ((type = 'unpaid_rent') = (invoice_line_id IS NOT NULL))
            SQL);

        DB::unprepared("CREATE TRIGGER deposit_settlements_no_delete BEFORE DELETE ON deposit_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'deposit_settlements cannot be deleted'");

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER deposit_settlements_guard BEFORE UPDATE ON deposit_settlements FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'approved', 'completed'))
                    OR (OLD.status = 'approved' AND NEW.status = 'completed')
                    OR (OLD.status = 'completed' AND NEW.status = 'approved')) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'deposit_settlements: status change not allowed';
                END IF;
                IF NOT (NEW.agreement_id <=> OLD.agreement_id AND NEW.created_by <=> OLD.created_by)
                    OR (OLD.number IS NOT NULL AND NOT (NEW.number <=> OLD.number AND NEW.approved_at <=> OLD.approved_at
                        AND NEW.deductions_invoice_id <=> OLD.deductions_invoice_id AND NEW.payment_id <=> OLD.payment_id)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'deposit_settlements: an approved settlement is frozen';
                END IF;
            END
            DDL);

        // Children: writable only while the parent is draft; units also take their applied and refund amounts while
        // the parent is pending approval (the approval writes them before flipping the status). Spec §8.5.
        foreach (['units', 'lines'] as $child) {
            $table = "deposit_settlement_{$child}";
            foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $event => $row) {
                $name = "{$table}_".strtolower($event);
                $allowApproval = $child === 'units' && $event === 'UPDATE'
                    ? "AND NOT (s = 'pending_approval' AND NEW.agreement_unit_id <=> OLD.agreement_unit_id)"
                    : '';
                $moveGuard = $event === 'UPDATE'
                    ? "IF NOT (NEW.deposit_settlement_id <=> OLD.deposit_settlement_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table}: rows cannot move to another settlement'; END IF;"
                    : '';
                DB::unprepared(<<<DDL
                    CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW
                    BEGIN
                        DECLARE s VARCHAR(20);
                        SELECT status INTO s FROM deposit_settlements WHERE id = {$row}.deposit_settlement_id;
                        IF s <> 'draft' {$allowApproval} THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} are frozen once submitted';
                        END IF;
                        {$moveGuard}
                    END
                    DDL);
            }
        }
    }

    public function down(): void
    {
        foreach (['units', 'lines'] as $child) {
            foreach (['insert', 'update', 'delete'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS deposit_settlement_{$child}_{$event}");
            }
        }
        DB::unprepared('DROP TRIGGER IF EXISTS deposit_settlements_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS deposit_settlements_no_delete');
        Schema::dropIfExists('deposit_settlement_lines');
        Schema::dropIfExists('deposit_settlement_units');
        Schema::dropIfExists('deposit_settlements');
    }
};
