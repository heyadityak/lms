<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 19: attachments, avatars, hourly leave, delegation, quotas, encashment, locations, audit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->timestampsTz();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('avatar_path')->nullable();
        });

        Schema::table('leave_types', function (Blueprint $table): void {
            $table->boolean('sandwich')->default(false);
            $table->string('accrual_frequency', 16)->default('yearly');
        });
        DB::statement("ALTER TABLE leave_types ADD CONSTRAINT leave_types_accrual_frequency_check CHECK (accrual_frequency IN ('yearly', 'monthly'))");

        Schema::table('holidays', function (Blueprint $table): void {
            $table->dropUnique(['date']);
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX holidays_date_location_unique ON holidays (date, location_id) NULLS NOT DISTINCT');

        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT leave_requests_days_check');
        DB::statement('ALTER TABLE leave_requests ALTER COLUMN days TYPE numeric(7,3)');
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_days_check CHECK (days > 0 AND days * 8 = trunc(days * 8))');
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->unsignedSmallInteger('hours')->nullable();
            $table->timestampTz('escalated_at')->nullable();
        });
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_hours_check CHECK (hours IS NULL OR (hours BETWEEN 1 AND 8 AND start_date = end_date AND day_part = 'full'))");

        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT leave_ledger_entries_days_check');
        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT leave_ledger_entries_kind_check');
        DB::statement('ALTER TABLE leave_ledger_entries ALTER COLUMN days TYPE numeric(8,4)');
        DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_days_check CHECK (days <> 0)');
        DB::statement("ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_kind_check CHECK (kind IN ('grant', 'carry_forward', 'lapse', 'debit', 'reversal', 'adjustment', 'encashment'))");
        DB::statement('DROP INDEX leave_ledger_entries_yearly_unique');
        Schema::table('leave_ledger_entries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('accrual_month')->nullable();
        });
        DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_accrual_month_check CHECK (accrual_month IS NULL OR accrual_month BETWEEN 1 AND 12)');
        DB::statement("CREATE UNIQUE INDEX leave_ledger_entries_yearly_unique ON leave_ledger_entries (user_id, leave_type_id, year, kind) WHERE kind IN ('grant', 'carry_forward', 'lapse') AND accrual_month IS NULL");
        DB::statement("CREATE UNIQUE INDEX leave_ledger_entries_monthly_grant_unique ON leave_ledger_entries (user_id, leave_type_id, year, accrual_month) WHERE kind = 'grant' AND accrual_month IS NOT NULL");

        Schema::create('leave_attachments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('leave_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->timestampsTz();
        });

        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('delegator_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_window_check CHECK (end_date >= start_date AND delegate_id <> delegator_id)');

        Schema::create('department_leave_quotas', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->decimal('annual_quota', 5, 1);
            $table->timestampsTz();
            $table->unique(['department_id', 'leave_type_id']);
        });
        DB::statement('ALTER TABLE department_leave_quotas ADD CONSTRAINT department_leave_quotas_amount_check CHECK (annual_quota >= 0)');

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('subject_type', 40);
            $table->string('subject_id', 26);
            $table->string('action', 20);
            $table->jsonb('changes')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['created_at', 'id']);
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_action_check CHECK (action IN ('created', 'updated', 'deleted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('department_leave_quotas');
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('leave_attachments');

        DB::statement('DROP INDEX IF EXISTS leave_ledger_entries_monthly_grant_unique');
        DB::statement('DROP INDEX IF EXISTS leave_ledger_entries_yearly_unique');
        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_accrual_month_check');
        Schema::table('leave_ledger_entries', function (Blueprint $table): void {
            $table->dropColumn('accrual_month');
        });
        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_days_check');
        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_kind_check');
        DB::statement('ALTER TABLE leave_ledger_entries ALTER COLUMN days TYPE numeric(5,1)');
        DB::statement("ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_kind_check CHECK (kind IN ('grant', 'carry_forward', 'lapse', 'debit', 'reversal', 'adjustment'))");
        DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_days_check CHECK (days <> 0 AND days * 2 = trunc(days * 2))');
        DB::statement("CREATE UNIQUE INDEX leave_ledger_entries_yearly_unique ON leave_ledger_entries (user_id, leave_type_id, year, kind) WHERE kind IN ('grant', 'carry_forward', 'lapse')");

        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_hours_check');
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropColumn(['hours', 'escalated_at']);
        });
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_days_check');
        DB::statement('ALTER TABLE leave_requests ALTER COLUMN days TYPE numeric(5,1)');
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_days_check CHECK (days > 0 AND days * 2 = trunc(days * 2))');

        DB::statement('DROP INDEX IF EXISTS holidays_date_location_unique');
        Schema::table('holidays', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
            $table->unique('date');
        });

        DB::statement('ALTER TABLE leave_types DROP CONSTRAINT IF EXISTS leave_types_accrual_frequency_check');
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn(['sandwich', 'accrual_frequency']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn('avatar_path');
        });

        Schema::dropIfExists('locations');
    }
};
