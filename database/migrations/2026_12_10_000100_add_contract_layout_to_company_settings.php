<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Blank space at the top of page 1, so contracts can be printed on official stamp paper.
            $table->unsignedSmallInteger('contract_stamp_space_mm')->default(0)->after('logo_path');
            $table->string('contract_header_path')->nullable()->after('contract_stamp_space_mm'); // company letterhead image
        });

        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT company_settings_stamp_space_chk CHECK (contract_stamp_space_mm <= 120)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE company_settings DROP CHECK company_settings_stamp_space_chk');
        Schema::table('company_settings', fn (Blueprint $table) => $table->dropColumn(['contract_stamp_space_mm', 'contract_header_path']));
    }
};
