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
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 10)->unique(); // immutable after create
            $table->string('name', 100);
            $table->decimal('annual_quota', 5, 1)->default(0);
            $table->boolean('tracks_balance')->default(true);
            $table->decimal('carry_forward_cap', 5, 1)->nullable(); // null = carry all
            $table->decimal('max_balance', 5, 1)->nullable(); // null = no cap
            $table->boolean('allow_half_day')->default(true);
            $table->smallInteger('max_backdate_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE leave_types ADD CONSTRAINT leave_types_amounts_check CHECK (
            annual_quota >= 0 AND (carry_forward_cap IS NULL OR carry_forward_cap >= 0)
            AND (max_balance IS NULL OR max_balance >= 0) AND max_backdate_days >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
