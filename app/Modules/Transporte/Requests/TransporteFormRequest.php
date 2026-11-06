<?php

namespace App\Modules\Transporte\Requests;

use App\Models\RQMina;
use App\Models\TransporteServicio;
use App\Models\Usuario;
use App\Modules\Transporte\Policies\TransportePolicy;
use App\Modules\Transporte\Support\TransportePlanningNavigation;
use App\Shared\Support\ApiResponse;
use App\Support\Rbac\MinaScopeAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

abstract class TransporteFormRequest extends FormRequest
{
    protected const ACTION = '';
    protected const FORBIDDEN_CODE = '';

    protected ?string $denialCode = null;

    public function authorize(TransportePolicy $policy): bool
    {
        $usuario = $this->user();
        if (!$usuario instanceof Usuario || !$policy->grants($usuario, static::ACTION)) {
            return false;
        }

        $serviceId = $this->route('servicio');
        $service = is_string($serviceId) ? TransporteServicio::query()->find($serviceId) : null;
        if ($service && !$policy->allows($usuario, static::ACTION, $service)) {
            return false;
        }

        $rqId = $this->input('rq_mina_id');
        if (in_array(static::ACTION, ['crear', 'editar'], true) && is_string($rqId) && Str::isUuid($rqId)) {
            $parada = RQMina::query()->find($rqId);
            if ($parada && !MinaScopeAccess::allows($usuario, $parada->mina_id)) {
                $this->denialCode = 'TRANSPORTE_RQ_FORBIDDEN';
                return false;
            }
        }

        return true;
    }

    protected function failedAuthorization(): void
    {
        if ($this->is('api/*')) {
            throw new HttpResponseException(ApiResponse::error('No autorizado', $this->denialCode ?? static::FORBIDDEN_CODE, status: 403));
        }

        parent::failedAuthorization();
    }

    protected function getRedirectUrl(): string
    {
        if ($this->routeIs('transporte.servicios.*')) {
            return TransportePlanningNavigation::url($this, failed: true);
        }

        return parent::getRedirectUrl();
    }
}
