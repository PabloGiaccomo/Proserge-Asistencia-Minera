<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalFicha;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PersonalFichaPdfService
{
    public function __construct(private readonly PersonalFichaService $fichaService)
    {
    }

    public function download(PersonalFicha $ficha): Response
    {
        if (!class_exists(Dompdf::class)) {
            return response($this->output($ficha), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        $name = $this->filename($ficha);

        return response($this->output($ficha), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    public function output(PersonalFicha $ficha): string
    {
        $ficha->loadMissing(['personal', 'familiares']);

        $html = view('personal.fichas.pdf', [
            'ficha' => $ficha,
            'data' => $this->fichaService->normalizeFichaData($ficha->datos_json ?? []),
            'familiares' => $ficha->familiares,
            'firmaBase64' => $ficha->firma_base64,
            'huellaDataUrl' => $this->fichaService->imageDataUrl($ficha->huella_path),
        ])->render();

        if (!class_exists(Dompdf::class)) {
            return $html;
        }

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(PersonalFicha $ficha): string
    {
        return 'ficha_colaborador_' . Str::slug($ficha->personal?->nombre_completo ?: $ficha->numero_documento) . '.pdf';
    }
}
