<?php

namespace App\Modules\Evaluaciones\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use Illuminate\View\View;

class EvaluacionSupervisorPageController extends Controller
{
    public function __invoke(): View
    {
        return view('evaluaciones.supervisor', [
            'items' => SupervisorEvaluationTemplate::ITEMS,
            'weights' => SupervisorEvaluationTemplate::WEIGHTS,
        ]);
    }
}
