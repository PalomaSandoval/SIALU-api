<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;

class DashboardController extends Controller
{
    public function index(DashboardService $dashboard)
    {
        $routes = [
            'dashboard' => route('api.v1.dashboard'),
            'logout' => route('api.v1.logout'),
            'usuarios_ldap' => route('api.v1.usuarios-ldap.consultar'),
            'tipos_usuarios' => route('api.v1.catalogos.tipos-usuarios.index'),
            'permisos' => route('api.v1.catalogos.permisos.index'),
            'tipos_usuarios_permisos' => route('api.v1.catalogos.tipos-usuarios-permisos.index'),
        ];
        foreach (array_keys(config('catalogs')) as $catalog) {
            $routes[str_replace('-', '_', $catalog)] = route('api.v1.catalogos.'.$catalog.'.index');
        }

        return response()->json(['data' => $dashboard->context('dashboard', $routes)]);
    }
}
