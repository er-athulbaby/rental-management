<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->foreignId('disbursement_id')->nullable()->after('payment_id')->constrained()->restrictOnDelete();
        });
        Schema::table('disbursements', function (Blueprint $table) {
            $table->foreign('cheque_id')->references('id')->on('cheques')->restrictOnDelete();
        });

        // A received cheque comes from a customer; an issued one goes to exactly one payee (spec §7.4).
        DB::statement('ALTER TABLE cheques DROP CHECK cheques_party_chk');
        DB::statement(<<<'SQL'
            ALTER TABLE cheques ADD CONSTRAINT cheques_party_chk CHECK (
                (direction = 'received' AND customer_id IS NOT NULL AND owner_id IS NULL)
                OR (direction = 'issued' AND ((customer_id IS NULL) <> (owner_id IS NULL))))
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

    public function down(): void
    {
        // Restoring M3a's trigger and check is not supported; roll back with migrate:fresh.
        Schema::table('disbursements', fn (Blueprint $table) => $table->dropForeign(['cheque_id']));
        Schema::table('cheques', fn (Blueprint $table) => $table->dropConstrainedForeignId('disbursement_id'));
    }
};
