<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A token per rebuild: a leg left in a backed-up queue after its rebuild was failed must not write into the next one. */
    public function up(): void
    {
        Schema::table('ledger_settings', function (Blueprint $table) {
            $table->string('rebuild_run', 36)->nullable()->after('rebuild_message');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_settings', function (Blueprint $table) {
            $table->dropColumn('rebuild_run');
        });
    }
};
