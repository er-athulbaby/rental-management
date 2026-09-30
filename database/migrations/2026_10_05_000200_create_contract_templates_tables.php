<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // At most one default: NULLs never collide in a UNIQUE index.
        DB::statement(<<<'SQL'
            ALTER TABLE contract_templates
                ADD COLUMN default_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(is_default, 1, NULL)) STORED,
                ADD UNIQUE KEY contract_templates_one_default (default_key),
                ADD CONSTRAINT contract_templates_default_active_chk CHECK (NOT is_default OR active)
            SQL);

        Schema::create('contract_template_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_template_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('heading_en', 150);
            $table->string('heading_ar', 150);
            $table->text('body_en');
            $table->text('body_ar');
            $table->timestamps();
            $table->unique(['contract_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_template_clauses');
        Schema::dropIfExists('contract_templates');
    }
};
