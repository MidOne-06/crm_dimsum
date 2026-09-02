<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Stock\NuevaSalidaStock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectTerminalToNewStockExit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->roles()->where('slug', 'terminal')->exists()) {
            return $next($request);
        }

        // Un usuario con otro rol, o con permisos operativos añadidos al rol
        // Terminal, conserva ese alcance. De otro modo un permiso asignado no
        // podría mostrarse ni utilizarse en el frontend.
        if ($user->roles()->where('slug', '!=', 'terminal')->exists()
            || $user->hasPermission('requerimientos-stock.crear')
            || $user->hasPermission('requerimientos-stock.plantillas.view')) {
            return $next($request);
        }

        if ($request->is('admin/salidas-stock/nueva', 'admin/salidas-stock/nueva/*', 'admin/logout')) {
            return $next($request);
        }

        return redirect()->to(NuevaSalidaStock::getUrl());
    }
}
