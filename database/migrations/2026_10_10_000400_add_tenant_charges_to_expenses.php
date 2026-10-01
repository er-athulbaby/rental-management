<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('agreement_unit_id')->nullable()->after('owner_contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->after('agreement_unit_id')->constrained()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expenses
                ADD CONSTRAINT expenses_tenant_chk CHECK ((charge_to = 'tenant') = (agreement_unit_id IS NOT NULL) AND (charge_to = 'tenant') = (invoice_id IS NOT NULL))
            SQL);

        // Spec §8.5: charge_to and attribution are frozen; the tenant charge's links with them.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER expenses_tenant_guard BEFORE UPDATE ON expenses FOR EACH ROW FOLLOWS expenses_guard
            BEGIN
                IF NOT (NEW.agreement_unit_id <=> OLD.agreement_unit_id AND NEW.invoice_id <=> OLD.invoice_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'expenses: the tenant charge is frozen';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS expenses_tenant_guard');
        DB::statement('ALTER TABLE expenses DROP CHECK expenses_tenant_chk');
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('agreement_unit_id');
        });
    }
};
