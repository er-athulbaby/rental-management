<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A split payment (e.g. card 300 + cash 200) is one payment and one receipt; its parts are its tenders.
        DB::statement('ALTER TABLE payments DROP CHECK payments_method_chk');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_chk CHECK (method IN ('cash', 'bank_transfer', 'cheque', 'card', 'deposit_applied', 'split'))");

        Schema::create('payment_tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 12, 3);
            $table->string('reference', 100)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_tenders
                ADD CONSTRAINT payment_tenders_method_chk CHECK (method IN ('cash', 'bank_transfer', 'card')),
                ADD CONSTRAINT payment_tenders_amount_chk CHECK (amount > 0)
            SQL);

        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'payment_tenders_no_'.strtolower($event);
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON payment_tenders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_tenders are write-once'");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_tenders_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_tenders_no_delete');
        Schema::dropIfExists('payment_tenders');
        DB::statement('ALTER TABLE payments DROP CHECK payments_method_chk');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_chk CHECK (method IN ('cash', 'bank_transfer', 'cheque', 'card', 'deposit_applied'))");
    }
};
