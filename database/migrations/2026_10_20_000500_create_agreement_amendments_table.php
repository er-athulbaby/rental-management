<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('effective_date');
            $table->foreignId('agreement_unit_id')->nullable()->constrained()->restrictOnDelete(); // release_unit
            $table->json('data')->nullable(); // add_unit: unit_id, deposit_amount, charges
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['agreement_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE agreement_amendments
                ADD CONSTRAINT agreement_amendments_type_chk CHECK (type IN ('add_unit', 'release_unit', 'terminate')),
                ADD CONSTRAINT agreement_amendments_status_chk CHECK (status IN ('draft', 'pending_approval', 'approved')),
                ADD CONSTRAINT agreement_amendments_target_chk CHECK ((type = 'release_unit') = (agreement_unit_id IS NOT NULL)
                    AND (type = 'add_unit') = (data IS NOT NULL)),
                ADD CONSTRAINT agreement_amendments_applied_chk CHECK (applied_at IS NULL OR status = 'approved')
            SQL);

        Schema::table('agreement_units', function (Blueprint $table) {
            $table->foreignId('amendment_id')->nullable()->after('agreement_id')->constrained('agreement_amendments')->restrictOnDelete();
        });

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_amendments_no_delete BEFORE DELETE ON agreement_amendments FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_amendments: only drafts can be deleted';
                END IF;
            END
            DDL);

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_amendments_guard BEFORE UPDATE ON agreement_amendments FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'approved'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_amendments: status change not allowed';
                END IF;
                IF OLD.status <> 'draft' AND NOT (NEW.agreement_id <=> OLD.agreement_id AND NEW.type <=> OLD.type
                    AND NEW.effective_date <=> OLD.effective_date AND NEW.agreement_unit_id <=> OLD.agreement_unit_id
                    AND NEW.data <=> OLD.data AND NEW.reason <=> OLD.reason AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_amendments: frozen once submitted';
                END IF;
                IF OLD.applied_at IS NOT NULL AND NOT (NEW.applied_at <=> OLD.applied_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_amendments: applied once';
                END IF;
            END
            DDL);

        // Plan ruling 6: rows enter a non-draft agreement only through an approved, not-yet-applied add_unit amendment.
        DB::unprepared('DROP TRIGGER IF EXISTS agreement_units_insert');
        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_units_insert BEFORE INSERT ON agreement_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM agreements WHERE id = NEW.agreement_id) <> 'draft' AND NOT EXISTS (
                    SELECT 1 FROM agreement_amendments m WHERE m.id = NEW.amendment_id AND m.agreement_id = NEW.agreement_id
                      AND m.type = 'add_unit' AND m.status = 'approved' AND m.applied_at IS NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units are frozen once the agreement is submitted';
                END IF;
            END
            DDL);

        DB::unprepared('DROP TRIGGER IF EXISTS agreement_unit_charges_insert');
        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_unit_charges_insert BEFORE INSERT ON agreement_unit_charges FOR EACH ROW
            BEGIN
                IF (SELECT a.status FROM agreement_units au JOIN agreements a ON a.id = au.agreement_id WHERE au.id = NEW.agreement_unit_id) <> 'draft'
                    AND NOT EXISTS (SELECT 1 FROM agreement_units au JOIN agreement_amendments m ON m.id = au.amendment_id
                        WHERE au.id = NEW.agreement_unit_id AND m.status = 'approved' AND m.applied_at IS NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_unit_charges are frozen once the agreement is submitted';
                END IF;
            END
            DDL);

        DB::unprepared(<<<'DDL'
            CREATE TRIGGER agreement_units_amendment_guard BEFORE UPDATE ON agreement_units FOR EACH ROW FOLLOWS agreement_units_guard
            BEGIN
                IF NOT (NEW.amendment_id <=> OLD.amendment_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units: the amendment that added a unit never changes';
                END IF;
            END
            DDL);
    }

    public function down(): void
    {
        // Restoring M2's insert triggers is not supported; roll back with migrate:fresh.
        foreach (['agreement_units_amendment_guard', 'agreement_amendments_guard', 'agreement_amendments_no_delete'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
        }
        Schema::table('agreement_units', fn (Blueprint $table) => $table->dropConstrainedForeignId('amendment_id'));
        Schema::dropIfExists('agreement_amendments');
    }
};
