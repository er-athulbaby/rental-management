<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-21 owner portal §3 and §5: a portal account holds a tenant, an owner or both; documents get "Share with
 * owner". Not money tables: no triggers (EXPECTED_TRIGGERS unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->foreignId('owner_id')->nullable()->unique()->after('customer_id')->constrained(); // no cascade: owners are soft-deleted
        });
        DB::statement('ALTER TABLE portal_accounts ADD CONSTRAINT portal_accounts_holder_chk CHECK (customer_id IS NOT NULL OR owner_id IS NOT NULL)');

        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('shared_with_owner')->default(false)->after('shared_with_tenant');
        });
    }

    /** ponytail: reversible only while every account still has a tenant; an owner-only row makes the NOT NULL change fail, which is the safe outcome. */
    public function down(): void
    {
        // MySQL commits each DDL statement on its own, so refuse before the first one rather than fail halfway.
        if (DB::table('portal_accounts')->whereNull('customer_id')->exists()) {
            throw new RuntimeException('Cannot roll back: owner-only portal accounts exist and must be removed first.');
        }
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('shared_with_owner'));
        DB::statement('ALTER TABLE portal_accounts DROP CHECK portal_accounts_holder_chk');
        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
    }
};
