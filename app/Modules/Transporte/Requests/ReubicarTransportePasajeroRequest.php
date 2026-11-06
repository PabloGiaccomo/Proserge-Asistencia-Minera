<?php

namespace App\Modules\Transporte\Requests;

use App\Models\TransporteServicio;
use App\Modules\Transporte\Policies\TransportePolicy;
use Illuminate\Support\Str;

class ReubicarTransportePasajeroRequest extends TransporteFormRequest
{
    protected const ACTION = 'retirar_pasajero';
    protected const FORBIDDEN_CODE = 'TRANSPORTE_PASSENGER_RETIRE_FORBIDDEN';

    public function authorize(TransportePolicy $policy): bool
    {
        if (!parent::authorize($policy)) {
            return false;
        }
        if (!$policy->grants($this->user(), 'agregar_pasajeros')) {
            $this->denialCode = 'TRANSPORTE_PASSENGER_FORBIDDEN';
            return false;
        }

        $destinationId = $this->input('destino_servicio_id');
        if (is_string($destinationId) && Str::isUuid($destinationId)) {
            $destination = TransporteServicio::query()->find($destinationId);
            if ($destination && !$policy->allows($this->user(), 'agregar_pasajeros', $destination)) {
                $this->denialCode = 'TRANSPORTE_PASSENGER_FORBIDDEN';
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'destino_servicio_id' => ['required', 'string', 'size:36', 'exists:transporte_servicios,id'],
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }
}
