<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('owner_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft');
            $table->date('terminated_on')->nullable();
            $table->string('termination_reason', 500)->nullable();
            $table->foreignId('previous_contract_id')->nullable()->constrained('owner_contracts')->restrictOnDelete();
            $table->decimal('rent_amount', 12, 3)->nullable();
            $table->string('payment_frequency', 20)->nullable();
            $table->string('fee_type', 20)->nullable();
            $table->decimal('fee_value', 12, 3)->nullable();
            $table->decimal('expense_approval_limit', 12, 3)->nullable();
            $table->string('deposits_held_by', 20)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'end_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE owner_contracts
                ADD CONSTRAINT oc_type_chk CHECK (type IN ('leased', 'managed')),
                ADD CONSTRAINT oc_status_chk CHECK (status IN ('draft', 'pending_approval', 'active', 'ended', 'terminated')),
                ADD CONSTRAINT oc_dates_chk CHECK (end_date >= start_date),
                ADD CONSTRAINT oc_number_chk CHECK (status IN ('draft', 'pending_approval') OR number IS NOT NULL),
                ADD CONSTRAINT oc_termination_chk CHECK ((terminated_on IS NULL) = (termination_reason IS NULL)),
                ADD CONSTRAINT oc_terminated_chk CHECK (status <> 'terminated' OR terminated_on IS NOT NULL),
                ADD CONSTRAINT oc_terms_chk CHECK (
                    (type = 'leased' AND rent_amount > 0
                        AND payment_frequency IN ('monthly', 'quarterly', 'half_yearly', 'yearly')
                        AND fee_type IS NULL AND fee_value IS NULL AND expense_approval_limit IS NULL AND deposits_held_by IS NULL)
                    OR (type = 'managed' AND fee_type IN ('percent_collected', 'percent_billed', 'fixed')
                        AND fee_value >= 0 AND (fee_type = 'fixed' OR fee_value <= 100)
                        AND deposits_held_by IN ('company', 'owner')
                        AND (expense_approval_limit IS NULL OR expense_approval_limit >= 0)
                        AND rent_amount IS NULL AND payment_frequency IS NULL)
                )
            SQL);

        Schema::create('owner_contract_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unique(['owner_contract_id', 'unit_id']);
        });

        // Spec §8.5. One statement per unprepared() call.
        DB::unprepared("CREATE TRIGGER owner_contracts_no_delete BEFORE DELETE ON owner_contracts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contracts cannot be deleted'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER owner_contracts_guard BEFORE UPDATE ON owner_contracts FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'active'))
                    OR (OLD.status = 'active' AND NEW.status IN ('ended', 'terminated'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contracts: status change not allowed';
                END IF;

                IF OLD.status <> 'draft' AND NOT (
                    NEW.owner_id <=> OLD.owner_id AND NEW.building_id <=> OLD.building_id AND NEW.type <=> OLD.type
                    AND NEW.start_date <=> OLD.start_date AND NEW.previous_contract_id <=> OLD.previous_contract_id
                    AND NEW.rent_amount <=> OLD.rent_amount AND NEW.payment_frequency <=> OLD.payment_frequency
                    AND NEW.fee_type <=> OLD.fee_type AND NEW.fee_value <=> OLD.fee_value
                    AND NEW.expense_approval_limit <=> OLD.expense_approval_limit AND NEW.deposits_held_by <=> OLD.deposits_held_by
                    AND NEW.created_by <=> OLD.created_by
                    AND (OLD.number IS NULL OR NEW.number <=> OLD.number)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contracts: terms are frozen once submitted';
                END IF;

                IF (OLD.status IN ('pending_approval', 'ended', 'terminated')
                        AND NOT (NEW.end_date <=> OLD.end_date AND NEW.terminated_on <=> OLD.terminated_on AND NEW.termination_reason <=> OLD.termination_reason))
                    OR (OLD.status = 'active' AND (NEW.end_date > OLD.end_date
                        OR (OLD.terminated_on IS NOT NULL AND NOT (NEW.terminated_on <=> OLD.terminated_on AND NEW.termination_reason <=> OLD.termination_reason)))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contracts: only end_date (earlier) and terminated_on (once) may change';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER owner_contract_units_insert BEFORE INSERT ON owner_contract_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM owner_contracts WHERE id = NEW.owner_contract_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contract_units are frozen once the contract is submitted';
                END IF;
            END
            SQL);

        DB::unprepared("CREATE TRIGGER owner_contract_units_no_update BEFORE UPDATE ON owner_contract_units FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contract_units cannot be updated'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER owner_contract_units_delete BEFORE DELETE ON owner_contract_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM owner_contracts WHERE id = OLD.owner_contract_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_contract_units are frozen once the contract is submitted';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        foreach (['owner_contract_units_delete', 'owner_contract_units_no_update', 'owner_contract_units_insert', 'owner_contracts_guard', 'owner_contracts_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('owner_contract_units');
        Schema::dropIfExists('owner_contracts');
    }
};
