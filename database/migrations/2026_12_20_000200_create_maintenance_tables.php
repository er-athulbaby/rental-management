<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-17 maintenance §3.2-§3.4: requests, work orders and their timeline, plus the "maintenance" document
 * category. Not money records: no triggers (EXPECTED_TRIGGERS unchanged). The expense link lives on work_orders, so
 * the locked expenses table is not touched.
 */
return new class extends Migration
{
    private const string OLD_CATEGORIES = "'photo', 'id_copy', 'cr_copy', 'signed_contract', 'cheque_image', 'move_out_photo', 'owner_approval', 'generated_pdf', 'other'";

    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('unit_id')->nullable()->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('reported_by_name', 150);
            $table->string('channel', 20);
            $table->string('category', 20);
            $table->string('priority', 10);
            $table->string('description', 2000);
            $table->boolean('entry_permitted')->default(false);
            $table->string('status', 20)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['status', 'priority']);
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('maintenance_request_id')->constrained();
            $table->foreignId('vendor_id')->constrained();
            $table->date('scheduled_on')->nullable();
            $table->string('instructions', 2000)->nullable();
            $table->decimal('estimate', 12, 3)->nullable();
            $table->string('status', 20)->default('assigned');
            $table->date('completed_on')->nullable();
            $table->string('completion_note', 2000)->nullable();
            $table->decimal('actual_net', 12, 3)->nullable();
            $table->decimal('actual_tax', 12, 3)->nullable();
            $table->foreignId('expense_id')->nullable()->unique()->constrained();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['status', 'scheduled_on']);
        });

        Schema::create('maintenance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained();
            $table->foreignId('work_order_id')->nullable()->constrained();
            $table->string('kind', 10);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE maintenance_requests
                ADD CONSTRAINT maintenance_requests_channel_chk CHECK (channel IN ('phone', 'whatsapp', 'walk_in', 'inspection', 'email')),
                ADD CONSTRAINT maintenance_requests_category_chk CHECK (category IN ('plumbing', 'electrical', 'ac', 'carpentry', 'painting', 'cleaning', 'pest_control', 'general')),
                ADD CONSTRAINT maintenance_requests_priority_chk CHECK (priority IN ('urgent', 'high', 'normal', 'low')),
                ADD CONSTRAINT maintenance_requests_status_chk CHECK (status IN ('open', 'in_progress', 'resolved', 'cancelled'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE work_orders
                ADD CONSTRAINT work_orders_status_chk CHECK (status IN ('assigned', 'in_progress', 'completed', 'cancelled')),
                ADD CONSTRAINT work_orders_amounts_chk CHECK (COALESCE(estimate, 0) >= 0 AND COALESCE(actual_net, 0) >= 0 AND COALESCE(actual_tax, 0) >= 0),
                ADD CONSTRAINT work_orders_completed_chk CHECK (status <> 'completed' OR completed_on IS NOT NULL),
                ADD CONSTRAINT work_orders_expense_chk CHECK (expense_id IS NULL OR status = 'completed')
            SQL);
        DB::statement("ALTER TABLE maintenance_events ADD CONSTRAINT maintenance_events_kind_chk CHECK (kind IN ('status', 'note'))");

        DB::statement('ALTER TABLE documents DROP CHECK documents_category_chk, ADD CONSTRAINT documents_category_chk CHECK (category IN ('.self::OLD_CATEGORIES.", 'maintenance'))"); // one statement: atomic
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CHECK documents_category_chk, ADD CONSTRAINT documents_category_chk CHECK (category IN ('.self::OLD_CATEGORIES.'))');
        Schema::dropIfExists('maintenance_events');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('maintenance_requests');
    }
};
