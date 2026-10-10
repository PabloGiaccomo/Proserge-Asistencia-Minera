<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Personal\Requests\PersonalMinaScheduledControlRequest;
use App\Modules\Personal\Services\PersonalMinaScheduledControlExcelService;
use App\Modules\Personal\Support\ScheduledControlFilters;
use App\Support\Rbac\PermissionMatrix;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonalMinaScheduledControlExportController extends WebPageController
{
    public function __construct(private readonly PersonalMinaScheduledControlExcelService $excel) {}

    public function __invoke(Request $request): StreamedResponse
    {
        foreach (['ver_programados', 'exportar'] as $action) {
            abort_unless(PermissionMatrix::allowsDirect(session('user.permissions', []), 'habilitacion_minera', $action),
                403, 'No tiene permiso para exportar el control de asistencia.');
        }
        $actor = $this->requireAuthenticatedUser();
        // Resolve the shared Form Request only after both permissions have been checked.
        $validatedRequest = PersonalMinaScheduledControlRequest::createFrom($request);
        $validatedRequest->setContainer(app())->setRedirector(app('redirect'));
        $validatedRequest->validateResolved();
        $filters = ScheduledControlFilters::fromValidated($validatedRequest->validated(), CarbonImmutable::now());

        return $this->excel->download($filters, $actor);
    }
}
