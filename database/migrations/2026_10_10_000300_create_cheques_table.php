<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained()->restrictOnDelete(); // issued cheques, M3b
            $table->foreignId('agreement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete(); // target; received only
            $table->string('cheque_no', 30);
            $table->string('bank_name', 100);
            $table->string('account_holder', 150)->nullable();
            $table->date('cheque_date');
            $table->decimal('amount', 12, 3);
            $table->string('status', 20);
            $table->date('deposited_on')->nullable();
            $table->date('cleared_on')->nullable();
            $table->date('bounced_on')->nullable();
            $table->string('bounce_reason', 500)->nullable();
            $table->date('returned_on')->nullable();
            $table->foreignId('replaced_by_cheque_id')->nullable()->constrained('cheques')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'cheque_date']);
            $table->index(['customer_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cheques
                ADD CONSTRAINT cheques_direction_chk CHECK (direction IN ('received', 'issued')),
                ADD CONSTRAINT cheques_status_chk CHECK (status IN ('held', 'deposited', 'cleared', 'bounced', 'replaced', 'returned', 'cancelled', 'issued')),
                ADD CONSTRAINT cheques_amount_chk CHECK (amount > 0),
                ADD CONSTRAINT cheques_party_chk CHECK ((direction = 'received') = (customer_id IS NOT NULL)),
                ADD CONSTRAINT cheques_cleared_chk CHECK (direction <> 'received' OR status NOT IN ('cleared') OR payment_id IS NOT NULL)
            SQL);

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('cheque_id')->references('id')->on('cheques')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER cheques_no_delete BEFORE DELETE ON cheques FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques cannot be deleted'");

        // Spec §7.4 (received). M3b widens the transitions for issued cheques.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cheques_guard BEFORE UPDATE ON cheques FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'held' AND NEW.status IN ('deposited', 'returned', 'cancelled'))
                    OR (OLD.status = 'deposited' AND NEW.status IN ('cleared', 'bounced'))
                    OR (OLD.status = 'cleared' AND NEW.status = 'bounced')
                    OR (OLD.status = 'bounced' AND NEW.status IN ('replaced', 'returned'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: status change not allowed';
                END IF;
                IF NOT (NEW.direction <=> OLD.direction AND NEW.customer_id <=> OLD.customer_id AND NEW.owner_id <=> OLD.owner_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: the party never changes';
                END IF;
                IF OLD.status <> 'held' AND NOT (NEW.cheque_no <=> OLD.cheque_no AND NEW.bank_name <=> OLD.bank_name
                    AND NEW.cheque_date <=> OLD.cheque_date AND NEW.amount <=> OLD.amount AND NEW.agreement_id <=> OLD.agreement_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: terms are frozen once deposited';
                END IF;
                IF (OLD.payment_id IS NOT NULL AND NOT (NEW.payment_id <=> OLD.payment_id))
                    OR (OLD.replaced_by_cheque_id IS NOT NULL AND NOT (NEW.replaced_by_cheque_id <=> OLD.replaced_by_cheque_id)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cheques: payment and replacement are set once';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cheques_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS cheques_no_delete');
        Schema::table('payments', fn (Blueprint $table) => $table->dropForeign(['cheque_id']));
        Schema::dropIfExists('cheques');
    }
};
