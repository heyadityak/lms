<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Identity-admin audit trail (D-22). Append-only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_admin_audits', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            // null when a create failed before the local row existed.
            $table->foreignId('target_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->string('keycloak_user_id', 255)->nullable();
            $table->string('action', 30);
            $table->jsonb('changes')->default('{}'); // before/after, never secrets
            $table->string('outcome', 10);
            $table->string('error_code', 40)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['actor_id', 'created_at']);
        });

        DB::statement("ALTER TABLE user_admin_audits
            ADD CONSTRAINT user_admin_audits_action_check CHECK (action IN ('create', 'update_profile', 'enable', 'disable', 'assign_role', 'password_reset', 'compensate_delete')),
            ADD CONSTRAINT user_admin_audits_outcome_check CHECK (outcome IN ('success', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_admin_audits');
    }
};
