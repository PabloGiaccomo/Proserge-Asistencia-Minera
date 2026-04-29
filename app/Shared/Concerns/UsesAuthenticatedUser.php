<?php

namespace App\Shared\Concerns;

use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

trait UsesAuthenticatedUser
{
    protected function getUser(): ?Usuario
    {
        $sessionUser = session('user');
        
        if (!$sessionUser) {
            return null;
        }
        
        $user = new Usuario();
        $user->id = $sessionUser['id'] ?? '00000000-0000-0000-0000-000000000001';
        $user->email = $sessionUser['email'] ?? '';
        $user->name = $sessionUser['name'] ?? 'Usuario';
        
        $user->setRelation('rol', (object) [
            'id' => 1,
            'nombre' => $sessionUser['rol'] ?? 'ADMIN',
            'permisos' => PermissionMatrix::normalize($sessionUser['permissions'] ?? ['*'])
        ]);

        $minaScopes = collect($sessionUser['mina_scopes'] ?? $sessionUser['scope_minas'] ?? [])
            ->map(fn ($scope) => (string) $scope)
            ->filter()
            ->values();

        $scopeSource = 'session';
        if ($minaScopes->isEmpty()) {
            $scopeSource = 'db';
            $minaScopes = $this->loadMinaScopesFromDatabase((string) $user->id);
        }

        $scopesCollection = new \Illuminate\Database\Eloquent\Collection();
        foreach ($minaScopes as $scope) {
            $scopeObj = new \stdClass();
            $scopeObj->mina_id = $scope;
            $scopesCollection->push($scopeObj);
        }

        $user->setRelation('scopesMina', $scopesCollection);

        Log::info('auth.session_user_scopes_loaded', [
            'usuario_id' => (string) $user->id,
            'scope_source' => $scopeSource,
            'mina_scope_ids' => $minaScopes->values()->all(),
        ]);
        
        return $user;
    }

    private function loadMinaScopesFromDatabase(string $usuarioId): \Illuminate\Support\Collection
    {
        if (!Schema::hasTable('usuario_mina_scope')) {
            Log::warning('auth.session_user_scope_table_missing', [
                'usuario_id' => $usuarioId,
                'table' => 'usuario_mina_scope',
            ]);

            return collect();
        }

        return DB::table('usuario_mina_scope')
            ->where('usuario_id', $usuarioId)
            ->pluck('mina_id')
            ->map(fn ($minaId) => (string) $minaId)
            ->filter()
            ->values();
    }

    protected function requireAuthenticatedUser(): Usuario
    {
        $user = $this->getUser();
        
        if (!$user) {
            abort(401, 'No autenticado');
        }
        
        return $user;
    }
}
