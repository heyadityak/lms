<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Module;

/**
 * enabled = licensed (MODULES_ENABLED) ∧ registry available ∧ ¬ disabled by admin (D-14, D-34).
 * Scoped binding: resolved once per request (D-27).
 */
final class Modules
{
    /** @var list<string>|null */
    private ?array $enabled = null;

    public function __construct(private readonly Settings $settings) {}

    public function enabled(Module $module): bool
    {
        $this->enabled ??= self::resolve($this->licensed(), config('modules.registry', []), (array) $this->settings->get('modules.disabled'));

        return in_array($module->value, $this->enabled, true);
    }

    /** @return list<string> */
    public function licensed(): array
    {
        return config('modules.licensed', []);
    }

    /** Registry rows for the admin UI. @return array<string, array{label: string, available: bool, licensed: bool, enabled: bool}> */
    public function all(): array
    {
        $rows = [];
        foreach (config('modules.registry', []) as $key => $entry) {
            $rows[$key] = [
                'label' => $entry['label'],
                'available' => $entry['available'],
                'licensed' => in_array($key, $this->licensed(), true),
                'enabled' => $this->enabled(Module::from($key)),
            ];
        }

        return $rows;
    }

    /**
     * Pure resolution, unit-tested.
     *
     * @param  list<string>  $licensed
     * @param  array<string, array{available: bool}>  $registry
     * @param  list<string>  $disabled
     * @return list<string>
     */
    public static function resolve(array $licensed, array $registry, array $disabled): array
    {
        return array_values(array_filter(
            $licensed,
            fn (string $key): bool => ($registry[$key]['available'] ?? false) && ! in_array($key, $disabled, true),
        ));
    }
}
