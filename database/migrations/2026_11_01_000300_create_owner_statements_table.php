<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_statements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('owner_contract_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('cutoff_at');
            $table->decimal('opening_balance', 12, 3)->default(0);
            $table->decimal('fee_base', 12, 3)->default(0);
            $table->decimal('fee_amount', 12, 3)->default(0);
            $table->decimal('fee_tax', 12, 3)->default(0);
            $table->decimal('closing_balance', 12, 3)->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalised_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_contract_id', 'period_start']);
            $table->index(['status', 'period_start']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE owner_statements
                ADD CONSTRAINT owner_statements_status_chk CHECK (status IN ('draft', 'pending_approval', 'finalised')),
                ADD CONSTRAINT owner_statements_final_chk CHECK ((status = 'finalised') = (number IS NOT NULL)
                    AND (status = 'finalised') = (finalised_at IS NOT NULL) AND (status = 'finalised') = (finalised_by IS NOT NULL)),
                ADD CONSTRAINT owner_statements_fee_chk CHECK (fee_amount >= 0 AND fee_tax >= 0),
                ADD CONSTRAINT owner_statements_period_chk CHECK (period_end >= period_start)
            SQL);

        Schema::table('owner_charges', function (Blueprint $table) {
            $table->foreign('owner_statement_id')->references('id')->on('owner_statements')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER owner_statements_no_delete BEFORE DELETE ON owner_statements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_statements cannot be deleted'");

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER owner_statements_guard BEFORE UPDATE ON owner_statements FOR EACH ROW
            BEGIN
                IF OLD.status = 'finalised' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_statements: a finalised statement never changes';
                END IF;
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'finalised'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_statements: status change not allowed';
                END IF;
                IF NOT (NEW.owner_contract_id <=> OLD.owner_contract_id AND NEW.period_start <=> OLD.period_start
                    AND NEW.period_end <=> OLD.period_end AND NEW.cutoff_at <=> OLD.cutoff_at AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_statements: the period and contract are frozen';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS owner_statements_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS owner_statements_no_delete');
        Schema::table('owner_charges', fn (Blueprint $table) => $table->dropForeign(['owner_statement_id']));
        Schema::dropIfExists('owner_statements');
    }
};
