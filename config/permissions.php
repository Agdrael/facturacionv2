<?php

$catalog = [];
foreach (['ver' => 'Consultar ventas e historial', 'crear' => 'Crear ventas', 'reintentar' => 'Reintentar guardado de DTE propios', 'transmitir' => 'Transmitir DTE propios', 'descargar' => 'Descargar DTE propios', 'invalidar' => 'Invalidar DTE de cualquier operador'] as $action => $description) {
    $catalog['libreria.ventas.'.$action] = $description;
}
foreach (['cuentas' => 'cuentas', 'productos' => 'productos', 'recibos' => 'conceptos de recibo', 'clientes' => 'clientes', 'proveedores' => 'proveedores'] as $resource => $label) {
    foreach (['ver' => 'Consultar', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar'] as $action => $verb) {
        $catalog['contaduria.'.$resource.'.'.$action] = $verb.' '.$label;
    }
}
foreach (['ver' => 'Consultar paquetes', 'crear' => 'Crear paquetes'] as $action => $description) {
    $catalog['contaduria.paquetes.'.$action] = $description;
}
foreach (['ver' => 'Consultar contadores DTE', 'editar' => 'Editar contadores DTE'] as $action => $description) {
    $catalog['contaduria.contadores.'.$action] = $description;
}
foreach (['ver' => 'Consultar roles y permisos', 'crear' => 'Crear roles', 'editar' => 'Editar roles y permisos', 'eliminar' => 'Eliminar roles sin usuarios'] as $action => $description) {
    $catalog['administracion.roles.'.$action] = $description;
}
$catalog['administracion.usuarios.ver'] = 'Consultar usuarios y permisos efectivos';
$catalog['administracion.usuarios.asignar_roles'] = 'Asignar o retirar roles de usuarios';

foreach (['ver' => 'Consultar estado de caja', 'abrir' => 'Abrir la caja asignada', 'cerrar' => 'Cerrar la caja asignada', 'reabrir' => 'Reabrir la caja con código', 'ver_todas' => 'Consultar todas las cajas', 'ver_codigos' => 'Consultar códigos de reapertura'] as $action => $description) {
    $catalog['libreria.cajas.'.$action] = $description;
}
foreach (['ver' => 'Consultar arqueos de la caja asignada', 'ver_todos' => 'Consultar arqueos de todas las cajas', 'cargar' => 'Entregar Excel de arqueo', 'descargar' => 'Descargar archivos de arqueo', 'revisar' => 'Revisar arqueos entregados', 'firmar' => 'Firmar arqueos revisados'] as $action => $description) {
    $catalog['libreria.arqueos.'.$action] = $description;
}

foreach (['ver' => 'Consultar recibos propios', 'ver_todos' => 'Consultar recibos de todos los operadores', 'crear' => 'Emitir recibos', 'descargar' => 'Descargar y reimprimir recibos', 'reintentar' => 'Reintentar aplicaciones de recibos', 'conciliar' => 'Vincular y conciliar obligaciones', 'anular' => 'Anular recibos de cualquier operador'] as $action => $description) {
    $catalog['libreria.recibos.'.$action] = $description;
}

return ['catalog' => $catalog];
