<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** code => [name, default use]. The first eight codes are the ones units already hold. */
    private const array DEFAULTS = [
        'flat' => ['Flat / Apartment', 'residential'],
        'studio' => ['Studio', 'residential'],
        'villa' => ['Villa', 'residential'],
        'townhouse' => ['Townhouse', 'residential'],
        'duplex' => ['Duplex', 'residential'],
        'penthouse' => ['Penthouse', 'residential'],
        'room' => ['Room', 'residential'],
        'labour_accommodation' => ['Labour accommodation', 'residential'],
        'shop' => ['Shop', 'commercial'],
        'office' => ['Office', 'commercial'],
        'showroom' => ['Showroom', 'commercial'],
        'clinic' => ['Clinic', 'commercial'],
        'restaurant' => ['Restaurant / Café', 'commercial'],
        'kiosk' => ['Kiosk', 'commercial'],
        'warehouse' => ['Warehouse / Store', 'commercial'],
        'workshop' => ['Workshop', 'commercial'],
        'land' => ['Land / Plot', 'commercial'],
        'parking' => ['Parking space', 'commercial'],
        'other' => ['Other', null],
    ];

    public function up(): void
    {
        // Unit types are a list the Admin manages. units.type keeps the type's code, so renaming a type changes no unit.
        Schema::create('unit_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60)->unique();
            $table->string('default_use', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE unit_types ADD CONSTRAINT unit_types_use_chk CHECK (default_use IN ('residential', 'commercial'))");

        $now = now();
        DB::table('unit_types')->insert(array_map(
            fn (string $code, array $t) => ['code' => $code, 'name' => $t[0], 'default_use' => $t[1], 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            array_keys(self::DEFAULTS), self::DEFAULTS,
        ));

        DB::statement('ALTER TABLE units DROP CHECK units_type_chk');
        Schema::table('units', fn (Blueprint $table) => $table->foreign('type')->references('code')->on('unit_types')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('units', fn (Blueprint $table) => $table->dropForeign(['type']));
        DB::table('units')->whereNotIn('type', ['flat', 'villa', 'studio', 'shop', 'office', 'showroom', 'warehouse', 'other'])->update(['type' => 'other']);
        DB::statement("ALTER TABLE units ADD CONSTRAINT units_type_chk CHECK (type IN ('flat', 'villa', 'studio', 'shop', 'office', 'showroom', 'warehouse', 'other'))");
        Schema::dropIfExists('unit_types');
    }
};
