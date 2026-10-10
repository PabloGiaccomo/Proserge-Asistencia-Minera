<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\ExamenMineroCategoriaHistorial;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamOperationalCategoryService
{
    public function update(ExamenMinero $exam, string $category, string $reason, Usuario $actor): ExamenMinero
    {
        if (! in_array($category, [ExamenMinero::CATEGORIA_CURSO, ExamenMinero::CATEGORIA_EMO, ExamenMinero::CATEGORIA_OTRO], true)) {
            throw ValidationException::withMessages(['categoria_operativa' => 'Seleccione Curso, EMO u Otro.']);
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 5000) {
            throw ValidationException::withMessages(['motivo' => 'Indique un motivo de hasta 5000 caracteres.']);
        }

        return DB::transaction(function () use ($exam, $category, $reason, $actor): ExamenMinero {
            $locked = ExamenMinero::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->categoria_operativa;
            if ($previous === $category) {
                return $locked;
            }
            $locked->fill(['categoria_operativa' => $category, 'updated_by_usuario_id' => $actor->id])->save();
            ExamenMineroCategoriaHistorial::create([
                'examen_id' => $locked->id, 'categoria_anterior' => $previous, 'categoria_nueva' => $category,
                'motivo' => $reason, 'usuario_id' => $actor->id, 'fecha_cambio' => now(),
            ]);

            return $locked;
        });
    }
}
