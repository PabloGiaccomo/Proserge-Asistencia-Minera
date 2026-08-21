<?php

namespace App\Modules\Evaluaciones\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class EvaluacionSupervisorPageController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('evaluaciones.index', ['tipo' => 'supervisores']);
    }
}
