<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Facilities are a list the Admin manages; a building ticks the ones it has.
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('building_facility', function (Blueprint $table) {
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->primary(['building_id', 'facility_id']);
        });

        Schema::table('buildings', fn (Blueprint $table) => $table->dropColumn('facilities'));

        // Parking is a fixed choice. Free text from before can't be mapped, so it is cleared.
        DB::table('buildings')->whereNotIn('parking', ['none', 'open', 'covered', 'basement', 'street'])->update(['parking' => null]);
        Schema::table('buildings', fn (Blueprint $table) => $table->string('parking', 20)->nullable()->change());
        DB::statement("ALTER TABLE buildings ADD CONSTRAINT buildings_parking_chk CHECK (parking IN ('none', 'open', 'covered', 'basement', 'street'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE buildings DROP CHECK buildings_parking_chk');
        Schema::table('buildings', function (Blueprint $table) {
            $table->string('parking', 150)->nullable()->change();
            $table->text('facilities')->nullable();
        });
        Schema::dropIfExists('building_facility');
        Schema::dropIfExists('facilities');
    }
};
