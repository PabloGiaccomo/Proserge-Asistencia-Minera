<?php

namespace App\Modules\Epps\Services;

use App\Models\EppOperacion;
use App\Models\Usuario;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class EppReceiptPdfService
{
    public function __construct(private readonly EppAcceptanceService $acceptances, private readonly EppQrCodeService $qr) {}

    public function html(EppOperacion $operation): string
    {
        $operation->loadMissing(['personal', 'almacen', 'detalles.epp', 'aceptacion', 'operacionOrigen', 'operacionesCompensatorias']);
        $acceptance = $operation->aceptacion;
        abort_unless($acceptance, 409, 'La operacion no tiene evidencia verificable asociada.');
        $verification = $this->acceptances->verify($acceptance);
        $signature = $verification['valid'] ? 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($acceptance->firma_path)) : null;
        $url = route('epps.evidence.verify', $acceptance->codigo_verificacion);

        return view('epps.pdf.operation-receipt', ['operation' => $operation, 'acceptance' => $acceptance, 'payload' => $acceptance->contenido_json, 'verification' => $verification, 'signature' => $signature, 'qrSvg' => $this->qr->svg($url)])->render();
    }

    public function output(EppOperacion $operation): string
    {
        $options = new Options;
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($operation), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    public function download(EppOperacion $operation, Usuario $viewer): Response
    {
        if (! Gate::forUser($viewer)->allows('downloadReceipt', $operation)) {
            throw new AuthorizationException('No tienes permiso para descargar esta constancia.');
        }

        $pdf = $this->output($operation);
        $operation->eventos()->create(['actor_usuario_id' => $viewer->id, 'accion' => 'EVIDENCIA_DESCARGADA', 'origen' => 'WEB', 'snapshot_json' => ['canal' => 'PDF']]);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="constancia-epp-'.$operation->id.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }
}
