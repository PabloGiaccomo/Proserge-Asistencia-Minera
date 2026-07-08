<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PersonalPuesto;
use App\Modules\Personal\Services\PersonalIngresoService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicPersonalIngresoController extends Controller
{
    public function __construct(private readonly PersonalIngresoService $ingresos)
    {
    }

    public function show(Request $request): View
    {
        $authorized = $this->isDailyKeyAuthorized($request);

        return view('ficha-public.ingreso', [
            'authorized' => $authorized,
            'data' => PersonalFichaCatalog::emptyData(),
            'sections' => PersonalFichaCatalog::sections(),
            'familiares' => $this->ingresos->familyRowsForForm(),
            'documentRequirements' => PersonalFichaCatalog::documentRequirements(),
            'declarationCheckboxes' => PersonalFichaCatalog::declarationCheckboxes(),
            'puestoOptions' => $this->puestoOptions(),
        ]);
    }

    public function verifyKey(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'clave' => ['required', 'string', 'max:20'],
        ], [
            'clave.required' => 'Ingresa la clave diaria que te envio RRHH.',
        ]);

        if (!$this->ingresos->verifyDailyKey($validated['clave'])) {
            return redirect()
                ->route('personal.ingresos.public.show')
                ->with('error', 'La clave diaria no es correcta. Solicitala nuevamente a RRHH.');
        }

        $request->session()->put('personal_ingreso_public_key_date', now()->toDateString());

        return redirect()
            ->route('personal.ingresos.public.show')
            ->with('success', 'Clave validada. Ya puedes completar la ficha.');
    }

    public function submit(Request $request): RedirectResponse
    {
        if (!$this->isDailyKeyAuthorized($request)) {
            return redirect()
                ->route('personal.ingresos.public.show')
                ->with('error', 'Primero valida la clave diaria para completar la ficha.');
        }

        $request->merge([
            'familiares' => $this->sanitizeFamiliares($request->input('familiares', [])),
        ]);

        $validated = $request->validate($this->rules(), $this->messages());
        $fields = $validated['fields'];
        $fields['declaraciones_json'] = json_encode(array_keys($validated['declaraciones'] ?? []));

        try {
            $this->ingresos->storeSubmission(
                $fields,
                $validated['familiares'] ?? [],
                $validated['firma_base64'],
                $request->file('huella'),
                $request->file('documentos', []),
                $validated['submission_uuid'] ?? null,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('No se pudo guardar ficha publica de ingreso.', [
                'submission_uuid' => $validated['submission_uuid'] ?? null,
                'documento' => $fields['numero_documento'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return back()
                ->withInput()
                ->withErrors([
                    'form' => 'No pudimos guardar tu ficha por un problema temporal. Revisa tu conexion e intenta enviarla nuevamente.',
                ]);
        }

        $request->session()->forget('personal_ingreso_public_key_date');

        return redirect()
            ->route('personal.ingresos.public.show')
            ->with('success', 'Ficha enviada correctamente. RRHH revisara tu informacion y se comunicara contigo si hace falta corregir algo.');
    }

    private function isDailyKeyAuthorized(Request $request): bool
    {
        return $request->session()->get('personal_ingreso_public_key_date') === now()->toDateString();
    }

    private function rules(): array
    {
        $rules = [
            'fields' => ['required', 'array'],
            'submission_uuid' => ['nullable', 'string', 'max:64'],
            'familiares' => ['nullable', 'array'],
            'familiares.*.nombres_apellidos' => ['nullable', 'string', 'max:191'],
            'familiares.*.parentesco' => ['nullable', 'string', 'max:80'],
            'familiares.*.fecha_nacimiento' => ['nullable', 'date'],
            'familiares.*.tipo_documento' => ['nullable', 'string', 'max:40'],
            'familiares.*.numero_documento' => ['nullable', 'string', 'max:40'],
            'familiares.*.telefono' => ['nullable', 'string', 'max:30'],
            'familiares.*.vive_con_trabajador' => ['nullable'],
            'familiares.*.estudia' => ['nullable'],
            'familiares.*.contacto_emergencia' => ['nullable'],
            'firma_base64' => ['required', 'string', 'max:2500000'],
            'huella' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'documentos' => ['nullable', 'array'],
            'declaraciones' => ['required', 'array'],
        ];

        foreach (PersonalFichaCatalog::documentRequirements() as $key => $requirement) {
            $rules['documentos.' . $key] = [
                'nullable',
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
            } elseif ($type === 'hidden') {
                $fieldRules[] = 'nullable';
                $fieldRules[] = 'string';
                $fieldRules[] = 'max:12000';
            } else {
                $fieldRules[] = 'string';
                $fieldRules[] = $type === 'textarea' ? 'max:2000' : 'max:191';
            }

            $rules['fields.' . $key] = $fieldRules;
        }

        $rules['fields.tipo_documento'] = ['required', 'string', 'max:40'];
        $rules['fields.numero_documento'] = ['required', 'string', 'max:40'];
        $rules['fields.puesto'] = ['required', 'string', 'max:191', Rule::exists('personal_puestos', 'nombre')];
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
            'fields.puesto.exists' => 'Selecciona un cargo o puesto de la lista.',
            'fields.numero_documento.required' => 'Ingresa tu numero de documento.',
            'firma_base64.required' => 'Firma dentro del recuadro antes de enviar.',
            'huella.required' => 'Adjunta una foto clara de tu huella en papel.',
            'huella.image' => 'La huella debe ser una imagen nitida.',
            'huella.max' => 'La imagen de huella no debe superar 5 MB.',
            'documentos.*.mimes' => 'El documento debe ser PDF, Word o imagen.',
            'documentos.*.max' => 'El documento no debe superar 10 MB.',
            'declaraciones.*.accepted' => 'Debes marcar esta declaracion para enviar la ficha.',
        ];
    }

    private function sanitizeFamiliares(mixed $familiares): array
    {
        if (!is_array($familiares)) {
            return [];
        }

        return collect($familiares)
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): array {
                return [
                    'nombres_apellidos' => trim((string) ($item['nombres_apellidos'] ?? '')),
                    'parentesco' => trim((string) ($item['parentesco'] ?? '')),
                    'fecha_nacimiento' => trim((string) ($item['fecha_nacimiento'] ?? '')),
                    'tipo_documento' => trim((string) ($item['tipo_documento'] ?? 'DNI')),
                    'numero_documento' => trim((string) ($item['numero_documento'] ?? '')),
                    'telefono' => trim((string) ($item['telefono'] ?? '')),
                    'vive_con_trabajador' => !empty($item['vive_con_trabajador']),
                    'estudia' => !empty($item['estudia']),
                    'contacto_emergencia' => !empty($item['contacto_emergencia']),
                ];
            })
            ->filter(function (array $item): bool {
                return $item['nombres_apellidos'] !== '' && $item['fecha_nacimiento'] !== '';
            })
            ->values()
            ->all();
    }

    private function puestoOptions(): array
    {
        if (!Schema::hasTable('personal_puestos')) {
            return [];
        }

        return PersonalPuesto::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->pluck('nombre')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->values()
            ->all();
    }
}
