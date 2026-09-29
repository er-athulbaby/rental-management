<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            // Not auto-increment: MySQL forbids CHECK constraints on auto-increment columns (error 3818).
            $table->unsignedTinyInteger('id')->default(1)->primary();
            $table->string('name_en', 150);
            $table->string('name_ar', 150)->nullable();
            $table->string('cr_number', 30)->nullable();
            $table->text('address_en')->nullable();
            $table->text('address_ar')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->string('trn', 20)->nullable();
            $table->decimal('vat_rate', 5, 2)->default(10.00);
            $table->string('residential_tax_category', 20)->default('exempt');
            $table->string('commercial_tax_category', 20)->default('standard');
            $table->char('currency_code', 3)->default('BHD');
            $table->string('date_format', 10)->default('d/m/Y');
            $table->unsignedSmallInteger('default_grace_days')->default(5);
            $table->unsignedSmallInteger('invoice_lead_days')->default(7);
            $table->string('proration_basis', 20)->default('actual_365');
            $table->boolean('require_different_approver')->default(true);
            $table->timestamp('go_live_at')->nullable();
            $table->timestamps();
        });

        // Blueprint has no check() in Laravel 13: one ALTER per constraint set.
        DB::statement(<<<'SQL'
            ALTER TABLE company_settings
                ADD CONSTRAINT company_settings_single_row CHECK (id = 1),
                ADD CONSTRAINT company_settings_residential_tax_chk CHECK (residential_tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT company_settings_commercial_tax_chk CHECK (commercial_tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT company_settings_proration_chk CHECK (proration_basis IN ('actual_365', 'days_30'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
