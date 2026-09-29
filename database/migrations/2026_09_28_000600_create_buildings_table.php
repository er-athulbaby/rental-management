<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 30)->unique();
            $table->string('location', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('type', 20)->default('residential');
            $table->unsignedSmallInteger('floors_count')->nullable();
            $table->string('parking', 150)->nullable();
            $table->text('facilities')->nullable();
            $table->foreignId('property_manager_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE buildings ADD CONSTRAINT buildings_type_chk CHECK (type IN ('residential', 'commercial', 'mixed'))");

        Schema::create('building_user', function (Blueprint $table) {
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->primary(['building_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_user');
        Schema::dropIfExists('buildings');
    }
};
