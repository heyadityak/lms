<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UC-S4: one row per recipient per APP_TIMEZONE date. The unique key stops a second mail that day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_reminders', function (Blueprint $table): void {
            $table->id();
            // Users are never hard-deleted (D-12). Not API-exposed: no public_id.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('reminded_on');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['user_id', 'reminded_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_reminders');
    }
};
