<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Protege rutas no-Filament con el mismo catálogo de permisos del panel. */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasPermission($permission), 403);

        return $next($request);
    }
}
