<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\EppOperacion;
use App\Models\Usuario;
use App\Modules\Epps\Requests\SignEppOperationRequest;
use App\Modules\Epps\Services\EppDeliverySignatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class EppDeliverySignatureController extends WebPageController
{
    public function review(EppOperacion $operation): View|RedirectResponse
    {
        abort_unless($operation->isCatalogWorkflow(), 404);
        $actor = $this->actor();
        Gate::forUser($actor)->authorize($operation->tipo === 'DEVOLUCION' ? 'confirmReturn' : 'confirmDelivery', $operation);
        if ($operation->estado !== 'LISTO_PARA_FIRMA' || $operation->reserva_expires_at?->lte(now())) {
            return redirect('/logistica?tab=entregas')->with('warning', 'Esta revision ya no esta disponible. Consulta el registro o prepara nuevamente.');
        }

        return view('epps.delivery-signature', ['operation' => $operation, 'payload' => $operation->resumen_json]);
    }

    public function confirm(SignEppOperationRequest $request, EppOperacion $operation, EppDeliverySignatureService $service): RedirectResponse
    {
        abort_unless($operation->isCatalogWorkflow(), 404);
        $request->validate(['confirmacion' => ['required', 'accepted']]);
        try {
            $confirmed = $service->confirm($operation, $request->signaturePayload(), $this->actor());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['firma_data_url' => $exception->getMessage()]);
        }
        $response = redirect('/logistica?tab=entregas')->with($confirmed->verificacion_evidencia['valid'] ? 'success' : 'warning', $confirmed->verificacion_evidencia['valid'] ? 'Movimiento registrado con firma del trabajador. La evidencia esta disponible en el Kardex.' : 'Movimiento registrado con alerta de integridad. Revisa la evidencia.');
        if (! empty($confirmed->resumen_json['input']['_epp_add_another']) && $confirmed->verificacion_evidencia['valid']) {
            $response->withInput(['_epp_open_modal' => 'eppDeliveryModal', 'personal_id' => $confirmed->personal_id, 'personal_label' => $confirmed->resumen_json['worker']['name'], 'fecha_entrega' => $confirmed->resumen_json['date']]);
        }

        return $response;
    }

    private function actor(): Usuario
    {
        return Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
    }
}
