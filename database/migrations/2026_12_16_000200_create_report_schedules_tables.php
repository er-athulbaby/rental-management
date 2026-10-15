<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_report_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 10);
            $table->unsignedTinyInteger('day')->nullable();
            $table->unsignedTinyInteger('hour');
            $table->timestamp('next_run_at');
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['paused_at', 'next_run_at']);
        });
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_frequency_chk CHECK (frequency IN ('daily', 'weekly', 'monthly'))");
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_hour_chk CHECK (hour <= 23)');

        Schema::create('report_schedule_user', function (Blueprint $table) {
            $table->foreignId('report_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['report_schedule_id', 'user_id']);
        });

        // One row per schedule per due time: the guarantee against sending twice (spec §7).
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_schedule_id')->constrained()->cascadeOnDelete();
            $table->timestamp('due_at');
            $table->string('status', 10);
            $table->unsignedSmallInteger('recipients_count')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['report_schedule_id', 'due_at']);
        });
        DB::statement("ALTER TABLE report_deliveries ADD CONSTRAINT report_deliveries_status_chk CHECK (status IN ('sending', 'sent', 'failed', 'skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_schedule_user');
        Schema::dropIfExists('report_schedules');
    }
};
