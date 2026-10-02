<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_contract_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('owner_statement_id')->nullable(); // its foreign key comes with owner_statements (Task 5)
            $table->string('type', 20);
            $table->decimal('net', 12, 3);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('amount', 12, 3);
            $table->timestamp('posted_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['owner_contract_id', 'posted_at']);
        });

        // amount: + = the company owes the owner. A fee is owed by the owner, with its VAT, and belongs to a statement.
        DB::statement(<<<'SQL'
            ALTER TABLE owner_charges
                ADD CONSTRAINT owner_charges_type_chk CHECK (type IN ('management_fee', 'opening_balance')),
                ADD CONSTRAINT owner_charges_amount_chk CHECK (amount <> 0),
                ADD CONSTRAINT owner_charges_fee_chk CHECK (type <> 'management_fee'
                    OR (net > 0 AND tax_amount >= 0 AND amount = -(net + tax_amount) AND owner_statement_id IS NOT NULL)),
                ADD CONSTRAINT owner_charges_opening_chk CHECK (type <> 'opening_balance'
                    OR (tax_amount = 0 AND amount = net AND owner_statement_id IS NULL))
            SQL);

        DB::unprepared("CREATE TRIGGER owner_charges_no_update BEFORE UPDATE ON owner_charges FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_charges are write-once'");
        DB::unprepared("CREATE TRIGGER owner_charges_no_delete BEFORE DELETE ON owner_charges FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_charges are write-once'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS owner_charges_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS owner_charges_no_update');
        Schema::dropIfExists('owner_charges');
    }
};
