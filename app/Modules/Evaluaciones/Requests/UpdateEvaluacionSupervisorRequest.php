<?php

namespace App\Modules\Evaluaciones\Requests;

use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEvaluacionSupervisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...SupervisorEvaluationTemplate::responseRules(),
            'comentarios_finales' => ['nullable', 'string'],
            'aspectos_positivos' => ['nullable', 'string'],
            'capacitaciones_recomendadas' => ['nullable', 'string'],
            'firma' => ['nullable', 'string'],
            'estado' => ['nullable', 'string', 'in:REGISTRADA,REVISADA,CERRADA'],
        ];
    }

    public function messages(): array
    {
        return SupervisorEvaluationTemplate::responseMessages();
    }
}
