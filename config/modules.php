<?php

declare(strict_types=1);

// SYSTEM_DESIGN §8.3. Created by platform (phase 4) for the EnsureModuleEnabled stub; owned by domain.
return [
    'licensed' => array_values(array_filter(array_map('trim', explode(',', (string) env('MODULES_ENABLED', 'holidays,reports,api,notifications,user_management'))))),
    'registry' => [
        'holidays' => ['label' => 'Holiday calendar', 'available' => true],
        'reports' => ['label' => 'Reports & exports', 'available' => true],
        'api' => ['label' => 'REST API', 'available' => true],
        'notifications' => ['label' => 'Email notifications', 'available' => true],
        'multi_level_approval' => ['label' => 'Multi-level approval', 'available' => false], // Later
        'delegation' => ['label' => 'Approval delegation', 'available' => false], // Later
        'user_management' => ['label' => 'User management (Keycloak)', 'available' => true], // D-22
    ],
];
