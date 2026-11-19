<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Append-only ledger (D-02). No updated_at: rows never change. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->smallInteger('year'); // leave-year label (D-26)
            $table->string('kind', 20);
            $table->decimal('days', 5, 1); // signed
            $table->foreignId('leave_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete(); // null = system
            $table->string('note', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'leave_type_id', 'year']);
        });

        DB::statement("ALTER TABLE leave_ledger_entries
            ADD CONSTRAINT leave_ledger_entries_kind_check CHECK (kind IN ('grant', 'carry_forward', 'lapse', 'debit', 'reversal', 'adjustment')),
            ADD CONSTRAINT leave_ledger_entries_days_check CHECK (days <> 0 AND days * 2 = trunc(days * 2))");
        // Idempotent accrual: one grant / carry_forward / lapse per (user, type, year).
        DB::statement("CREATE UNIQUE INDEX leave_ledger_entries_yearly_unique ON leave_ledger_entries (user_id, leave_type_id, year, kind)
            WHERE kind IN ('grant', 'carry_forward', 'lapse')");
        // At most one debit and one reversal per request.
        DB::statement('CREATE UNIQUE INDEX leave_ledger_entries_request_kind_unique ON leave_ledger_entries (leave_request_id, kind)
            WHERE leave_request_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger_entries');
    }
};
