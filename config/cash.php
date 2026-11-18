<?php

return [
    'registers' => ['P001', 'P002', 'P003'],
    'timezone' => 'America/El_Salvador',
    'disk' => 'cash',
    'template' => resource_path('templates/cash/NuevaAuditoria.xlsx'),
    'role_permissions' => [
        'libreria' => ['libreria.cajas.ver', 'libreria.cajas.abrir', 'libreria.cajas.cerrar', 'libreria.cajas.reabrir', 'libreria.arqueos.ver', 'libreria.arqueos.cargar', 'libreria.arqueos.descargar'],
        'contador' => ['libreria.cajas.ver', 'libreria.cajas.ver_todas', 'libreria.cajas.ver_codigos', 'libreria.arqueos.ver', 'libreria.arqueos.ver_todos', 'libreria.arqueos.descargar', 'libreria.arqueos.revisar', 'libreria.arqueos.firmar'],
    ],
];
