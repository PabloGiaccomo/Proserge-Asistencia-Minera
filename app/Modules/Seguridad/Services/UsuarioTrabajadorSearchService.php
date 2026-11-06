<?php

namespace App\Modules\Seguridad\Services;

use App\Models\Personal;
use App\Modules\Seguridad\Support\UsuarioEmailSuggestion;
use App\Shared\Support\SearchTerms;
use Illuminate\Database\Eloquent\Builder;

class UsuarioTrabajadorSearchService
{
    private const LIMIT = 20;

    public function search(string $search, bool $includeEmail = true): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return [];
        }

        $query = $this->workerQuery($includeEmail);
        SearchTerms::apply($query, $search, ['nombre_completo', 'dni', 'numero_documento', 'puesto']);

        return $query->orderBy('nombre_completo')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Personal $worker): array => $this->row($worker, $includeEmail, $search))
            ->all();
    }

    public function rowsForIds(array $ids, bool $includeEmail = true): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $workers = $this->workerQuery($includeEmail)->whereIn('id', $ids)->get()->keyBy('id');
        $rows = [];
        foreach ($ids as $id) {
            if ($worker = $workers->get($id)) {
                $rows[] = $this->row($worker, $includeEmail);
            }
        }

        return $rows;
    }

    private function workerQuery(bool $includeEmail): Builder
    {
        $query = Personal::query()
            ->select(['id', 'nombre_completo', 'dni', 'puesto'])
            ->withExists('usuario');

        if ($includeEmail) {
            $query->addSelect('correo')
                ->with('fichaColaborador:personal_fichas.id,personal_fichas.personal_id,personal_fichas.datos_json');
        }

        return $query;
    }

    private function row(Personal $worker, bool $includeEmail, string $search = ''): array
    {
        return [
            'id' => $worker->id,
            'nombre' => $worker->nombre_completo,
            'dni' => $worker->dni,
            'puesto' => $worker->puesto,
            'correo_sugerido' => $includeEmail && ! $worker->usuario_exists ? $this->suggestedEmail($worker) : null,
            'tiene_usuario' => (bool) $worker->usuario_exists,
            'seleccionar_url' => route('usuarios.create', array_filter([
                'trabajador_id' => $worker->id,
                'search' => $search,
            ], fn (string $value): bool => $value !== '')),
        ];
    }

    private function suggestedEmail(Personal $worker): ?string
    {
        $suggested = UsuarioEmailSuggestion::fromFicha($worker->fichaColaborador?->datos_json ?? []);
        $fallback = mb_strtolower(trim((string) $worker->correo));

        return $suggested ?? (filter_var($fallback, FILTER_VALIDATE_EMAIL) ? $fallback : null);
    }
}
