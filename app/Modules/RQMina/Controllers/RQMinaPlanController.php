<?php

namespace App\Modules\RQMina\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\RQMinaPlan;
use App\Modules\RQMina\Requests\DuplicateRQMinaPlanRequest;
use App\Modules\RQMina\Requests\StoreRQMinaPlanRequest;
use App\Modules\RQMina\Requests\UpdateRQMinaPlanRequest;
use App\Modules\RQMina\Services\RQMinaPlanService;
use App\Modules\RQMina\Services\RQMinaService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class RQMinaPlanController extends WebPageController
{
    public function __construct(
        private readonly RQMinaService $rqMinaService,
        private readonly RQMinaPlanService $planService,
    ) {
    }

    public function store(StoreRQMinaPlanRequest $request, string $rqMinaId): JsonResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->rqMinaService->findForUser($usuario, $rqMinaId);

        if (!$rqMina) {
            return $this->jsonError('RQ Mina no encontrado o sin acceso.', 404);
        }

        if (!$this->rqMinaService->canUpdate($usuario, $rqMina)) {
            return $this->jsonError('No tienes permiso para crear planes en esta parada.', 403);
        }

        try {
            $plan = $this->planService->createPlan($rqMina, $request->validated(), $usuario);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Plan creado correctamente.',
            'data' => [
                'plan' => $this->planService->planToArray($plan),
                'redirect_url' => route('rq-mina.plan', ['id' => $rqMina->id, 'plan_id' => $plan->id]),
            ],
        ], 201);
    }

    public function update(UpdateRQMinaPlanRequest $request, string $rqMinaId, string $planId): JsonResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->rqMinaService->findForUser($usuario, $rqMinaId);

        if (!$rqMina) {
            return $this->jsonError('RQ Mina no encontrado o sin acceso.', 404);
        }

        if (!$this->rqMinaService->canUpdate($usuario, $rqMina)) {
            return $this->jsonError('No tienes permiso para editar planes en esta parada.', 403);
        }

        $plan = $this->findPlan($rqMinaId, $planId);
        if (!$plan) {
            return $this->jsonError('El plan operativo seleccionado no pertenece a esta parada.', 404);
        }

        try {
            $updated = $this->planService->updatePlan($rqMina, $plan, $request->validated(), $usuario);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Plan actualizado correctamente.',
            'data' => [
                'plan' => $this->planService->planToArray($updated),
                'redirect_url' => route('rq-mina.plan', ['id' => $rqMina->id, 'plan_id' => $updated->id]),
            ],
        ]);
    }

    public function duplicate(DuplicateRQMinaPlanRequest $request, string $rqMinaId, string $planId): JsonResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->rqMinaService->findForUser($usuario, $rqMinaId);

        if (!$rqMina) {
            return $this->jsonError('RQ Mina no encontrado o sin acceso.', 404);
        }

        if (!$this->rqMinaService->canUpdate($usuario, $rqMina)) {
            return $this->jsonError('No tienes permiso para duplicar planes en esta parada.', 403);
        }

        $plan = $this->findPlan($rqMinaId, $planId);
        if (!$plan) {
            return $this->jsonError('El plan operativo seleccionado no pertenece a esta parada.', 404);
        }

        try {
            $newPlan = $this->planService->duplicatePlan($rqMina, $plan, $request->validated(), $usuario);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Plan duplicado correctamente.',
            'data' => [
                'plan' => $this->planService->planToArray($newPlan),
                'redirect_url' => route('rq-mina.plan', ['id' => $rqMina->id, 'plan_id' => $newPlan->id]),
            ],
        ], 201);
    }

    public function archive(string $rqMinaId, string $planId): JsonResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->rqMinaService->findForUser($usuario, $rqMinaId);

        if (!$rqMina) {
            return $this->jsonError('RQ Mina no encontrado o sin acceso.', 404);
        }

        if (!$this->rqMinaService->canUpdate($usuario, $rqMina)) {
            return $this->jsonError('No tienes permiso para archivar planes en esta parada.', 403);
        }

        $plan = $this->findPlan($rqMinaId, $planId);
        if (!$plan) {
            return $this->jsonError('El plan operativo seleccionado no pertenece a esta parada.', 404);
        }

        try {
            $archived = $this->planService->archivePlan($rqMina, $plan, $usuario);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        $nextPlanId = $this->planService->firstEditablePlanId($rqMina, (string) $archived->id) ?: $archived->id;

        return response()->json([
            'ok' => true,
            'message' => 'Plan archivado correctamente.',
            'data' => [
                'plan' => $this->planService->planToArray($archived),
                'redirect_url' => route('rq-mina.plan', ['id' => $rqMina->id, 'plan_id' => $nextPlanId]),
            ],
        ]);
    }

    private function findPlan(string $rqMinaId, string $planId): ?RQMinaPlan
    {
        return RQMinaPlan::query()
            ->whereKey($planId)
            ->where('rq_mina_id', $rqMinaId)
            ->first();
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
        ], $status);
    }
}
