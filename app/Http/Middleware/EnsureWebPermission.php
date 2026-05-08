<?php

namespace App\Http\Middleware;

use App\Support\Rbac\PermissionMatrix;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWebPermission
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'ver'): Response
    {
        $permissions = session('user.permissions', []);

        if (PermissionMatrix::allows($permissions, $module, $action)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false,
                'error' => 'PERMISSION_DENIED',
                'message' => 'No tienes permiso para realizar esta accion.',
            ], 403);
        }

        abort(403, 'No tienes permiso para realizar esta accion.');
    }
}
