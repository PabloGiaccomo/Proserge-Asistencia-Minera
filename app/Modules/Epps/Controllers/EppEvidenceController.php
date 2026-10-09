<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\EppAceptacion;
use App\Models\EppOperacion;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppAcceptanceService;
use App\Modules\Epps\Services\EppKardexService;
use App\Modules\Epps\Services\EppReceiptPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EppEvidenceController extends WebPageController
{
    public function __construct(private readonly EppAcceptanceService $acceptances, private readonly EppReceiptPdfService $receipts, private readonly EppKardexService $kardex) {}

    public function verify(string $code): View
    {
        $acceptance = EppAceptacion::query()->with(['operacion.personal', 'operacion.detalles.epp', 'operacion.operacionesCompensatorias'])->where('codigo_verificacion', $code)->firstOrFail();
        $actor = $this->actor();
        Gate::forUser($actor)->authorize('viewEvidence', $acceptance->operacion);
        $acceptance->operacion->eventos()->create(['actor_usuario_id' => $actor->id, 'accion' => 'EVIDENCIA_CONSULTADA', 'origen' => 'WEB', 'snapshot_json' => ['canal' => 'VERIFICACION']]);

        return view('epps.operations.verify', ['operation' => $acceptance->operacion, 'acceptance' => $acceptance, 'verification' => $this->acceptances->verify($acceptance)]);
    }

    public function receipt(EppOperacion $operation): Response
    {
        return $this->receipts->download($operation, $this->actor());
    }

    public function excel(Request $request): StreamedResponse
    {
        $data = $request->validate(['personal_id' => ['required', 'string', 'exists:personal,id']]);

        return $this->kardex->downloadExcel(Personal::query()->findOrFail($data['personal_id']), $this->actor());
    }

    private function actor(): Usuario
    {
        return Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
    }
}
