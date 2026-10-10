<?php

namespace App\Modules\Personal\Requests;

use App\Models\ExamenMinero;
use Illuminate\Validation\Rule;

class UpdateExamOperationalCategoryRequest extends MiningEmoFormRequest
{
    protected function requiredPermission(): string
    {
        return 'configurar';
    }

    public function rules(): array
    {
        return [
            'categoria_operativa' => ['required', Rule::in([ExamenMinero::CATEGORIA_CURSO, ExamenMinero::CATEGORIA_EMO, ExamenMinero::CATEGORIA_OTRO])],
            'motivo' => ['required', 'string', 'max:5000'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        $this->session()->flash('habilitacion_mina_emo_modal', 'category');
        $this->session()->flash('habilitacion_mina_emo_context', $this->route()->parameters());

        return route('personal.habilitacion-minera.index', $this->query());
    }
}
