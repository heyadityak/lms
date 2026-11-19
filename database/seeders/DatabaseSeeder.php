<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Reference data only, safe in every environment. Dev data: `php artisan db:seed --class=DemoSeeder`. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(LeaveTypeSeeder::class);
    }
}
