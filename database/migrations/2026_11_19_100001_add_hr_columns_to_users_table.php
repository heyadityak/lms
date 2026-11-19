<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Domain columns on users (SYSTEM_DESIGN §4). Identity columns came with phase 3 (D-39 f). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable();
            $table->foreignId('department_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('employee_code', 50)->nullable()->unique();
            $table->string('designation', 100)->nullable();
            $table->string('employment_type', 50)->nullable();
            $table->date('date_of_joining')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            // Keycloak owns credentials and verification (D-20).
            $table->dropColumn(['email_verified_at', 'remember_token']);
        });

        // Backfill users provisioned before this migration (phase 3 logins), then enforce NOT NULL.
        DB::table('users')->whereNull('public_id')->eachById(
            fn (object $user) => DB::table('users')->where('id', $user->id)->update(['public_id' => (string) Str::ulid()]),
        );

        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable(false)->unique()->change();
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_manager_not_self_check CHECK (manager_id <> id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_manager_not_self_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropUnique(['public_id']);
            $table->dropUnique(['employee_code']);
            $table->dropColumn(['public_id', 'employee_code', 'designation', 'employment_type', 'date_of_joining', 'last_login_at']);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
        });
    }
};
