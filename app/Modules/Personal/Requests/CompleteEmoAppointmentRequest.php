<?php

namespace App\Modules\Personal\Requests;

use App\Modules\Personal\Support\CompleteEmoData;
use App\Modules\Personal\Support\EmoOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class CompleteEmoAppointmentRequest extends MiningEmoFormRequest
{
    protected function requiredPermission(): string
    {
        return 'registrar';
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'fecha_realizacion' => ['required', 'date_format:Y-m-d'],
            'resultado_emo' => ['required', Rule::enum(EmoOutcome::class)],
            'detalle_resultado_emo' => [Rule::requiredIf(fn () => in_array($this->input('resultado_emo'), ['APTO_CON_RESTRICCION', 'APTO_CON_OBSERVACION'], true)), 'nullable', 'string', 'max:5000'],
            'nota' => ['nullable', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/D'],
            'fecha_vencimiento' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_realizacion'],
            'observacion' => ['nullable', 'string', 'max:5000'],
            'archivo' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function toData(): CompleteEmoData
    {
        $data = $this->validated();
        $timezone = config('app.display_timezone', 'America/Lima');

        return new CompleteEmoData(
            CarbonImmutable::createFromFormat('!Y-m-d', $data['fecha_realizacion'], $timezone),
            EmoOutcome::from($data['resultado_emo']), $data['detalle_resultado_emo'] ?? null,
            isset($data['nota']) ? (string) $data['nota'] : null,
            isset($data['fecha_vencimiento']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['fecha_vencimiento'], $timezone) : null,
            $data['observacion'] ?? null, $this->file('archivo'),
        );
    }

    protected function getRedirectUrl(): string
    {
        $this->session()->flash('habilitacion_mina_emo_modal', 'complete');
        $this->session()->flash('habilitacion_mina_emo_context', $this->route()->parameters());

        return route('personal.habilitacion-minera.index', $this->query());
    }
}
