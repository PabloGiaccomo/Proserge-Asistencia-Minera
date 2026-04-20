<?php

namespace App\Shared\Concerns;

use App\Models\Usuario;
use Illuminate\Support\Facades\Auth;

trait UsesAuthenticatedUser
{
    protected function getUser(): ?Usuario
    {
        // Solo usar datos de sesión - NO tocar base de datos
        $sessionUser = session('user');
        
        if (!$sessionUser) {
            return null;
        }
        
        // Crear un objeto Usuario simulado de la sesión
        $user = new Usuario();
        $user->id = $sessionUser['id'] ?? '00000000-0000-0000-0000-000000000001';
        $user->email = $sessionUser['email'] ?? '';
        $user->name = $sessionUser['name'] ?? 'Usuario';
        
        // Simular relación de rol desde sesión
        $user->setRelation('rol', (object) [
            'id' => 1,
            'nombre' => $sessionUser['rol'] ?? 'ADMIN',
            'permisos' => json_encode(['*'])
        ]);
        
        return $user;
    }
}