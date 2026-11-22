<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Module;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use stdClass;

/** openapi `Module` rows for the admin registry. */
final class ModuleResource
{
    public static function list(Modules $modules): JsonResponse
    {
        $data = [];
        foreach ($modules->all() as $key => $row) {
            $data[] = self::row($key, $row);
        }

        return response()->json([
            'data' => $data,
            'meta' => new stdClass,
            'links' => new stdClass,
        ]);
    }

    public static function one(Modules $modules, Module $module): JsonResponse
    {
        return response()->json([
            'data' => self::row($module->value, $modules->all()[$module->value]),
            'meta' => new stdClass,
            'links' => new stdClass,
        ]);
    }

    /** @param  array{label: string, available: bool, licensed: bool, enabled: bool}  $row
     * @return array{key: string, label: string, licensed: bool, available: bool, enabled: bool, locked: bool}
     */
    private static function row(string $key, array $row): array
    {
        return [
            'key' => $key,
            'label' => $row['label'],
            'licensed' => $row['licensed'],
            'available' => $row['available'],
            'enabled' => $row['enabled'],
            'locked' => ! $row['licensed'] || ! $row['available'],
        ];
    }
}
