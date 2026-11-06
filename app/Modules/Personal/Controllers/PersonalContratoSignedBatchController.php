<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Personal\Services\PersonalContratoSignedBatchService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PersonalContratoSignedBatchController extends WebPageController
{
    private const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(private readonly PersonalContratoSignedBatchService $batches) {}

    public function index(Request $request): Response
    {
        $actor = $this->requireAuthenticatedUser();

        return response()->view('personal.contratos.carga-firmados', [
            'lote' => $request->boolean('nuevo') ? null : $this->batches->recent($actor),
            'maxBytes' => min(20 * 1024 * 1024, UploadedFile::getMaxFilesize()),
            'canPreviewPdf' => PermissionMatrix::userCanDirect($actor, 'personal', 'descargar_documentos'),
        ], 200, self::PRIVATE_HEADERS);
    }

    public function start(): JsonResponse
    {
        return $this->json(['id' => $this->batches->start($this->requireAuthenticatedUser())], 201);
    }

    public function show(string $lote): JsonResponse
    {
        return $this->json($this->batches->preview($this->requireAuthenticatedUser(), $lote));
    }

    public function stage(Request $request, string $lote): JsonResponse
    {
        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'operacion_id' => ['required', 'uuid'],
        ], [
            'archivo.required' => 'Selecciona un PDF.',
            'archivo.uploaded' => 'El PDF supera el límite de carga del servidor o la carga se interrumpió.',
            'archivo.mimes' => 'Selecciona un archivo PDF.',
            'archivo.max' => 'El PDF no debe superar 20 MB.',
        ]);

        return $this->json([
            'id' => $this->batches->stage($this->requireAuthenticatedUser(), $lote, $data['operacion_id'], $data['archivo']),
        ], 201);
    }

    public function assign(Request $request, string $lote, string $archivo): JsonResponse
    {
        $data = $request->validate(['contrato_id' => ['required', 'uuid']]);
        $this->batches->assign($this->requireAuthenticatedUser(), $lote, $archivo, $data['contrato_id']);

        return $this->json(['ok' => true]);
    }

    public function discard(string $lote, string $archivo): JsonResponse
    {
        $this->batches->discard($this->requireAuthenticatedUser(), $lote, $archivo);

        return $this->json(['ok' => true]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return $this->json(['contratos' => $this->batches->candidates($this->requireAuthenticatedUser(), $data['q'])]);
    }

    public function confirm(Request $request, string $lote): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'string', 'size:64'],
            'archivos' => ['required', 'array', 'min:1', 'max:20'],
            'archivos.*' => ['required', 'uuid', 'distinct'],
            'revisado' => ['required', 'accepted'],
        ]);

        return $this->json([
            'resultados' => $this->batches->confirm($this->requireAuthenticatedUser(), $lote, $data['revision'], $data['archivos']),
        ]);
    }

    public function ready(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:10000']]);

        return $this->json($this->batches->readyToActivate($this->requireAuthenticatedUser(), (int) ($data['page'] ?? 1)));
    }

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contratos' => ['required', 'array', 'min:1', 'max:20'],
            'contratos.*.id' => ['required', 'uuid', 'distinct'],
            'contratos.*.revision' => ['required', 'string', 'size:64'],
            'revisado' => ['required', 'accepted'],
        ]);

        return $this->json(['resultados' => $this->batches->activateReady($this->requireAuthenticatedUser(), $data['contratos'])]);
    }

    public function pdf(string $lote, string $archivo): Response
    {
        $row = $this->batches->privatePdf($this->requireAuthenticatedUser(), $lote, $archivo);

        return Storage::disk('local')->response($row->path, 'Contrato.pdf', self::PRIVATE_HEADERS + [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ], 'inline');
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, self::PRIVATE_HEADERS);
    }
}
