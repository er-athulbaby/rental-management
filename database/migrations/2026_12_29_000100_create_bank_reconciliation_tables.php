<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliation spec §2–§5 (plan rulings 3, 4, 7 and 11): a column mapping per money account, statement uploads and
 * their lines, reconciliations and matches. Four triggers: statement lines are never updated; matches are never updated,
 * and a finished reconciliation's matches are neither added nor removed (EXPECTED_TRIGGERS 73 → 77).
 */
return new class extends Migration
{
    /** Children first: the order down() drops them in. */
    private const array TABLES = ['bank_matches', 'bank_reconciliations', 'bank_statement_lines', 'bank_uploads', 'bank_mappings'];

    private const array TRIGGERS = ['bank_statement_lines_no_update', 'bank_matches_no_update', 'bank_matches_insert_guard', 'bank_matches_delete_guard'];

    public function up(): void
    {
        // Plan ruling 7: typed columns, 1-based (1 = A); header_row 0 means none.
        Schema::create('bank_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('header_row');
            $table->unsignedTinyInteger('date_column');
            $table->string('date_format', 10);
            $table->unsignedTinyInteger('description_column');
            $table->unsignedTinyInteger('reference_column')->nullable();
            $table->string('amount_mode', 10);
            $table->unsignedTinyInteger('amount_column')->nullable();
            $table->unsignedTinyInteger('debit_column')->nullable();
            $table->unsignedTinyInteger('credit_column')->nullable();
            $table->unsignedTinyInteger('balance_column')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE bank_mappings
                ADD CONSTRAINT bank_mappings_format_chk CHECK (date_format IN ('d/m/Y', 'Y-m-d', 'd-M-Y')),
                ADD CONSTRAINT bank_mappings_mode_chk CHECK (
                    (amount_mode = 'signed' AND amount_column IS NOT NULL AND debit_column IS NULL AND credit_column IS NULL)
                    OR (amount_mode = 'split' AND amount_column IS NULL AND debit_column IS NOT NULL AND credit_column IS NOT NULL))
            SQL);

        Schema::create('bank_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('file_name', 255);
            $table->unsignedInteger('imported');
            $table->unsignedInteger('skipped');
            $table->unsignedInteger('failed');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ledger_account_id', 'id']);
        });

        // Plan ruling 7: a duplicate is the same account, date, amount and description at the same occurrence within its file.
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_upload_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('date');
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 13, 3);
            $table->decimal('balance', 15, 3)->nullable();
            $table->char('description_hash', 64);
            $table->unsignedSmallInteger('occurrence');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['ledger_account_id', 'date', 'amount', 'description_hash', 'occurrence'], 'bank_statement_lines_duplicate_unique');
        });
        DB::statement('ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_amount_chk CHECK (amount <> 0)');
        DB::unprepared("CREATE TRIGGER bank_statement_lines_no_update BEFORE UPDATE ON bank_statement_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bank_statement_lines are write-once'");

        // Plan ruling 4: one open reconciliation per account (open_account_id is NULL once finished), end dates unique per account.
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->date('end_date');
            $table->decimal('opening_balance', 15, 3);
            $table->decimal('closing_balance', 15, 3);
            $table->string('status', 10);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finished_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
            $table->unique(['ledger_account_id', 'end_date']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE bank_reconciliations
                ADD COLUMN open_account_id BIGINT UNSIGNED AS (IF(status = 'open', ledger_account_id, NULL)) STORED,
                ADD UNIQUE KEY bank_reconciliations_one_open (open_account_id),
                ADD CONSTRAINT bank_reconciliations_status_chk CHECK (status IN ('open', 'finished')),
                ADD CONSTRAINT bank_reconciliations_finished_chk CHECK ((status = 'finished') = (finished_at IS NOT NULL AND finished_by IS NOT NULL))
            SQL);

        // Plan rulings 1 and 3: an entry is its source's key; a row exists only while the match does.
        Schema::create('bank_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('entry_type', 30);
            $table->unsignedBigInteger('entry_id');
            $table->decimal('amount', 13, 3);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['ledger_account_id', 'entry_type', 'entry_id'], 'bank_matches_entry_unique');
            $table->index(['entry_type', 'entry_id']);
        });
        DB::statement('ALTER TABLE bank_matches ADD CONSTRAINT bank_matches_amount_chk CHECK (amount <> 0)');
        DB::unprepared("CREATE TRIGGER bank_matches_no_update BEFORE UPDATE ON bank_matches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bank_matches are write-once'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bank_matches_insert_guard BEFORE INSERT ON bank_matches FOR EACH ROW
            BEGIN
                IF (SELECT status FROM bank_reconciliations WHERE id = NEW.bank_reconciliation_id) <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bank_matches: the reconciliation is finished';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bank_matches_delete_guard BEFORE DELETE ON bank_matches FOR EACH ROW
            BEGIN
                IF (SELECT status FROM bank_reconciliations WHERE id = OLD.bank_reconciliation_id) <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bank_matches: the reconciliation is finished';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Cannot roll back: {$table} has rows. Nothing was changed.");
            }
        }
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
