<?php

namespace App\Modules\Perfil\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\PersonalMina;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Services\EvaluacionDesempenoService;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Support\Rbac\MinaScopeAccess;
use Illuminate\View\View;

class PerfilPageController extends WebPageController
{
    public function __construct(
        private readonly EvaluacionDesempenoService $evaluaciones,
        private readonly PersonalMinaHabilitacionService $habilitacion,
    ) {}

    public function index(): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $usuario->loadMissing(['rol', 'rolesAdicionales', 'personal.relacionesMina.mina:id,nombre,estado', 'scopesMina.mina:id,nombre']);

        $perfil = [
            'id' => $usuario->id,
            'nombre' => $usuario->personal?->nombre_completo ?? session('user.name', $usuario->email),
            'email' => $usuario->email,
            'rol' => $usuario->rol?->nombre ?? 'Sin rol principal',
            'estado' => $usuario->estado,
        ];
        $evaluacionesResumen = $this->evaluationSummary($usuario);
        $minasDeAcceso = $usuario->scopesMina->pluck('mina.nombre')->filter()->unique()->sort()->values()->all();
        $accesoGeneral = MinaScopeAccess::isPrivileged($usuario);
        $states = $this->habilitacion->habilitationStateOptions();
        $habilitaciones = $usuario->personal?->relacionesMina
            ->filter(fn (PersonalMina $row): bool => $row->mina !== null)
            ->map(fn (PersonalMina $row): array => [
                'mina' => $row->mina->nombre,
                'estado' => !$row->activo || $row->mina->estado !== 'ACTIVO'
                    ? 'Asignación inactiva' : ($states[$row->estadoHabilitacionActual()] ?? 'Por revisar'),
            ])->sortBy('mina')->values()->all() ?? [];

        return view('perfil.index', compact('perfil', 'evaluacionesResumen', 'minasDeAcceso', 'accesoGeneral', 'habilitaciones'));
    }

    private function evaluationSummary(Usuario $usuario): ?array
    {
        if (!$usuario->personal) {
            return null;
        }

        $rows = $this->evaluaciones->list($usuario, ['trabajador_id' => $usuario->personal->id]);
        if ($rows === null) {
            return null;
        }
        $rows = $rows->where('estado_revision', 'VALIDA');
        $average = $rows->whereBetween('total', [0, 20])->avg('total');

        return [
            'total' => $rows->count(),
            'promedio' => $average === null ? 'Sin puntaje' : number_format($average, 2, '.', ''),
            'ultima' => $rows->first()?->fecha?->format('d/m/Y'),
        ];
    }
}
