<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            // Users are never hard-deleted (D-12); restrict keeps history intact if someone tries.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('day_part', 20)->default('full');
            $table->decimal('days', 5, 1); // server-computed
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->text('handover_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'start_date']);
        });

        DB::statement("ALTER TABLE leave_requests
            ADD CONSTRAINT leave_requests_dates_check CHECK (end_date >= start_date),
            ADD CONSTRAINT leave_requests_days_check CHECK (days > 0 AND days * 2 = trunc(days * 2)),
            ADD CONSTRAINT leave_requests_day_part_check CHECK (day_part IN ('full', 'first_half', 'second_half')),
            ADD CONSTRAINT leave_requests_half_day_check CHECK (day_part = 'full' OR start_date = end_date),
            ADD CONSTRAINT leave_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");

        // Overlap backstop (D-06): app check V6 first, this catches races. SQLSTATE 23P01 → 422.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_no_overlap
            EXCLUDE USING gist (user_id WITH =, daterange(start_date, end_date, '[]') WITH &&)
            WHERE (status IN ('pending', 'approved'))");
    }

    public function down(): void
    {
        // btree_gist stays installed: other objects may use it and dropping extensions needs extra privileges.
        Schema::dropIfExists('leave_requests');
    }
};
