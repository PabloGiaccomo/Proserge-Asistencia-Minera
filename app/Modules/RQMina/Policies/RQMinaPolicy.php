<?php

namespace App\Modules\RQMina\Policies;

use App\Models\RQMina;
use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RQMinaPolicy
{
    public function canAccessMina(Usuario $usuario, string $minaId): bool
    {
        $isPrivileged = $this->isPrivileged($usuario);

        if ($isPrivileged) {
            Log::info('rqmina.policy_mina_access_check', [
                'usuario_id' => (string) $usuario->id,
                'mina_id' => (string) $minaId,
                'has_access' => true,
                'is_privileged' => true,
            ]);

            return true;
        }

        $hasAccess = DB::table('usuario_mina_scope')
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $minaId)
            ->exists();

        Log::info('rqmina.policy_mina_access_check', [
            'usuario_id' => (string) $usuario->id,
            'mina_id' => (string) $minaId,
            'has_access' => $hasAccess,
            'is_privileged' => false,
        ]);

        return $hasAccess;
    }

    public function view(Usuario $usuario, RQMina $rqMina): bool
    {
        if (!PermissionMatrix::userCan($usuario, 'rq_mina', 'ver')) {
            return false;
        }

        if ($this->isPrivileged($usuario)) {
            return true;
        }

        return $this->canAccessMina($usuario, $rqMina->mina_id);
    }

    public function update(Usuario $usuario, RQMina $rqMina): bool
    {
        if (in_array($rqMina->estado, ['CERRADO', 'CANCELADO'], true)) {
            return false;
        }

        if (!PermissionMatrix::userCanAny($usuario, 'rq_mina', ['editar', 'actualizar'])) {
            return false;
        }

        if ($this->isPrivileged($usuario)) {
            return true;
        }

        if ((string) $rqMina->created_by_usuario_id !== (string) $usuario->id) {
            return false;
        }

        return $this->canAccessMina($usuario, $rqMina->mina_id);
    }

    public function send(Usuario $usuario, RQMina $rqMina): bool
    {
        if ((string) $rqMina->estado !== 'BORRADOR') {
            return false;
        }

        return $this->update($usuario, $rqMina);
    }

    public function delete(Usuario $usuario, RQMina $rqMina): bool
    {
        if ((string) $rqMina->estado !== 'BORRADOR') {
            return false;
        }

        return $this->update($usuario, $rqMina);
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCan($usuario, 'rq_mina', 'administrar');
    }
}
