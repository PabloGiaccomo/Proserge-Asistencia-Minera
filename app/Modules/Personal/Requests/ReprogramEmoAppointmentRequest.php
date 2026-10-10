<?php

namespace App\Modules\Personal\Requests;

use App\Modules\Personal\Support\EmoOrigin;
use App\Modules\Personal\Support\ReprogramEmoData;
use Carbon\CarbonImmutable;

class ReprogramEmoAppointmentRequest extends MiningEmoFormRequest
{
    protected function requiredPermission(): string
    {
        return 'programar';
    }

    public function rules(): array
    {
        $rules = ['revision' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'max:5000']];
        if (! $this->routeIs('personal.habilitacion-minera.emo.cancel')) {
            $rules += ['fecha_programacion' => ['required', 'date_format:Y-m-d'], 'clinica_id' => ['required', 'uuid']];
        }

        return $rules;
    }

    public function toData(): ReprogramEmoData
    {
        $data = $this->validated();

        return new ReprogramEmoData(
            CarbonImmutable::createFromFormat('!Y-m-d', $data['fecha_programacion'], config('app.display_timezone', 'America/Lima')),
            $data['clinica_id'], $data['motivo'], EmoOrigin::MANUAL,
        );
    }

    protected function getRedirectUrl(): string
    {
        $this->session()->flash('habilitacion_mina_emo_modal', $this->routeIs('personal.habilitacion-minera.emo.cancel') ? 'cancel' : 'reprogram');
        $this->session()->flash('habilitacion_mina_emo_context', $this->route()->parameters());

        return route('personal.habilitacion-minera.index', $this->query());
    }
}
