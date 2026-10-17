<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-17 §5, §7: report packages, their items, and schedules for a saved report OR a package.
 * Plan ruling 5: MySQL refuses a CHECK on a column a foreign-key action uses, so the CHECKed references have no
 * ON DELETE action. Both parents are soft-deleted; deleting a package removes its schedules in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('visibility', 10)->default('private');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['visibility', 'created_by']);
        });
        DB::statement("ALTER TABLE report_packages ADD CONSTRAINT report_packages_visibility_chk CHECK (visibility IN ('private', 'shared'))");

        Schema::create('report_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_package_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('kind', 10);
            $table->foreignId('saved_report_id')->nullable()->constrained();
            $table->string('report', 40)->nullable();
            $table->json('settings');
            $table->timestamps();
            $table->unique(['report_package_id', 'position']);
        });
        DB::statement("ALTER TABLE report_package_items ADD CONSTRAINT report_package_items_kind_chk CHECK (
            (kind = 'saved' AND saved_report_id IS NOT NULL AND report IS NULL)
            OR (kind = 'ready_made' AND report IS NOT NULL AND saved_report_id IS NULL))");

        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropForeign(['saved_report_id']);
        });
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('saved_report_id')->nullable()->change();
            $table->foreign('saved_report_id')->references('id')->on('saved_reports');
            $table->foreignId('report_package_id')->nullable()->after('saved_report_id')->constrained();
        });
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_target_chk CHECK ((saved_report_id IS NULL) <> (report_package_id IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE report_schedules DROP CHECK report_schedules_target_chk');
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('report_package_id');
            $table->dropForeign(['saved_report_id']);
        });
        DB::table('report_schedules')->whereNull('saved_report_id')->delete(); // package schedules cannot go back
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('saved_report_id')->nullable(false)->change();
            $table->foreign('saved_report_id')->references('id')->on('saved_reports')->cascadeOnDelete();
        });
        Schema::dropIfExists('report_package_items');
        Schema::dropIfExists('report_packages');
    }
};
