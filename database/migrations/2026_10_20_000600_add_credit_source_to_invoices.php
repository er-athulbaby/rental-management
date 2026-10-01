<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Re-billing nets only its own credit notes ('rebill'); a discretionary credit note (null) stays a concession. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('credit_source', 20)->nullable()->after('credit_reason');
        });

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER invoices_credit_source_guard BEFORE UPDATE ON invoices FOR EACH ROW FOLLOWS invoices_paid_only_issued
            BEGIN
                IF NOT (NEW.credit_source <=> OLD.credit_source) AND OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoices: the credit source is frozen once the invoice is not draft';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_credit_source_guard');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('credit_source'));
    }
};
