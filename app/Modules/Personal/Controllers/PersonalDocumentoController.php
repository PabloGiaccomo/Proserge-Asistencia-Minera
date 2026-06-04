<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Personal;
use App\Models\PersonalBloqueo;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PersonalDocumentoController extends WebPageController
{
    public function __construct(private readonly PersonalFichaService $fichaService)
    {
    }

    public function index(string $id): View
    {
        $trabajador = Personal::query()
            ->with([
                'contratoDatos',
                'fichaColaborador.archivos',
                'bloqueos' => function ($query): void {
                    $query->where('estado', 'ACTIVO')
                        ->where('tipo', 'gestacion')
                        ->orderByDesc('fecha_inicio');
                },
            ])
            ->findOrFail($id);

        $ficha = $trabajador->fichaColaborador;
        $requirements = PersonalFichaCatalog::documentRequirements();
        $attachedByType = $ficha?->archivos?->keyBy('tipo') ?? collect();
        $catalogKeys = array_keys($requirements);
        $extraArchivos = $ficha?->archivos
            ? $ficha->archivos->reject(fn ($archivo) => in_array((string) $archivo->tipo, $catalogKeys, true))->values()
            : collect();

        return view('personal.documentos.index', [
            'trabajador' => $trabajador,
            'ficha' => $ficha,
            'requirements' => $requirements,
            'attachedByType' => $attachedByType,
            'extraArchivos' => $extraArchivos,
            'contratoDatos' => $trabajador->contratoDatos,
            'isMujer' => $this->isFemale($trabajador),
            'gestacionBloqueos' => $trabajador->bloqueos,
            'today' => Carbon::today(),
        ]);
    }

    public function store(Request $request, string $id): RedirectResponse
    {
        $trabajador = Personal::query()->with('fichaColaborador')->findOrFail($id);

        $rules = [
            'documentos' => ['required', 'array'],
        ];

        foreach (PersonalFichaCatalog::documentRequirements() as $key => $requirement) {
            $rules['documentos.' . $key] = ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp'];
        }

        $validated = $request->validate($rules, [
            'documentos.required' => 'Adjunta al menos un documento.',
            'documentos.*.mimes' => 'El documento debe ser PDF, Word o imagen.',
            'documentos.*.max' => 'El documento no debe superar 10 MB.',
        ]);

        $documentos = collect($request->file('documentos', []))
            ->only(array_keys(PersonalFichaCatalog::documentRequirements()))
            ->filter(fn ($documento): bool => $documento instanceof UploadedFile)
            ->all();

        if (count($documentos) === 0) {
            return back()
                ->withErrors(['documentos' => 'Adjunta al menos un documento.'])
                ->withInput();
        }

        $this->fichaService->updateDocuments(
            $trabajador,
            $documentos,
            $this->requireAuthenticatedUser(),
        );

        return redirect()
            ->route('personal.documentos.index', $trabajador->id)
            ->with('success', 'Documento actualizado correctamente.');
    }

    public function gestacionPdf(string $id, string $bloqueoId): Response
    {
        $trabajador = Personal::query()
            ->with('fichaColaborador')
            ->findOrFail($id);

        abort_unless($this->isFemale($trabajador), 404);

        $bloqueo = PersonalBloqueo::query()
            ->where('personal_id', $trabajador->id)
            ->where('tipo', 'gestacion')
            ->where('estado', 'ACTIVO')
            ->findOrFail($bloqueoId);

        abort_if($bloqueo->fecha_inicio && $bloqueo->fecha_inicio->startOfDay()->greaterThan(Carbon::today()), 404);

        $ficha = $trabajador->fichaColaborador;
        $data = $ficha ? $this->fichaService->normalizeFichaData($ficha->datos_json ?? []) : [];
        $html = view('personal.documentos.gestacion-pdf', [
            'trabajador' => $trabajador,
            'bloqueo' => $bloqueo,
            'data' => $data,
        ])->render();

        if (!class_exists(Dompdf::class)) {
            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'constancia_gestacion_' . Str::slug($trabajador->nombre_completo ?: $trabajador->dni) . '.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function contratoFirmado(string $id): Response
    {
        $trabajador = Personal::query()
            ->with('contratoDatos')
            ->findOrFail($id);

        $contrato = $trabajador->contratoDatos;
        abort_unless($contrato?->signed_contract_path && Storage::disk('local')->exists($contrato->signed_contract_path), 404);

        $filename = $contrato->signed_contract_original_name
            ?: 'contrato_firmado_' . Str::slug($trabajador->nombre_completo ?: $trabajador->dni) . '.pdf';

        return response(Storage::disk('local')->get($contrato->signed_contract_path), 200, [
            'Content-Type' => $contrato->signed_contract_mime ?: 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
        ]);
    }

    private function isFemale(Personal $trabajador): bool
    {
        $trabajador->loadMissing('fichaColaborador');
        $data = is_array($trabajador->fichaColaborador?->datos_json ?? null)
            ? $trabajador->fichaColaborador->datos_json
            : [];

        $sexo = Str::lower(trim((string) ($data['sexo'] ?? '')));

        return $sexo !== '' && (str_starts_with($sexo, 'f') || in_array($sexo, ['mujer', 'femenino'], true));
    }
}
