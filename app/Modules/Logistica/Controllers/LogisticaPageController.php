<?php

namespace App\Modules\Logistica\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LogisticaPageController extends WebPageController
{
    public function __construct(private readonly LogisticaDashboardService $service)
    {
    }

    public function index(Request $request): View
    {
        $this->requireAuthenticatedUser();

        return view('logistica.index', $this->service->pageData($request->query()));
    }
}
