<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_payables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_contract_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->decimal('amount', 12, 3);
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('disbursement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('replaced_by_payable_id')->nullable()->constrained('owner_payables')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_date']);
            $table->index(['owner_contract_id', 'period_start']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE owner_payables
                ADD CONSTRAINT owner_payables_status_chk CHECK (status IN ('scheduled', 'paid', 'cancelled')),
                ADD CONSTRAINT owner_payables_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT owner_payables_dates_chk CHECK (period_end >= period_start AND due_date = period_start),
                ADD CONSTRAINT owner_payables_paid_chk CHECK ((status = 'paid') = (disbursement_id IS NOT NULL))
            SQL);

        DB::unprepared("CREATE TRIGGER owner_payables_no_delete BEFORE DELETE ON owner_payables FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_payables cannot be deleted'");

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER owner_payables_guard BEFORE UPDATE ON owner_payables FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'scheduled' AND NEW.status IN ('paid', 'cancelled'))
                    OR (OLD.status = 'paid' AND NEW.status = 'scheduled')) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_payables: status change not allowed';
                END IF;
                IF NOT (NEW.owner_contract_id <=> OLD.owner_contract_id AND NEW.period_start <=> OLD.period_start
                    AND NEW.period_end <=> OLD.period_end AND NEW.due_date <=> OLD.due_date AND NEW.amount <=> OLD.amount) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_payables: terms are frozen';
                END IF;
                IF OLD.replaced_by_payable_id IS NOT NULL AND NOT (NEW.replaced_by_payable_id <=> OLD.replaced_by_payable_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_payables: the replacement is set once';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS owner_payables_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS owner_payables_no_delete');
        Schema::dropIfExists('owner_payables');
    }
};
