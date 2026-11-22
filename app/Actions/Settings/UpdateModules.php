<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\Module;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Support\Modules;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * UC-B5. One licensed module at a time (`PATCH /modules/{key}`).
 * Disabling `api` is allowed: EnsureModuleEnabled still lets the BFF through (D-43).
 */
final class UpdateModules
{
    public function __construct(private readonly Settings $settings, private readonly Modules $modules) {}

    public function handle(Module $module, bool $enabled, User $actor): void
    {
        $entry = config('modules.registry.'.$module->value);
        $licensed = in_array($module->value, config('modules.licensed', []), true);
        $available = is_array($entry) && ($entry['available'] ?? false) === true;
        if (! $licensed || ! $available) {
            throw new DomainException(
                'This module is not licensed.',
                'MODULE_NOT_LICENSED',
                422,
                ['enabled' => ['This module is not licensed or not available.']],
            );
        }

        DB::transaction(function () use ($module, $enabled, $actor): void {
            $disabled = array_values(array_filter(
                array_map(strval(...), (array) $this->settings->get('modules.disabled')),
                fn (string $key): bool => $key !== '' && $key !== $module->value,
            ));
            if (! $enabled) {
                $disabled[] = $module->value;
            }

            if ($disabled === []) {
                $this->settings->forget('modules.disabled');
            } else {
                $this->settings->set('modules.disabled', $disabled, $actor);
            }
        });

        $this->modules->flush();
    }
}
