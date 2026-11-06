<?php

namespace App\Modules\Evaluaciones\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Evaluaciones\Services\EvaluacionAsistenciaRevisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EvaluacionAsistenciaRevisionController extends WebPageController
{
    public function __construct(private readonly EvaluacionAsistenciaRevisionService $reviews) {}

    public function index(): View
    {
        return view('evaluaciones.revisiones-asistencia', ['rows' => $this->reviews->pending($this->requireAuthenticatedUser())]);
    }

    public function resolve(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:RATIFICAR,EXCLUIR'],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);
        $this->reviews->resolve($id, $this->requireAuthenticatedUser(), $data['revision'], $data['decision'], $data['motivo']);

        return redirect()->route('evaluaciones.revisiones-asistencia')->with('success', 'Decisión registrada. El promedio fue actualizado.');
    }
}
