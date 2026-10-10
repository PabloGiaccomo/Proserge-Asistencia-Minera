<?php

namespace App\Modules\Personal\Requests;

class StoreClinicaEmoRequest extends MiningEmoFormRequest
{
    protected function requiredPermission(): string
    {
        return 'configurar';
    }

    public function rules(): array
    {
        return ['nombre' => ['required', 'string', 'max:191']];
    }

    protected function getRedirectUrl(): string
    {
        $this->session()->flash('habilitacion_mina_emo_modal', 'clinics');

        return route('personal.habilitacion-minera.index', $this->query());
    }
}
