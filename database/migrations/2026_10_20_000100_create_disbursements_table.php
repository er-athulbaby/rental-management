<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->string('payee_type', 10);
            $table->unsignedBigInteger('payee_id');
            $table->string('purpose', 20);
            $table->decimal('amount', 12, 3);
            $table->string('method', 20);
            $table->unsignedBigInteger('cheque_id')->nullable(); // FK added with issued cheques (Task 2)
            $table->string('reference', 100)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status', 20);
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index(['payee_type', 'payee_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE disbursements
                ADD CONSTRAINT disbursements_payee_chk CHECK (payee_type IN ('owner', 'customer')),
                ADD CONSTRAINT disbursements_purpose_chk CHECK (purpose IN ('owner_remittance', 'head_lease', 'deposit_refund', 'credit_refund', 'other')),
                ADD CONSTRAINT disbursements_method_chk CHECK (method IN ('bank_transfer', 'cheque', 'cash')),
                ADD CONSTRAINT disbursements_status_chk CHECK (status IN ('pending_approval', 'approved', 'rejected', 'paid', 'reversed')),
                ADD CONSTRAINT disbursements_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT disbursements_source_chk CHECK ((source_type IS NULL) = (source_id IS NULL)
                    AND (source_type IS NULL OR source_type IN ('owner_statement', 'owner_payable', 'deposit_settlement', 'payment'))),
                ADD CONSTRAINT disbursements_paid_chk CHECK ((status IN ('paid', 'reversed')) = (number IS NOT NULL)
                    AND (status IN ('paid', 'reversed')) = (paid_on IS NOT NULL)
                    AND (status IN ('paid', 'reversed')) = (posted_at IS NOT NULL)
                    AND (status IN ('paid', 'reversed')) = (recorded_by IS NOT NULL)),
                ADD CONSTRAINT disbursements_reversed_chk CHECK ((status = 'reversed') = (reversed_at IS NOT NULL))
            SQL);

        DB::unprepared("CREATE TRIGGER disbursements_no_delete BEFORE DELETE ON disbursements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursements cannot be deleted'");

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER disbursements_guard BEFORE UPDATE ON disbursements FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('approved', 'rejected'))
                    OR (OLD.status = 'approved' AND NEW.status = 'paid')
                    OR (OLD.status = 'paid' AND NEW.status = 'reversed')) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursements: status change not allowed';
                END IF;
                IF NOT (NEW.payee_type <=> OLD.payee_type AND NEW.payee_id <=> OLD.payee_id AND NEW.purpose <=> OLD.purpose
                    AND NEW.amount <=> OLD.amount AND NEW.source_type <=> OLD.source_type AND NEW.source_id <=> OLD.source_id
                    AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursements: terms are frozen';
                END IF;
                IF OLD.status IN ('paid', 'reversed', 'rejected') AND NOT (NEW.number <=> OLD.number AND NEW.method <=> OLD.method
                    AND NEW.cheque_id <=> OLD.cheque_id AND NEW.reference <=> OLD.reference AND NEW.paid_on <=> OLD.paid_on
                    AND NEW.posted_at <=> OLD.posted_at AND NEW.recorded_by <=> OLD.recorded_by AND NEW.reason <=> OLD.reason) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disbursements: a paid payment out is frozen';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS disbursements_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS disbursements_no_delete');
        Schema::dropIfExists('disbursements');
    }
};
