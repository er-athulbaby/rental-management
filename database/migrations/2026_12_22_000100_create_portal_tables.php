<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-18 tenant portal §3 and §5: portal accounts and sign-in codes, and the portal account as the other
 * possible creator of a maintenance request, its timeline rows (plan ruling 4) and a document. Not money tables: no
 * triggers (EXPECTED_TRIGGERS unchanged).
 */
return new class extends Migration
{
    private const string OLD_CHANNELS = "'phone', 'whatsapp', 'walk_in', 'inspection', 'email'";

    /** table => the staff creator column that becomes nullable */
    private const array CREATORS = ['maintenance_requests' => 'created_by', 'maintenance_events' => 'user_id', 'documents' => 'uploaded_by'];

    public function up(): void
    {
        Schema::create('portal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained(); // no cascade: customers are soft-deleted
            $table->string('email')->unique();
            $table->string('locale', 2)->default('en');
            $table->rememberToken();
            $table->timestamp('invited_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE portal_accounts ADD CONSTRAINT portal_accounts_locale_chk CHECK (locale IN ('en', 'ar'))");

        Schema::create('portal_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_account_id')->constrained();
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['portal_account_id', 'used_at']);
        });

        foreach (self::CREATORS as $name => $creator) {
            Schema::table($name, function (Blueprint $table) use ($creator) {
                $table->unsignedBigInteger($creator)->nullable()->change();
                $table->foreignId('portal_account_id')->nullable()->after($creator)->constrained();
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_creator_chk CHECK (({$creator} IS NULL) <> (portal_account_id IS NULL))");
        }
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('shared_with_tenant')->default(false)->after('expires_on');
        });

        DB::statement('ALTER TABLE maintenance_requests DROP CHECK maintenance_requests_channel_chk');
        DB::statement('ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_channel_chk CHECK (channel IN ('.self::OLD_CHANNELS.", 'portal'))");
    }

    /** ponytail: only reversible before any portal row exists; with portal rows the NOT NULL changes fail, which is the safe outcome. */
    public function down(): void
    {
        DB::statement('ALTER TABLE maintenance_requests DROP CHECK maintenance_requests_channel_chk');
        DB::statement('ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_channel_chk CHECK (channel IN ('.self::OLD_CHANNELS.'))');
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('shared_with_tenant'));

        foreach (self::CREATORS as $name => $creator) {
            DB::statement("ALTER TABLE {$name} DROP CHECK {$name}_creator_chk");
            Schema::table($name, function (Blueprint $table) use ($creator) {
                $table->dropConstrainedForeignId('portal_account_id');
                $table->unsignedBigInteger($creator)->nullable(false)->change();
            });
        }

        Schema::dropIfExists('portal_login_codes');
        Schema::dropIfExists('portal_accounts');
    }
};
