<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // One statement per unprepared() call (spec §8.5).
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER activity_log_no_update BEFORE UPDATE ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only'");
        DB::unprepared("CREATE TRIGGER activity_log_no_delete BEFORE DELETE ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_no_delete');
    }
};
