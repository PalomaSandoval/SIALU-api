<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Catalogos\PermisoController;
use App\Http\Controllers\Catalogos\TipoUsuarioController;
use App\Http\Controllers\Catalogos\TipoUsuarioPermisoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UsuarioLdapController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'application' => 'SIALU API',
            'status' => 'ok',
        ]);
    })->name('api.v1.health');

    // Laravel sessions and CSRF protection preserve Login's browser authentication model.
    Route::middleware('web')->name('api.v1.')->group(function (): void {
        Route::get('/csrf-token', [AuthController::class, 'csrf'])->name('csrf');
        Route::post('/login', [AuthController::class, 'login'])->name('login');

        Route::middleware ('sialu.auth')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/monitoreo/dispositivos/estado', [\App\Http\Controllers\DeviceStatusController::class, 'show'])->name('monitoreo.dispositivos.estado');
            Route::get('/monitoreo/dispositivos/actividad', [\App\Http\Controllers\DeviceActivityController::class, 'show'])->name('monitoreo.dispositivos.actividad');
            Route::get('/monitoreo/dispositivos/actividad/historial', [\App\Http\Controllers\DeviceActivityController::class, 'history'])->name('monitoreo.dispositivos.actividad.historial');
            Route::get('/monitoreo/orion/incidentes/caidas', [\App\Http\Controllers\OrionIncidentsController::class, 'open'])->name('monitoreo.orion.incidentes.caidas');
            Route::get('/monitoreo/orion/incidentes/intermitencias', [\App\Http\Controllers\OrionIncidentsController::class, 'intermittent'])->name('monitoreo.orion.incidentes.intermitencias');
            Route::get('/monitoreo/disponibilidad/areas', [\App\Http\Controllers\AreaAvailabilityController::class, 'show'])->name('monitoreo.disponibilidad.areas');
            Route::get('/monitoreo/disponibilidad/nodos/orion', [\App\Http\Controllers\OrionNodesController::class, 'index'])->name('monitoreo.disponibilidad.nodos.orion');
            Route::get('/monitoreo/disponibilidad/nodos/{nodeId}/orion',[\App\Http\Controllers\NodeAvailabilityStatsController::class, 'show'])->name('monitoreo.disponibilidad.nodo.orion');
            // Reporte mensual (docs/disponibilidad-mensual.md): un reporte por mes leído de capturas guardadas. Las capturas
            // (historial, estado y solicitudes expresas) son diagnóstico de administradores.
            Route::prefix('/monitoreo/disponibilidad/reporte-mensual')->name('monitoreo.disponibilidad.mensual.')->group(function (): void {
                Route::get('/capturas', [\App\Http\Controllers\MonthlyAvailabilityController::class, 'index'])->middleware('sialu.role:ADMINISTRADOR')->name('capturas');
                Route::post('/capturas', [\App\Http\Controllers\MonthlyAvailabilityController::class, 'request'])->middleware('sialu.role:ADMINISTRADOR')->name('capturas.solicitar');
                Route::get('/capturas/{id}', [\App\Http\Controllers\MonthlyAvailabilityController::class, 'show'])->whereNumber('id')->middleware('sialu.role:ADMINISTRADOR')->name('capturas.estado');
                Route::get('/meses/{mes}', [\App\Http\Controllers\MonthlyAvailabilityController::class, 'month'])->name('mes');
                Route::get('/meses/{mes}/pdf', [\App\Http\Controllers\MonthlyAvailabilityController::class, 'pdf'])->name('mes.pdf');
            });
            Route::get('/monitoreo/amenazas/severidad', [\App\Http\Controllers\ThreatSeverityController::class, 'show'])->name('monitoreo.amenazas.severidad');
            Route::get('/monitoreo/amenazas/detalle', [\App\Http\Controllers\ThreatSeverityController::class, 'threats'])->name('monitoreo.amenazas.detalle');
            Route::get('/monitoreo/amenazas/ficha', [\App\Http\Controllers\ThreatSeverityController::class, 'profile'])->name('monitoreo.amenazas.ficha');
            Route::get('/fuentes-navegacion', [\App\Http\Controllers\Catalogos\FuenteController::class, 'navigation'])->name('fuentes.navigation');
            Route::get('/catalogos/fuentes/{id}/icono', [\App\Http\Controllers\Catalogos\FuenteController::class, 'showIcon'])->whereNumber('id')->name('catalogos.fuentes.icono.show');
            Route::get('/integraciones/{adapter}/estado', [\App\Http\Controllers\IntegrationStatusController::class, 'show'])->middleware('sialu.role:ADMINISTRADOR');
            Route::get('/integraciones/{adapter}/cobertura', [\App\Http\Controllers\IngestionCoverageController::class, 'show'])->whereIn('adapter', ['orion', 'paloalto'])->middleware('sialu.role:ADMINISTRADOR');
            Route::get('/integraciones/orion/alertas', [\App\Http\Controllers\OrionAlertsController::class, 'index'])->middleware('sialu.role:ADMINISTRADOR');
            Route::get('/consultas/eventos', [\App\Http\Controllers\EventQueriesController::class, 'index'])->middleware('sialu.role:ADMINISTRADOR');
            Route::get('/usuarios-ldap/consultar', [UsuarioLdapController::class, 'consultar'])
                ->middleware('sialu.permission:USUARIO_LDAP_CONSULTAR')->name('usuarios-ldap.consultar');

            Route::prefix('catalogos')->name('catalogos.')->middleware('sialu.role:ADMINISTRADOR')->group(function (): void {
                foreach (['contextos-red','contextos-fuente','asignaciones-ip','grupos-infraestructura','grupos-activos','interfaces','identificadores-interfaz','identificadores-activo'] as $identity) {
                    $controller=\App\Http\Controllers\Catalogos\NetworkIdentityController::class;
                    Route::get('/'.$identity,[$controller,'index'])->defaults('identity',$identity);
                    Route::post('/'.$identity,[$controller,'store'])->defaults('identity',$identity);
                    Route::put('/'.$identity.'/{id}',[$controller,'update'])->whereNumber('id')->defaults('identity',$identity);
                }
                Route::get('/zonas-firewall',[\App\Http\Controllers\Catalogos\NetworkZonesController::class,'index']);
                Route::put('/zonas-firewall',[\App\Http\Controllers\Catalogos\NetworkZonesController::class,'update']);
                Route::get('/ubicaciones', [\App\Http\Controllers\Catalogos\LocationsController::class, 'index'])->name('ubicaciones.index');
                Route::get('/ubicaciones/activos', [\App\Http\Controllers\Catalogos\LocationsController::class, 'assets'])->name('ubicaciones.assets');
                Route::get('/equivalencias-acciones', [\App\Http\Controllers\Catalogos\TrafficActionsController::class, 'index']);
                Route::put('/equivalencias-acciones/{id}', [\App\Http\Controllers\Catalogos\TrafficActionsController::class, 'update'])->whereNumber('id');
                foreach (array_keys(config('reference_catalogs')) as $reference) {
                    $controller = \App\Http\Controllers\Catalogos\ReferenceCatalogController::class;
                    Route::get('/'.$reference, [$controller, 'index'])->defaults('reference', $reference)->name($reference.'.index');
                    if ($reference !== 'severidad') Route::post('/'.$reference, [$controller, 'store'])->defaults('reference', $reference)->name($reference.'.store');
                    Route::get('/'.$reference.'/{id}', [$controller, 'show'])->whereNumber('id')->defaults('reference', $reference)->name($reference.'.show');
                    Route::put('/'.$reference.'/{id}', [$controller, 'update'])->whereNumber('id')->defaults('reference', $reference)->name($reference.'.update');
                    if ($reference !== 'severidad') {
                        Route::post('/'.$reference.'/{id}/activar', [$controller, 'activate'])->whereNumber('id')->defaults('reference', $reference)->name($reference.'.activate');
                        Route::post('/'.$reference.'/{id}/desactivar', [$controller, 'deactivate'])->whereNumber('id')->defaults('reference', $reference)->name($reference.'.deactivate');
                    }
                }
                $campus = \App\Http\Controllers\Catalogos\CampusController::class;
                Route::get('/campus', [$campus, 'index'])->name('campus.index');
                Route::post('/campus', [$campus, 'store'])->name('campus.store');
                Route::get('/campus/{id}', [$campus, 'show'])->whereNumber('id')->name('campus.show');
                Route::put('/campus/{id}', [$campus, 'update'])->whereNumber('id')->name('campus.update');
                Route::post('/campus/{id}/activar', [$campus, 'activate'])->whereNumber('id')->name('campus.activate');
                Route::post('/campus/{id}/desactivar', [$campus, 'deactivate'])->whereNumber('id')->name('campus.deactivate');
                $fuentes = \App\Http\Controllers\Catalogos\FuenteController::class;
                Route::get('/fuentes', [$fuentes, 'index'])->name('fuentes.index');
                Route::post('/fuentes', [$fuentes, 'store'])->name('fuentes.store');
                Route::get('/fuentes/{id}', [$fuentes, 'show'])->whereNumber('id')->name('fuentes.show');
                Route::get('/fuentes/{id}/dependencias', [$fuentes, 'dependencies'])->whereNumber('id')->name('fuentes.dependencies');
                Route::put('/fuentes/{id}', [$fuentes, 'update'])->whereNumber('id')->name('fuentes.update');
                Route::post('/fuentes/{id}/icono', [$fuentes, 'uploadIcon'])->whereNumber('id')->name('fuentes.icono.upload');
                Route::post('/fuentes/{id}/activar', [$fuentes, 'activate'])->whereNumber('id')->name('fuentes.activate');
                Route::post('/fuentes/{id}/desactivar', [$fuentes, 'deactivate'])->whereNumber('id')->name('fuentes.deactivate');
                Route::get('/tipos-usuarios/vigentes', [TipoUsuarioController::class, 'active'])->name('tipos-usuarios.vigentes');
                Route::get('/permisos/vigentes', [PermisoController::class, 'active'])->name('permisos.vigentes');
                foreach ([
                    'tipos-usuarios' => TipoUsuarioController::class,
                    'permisos' => PermisoController::class,
                    'tipos-usuarios-permisos' => TipoUsuarioPermisoController::class,
                ] as $path => $controller) {
                    Route::get('/'.$path, [$controller, 'index'])->name($path.'.index');
                    Route::post('/'.$path, [$controller, 'store'])->name($path.'.store');
                    Route::get('/'.$path.'/{id}', [$controller, 'show'])->whereNumber('id')->name($path.'.show');
                    Route::put('/'.$path.'/{id}', [$controller, 'update'])->whereNumber('id')->name($path.'.update');
                    Route::post('/'.$path.'/{id}/activar', [$controller, 'activate'])->whereNumber('id')->name($path.'.activate');
                    Route::post('/'.$path.'/{id}/desactivar', [$controller, 'deactivate'])->whereNumber('id')->name($path.'.deactivate');
                }
            });
        });
    });
});
