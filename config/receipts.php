<?php

return [
    'enabled' => env('RECEIPTS_ENABLED', false),
    'legacy_connection' => env('RECEIPTS_LEGACY_CONNECTION', 'puntoventa'),
    'platform_connection' => 'plataforma',
    'cash_account' => env('RECEIPTS_CASH_ACCOUNT', '11010101'),
    'photo_month' => 20,
    'role_permissions' => [
        'libreria' => ['ver', 'crear', 'descargar', 'reintentar'],
        'contador' => ['ver', 'ver_todos', 'descargar', 'reintentar', 'conciliar', 'anular'],
    ],
];
