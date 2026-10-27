<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * General ledger spec §3, §7 (plan rulings 5, 10 and 12): the chart of accounts, the one settings row, posting rules,
 * journals and their lines. The default chart and rules are inserted here, so the dev DB, production and every test
 * database get them without a seeder. Six triggers keep manual journals and everything on or before "Locked up to"
 * unchanged (EXPECTED_TRIGGERS 61 → 67). On an installed system the ledger permissions are granted additively.
 */
return new class extends Migration
{
    /** code, English, Arabic, type, system_key */
    private const array CHART = [
        ['1000', 'Cash on hand', 'النقد في الصندوق', 'asset', 'cash'],
        ['1010', 'Bank', 'البنك', 'asset', 'bank'],
        ['1020', 'Card clearing', 'تسويات البطاقات', 'asset', 'card_clearing'],
        ['1100', 'Tenant receivables', 'ذمم المستأجرين المدينة', 'asset', 'receivables'],
        ['2000', 'Tenant deposits held', 'تأمينات المستأجرين المحتفظ بها', 'liability', 'deposits_held'],
        ['2100', 'Owner payable', 'مستحقات الملاك', 'liability', 'owner_payable'],
        ['2200', 'Vendor payable', 'مستحقات الموردين', 'liability', 'vendor_payable'],
        ['2300', 'Tenant credit', 'أرصدة المستأجرين الدائنة', 'liability', 'tenant_credit'],
        ['2400', 'VAT payable', 'ضريبة القيمة المضافة المستحقة', 'liability', 'vat_payable'],
        ['3000', 'Opening balance equity', 'حقوق ملكية الأرصدة الافتتاحية', 'equity', 'opening_equity'],
        ['3100', 'Retained earnings', 'الأرباح المحتجزة', 'equity', 'retained_earnings'],
        ['4000', 'Rent income', 'إيرادات الإيجار', 'income', 'rent_income'],
        ['4100', 'Other charges income', 'إيرادات الرسوم الأخرى', 'income', 'other_income'],
        ['4200', 'Management fee income', 'إيرادات رسوم الإدارة', 'income', 'fee_income'],
        ['5000', 'Lease rent to owners', 'إيجار العقارات المستأجرة من الملاك', 'expense', 'lease_rent'],
        ['5100', 'Maintenance and repairs', 'الصيانة والإصلاحات', 'expense', 'expense_maintenance'],
        ['5110', 'Utilities', 'المرافق', 'expense', 'expense_utilities'],
        ['5120', 'Cleaning', 'التنظيف', 'expense', 'expense_cleaning'],
        ['5130', 'Security', 'الأمن والحراسة', 'expense', 'expense_security'],
        ['5140', 'Insurance', 'التأمين', 'expense', 'expense_insurance'],
        ['5150', 'Government fees', 'الرسوم الحكومية', 'expense', 'expense_government'],
        ['5160', 'Legal fees', 'الرسوم القانونية', 'expense', 'expense_legal'],
        ['5170', 'Commissions', 'العمولات', 'expense', 'expense_commission'],
        ['5190', 'Other expenses', 'مصروفات أخرى', 'expense', 'expense_other'],
    ];

    /** posting rule key => the system_key of its default account (plan ruling 10) */
    private const array RULES = [
        'receivables' => 'receivables', 'tenant_credit' => 'tenant_credit', 'deposits_held' => 'deposits_held',
        'owner_payable' => 'owner_payable', 'vendor_payable' => 'vendor_payable', 'vat_payable' => 'vat_payable',
        'rent_income' => 'rent_income', 'other_income' => 'other_income', 'fee_income' => 'fee_income',
        'lease_rent' => 'lease_rent', 'opening_equity' => 'opening_equity', 'other_payments_out' => 'expense_other',
        'method.cash' => 'cash', 'method.bank_transfer' => 'bank', 'method.cheque' => 'bank', 'method.card' => 'card_clearing',
        'category.maintenance' => 'expense_maintenance', 'category.utilities' => 'expense_utilities', 'category.cleaning' => 'expense_cleaning',
        'category.security' => 'expense_security', 'category.insurance' => 'expense_insurance', 'category.government' => 'expense_government',
        'category.legal' => 'expense_legal', 'category.commission' => 'expense_commission', 'category.other' => 'expense_other',
    ];

    /** role => permissions, as RolesAndPermissionsSeeder::matrix() grants them (plan ruling 12) */
    private const array GRANTS = ['admin' => ['ledger.view', 'ledger.admin'], 'finance' => ['ledger.view', 'ledger.manage']];

    private const array PERMISSIONS = ['ledger.view', 'ledger.manage', 'ledger.admin'];

    private const array TRIGGERS = ['journal_lines_delete_guard', 'journal_lines_insert_guard', 'journal_lines_no_update', 'journals_delete_guard', 'journals_insert_guard', 'journals_no_update'];

    private const string LOCKED = "(SELECT COALESCE(MAX(locked_until), '1000-01-01') FROM ledger_settings)";

    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name_en', 150);
            $table->string('name_ar', 150);
            $table->string('type', 10);
            $table->string('system_key', 40)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_chk CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense'))");

        Schema::create('ledger_settings', function (Blueprint $table) {
            $table->id();
            $table->date('start_date')->nullable();
            $table->date('locked_until')->nullable();
            $table->json('health')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->string('rebuild_status', 10)->nullable();
            $table->date('rebuild_from')->nullable();
            $table->date('rebuild_to')->nullable();
            $table->unsignedInteger('rebuild_done')->default(0);
            $table->unsignedInteger('rebuild_total')->default(0);
            $table->string('rebuild_message', 500)->nullable();
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_settings
                ADD CONSTRAINT ledger_settings_lock_chk CHECK (locked_until IS NULL OR (start_date IS NOT NULL AND locked_until >= start_date)),
                ADD CONSTRAINT ledger_settings_rebuild_chk CHECK (rebuild_status IS NULL OR rebuild_status IN ('queued', 'running', 'done', 'failed'))
            SQL);

        Schema::create('ledger_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('kind', 10);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 500);
            $table->boolean('late_posting')->default(false);
            $table->foreignId('reverses_journal_id')->nullable()->unique()->constrained('journals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']); // plan ruling 1: one journal per source
            $table->index(['date', 'id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE journals
                ADD CONSTRAINT journals_kind_chk CHECK (kind IN ('auto', 'manual', 'opening')),
                ADD CONSTRAINT journals_source_chk CHECK ((kind = 'auto') = (source_type IS NOT NULL) AND (source_type IS NULL) = (source_id IS NULL)),
                ADD CONSTRAINT journals_reverses_chk CHECK (reverses_journal_id IS NULL OR kind = 'manual'),
                ADD CONSTRAINT journals_late_chk CHECK (late_posting = 0 OR kind = 'auto')
            SQL);

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete(); // spec §3: an account with lines cannot be deleted
            $table->decimal('debit', 15, 3)->default(0);
            $table->decimal('credit', 15, 3)->default(0);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('memo', 255)->nullable();
            $table->index(['ledger_account_id', 'journal_id']);
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_side_chk CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');

        // Plan ruling 5. One statement per unprepared() call.
        DB::unprepared("CREATE TRIGGER journals_no_update BEFORE UPDATE ON journals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals are write-once: correct one by reversal'");
        DB::unprepared("CREATE TRIGGER journal_lines_no_update BEFORE UPDATE ON journal_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines are write-once'");
        DB::unprepared('CREATE TRIGGER journals_insert_guard BEFORE INSERT ON journals FOR EACH ROW
            BEGIN
                IF NEW.date <= '.self::LOCKED." THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals: that date is in a locked period';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER journals_delete_guard BEFORE DELETE ON journals FOR EACH ROW
            BEGIN
                IF OLD.kind = 'manual' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals: a manual journal is never deleted; reverse it';
                END IF;
                IF OLD.date <= ".self::LOCKED." THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals: that date is in a locked period';
                END IF;
            END");
        DB::unprepared('CREATE TRIGGER journal_lines_insert_guard BEFORE INSERT ON journal_lines FOR EACH ROW
            BEGIN
                IF (SELECT date FROM journals WHERE id = NEW.journal_id) <= '.self::LOCKED." THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: that date is in a locked period';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER journal_lines_delete_guard BEFORE DELETE ON journal_lines FOR EACH ROW
            BEGIN
                DECLARE k VARCHAR(10);
                DECLARE d DATE;
                SELECT kind, date INTO k, d FROM journals WHERE id = OLD.journal_id;
                IF k = 'manual' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: a manual journal never changes; reverse it';
                END IF;
                IF d <= ".self::LOCKED." THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines: that date is in a locked period';
                END IF;
            END");

        // Plan ruling 10: the default chart, its rules and the one settings row.
        $now = now();
        foreach (self::CHART as [$code, $en, $ar, $type, $key]) {
            DB::table('ledger_accounts')->insert(['code' => $code, 'name_en' => $en, 'name_ar' => $ar, 'type' => $type, 'system_key' => $key,
                'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('ledger_accounts')->pluck('id', 'system_key');
        foreach (self::RULES as $rule => $key) {
            DB::table('ledger_rules')->insert(['key' => $rule, 'ledger_account_id' => $ids[$key], 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('ledger_settings')->insert(['created_at' => $now, 'updated_at' => $now]);

        // Plan ruling 12: a fresh install has no roles yet and gets these from RolesAndPermissionsSeeder (rms:install).
        if (Role::query()->exists()) {
            foreach (self::PERMISSIONS as $name) {
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
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('ledger_rules');
        Schema::dropIfExists('ledger_settings');
        Schema::dropIfExists('ledger_accounts');
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
