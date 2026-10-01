<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Plan ruling 5: rent-schedule lines remember their charge, so re-billing matches them exactly. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('agreement_unit_charge_id')->nullable()->after('agreement_unit_id')->constrained()->restrictOnDelete();
        });

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER invoice_lines_charge_guard BEFORE UPDATE ON invoice_lines FOR EACH ROW FOLLOWS invoice_lines_paid_only_issued
            BEGIN
                IF NOT (NEW.agreement_unit_charge_id <=> OLD.agreement_unit_charge_id)
                    AND (SELECT status FROM invoices WHERE id = OLD.invoice_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_lines: the charge is frozen once the invoice is not draft';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_lines_charge_guard');
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('agreement_unit_charge_id'));
    }
};
