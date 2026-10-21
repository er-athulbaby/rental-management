<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-21 custom fields §6: field definitions, choice lists and one typed value row per record per field, plus
 * the "custom_field" document category. Not money tables: no triggers (EXPECTED_TRIGGERS unchanged).
 */
return new class extends Migration
{
    private const string CATEGORIES = "'photo', 'id_copy', 'cr_copy', 'signed_contract', 'cheque_image', 'move_out_photo', 'owner_approval', 'generated_pdf', 'other', 'maintenance'";

    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 20);
            $table->string('key', 60);
            $table->string('label_en', 100);
            $table->string('label_ar', 100)->nullable();
            $table->string('type', 20);
            $table->string('help', 255)->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('in_portal')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['entity', 'key']);
            $table->index(['entity', 'position']);
        });
        DB::statement("ALTER TABLE custom_fields
            ADD CONSTRAINT custom_fields_entity_chk CHECK (entity IN ('customer', 'unit', 'agreement', 'owner', 'building')),
            ADD CONSTRAINT custom_fields_type_chk CHECK (type IN ('text', 'long_text', 'number', 'date', 'bool', 'choice', 'file'))");

        Schema::create('custom_field_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete(); // a field is deleted only with no values, so its choices are unused
            $table->string('label_en', 100);
            $table->string('label_ar', 100)->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained(); // no cascade: a field with values is archived, never deleted
            $table->string('entity', 20);
            $table->unsignedBigInteger('entity_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 15, 3)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_bool')->nullable();
            $table->foreignId('choice_id')->nullable()->constrained('custom_field_choices'); // no cascade: a used choice is hidden, never deleted
            $table->foreignId('document_id')->nullable()->constrained('documents'); // documents are soft-deleted
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['custom_field_id', 'entity_id']);
            $table->index(['entity', 'entity_id']);
        });
        DB::statement('ALTER TABLE custom_field_values ADD CONSTRAINT custom_field_values_one_value_chk CHECK (
            (value_text IS NOT NULL) + (value_number IS NOT NULL) + (value_date IS NOT NULL) + (value_bool IS NOT NULL)
            + (choice_id IS NOT NULL) + (document_id IS NOT NULL) <= 1)');

        DB::statement('ALTER TABLE documents DROP CHECK documents_category_chk');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_category_chk CHECK (category IN ('.self::CATEGORIES.", 'custom_field'))");
    }

    /** ponytail: the category CHECK goes back first, so a custom-field document makes down() fail before any table is dropped. */
    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CHECK documents_category_chk');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_category_chk CHECK (category IN ('.self::CATEGORIES.'))');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_choices');
        Schema::dropIfExists('custom_fields');
    }
};
