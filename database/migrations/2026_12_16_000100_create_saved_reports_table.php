<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user's own report: a validated description of what to show (spec §9). Never SQL.
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('subject', 40);
            $table->json('definition');
            $table->string('visibility', 10)->default('private');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['visibility', 'created_by']);
        });
        DB::statement("ALTER TABLE saved_reports ADD CONSTRAINT saved_reports_visibility_chk CHECK (visibility IN ('private', 'shared'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
