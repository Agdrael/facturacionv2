<?php

use App\Http\Controllers\Auth\EmployeeAccessRejectedController;
use App\Http\Controllers\Auth\LoginPageController;
use App\Http\Controllers\Contaduria\CatalogController;
use App\Http\Controllers\Contaduria\CatalogRecordController;
use App\Http\Controllers\Contaduria\DteCounterController;
use App\Http\Controllers\Contaduria\PackageController;
use App\Http\Controllers\Libreria\CashReconciliationController;
use App\Http\Controllers\Libreria\CashRegisterController;
use App\Http\Controllers\Libreria\SaleClientController;
use App\Http\Controllers\Libreria\SaleController;
use App\Http\Controllers\Libreria\SaleGenerationController;
use App\Http\Controllers\Libreria\SaleInvalidationController;
use App\Http\Controllers\Libreria\SalePackageController;
use App\Http\Controllers\Libreria\SaleProductController;
use App\Http\Controllers\Libreria\SaleStockController;
use App\Http\Controllers\Libreria\SaleTicketController;
use App\Http\Controllers\Libreria\StudentController;
use App\Http\Controllers\Libreria\StudentTuitionController;
use App\Http\Middleware\AuthorizeCatalogAction;
use App\Http\Middleware\EnsureUserHasRoles;
use App\Http\Middleware\EnsureUserIsEmpleado;
use App\Http\Middleware\ResolvePuntoVentaById;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::get('/', LoginPageController::class)->name('home');

Route::middleware(['auth', ValidateSessionWithWorkOS::class])
    ->get('employee-access/rejected', EmployeeAccessRejectedController::class)
    ->name('employee-access.rejected');

Route::view('access/pending', 'auth.access-pending')
    ->middleware(['auth', ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class])->name('access.pending');

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
    EnsureUserIsEmpleado::class,
    EnsureUserHasRoles::class,
    ResolvePuntoVentaById::class,
])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::prefix('libreria/cajas')->name('libreria.cajas.')->controller(CashRegisterController::class)->group(function () {
        Route::get('/', 'index')->middleware('can:libreria.cajas.ver')->name('index');
        Route::post('abrir', 'open')->middleware('can:libreria.cajas.abrir')->name('open');
        Route::post('reabrir', 'reopen')->middleware('can:libreria.cajas.reabrir')->name('reopen');
        Route::post('sesiones/{session}/cerrar', 'close')->whereNumber('session')->middleware('can:libreria.cajas.cerrar')->name('close');
        Route::get('sesiones/{session}/codigo', 'code')->whereNumber('session')->middleware('can:libreria.cajas.ver_codigos')->name('code');
    });
    Route::prefix('libreria/arqueos/{reconciliation}')->whereNumber('reconciliation')->name('libreria.arqueos.')->controller(CashReconciliationController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::get('archivos/{kind}', 'download')->where('kind', 'original|entregado|firmado')->name('download');
        Route::post('archivo', 'upload')->name('upload');
        Route::post('revisar', 'review')->name('review');
        Route::post('firmar', 'sign')->name('sign');
    });
    Route::get('contaduria/catalogos', CatalogController::class)->name('contaduria.catalogos');
    Route::get('contaduria/contadores-dte', [DteCounterController::class, 'index'])->middleware('can:contaduria.contadores.ver')->name('contaduria.contadores.index');
    Route::put('contaduria/contadores-dte/{type}', [DteCounterController::class, 'update'])->middleware('can:contaduria.contadores.editar')->where('type', '01|03')->name('contaduria.contadores.update');
    Route::prefix('contaduria/paquetes')->name('contaduria.paquetes.')->controller(PackageController::class)->group(function () {
        Route::get('/', 'index')->middleware('can:contaduria.paquetes.ver')->name('index');
        Route::get('crear', 'create')->middleware('can:contaduria.paquetes.crear')->name('create');
        Route::get('productos', 'products')->middleware('can:contaduria.paquetes.crear')->name('productos');
        Route::post('/', 'store')->middleware('can:contaduria.paquetes.crear')->name('store');
    });
    Route::prefix('contaduria/catalogos/{catalog}')->name('contaduria.catalogos.registros.')
        ->where(['catalog' => 'cuentas|productos|recibos|clientes|proveedores', 'record' => '[0-9]+'])
        ->middleware(AuthorizeCatalogAction::class)->controller(CatalogRecordController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('crear', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{record}/editar', 'edit')->name('edit');
            Route::put('{record}', 'update')->name('update');
            Route::delete('{record}', 'destroy')->name('destroy');
        });
    Route::get('libreria/venta', SaleController::class)->middleware('can:libreria.ventas.ver')->name('libreria.venta');
    Route::get('libreria/ventas/{journal}/ticket', SaleTicketController::class)->whereNumber('journal')->middleware('can:libreria.ventas.ver')->name('libreria.ventas.ticket');
    Route::prefix('libreria/ventas/{journal}/invalidacion')->middleware('can:libreria.ventas.invalidar')->whereNumber('journal')->name('libreria.ventas.invalidacion.')->controller(SaleInvalidationController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'store')->name('store');
        Route::post('transmitir', 'transmit')->name('transmit');
        Route::get('json', 'download')->name('download');
    });
    Route::prefix('libreria/ventas')->name('libreria.ventas.')->controller(SaleGenerationController::class)->group(function () {
        Route::post('preparar', 'quote')->middleware('can:libreria.ventas.crear')->name('quote');
        Route::post('/', 'store')->middleware('can:libreria.ventas.crear')->name('store');
        Route::get('historial', 'history')->middleware('can:libreria.ventas.ver')->name('history');
        Route::post('{journal}/guardar-dte', 'retry')->middleware('can:libreria.ventas.reintentar')->whereNumber('journal')->name('retry');
        Route::post('{journal}/transmitir', 'transmit')->middleware('can:libreria.ventas.transmitir')->whereNumber('journal')->name('transmit');
        Route::get('{journal}/json', 'download')->middleware('can:libreria.ventas.descargar')->whereNumber('journal')->name('download');
    });
    Route::middleware('can:libreria.ventas.crear')->group(function () {
        Route::get('libreria/clientes', SaleClientController::class)->name('libreria.clientes.index');
        Route::get('libreria/productos', SaleProductController::class)->name('libreria.productos.index');
        Route::get('libreria/paquetes', [SalePackageController::class, 'index'])->name('libreria.paquetes.index');
        Route::get('libreria/paquetes/{package}', [SalePackageController::class, 'show'])->whereNumber('package')->name('libreria.paquetes.show');
        Route::post('libreria/venta/disponibilidad', SaleStockController::class)->name('libreria.venta.disponibilidad');
        Route::get('libreria/alumnos', [StudentController::class, 'index'])->name('libreria.alumnos.index');
        Route::get('libreria/alumnos/{student}', [StudentController::class, 'show'])->whereNumber('student')->name('libreria.alumnos.show');
        Route::get('libreria/alumnos/{student}/cuotas', StudentTuitionController::class)->whereNumber('student')->name('libreria.alumnos.cuotas');
    });

    Route::livewire('administracion/roles', 'pages::administracion.roles')->middleware('can:administracion.roles.ver')->name('administracion.roles');
    Route::livewire('administracion/usuarios', 'pages::administracion.usuarios')->middleware('can:administracion.usuarios.ver')->name('administracion.usuarios');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
