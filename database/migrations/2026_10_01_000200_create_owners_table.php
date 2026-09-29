<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name_en', 150);
            $table->string('name_ar', 150)->nullable();
            $table->string('id_type', 20);
            $table->string('id_number', 30);
            $table->string('nationality', 60)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->timestamp('bank_changed_at')->nullable();
            $table->foreignId('bank_changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['id_type', 'id_number']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE owners
                ADD CONSTRAINT owners_type_chk CHECK (type IN ('person', 'company')),
                ADD CONSTRAINT owners_id_type_chk CHECK (id_type IN ('cpr', 'passport', 'cr'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('owners');
    }
};
