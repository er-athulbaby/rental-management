<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Email (SMTP) settings an Admin edits on screen: one row, created on the first save. No row means `.env` decides. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('encryption', 4)->default('tls');
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted by the model's cast
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE mail_settings ADD CONSTRAINT mail_settings_encryption_chk CHECK (encryption IN ('none', 'tls', 'ssl'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
