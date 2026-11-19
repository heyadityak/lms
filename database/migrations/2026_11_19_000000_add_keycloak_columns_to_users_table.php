<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (platform): only the identity columns Keycloak login needs (D-20, D-29).
 * Every other users column (public_id, department, manager, joining date, ...) is the domain phase's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // OIDC `sub`; nullable until first login (users pre-created by HR/admin).
            $table->string('keycloak_sub', 255)->nullable()->unique();
            // Cache of the base realm role (Keycloak wins), re-synced on every login.
            $table->string('role', 20)->default('employee');
            // Cache of the additive realm role `admin` (D-29).
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dropColumn('password');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('employee', 'manager', 'hr_admin'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['keycloak_sub']);
            $table->dropColumn(['keycloak_sub', 'role', 'is_admin', 'is_active']);
            $table->string('password')->nullable();
        });
    }
};
