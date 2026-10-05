<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Shared\Support\ApiResponse;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        if (!PermissionMatrix::userCanDirect($request->user(), 'usuarios', 'ver')) {
            return ApiResponse::error(
                message: 'No tienes permiso para consultar usuarios.',
                code: 'USUARIOS_FORBIDDEN',
                status: 403,
            );
        }

        $estado = strtoupper($request->string('estado')->toString());

        $query = Usuario::query()
            ->select(['id', 'email', 'rol_id', 'personal_id', 'created_at'])
            ->with(['rol:id,nombre,estado']);

        if ($estado !== '') {
            $query->whereHas('rol', function ($q) use ($estado): void {
                $q->where('estado', $estado);
            });
        }

        return ApiResponse::success(
            data: $query->orderBy('email')->get(),
            message: 'Usuarios obtenidos',
            code: 'USUARIOS_LIST_OK',
        );
    }

    public function destroy(Request $request, string $id)
    {
        $actor = $request->user();

        if (!PermissionMatrix::userCanDirect($actor, 'usuarios', 'eliminar')) {
            return ApiResponse::error(
                message: 'No tienes permiso para eliminar usuarios.',
                code: 'USUARIOS_DELETE_FORBIDDEN',
                status: 403,
            );
        }

        $adminProtection = app(\App\Modules\Seguridad\Services\AdminProtectionService::class);

        if (!$adminProtection->isSuperAdminUser($actor)) {
            return ApiResponse::error(
                message: 'La eliminación de usuarios está restringida estrictamente al rol SUPERADMIN.',
                code: 'USUARIOS_DELETE_SUPERADMIN_ONLY',
                status: 403,
            );
        }

        $usuario = Usuario::query()->find($id);
        if (!$usuario) {
            return ApiResponse::error(
                message: 'Usuario no encontrado.',
                code: 'USUARIO_NOT_FOUND',
                status: 404,
            );
        }

        if ((string) $actor->id === (string) $usuario->id) {
            return ApiResponse::error(
                message: 'No puedes eliminar tu propia cuenta de usuario.',
                code: 'USUARIOS_SELF_DELETE_FORBIDDEN',
                status: 422,
            );
        }

        try {
            $adminProtection->assertUserDeleteAllowed($usuario);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error(
                message: (string) collect($e->errors())->flatten()->first(),
                code: 'USUARIOS_LAST_ADMIN_PROTECTED',
                status: 422,
            );
        }

        $conflicts = $adminProtection->getTransactionalDependencies($usuario);
        if (!empty($conflicts)) {
            return ApiResponse::error(
                message: 'No se puede eliminar el usuario porque registra actividad histórica en el sistema (' . implode(', ', $conflicts) . ').',
                code: 'USUARIOS_HAS_TRANSACTIONAL_DATA',
                status: 422,
            );
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($usuario): void {
            if (\Illuminate\Support\Facades\Schema::hasTable('usuario_roles')) {
                \Illuminate\Support\Facades\DB::table('usuario_roles')->where('usuario_id', $usuario->id)->delete();
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('usuario_mina_scope')) {
                \Illuminate\Support\Facades\DB::table('usuario_mina_scope')->where('usuario_id', $usuario->id)->delete();
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('notification_preferences')) {
                \Illuminate\Support\Facades\DB::table('notification_preferences')->where('usuario_id', $usuario->id)->delete();
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('notification_user_settings')) {
                \Illuminate\Support\Facades\DB::table('notification_user_settings')->where('usuario_id', $usuario->id)->delete();
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('notification_recipients')) {
                \Illuminate\Support\Facades\DB::table('notification_recipients')->where('usuario_id', $usuario->id)->delete();
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('auth_tokens')) {
                \Illuminate\Support\Facades\DB::table('auth_tokens')->where('usuario_id', $usuario->id)->delete();
            }

            $usuario->delete();
        });

        return ApiResponse::success(
            data: ['id' => $id],
            message: 'Usuario eliminado correctamente',
            code: 'USUARIOS_DELETED',
        );
    }
}
