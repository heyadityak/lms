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
        Schema::create('leave_request_actions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['leave_request_id', 'created_at']);
        });

        DB::statement("ALTER TABLE leave_request_actions
            ADD CONSTRAINT leave_request_actions_action_check CHECK (action IN ('submitted', 'approved', 'rejected', 'cancelled')),
            ADD CONSTRAINT leave_request_actions_status_check CHECK (
                (from_status IS NULL OR from_status IN ('pending', 'approved', 'rejected', 'cancelled'))
                AND to_status IN ('pending', 'approved', 'rejected', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_actions');
    }
};
