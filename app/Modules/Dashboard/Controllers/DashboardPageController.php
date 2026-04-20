<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardPageController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard.principal');
    }
}
