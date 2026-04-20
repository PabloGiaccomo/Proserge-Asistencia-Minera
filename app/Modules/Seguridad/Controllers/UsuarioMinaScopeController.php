<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UsuarioMinaScope;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsuarioMinaScopeController extends Controller
{
    public function index(string $usuarioId)
    {
        $scope = UsuarioMinaScope::query()
            ->where('usuario_id', $usuarioId)
            ->orderBy('mina_id')
            ->pluck('mina_id')
            ->values();

        return ApiResponse::success(
            data: [
                'usuario_id' => $usuarioId,
                'mina_ids' => $scope,
            ],
            message: 'Scope por mina obtenido',
            code: 'USUARIO_MINA_SCOPE_OK',
        );
    }

    public function sync(Request $request, string $usuarioId)
    {
        $payload = $request->validate([
            'mina_ids' => ['required', 'array'],
            'mina_ids.*' => ['string', 'size:36'],
        ]);

        $minaIds = collect($payload['mina_ids'])
            ->map(fn (string $id) => trim($id))
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values();

        DB::transaction(function () use ($usuarioId, $minaIds): void {
            UsuarioMinaScope::query()->where('usuario_id', $usuarioId)->delete();

            $rows = $minaIds->map(fn (string $minaId) => [
                'id' => (string) Str::uuid(),
                'usuario_id' => $usuarioId,
                'mina_id' => $minaId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if (!empty($rows)) {
                UsuarioMinaScope::query()->insert($rows);
            }
        });

        return ApiResponse::success(
            data: [
                'usuario_id' => $usuarioId,
                'mina_ids' => $minaIds,
            ],
            message: 'Scope por mina actualizado',
            code: 'USUARIO_MINA_SCOPE_SYNC_OK',
        );
    }
}
