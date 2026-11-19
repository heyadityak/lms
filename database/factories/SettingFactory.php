<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Writes rows directly; app code goes through App\Support\Settings (which busts the cache).
 *
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => 'brand.name',
            'value' => fake()->company(),
        ];
    }
}
