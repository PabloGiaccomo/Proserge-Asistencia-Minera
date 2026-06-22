<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\PersonalPuesto;
use App\Modules\Personal\Services\PersonalIngresoService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalIngresoController extends WebPageController
{
    public function __construct(private readonly PersonalIngresoService $ingresos)
    {
    }

    public function index(Request $request): View
    {
        $filters = [
            'estado' => $request->query('estado', ''),
            'search' => $request->query('q', ''),
        ];

        $rows = $this->ingresos->list($filters);

        return view('personal.fichas.ingresos', [
            'rows' => $rows,
            'rowsTotal' => $rows->count(),
            'dailyKey' => $this->ingresos->todayKey(),
            'publicUrl' => $this->ingresos->publicUrl(),
            'estadoFilter' => $filters['estado'],
            'search' => $filters['search'],
            'statusLabels' => [
                '' => 'Todos',
                'FICHA_RECIBIDA' => 'Ficha recibida',
                'FALTA_REVISION' => 'Falta revision',
                'ACEPTADA' => 'Agregado a Personal',
                'CONTRATO_NO_FIRMADO' => 'No firmo contrato',
            ],
            'ingresosService' => $this->ingresos,
        ]);
    }

    public function show(string $id): View
    {
        $ingreso = $this->ingresos->findOrFail($id);

        return $this->reviewView($ingreso, false);
    }

    public function edit(string $id): View
    {
        $ingreso = $this->ingresos->findOrFail($id);

        return $this->reviewView($ingreso, true);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $ingreso = $this->ingresos->findOrFail($id);
        $request->merge([
            'familiares' => $this->sanitizeFamiliares($request->input('familiares', [])),
        ]);

        $validated = $request->validate($this->rules(), $this->messages());
        $fields = $validated['fields'];
        $fields['declaraciones_json'] = json_encode(array_keys($validated['declaraciones'] ?? []));

        $this->ingresos->updateIngreso(
            $ingreso,
            $fields,
            $validated['familiares'] ?? [],
            $validated['firma_base64'] ?? null,
            $request->file('huella'),
            $request->file('documentos', []),
            $this->requireAuthenticatedUser(),
        );

        return redirect()
            ->route('personal.ingresos.show', $ingreso->id)
            ->with('success', 'Ficha guardada para revision.');
    }

    public function accept(string $id): RedirectResponse
    {
        $ingreso = $this->ingresos->findOrFail($id);
        $personal = $this->ingresos->accept($ingreso, $this->requireAuthenticatedUser());

        return redirect()
            ->route('personal.edit', $personal->id)
            ->with('success', 'Ficha agregada a Personal. Queda marcada con pendiente de adjuntar contrato firmado.');
    }

    public function contractNotSigned(string $id): RedirectResponse
    {
        $ingreso = $this->ingresos->findOrFail($id);
        $personal = $this->ingresos->markContractNotSigned($ingreso, $this->requireAuthenticatedUser());

        return redirect()
            ->route('personal.edit', $personal->id)
            ->with('success', 'El trabajador quedo guardado como no firmo contrato.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $ingreso = $this->ingresos->findOrFail($id);

        try {
            $this->ingresos->deleteErroneous($ingreso);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.ingresos.show', $id)
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo eliminar la ficha.');
        }

        return redirect()
            ->route('personal.fichas.temporales')
            ->with('success', 'Ficha erronea eliminada por completo de la bandeja.');
    }

    public function downloadArchivo(string $id, string $archivoId)
    {
        $ingreso = $this->ingresos->findOrFail($id);
        $archivo = $ingreso->archivos->firstWhere('id', $archivoId);

        abort_unless($archivo && $archivo->path && Storage::disk('local')->exists($archivo->path), 404);

        return Storage::disk('local')->download($archivo->path, $archivo->nombre_original ?: 'archivo');
    }

    private function reviewView($ingreso, bool $editing): View
    {
        return view('personal.fichas.ingreso-review', [
            'ingreso' => $ingreso,
            'editing' => $editing,
            'data' => $this->ingresos->dataForForm($ingreso),
            'sections' => PersonalFichaCatalog::sections(),
            'familiares' => $this->ingresos->familyRowsForForm($ingreso),
            'documentRequirements' => PersonalFichaCatalog::documentRequirements(),
            'declarationCheckboxes' => PersonalFichaCatalog::declarationCheckboxes(),
            'puestoOptions' => $this->puestoOptions(),
            'ingresosService' => $this->ingresos,
        ]);
    }

    private function rules(): array
    {
        $rules = [
            'fields' => ['required', 'array'],
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
            'firma_base64' => ['nullable', 'string', 'max:2500000'],
            'huella' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'documentos' => ['nullable', 'array'],
            'declaraciones' => ['nullable', 'array'],
        ];

        foreach (PersonalFichaCatalog::documentRequirements() as $key => $requirement) {
            $rules['documentos.' . $key] = ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp'];
        }

        foreach (PersonalFichaCatalog::fields() as $key => $field) {
            $type = $field['type'] ?? 'text';
            $rules['fields.' . $key] = ['nullable', 'string', $type === 'textarea' ? 'max:2000' : 'max:191'];
        }

        $rules['fields.tipo_documento'] = ['required', 'string', 'max:40'];
        $rules['fields.numero_documento'] = ['required', 'string', 'max:40'];
        $rules['fields.nombres'] = ['required', 'string', 'max:191'];
        $rules['fields.apellido_paterno'] = ['required', 'string', 'max:191'];
        $rules['fields.apellido_materno'] = ['required', 'string', 'max:191'];
        $rules['fields.telefono'] = ['required', 'string', 'max:30'];
        $rules['fields.correo'] = ['required', 'email', 'max:191'];
        $rules['fields.puesto'] = ['required', 'string', 'max:191', Rule::exists('personal_puestos', 'nombre')];

        return $rules;
    }

    private function messages(): array
    {
        return [
            'fields.*.required' => 'Completa este campo obligatorio.',
            'fields.puesto.exists' => 'Selecciona un cargo o puesto de la lista.',
            'documentos.*.mimes' => 'El documento debe ser PDF, Word o imagen.',
            'documentos.*.max' => 'El documento no debe superar 10 MB.',
        ];
    }

    private function sanitizeFamiliares(mixed $familiares): array
    {
        if (!is_array($familiares)) {
            return [];
        }

        return collect($familiares)
            ->filter(fn ($item): bool => is_array($item))
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
