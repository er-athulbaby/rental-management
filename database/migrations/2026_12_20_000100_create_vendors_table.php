<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Spec 2026-10-17 maintenance §3.1 and §6: vendors, and the two maintenance permissions on an installed system (plan
 * ruling 1). A fresh install has no roles yet and gets the permissions from RolesAndPermissionsSeeder (rms:install).
 */
return new class extends Migration
{
    /** role => permissions, as RolesAndPermissionsSeeder::matrix() grants them. */
    private const array GRANTS = [
        'admin' => ['maintenance.view', 'maintenance.manage'],
        'management' => ['maintenance.view'],
        'property-manager' => ['maintenance.view', 'maintenance.manage'],
        'leasing' => ['maintenance.view'],
        'vendor-support' => ['maintenance.view', 'maintenance.manage'],
    ];

    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('type', 20);
            $table->json('trades');
            $table->string('mobile', 30);
            $table->string('email')->nullable();
            $table->string('cr_number', 30)->nullable();
            $table->string('vat_number', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_type_chk CHECK (type IN ('company', 'individual', 'own_staff'))");

        if (Role::query()->exists()) {
            foreach (['maintenance.view', 'maintenance.manage'] as $name) {
                Permission::findOrCreate($name, 'web');
            }
            foreach (self::GRANTS as $role => $permissions) {
                Role::query()->where('name', $role)->first()?->givePermissionTo($permissions); // additive: keeps Admin's role edits
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
        Permission::query()->whereIn('name', ['maintenance.view', 'maintenance.manage'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
