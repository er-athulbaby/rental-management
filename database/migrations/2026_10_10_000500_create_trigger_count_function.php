<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec §8.5: rms_app cannot read information_schema.TRIGGERS; this definer-rights function (owned by rms_migrate,
 * which runs the migration) counts them for the integrity check. rms_app has EXECUTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        // migrate:fresh drops tables, not functions, so a re-run must replace the old one.
        DB::unprepared('DROP FUNCTION IF EXISTS rms_trigger_count');
        DB::unprepared(<<<'DDL'
            CREATE FUNCTION rms_trigger_count() RETURNS INT
                READS SQL DATA
                SQL SECURITY DEFINER
                RETURN (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE())
            DDL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS rms_trigger_count');
    }
};
