<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->string('category', 30);
            $table->string('disk', 20)->default('local');
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime', 150);
            $table->unsignedInteger('size');
            $table->date('expires_on')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_category_chk CHECK (category IN ('photo', 'id_copy', 'cr_copy', 'signed_contract', 'cheque_image', 'move_out_photo', 'owner_approval', 'generated_pdf', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
