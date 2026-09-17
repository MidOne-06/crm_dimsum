<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Produccion\RegistroProduccionDiaria;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pedido explícito del usuario (2026-09-17): un jefe/operario de
 * Producción que entra al panel cae en el Dashboard general (widgets de
 * ventas, indicadores ajenos a su trabajo) -- mismo problema ya resuelto
 * antes para el rol "terminal" (ver RedirectTerminalToNewStockExit), pero
 * más liviano: acá SOLO se saca al usuario del Dashboard raíz, sin
 * encerrarlo en una única pantalla -- un jefe de Producción sí tiene
 * varias pantallas propias (Registro, Consolidado, Historial, Catálogo) y
 * debe poder moverse libremente entre ellas.
 */
class RedirectProduccionOnlyToRegistro
{
    private const ROLES_PRODUCCION = ['jefe-planta-produccion', 'jefe-produccion', 'operario-produccion'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $rolesUsuario = $user->roles()->pluck('slug')->all();

        // Vacío (sin rol asignado) o con CUALQUIER rol fuera de los 3 de
        // Producción (incluido superadministrador) -- conserva su alcance
        // normal, no se lo redirige. Mismo criterio ya usado en
        // RedirectTerminalToNewStockExit: un rol adicional no debe perder
        // acceso al resto del panel.
        if ($rolesUsuario === [] || array_diff($rolesUsuario, self::ROLES_PRODUCCION) !== []) {
            return $next($request);
        }

        // Solo intercepta el Dashboard raíz -- el resto de sus propias
        // pantallas de Producción (o cualquier otra que su permiso ya
        // permita) sigue accesible sin trabas.
        if (! $request->is('admin')) {
            return $next($request);
        }

        return redirect()->to(RegistroProduccionDiaria::getUrl());
    }
}
