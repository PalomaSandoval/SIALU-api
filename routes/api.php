<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Catalogos\InstitutionalCatalogController;
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
            Route::get('/integraciones/{adapter}/estado', [\App\Http\Controllers\IntegrationStatusController::class, 'show'])->middleware('sialu.role:ADMINISTRADOR');
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
                Route::get('/tipos-activo/{id}/activos', [\App\Http\Controllers\Catalogos\LocationsController::class, 'assetsByType'])->whereNumber('id')->name('tipos-activo.assets');
                Route::get('/equivalencias-acciones', [\App\Http\Controllers\Catalogos\TrafficActionsController::class, 'index']);
                $networks = \App\Http\Controllers\Catalogos\InstitutionalNetworksController::class;
                Route::get('/redes-institucionales', [$networks, 'index']);
                Route::post('/redes-institucionales', [$networks, 'store']);
                Route::put('/redes-institucionales/{id}', [$networks, 'update'])->whereNumber('id');
                Route::post('/redes-institucionales/{id}/activar', [$networks, 'activate'])->whereNumber('id');
                Route::post('/redes-institucionales/{id}/desactivar', [$networks, 'deactivate'])->whereNumber('id');
                Route::get('/clasificacion-trafico', [\App\Http\Controllers\Catalogos\TrafficClassificationController::class, 'index']);
                Route::post('/clasificacion-trafico/redes', [\App\Http\Controllers\Catalogos\TrafficClassificationController::class, 'storeNetwork']);
                Route::put('/equivalencias-acciones/{id}', [\App\Http\Controllers\Catalogos\TrafficActionsController::class, 'update'])->whereNumber('id');
                foreach (array_keys(config('reference_catalogs')) as $reference) {
                    $controller = \App\Http\Controllers\Catalogos\ReferenceCatalogController::class;
                    Route::get('/'.$reference, [$controller, 'index'])->defaults('reference', $reference)->name($reference.'.index');
                    Route::post('/'.$reference, [$controller, 'store'])->defaults('reference', $reference)->name($reference.'.store');
                    Route::get('/'.$reference.'/{id}', [$controller, 'show'])->whereNumber('id')->defaults('reference', $reference)->name($reference.'.show');
                    Route::put('/'.$reference.'/{id}', [$controller, 'update'])->whereNumber('id')->defaults('reference', $reference)->name($reference.'.update');
                    Route::post('/'.$reference.'/{id}/activar', [$controller, 'activate'])->whereNumber('id')->defaults('reference', $reference)->name($reference.'.activate');
                    Route::post('/'.$reference.'/{id}/desactivar', [$controller, 'deactivate'])->whereNumber('id')->defaults('reference', $reference)->name($reference.'.deactivate');
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
                Route::put('/fuentes/{id}', [$fuentes, 'update'])->whereNumber('id')->name('fuentes.update');
                Route::post('/fuentes/{id}/activar', [$fuentes, 'activate'])->whereNumber('id')->name('fuentes.activate');
                Route::post('/fuentes/{id}/desactivar', [$fuentes, 'deactivate'])->whereNumber('id')->name('fuentes.deactivate');
                Route::get('/tipos-usuarios/vigentes', [TipoUsuarioController::class, 'active'])->name('tipos-usuarios.vigentes');
                Route::get('/permisos/vigentes', [PermisoController::class, 'active'])->name('permisos.vigentes');
                foreach (array_keys(config('catalogs')) as $catalog) {
                    $controller = InstitutionalCatalogController::class;
                    Route::get('/'.$catalog, [$controller, 'index'])->defaults('catalog', $catalog)->name($catalog.'.index');
                    Route::post('/'.$catalog, [$controller, 'store'])->defaults('catalog', $catalog)->name($catalog.'.store');
                    Route::get('/'.$catalog.'/{id}', [$controller, 'show'])->whereNumber('id')->defaults('catalog', $catalog)->name($catalog.'.show');
                    Route::put('/'.$catalog.'/{id}', [$controller, 'update'])->whereNumber('id')->defaults('catalog', $catalog)->name($catalog.'.update');
                    Route::post('/'.$catalog.'/{id}/activar', [$controller, 'activate'])->whereNumber('id')->defaults('catalog', $catalog)->name($catalog.'.activate');
                    Route::post('/'.$catalog.'/{id}/desactivar', [$controller, 'deactivate'])->whereNumber('id')->defaults('catalog', $catalog)->name($catalog.'.deactivate');
                }
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
