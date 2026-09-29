<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->string('action', 50);
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'requested_at']);
        });

        // One pending request per record and action: NULLs never collide in a UNIQUE index.
        DB::statement(<<<'SQL'
            ALTER TABLE approvals
                ADD COLUMN pending_key VARCHAR(320) GENERATED ALWAYS AS (IF(status = 'pending', CONCAT(approvable_type, '#', approvable_id, '#', action), NULL)) STORED,
                ADD UNIQUE KEY approvals_one_pending (pending_key),
                ADD CONSTRAINT approvals_status_chk CHECK (status IN ('pending', 'approved', 'rejected')),
                ADD CONSTRAINT approvals_decided_chk CHECK ((status = 'pending') = (decided_at IS NULL) AND (status = 'pending') = (decided_by IS NULL))
            SQL);

        // Spec §8.5: no UPDATE or DELETE once decided. Nothing ever deletes an approval.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER approvals_guard BEFORE UPDATE ON approvals FOR EACH ROW
            BEGIN
                IF OLD.status <> 'pending' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approvals: decided approvals are immutable';
                END IF;
                IF NOT (NEW.approvable_type <=> OLD.approvable_type AND NEW.approvable_id <=> OLD.approvable_id
                    AND NEW.action <=> OLD.action AND NEW.requested_by <=> OLD.requested_by
                    AND NEW.requested_at <=> OLD.requested_at AND NEW.payload <=> OLD.payload AND NEW.reason <=> OLD.reason) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approvals: the request itself cannot change';
                END IF;
            END
            SQL);

        DB::unprepared("CREATE TRIGGER approvals_no_delete BEFORE DELETE ON approvals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approvals cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS approvals_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS approvals_guard');
        Schema::dropIfExists('approvals');
    }
};
