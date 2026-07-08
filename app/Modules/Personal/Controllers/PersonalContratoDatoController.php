<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\PersonalPuesto;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Modules\Personal\Services\PersonalContratoDatoService;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalContratoDatoController extends WebPageController
{
    public function __construct(
        private readonly PersonalService $personalService,
        private readonly PersonalContratoDatoService $service,
        private readonly PersonalContratoService $contratoService,
    ) {
    }

    public function edit(string $id): View|RedirectResponse
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        try {
            $contrato = $this->contratoService->assertContractEditable($personal, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.contratos.index', $personal->id)
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se puede editar este contrato.');
        }

        $datos = $this->service->ensureForPersonal($personal, [
            'fecha_inicio_contrato' => $this->dateString($personal->fecha_ingreso),
            'puesto' => $personal->puesto,
        ]);

        return view('personal.contrato-datos.edit', [
            'personal' => $personal,
            'datos' => $datos,
            'contrato' => $contrato,
            'puestoOptions' => $this->puestoOptions(),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        $validated = $request->validate($this->rules());
        try {
            $this->service->update($personal, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.contratos.index', $personal->id)
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo actualizar el contrato.');
        }

        return redirect()
            ->route('personal.index')
            ->with('success', 'Datos de contrato actualizados correctamente.');
    }

    public function signedContract(Request $request, string $id): RedirectResponse
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        $validated = $request->validate([
            'contrato_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ], [
            'contrato_pdf.required' => 'Sube el contrato firmado en PDF.',
            'contrato_pdf.mimes' => 'El contrato firmado debe ser PDF.',
            'contrato_pdf.max' => 'El PDF no debe superar 20 MB.',
        ]);

        try {
            $this->service->uploadSignedContract($personal, $validated['contrato_pdf'], $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.index')
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo subir el contrato firmado.');
        }

        return redirect()
            ->route('personal.index')
            ->with('success', 'Contrato firmado subido correctamente.');
    }

    private function rules(): array
    {
        return [
            'fecha_inicio_contrato' => ['nullable', 'date'],
            'fecha_fin_contrato' => ['nullable', 'date', 'after_or_equal:fecha_inicio_contrato'],
            'periodo_prueba_inicio' => ['nullable', 'date'],
            'periodo_prueba_fin' => ['nullable', 'date', 'after_or_equal:periodo_prueba_inicio'],
            'sueldo_hora_paradas' => ['nullable', 'string', 'max:80'],
            'sueldo_hora_paradas_texto' => ['nullable', 'string', 'max:191'],
            'sueldo_dia_taller' => ['nullable', 'string', 'max:80'],
            'sueldo_dia_taller_texto' => ['nullable', 'string', 'max:191'],
            'funciones' => ['nullable', 'string', 'max:5000'],
            'sueldo_num' => ['nullable', 'string', 'max:80'],
            'sueldo_texto' => ['nullable', 'string', 'max:191'],
            'puesto' => ['nullable', 'string', 'max:191', Rule::exists('personal_puestos', 'nombre')],
            'fecha_firma' => ['nullable', 'date'],
        ];
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

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value)->toDateString();
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
