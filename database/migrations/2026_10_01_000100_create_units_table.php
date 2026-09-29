<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('floor', 10)->nullable();
            $table->string('use', 20);
            $table->string('type', 20);
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->decimal('area_sqm', 8, 2)->nullable();
            $table->string('furnishing', 20)->default('unfurnished');
            $table->decimal('list_rent', 12, 3)->default(0);
            $table->decimal('list_deposit', 12, 3)->default(0);
            $table->decimal('list_service_charge', 12, 3)->default(0);
            $table->string('default_tax_category', 20)->nullable();
            $table->string('ewa_account_no', 30)->nullable();
            $table->boolean('blocked')->default(false);
            $table->string('blocked_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['building_id', 'code']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE units
                ADD CONSTRAINT units_use_chk CHECK (`use` IN ('residential', 'commercial')),
                ADD CONSTRAINT units_type_chk CHECK (type IN ('flat', 'villa', 'studio', 'shop', 'office', 'showroom', 'warehouse', 'other')),
                ADD CONSTRAINT units_furnishing_chk CHECK (furnishing IN ('unfurnished', 'semi', 'furnished')),
                ADD CONSTRAINT units_tax_chk CHECK (default_tax_category IS NULL OR default_tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT units_money_chk CHECK (list_rent >= 0 AND list_deposit >= 0 AND list_service_charge >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
