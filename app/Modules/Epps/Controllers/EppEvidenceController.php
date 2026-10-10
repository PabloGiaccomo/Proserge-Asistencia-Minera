<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\EppAceptacion;
use App\Models\EppOperacion;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Requests\DownloadEppKardexRequest;
use App\Modules\Epps\Services\EppAcceptanceService;
use App\Modules\Epps\Services\EppKardexExcelService;
use App\Modules\Epps\Services\EppKardexService;
use App\Modules\Epps\Services\EppReceiptPdfService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

        $verification = $this->acceptances->verify($acceptance);
        $signature = null;
        if ($verification['valid']) {
            $bytes = Storage::disk('local')->get($acceptance->firma_path);
            if (is_string($bytes) && hash_equals($acceptance->firma_sha256, hash('sha256', $bytes))) {
                $signature = 'data:image/png;base64,'.base64_encode($bytes);
            }
        }

        return view('epps.operations.verify', ['operation' => $acceptance->operacion, 'acceptance' => $acceptance, 'verification' => $verification, 'signature' => $signature]);
    }

    public function receipt(EppOperacion $operation): Response
    {
        return $this->receipts->download($operation, $this->actor());
    }

    public function excel(DownloadEppKardexRequest $request, EppKardexExcelService $excel): StreamedResponse
    {
        $data = $request->validated();

        return $excel->download(Personal::query()->findOrFail($data['personal_id']), $this->actor(), $data['fecha_desde'], $data['fecha_hasta']);
    }

    private function actor(): Usuario
    {
        return Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
    }
}
