<?php

declare(strict_types=1);

// SYSTEM_DESIGN §8.3 (D-14, D-34). `licensed` = env ceiling; admins may only disable licensed modules (setting `modules.disabled`).
return [
    'licensed' => array_values(array_filter(array_map('trim', explode(',', (string) env('MODULES_ENABLED', 'holidays,reports,api,notifications,user_management'))))),
    'registry' => [
        'holidays' => ['label' => 'Holiday calendar', 'available' => true],
        'reports' => ['label' => 'Reports & exports', 'available' => true],
        'api' => ['label' => 'REST API', 'available' => true],
        'notifications' => ['label' => 'Email notifications', 'available' => true],
        'multi_level' => ['label' => 'Multi-level approval', 'available' => true],
        'user_management' => ['label' => 'User management (Keycloak)', 'available' => true], // D-22
    ],
];
