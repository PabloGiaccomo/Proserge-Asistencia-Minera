<?php

namespace App\Modules\Logistica\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Epps\Services\EppService;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LogisticaPageController extends WebPageController
{
    public function __construct(
        private readonly LogisticaDashboardService $service,
        private readonly EppService $eppService
    )
    {
    }

    public function index(Request $request): View
    {
        $this->requireAuthenticatedUser();

        $data = $this->service->pageData($request->query());
        $data['eppModule'] = $this->eppService->pageData([
            'q' => $request->query('q'),
            'estado' => $request->query('estado'),
            'per_page' => $request->query('per_page'),
        ]);

        return view('logistica.index', $data);
    }
}
