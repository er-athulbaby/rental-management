<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->boolean('to_return')->default(false)->after('status'); // re-billing left it without an invoice (spec §6.3)
        });
    }

    public function down(): void
    {
        Schema::table('cheques', fn (Blueprint $table) => $table->dropColumn('to_return'));
    }
};
