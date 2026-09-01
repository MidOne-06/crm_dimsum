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

        // Un usuario con otro rol conserva el alcance adicional que este le otorgue.
        if ($user->roles()->where('slug', '!=', 'terminal')->exists()) {
            return $next($request);
        }

        if ($request->is('admin/salidas-stock/nueva', 'admin/salidas-stock/nueva/*', 'admin/logout')) {
            return $next($request);
        }

        return redirect()->to(NuevaSalidaStock::getUrl());
    }
}
