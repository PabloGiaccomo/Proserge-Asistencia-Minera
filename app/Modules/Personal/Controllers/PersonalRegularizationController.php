<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Personal\Services\PersonalRegularizationDashboardService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PersonalRegularizationController extends WebPageController
{
    public function __construct(
        private readonly PersonalRegularizationDashboardService $dashboardService,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'section' => ['nullable', 'in:pending,ceased'],
            'type' => ['nullable', 'in:all,fields,documents,contracts'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
            'fragment' => ['nullable', 'boolean'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $dashboard = $this->dashboardService->dashboard($filters, $perPage);
        $permissions = session('user.permissions', []);
        $access = [
            'can_edit' => PermissionMatrix::allowsDirectAny($permissions, 'personal', ['editar', 'actualizar', 'editar_ficha']),
            'can_view_documents' => PermissionMatrix::allowsDirect($permissions, 'personal', 'ver_documentos')
                || PermissionMatrix::allowsDirect($permissions, 'personal_documentos', 'ver'),
            'can_view_contracts' => PermissionMatrix::allowsDirect($permissions, 'personal', 'ver_contratos')
                || PermissionMatrix::allowsDirect($permissions, 'personal_contratos', 'ver'),
        ];

        if ($request->boolean('fragment')) {
            return response()->json([
                'html' => view('personal.regularizacion._results', compact('dashboard', 'access'))->render(),
                'updated_at' => $dashboard['updated_at']->format('H:i:s'),
            ])->header('Cache-Control', 'no-store, private');
        }

        return response()
            ->view('personal.regularizacion.index', compact('dashboard', 'access'))
            ->header('Cache-Control', 'no-store, private');
    }
}
