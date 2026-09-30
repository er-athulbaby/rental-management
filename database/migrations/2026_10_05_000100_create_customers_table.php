<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name_en', 150);
            $table->string('name_ar', 150)->nullable();
            $table->string('id_type', 20);
            $table->string('id_number', 30);
            $table->string('nationality', 60)->nullable();
            $table->string('mobile', 30);
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['id_type', 'id_number']);
            $table->index('mobile');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_type_chk CHECK (type IN ('individual', 'company')),
                ADD CONSTRAINT customers_id_type_chk CHECK ((type = 'company' AND id_type = 'cr') OR (type = 'individual' AND id_type IN ('cpr', 'passport')))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
