<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->string('import_ref', 40)->nullable()->unique()->after('number'); // the legacy system's reference (spec §11)
        });
    }

    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->dropUnique(['import_ref']);
            $table->dropColumn('import_ref');
        });
    }
};
