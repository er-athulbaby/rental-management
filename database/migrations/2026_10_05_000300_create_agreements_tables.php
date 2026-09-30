<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('frequency', 20);
            $table->unsignedTinyInteger('billing_day')->nullable();
            $table->unsignedSmallInteger('grace_days');
            $table->unsignedSmallInteger('notice_period_days')->default(30);
            $table->date('notice_date')->nullable();
            $table->date('planned_exit_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('previous_agreement_id')->nullable()->constrained('agreements')->restrictOnDelete();
            $table->foreignId('contract_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('verify_token', 32)->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'end_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE agreements
                ADD CONSTRAINT agreements_status_chk CHECK (status IN ('draft', 'pending_approval', 'active', 'expired', 'renewed', 'closed', 'terminated')),
                ADD CONSTRAINT agreements_frequency_chk CHECK (frequency IN ('monthly', 'quarterly', 'half_yearly', 'yearly')),
                ADD CONSTRAINT agreements_billing_day_chk CHECK (billing_day IS NULL OR billing_day BETWEEN 1 AND 28),
                ADD CONSTRAINT agreements_dates_chk CHECK (end_date >= start_date),
                ADD CONSTRAINT agreements_days_chk CHECK (grace_days <= 60 AND notice_period_days <= 365),
                ADD CONSTRAINT agreements_number_chk CHECK ((status IN ('draft', 'pending_approval')) = (number IS NULL)),
                ADD CONSTRAINT agreements_token_chk CHECK ((status IN ('draft', 'pending_approval')) = (verify_token IS NULL))
            SQL);

        Schema::create('agreement_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('list_rent', 12, 3);
            $table->decimal('deposit_amount', 12, 3)->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->date('planned_exit_date')->nullable();
            $table->date('move_out_date')->nullable();
            $table->text('move_out_readings')->nullable();
            $table->text('move_out_notes')->nullable();
            $table->foreignId('move_out_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['agreement_id', 'unit_id']);
            $table->index(['unit_id', 'start_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE agreement_units
                ADD CONSTRAINT agreement_units_dates_chk CHECK (end_date >= start_date),
                ADD CONSTRAINT agreement_units_money_chk CHECK (list_rent >= 0 AND deposit_amount >= 0)
            SQL);

        Schema::create('agreement_unit_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_unit_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('description', 150)->nullable();
            $table->decimal('monthly_amount', 12, 3);
            $table->string('tax_category', 20);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE agreement_unit_charges
                ADD COLUMN rent_key BIGINT UNSIGNED GENERATED ALWAYS AS (IF(type = 'rent', agreement_unit_id, NULL)) STORED,
                ADD UNIQUE KEY agreement_unit_charges_one_rent (rent_key),
                ADD CONSTRAINT agreement_unit_charges_type_chk CHECK (type IN ('rent', 'service_charge', 'parking', 'other')),
                ADD CONSTRAINT agreement_unit_charges_tax_chk CHECK (tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT agreement_unit_charges_amount_chk CHECK (monthly_amount >= 0)
            SQL);

        Schema::create('agreement_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('heading_en', 150);
            $table->string('heading_ar', 150);
            $table->text('body_en');
            $table->text('body_ar');
            $table->timestamps();
            $table->unique(['agreement_id', 'position']);
        });

        // Spec §8.5. One statement per unprepared() call.
        DB::unprepared("CREATE TRIGGER agreements_no_delete BEFORE DELETE ON agreements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreements cannot be deleted'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agreements_guard BEFORE UPDATE ON agreements FOR EACH ROW
            BEGIN
                IF NOT (NEW.status = OLD.status
                    OR (OLD.status = 'draft' AND NEW.status = 'pending_approval')
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('draft', 'active'))
                    OR (OLD.status = 'active' AND NEW.status IN ('expired', 'renewed', 'closed', 'terminated'))
                    OR (OLD.status = 'expired' AND NEW.status IN ('renewed', 'closed', 'terminated'))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreements: status change not allowed';
                END IF;

                IF OLD.status <> 'draft' AND NOT (
                    NEW.customer_id <=> OLD.customer_id AND NEW.start_date <=> OLD.start_date AND NEW.frequency <=> OLD.frequency
                    AND NEW.billing_day <=> OLD.billing_day AND NEW.grace_days <=> OLD.grace_days
                    AND NEW.notice_period_days <=> OLD.notice_period_days AND NEW.previous_agreement_id <=> OLD.previous_agreement_id
                    AND NEW.contract_template_id <=> OLD.contract_template_id AND NEW.created_by <=> OLD.created_by
                    AND NEW.deleted_at <=> OLD.deleted_at
                    AND (OLD.number IS NULL OR NEW.number <=> OLD.number)
                    AND (OLD.verify_token IS NULL OR NEW.verify_token <=> OLD.verify_token)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreements: terms are frozen once submitted';
                END IF;

                IF (OLD.status IN ('pending_approval', 'renewed', 'closed', 'terminated')
                        AND NOT (NEW.end_date <=> OLD.end_date AND NEW.notice_date <=> OLD.notice_date AND NEW.planned_exit_date <=> OLD.planned_exit_date))
                    OR (OLD.status IN ('active', 'expired') AND NEW.end_date > OLD.end_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreements: only notice dates and an earlier end_date may change';
                END IF;
            END
            SQL);

        // ponytail: M3's add_unit amendment inserts units into an active agreement; it widens this trigger then.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agreement_units_insert BEFORE INSERT ON agreement_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM agreements WHERE id = NEW.agreement_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units are frozen once the agreement is submitted';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agreement_units_guard BEFORE UPDATE ON agreement_units FOR EACH ROW
            BEGIN
                DECLARE s VARCHAR(20);
                SELECT status INTO s FROM agreements WHERE id = OLD.agreement_id;

                IF s <> 'draft' AND NOT (NEW.agreement_id <=> OLD.agreement_id AND NEW.unit_id <=> OLD.unit_id
                    AND NEW.list_rent <=> OLD.list_rent AND NEW.deposit_amount <=> OLD.deposit_amount
                    AND NEW.start_date <=> OLD.start_date AND NEW.end_date <= OLD.end_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units: terms are frozen once submitted';
                END IF;

                IF s = 'pending_approval' AND NOT (NEW.end_date <=> OLD.end_date AND NEW.planned_exit_date <=> OLD.planned_exit_date
                    AND NEW.move_out_date <=> OLD.move_out_date AND NEW.move_out_readings <=> OLD.move_out_readings
                    AND NEW.move_out_notes <=> OLD.move_out_notes AND NEW.move_out_recorded_by <=> OLD.move_out_recorded_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units: locked while pending approval';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agreement_units_delete BEFORE DELETE ON agreement_units FOR EACH ROW
            BEGIN
                IF (SELECT status FROM agreements WHERE id = OLD.agreement_id) <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_units are frozen once the agreement is submitted';
                END IF;
            END
            SQL);

        foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $event => $row) {
            $name = 'agreement_unit_charges_'.strtolower($event);
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name} BEFORE {$event} ON agreement_unit_charges FOR EACH ROW
                BEGIN
                    IF (SELECT a.status FROM agreement_units au JOIN agreements a ON a.id = au.agreement_id WHERE au.id = {$row}.agreement_unit_id) <> 'draft' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_unit_charges are frozen once the agreement is submitted';
                    END IF;
                END
                SQL);

            $name = 'agreement_clauses_'.strtolower($event);
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name} BEFORE {$event} ON agreement_clauses FOR EACH ROW
                BEGIN
                    IF (SELECT status FROM agreements WHERE id = {$row}.agreement_id) <> 'draft' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'agreement_clauses are frozen once the agreement is submitted';
                    END IF;
                END
                SQL);
        }
    }

    public function down(): void
    {
        foreach (['agreement_clauses_insert', 'agreement_clauses_update', 'agreement_clauses_delete',
            'agreement_unit_charges_insert', 'agreement_unit_charges_update', 'agreement_unit_charges_delete',
            'agreement_units_delete', 'agreement_units_guard', 'agreement_units_insert', 'agreements_guard', 'agreements_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('agreement_clauses');
        Schema::dropIfExists('agreement_unit_charges');
        Schema::dropIfExists('agreement_units');
        Schema::dropIfExists('agreements');
    }
};
