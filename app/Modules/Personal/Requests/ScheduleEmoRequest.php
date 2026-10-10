<?php

namespace App\Modules\Personal\Requests;

use App\Modules\Personal\Support\ScheduleEmoData;
use Carbon\CarbonImmutable;

class ScheduleEmoRequest extends MiningEmoFormRequest
{
    protected function requiredPermission(): string
    {
        return 'programar';
    }

    public function rules(): array
    {
        $rules = ['clinica_id' => ['required', 'uuid']];
        if (! $this->routeIs('personal.habilitacion-minera.emo.regularize')) {
            $rules += [
                'fecha_programacion' => ['required', 'date_format:Y-m-d'],
                'observacion' => ['nullable', 'string', 'max:5000'],
            ];
        }

        return $rules;
    }

    public function toData(): ScheduleEmoData
    {
        $data = $this->validated();

        return new ScheduleEmoData(
            CarbonImmutable::createFromFormat('!Y-m-d', $data['fecha_programacion'], config('app.display_timezone', 'America/Lima')),
            $data['clinica_id'], $data['observacion'] ?? null,
        );
    }

    protected function getRedirectUrl(): string
    {
        $this->session()->flash('habilitacion_mina_emo_modal', $this->routeIs('personal.habilitacion-minera.emo.regularize') ? 'regularize' : 'schedule');
        $this->session()->flash('habilitacion_mina_emo_context', $this->route()->parameters());

        return route('personal.habilitacion-minera.index', $this->query());
    }
}
