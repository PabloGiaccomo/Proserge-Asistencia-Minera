<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PersonalFichaLink;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicPersonalFichaController extends Controller
{
    public function __construct(private readonly PersonalFichaService $fichaService)
    {
    }

    public function show(string $token): View
    {
        $resolved = $this->fichaService->resolveToken($token);
        $ficha = $resolved['ficha'];
        $data = $ficha ? $this->fichaService->fichaDataForPublic($ficha) : PersonalFichaCatalog::emptyData();

        return view('ficha-public.show', [
            'token' => $token,
            'mode' => $resolved['mode'],
            'ficha' => $ficha,
            'link' => $resolved['link'],
            'data' => $data,
            'sections' => PersonalFichaCatalog::sections(),
            'verifyFields' => $ficha?->campos_verificacion_json ?? PersonalFichaCatalog::defaultVerificationKeys(),
            'familiares' => $ficha?->familiares ?? collect(),
            'archivos' => $ficha?->archivos ?? collect(),
            'documentRequirements' => PersonalFichaCatalog::documentRequirements(),
            'declarationCheckboxes' => PersonalFichaCatalog::declarationCheckboxes(),
            'firmaBase64' => $ficha?->firma_base64,
            'huellaDataUrl' => $this->fichaService->imageDataUrl($ficha?->huella_path),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $resolved = $this->fichaService->resolveToken($token);

        if (($resolved['mode'] ?? '') !== 'edit' || !($resolved['link'] instanceof PersonalFichaLink)) {
            return redirect()
                ->route('ficha-colaborador.show', ['token' => $token])
                ->with('error', 'Este link ya no permite modificaciones.');
        }

        $validated = $request->validate($this->rules(), $this->messages());
        $fields = $validated['fields'];
        $fields['declaraciones_json'] = json_encode(array_keys($validated['declaraciones'] ?? []));
        $ficha = $resolved['ficha'];
        $fields['tipo_documento'] = $ficha->tipo_documento;
        $fields['numero_documento'] = $ficha->numero_documento;

        $submitted = $this->fichaService->submitFromWorker(
            $resolved['link'],
            $fields,
            $validated['familiares'],
            $validated['firma_base64'],
            $request->file('huella'),
            $request->file('documentos', []),
        );

        $this->fichaService->notifyFichaSubmitted($submitted);

        return redirect()
            ->route('ficha-colaborador.show', ['token' => $token])
            ->with('success', 'Ficha enviada correctamente. El link queda en solo lectura durante 24 horas.');
    }

    private function rules(): array
    {
        $rules = [
            'fields' => ['required', 'array'],
            'familiares' => ['required', 'array', 'min:1'],
            'familiares.*.nombres_apellidos' => ['required', 'string', 'max:191'],
            'familiares.*.parentesco' => ['required', 'string', 'max:80'],
            'familiares.*.fecha_nacimiento' => ['nullable', 'date'],
            'familiares.*.tipo_documento' => ['nullable', 'string', 'max:40'],
            'familiares.*.numero_documento' => ['nullable', 'string', 'max:40'],
            'familiares.*.telefono' => ['required', 'string', 'max:30'],
            'familiares.*.vive_con_trabajador' => ['nullable'],
            'familiares.*.contacto_emergencia' => ['nullable'],
            'firma_base64' => ['required', 'string'],
            'huella' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'documentos' => ['required', 'array'],
            'declaraciones' => ['required', 'array'],
        ];

        foreach (PersonalFichaCatalog::documentRequirements() as $key => $requirement) {
            $rules['documentos.' . $key] = [
                ($requirement['required'] ?? false) ? 'required' : 'nullable',
                'file',
                'max:10240',
                'mimes:pdf,doc,docx,jpg,jpeg,png,webp',
            ];
        }

        foreach (PersonalFichaCatalog::declarationCheckboxes() as $key => $label) {
            $rules['declaraciones.' . $key] = ['accepted'];
        }

        foreach (PersonalFichaCatalog::fields() as $key => $field) {
            $fieldRules = [(bool) ($field['required'] ?? false) ? 'required' : 'nullable'];
            $type = $field['type'] ?? 'text';

            if ($type === 'date') {
                $fieldRules[] = 'date';
            } elseif ($type === 'email') {
                $fieldRules[] = 'email';
            } else {
                $fieldRules[] = 'string';
                $fieldRules[] = $type === 'textarea' ? 'max:2000' : 'max:191';
            }

            $rules['fields.' . $key] = $fieldRules;
        }

        $rules['fields.tipo_documento'] = ['required', 'string', 'max:40'];
        $rules['fields.numero_documento'] = ['required', 'string', 'max:40'];
        $rules['fields.estado_civil_otro'] = ['required_if:fields.estado_civil,Otro', 'nullable', 'string', 'max:120'];
        $rules['fields.nacionalidad_otra'] = ['required_if:fields.nacionalidad,Otra', 'nullable', 'string', 'max:120'];
        $rules['fields.pais_nacimiento_otro'] = ['required_if:fields.pais_nacimiento,Otro', 'nullable', 'string', 'max:120'];
        $rules['fields.lugar_nacimiento_extranjero'] = ['required_if:fields.pais_nacimiento,Otro', 'nullable', 'string', 'max:191'];
        $rules['fields.domicilio_pais_otro'] = ['required_if:fields.domicilio_tipo,Extranjero', 'nullable', 'string', 'max:120'];
        $rules['fields.domicilio_direccion'] = ['required_unless:fields.domicilio_tipo,Extranjero', 'nullable', 'string', 'max:2000'];
        $rules['fields.domicilio_extranjero'] = ['required_if:fields.domicilio_tipo,Extranjero', 'nullable', 'string', 'max:2000'];
        $rules['fields.banco_otro'] = ['required_if:fields.banco,Otro', 'nullable', 'string', 'max:120'];
        $rules['fields.numero_cuenta'] = ['required_if:fields.banco,BCP,Interbank', 'nullable', 'string', 'max:60'];
        $rules['fields.cci'] = ['required_if:fields.banco,Otro', 'nullable', 'string', 'max:60'];
        $rules['fields.quinta_otros_empleadores_json'] = ['nullable', 'string', 'max:12000'];
        $rules['fields.quinta_ejercicio_anio'] = ['nullable', 'string', 'max:4'];

        return $rules;
    }

    private function messages(): array
    {
        return [
            'fields.*.required' => 'Completa este campo obligatorio.',
            'familiares.required' => 'Agrega al menos un familiar o contacto de emergencia.',
            'familiares.min' => 'Agrega al menos un familiar o contacto de emergencia.',
            'familiares.*.nombres_apellidos.required' => 'Ingresa nombres y apellidos del familiar.',
            'familiares.*.parentesco.required' => 'Selecciona o escribe el parentesco.',
            'familiares.*.telefono.required' => 'Ingresa un telefono de contacto.',
            'firma_base64.required' => 'La firma digital es obligatoria.',
            'huella.required' => 'La foto de huella es obligatoria.',
            'huella.image' => 'La huella debe ser una imagen nitida.',
            'huella.max' => 'La imagen de huella no debe superar 5 MB.',
            'documentos.*.required' => 'Adjunta este documento obligatorio.',
            'documentos.*.mimes' => 'El documento debe ser PDF, Word o imagen.',
            'documentos.*.max' => 'El documento no debe superar 10 MB.',
            'declaraciones.*.accepted' => 'Debes marcar esta declaracion para enviar la ficha.',
        ];
    }
}
