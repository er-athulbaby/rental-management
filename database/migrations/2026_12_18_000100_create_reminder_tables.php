<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-17 §8: reminder settings (one row per type, all off), the reminder log, and the tenant's opt-out.
 * Plan ruling 3: automatic rows are unique through a generated key; MySQL forbids FK actions on a generated column's
 * base column, so customer_id and sent_by have none (customers are only soft-deleted). No triggers.
 */
return new class extends Migration
{
    /** type => [days, intro_en, intro_ar]. */
    private const array DEFAULTS = [
        'overdue' => [[1, 7, 14], 'Our records show the following amounts are overdue. Please arrange payment as soon as possible.',
            'تشير سجلاتنا إلى أن المبالغ التالية متأخرة السداد. يرجى المبادرة بالدفع في أقرب وقت.'],
        'rent_due' => [[3], 'This is a friendly reminder that the following rent is due soon.', 'نود تذكيركم بأن الإيجار التالي يستحق قريباً.'],
        'cheque' => [[2], 'The following cheque will be presented to the bank soon.', 'سيتم تقديم الشيك التالي إلى البنك قريباً.'],
        'renewal' => [[30, 60], 'Your tenancy agreement is ending soon.', 'تقترب اتفاقية الإيجار الخاصة بكم من نهايتها.'],
    ];

    public function up(): void
    {
        Schema::create('reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->unique();
            $table->boolean('enabled')->default(false);
            $table->json('days');
            $table->string('intro_en', 300);
            $table->string('intro_ar', 300);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE reminder_settings ADD CONSTRAINT reminder_settings_type_chk CHECK (type IN ('overdue', 'rent_due', 'cheque', 'renewal'))");

        $now = now();
        DB::table('reminder_settings')->insert(array_map(
            fn (string $type, array $d) => ['type' => $type, 'enabled' => false, 'days' => json_encode($d[0]), 'intro_en' => $d[1], 'intro_ar' => $d[2],
                'created_at' => $now, 'updated_at' => $now],
            array_keys(self::DEFAULTS), self::DEFAULTS,
        ));

        Schema::create('reminder_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('type', 20);
            $table->string('item_type', 20);
            $table->unsignedBigInteger('item_id');
            $table->unsignedSmallInteger('rule_day')->nullable();
            $table->date('sent_on');
            $table->string('channel', 20)->default('email');
            $table->string('status', 10);
            $table->string('reason', 500)->nullable();
            $table->boolean('manual')->default(false);
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->char('batch', 36);
            $table->timestamps();
            $table->index(['item_type', 'item_id']);
            $table->index('sent_on');
            $table->index('batch');
        });

        // Automatic rows: one per tenant, type, item, rule day and date. NULLs never collide, so manual rows are free.
        DB::statement(<<<'SQL'
            ALTER TABLE reminder_sends
                ADD COLUMN auto_key VARCHAR(120) GENERATED ALWAYS AS (IF(manual, NULL, CONCAT_WS('#', customer_id, type, item_type, item_id, rule_day, sent_on))) STORED,
                ADD UNIQUE KEY reminder_sends_auto_key_unique (auto_key),
                ADD CONSTRAINT reminder_sends_type_chk CHECK (type IN ('overdue', 'rent_due', 'cheque', 'renewal')),
                ADD CONSTRAINT reminder_sends_item_chk CHECK (item_type IN ('invoice', 'cheque', 'agreement')),
                ADD CONSTRAINT reminder_sends_status_chk CHECK (status IN ('sending', 'sent', 'failed', 'skipped')),
                ADD CONSTRAINT reminder_sends_rule_chk CHECK (manual OR rule_day IS NOT NULL)
            SQL);

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('no_reminders')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('no_reminders'));
        Schema::dropIfExists('reminder_sends');
        Schema::dropIfExists('reminder_settings');
    }
};
