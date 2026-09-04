<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PersonalRegularizationDashboardService
{
    public function __construct(
        private readonly PersonalFichaService $fichaService,
    ) {
    }

    public function dashboard(array $filters = [], int $perPage = 25): array
    {
        $workers = Personal::query()
            ->with([
                'fichaColaborador.archivos',
                'fichaColaborador.documentoEstados',
                'fichaColaborador.familiares',
                'contratosLaborales',
                'contratoDatos',
            ])
            ->orderBy('nombre_completo')
            ->get();

        $pending = $workers
            ->reject(fn (Personal $personal): bool => strtoupper((string) $personal->estado) === 'CESADO')
            ->map(fn (Personal $personal): array => $this->regularizationRow($personal))
            ->filter(fn (array $row): bool => $row['total_issues'] > 0)
            ->values();

        $ceased = $workers
            ->filter(fn (Personal $personal): bool => strtoupper((string) $personal->estado) === 'CESADO')
            ->map(fn (Personal $personal): array => $this->ceasedRow($personal))
            ->values();

        $metrics = [
            'pending_workers' => $pending->count(),
            'missing_fields_workers' => $pending->where('missing_fields_count', '>', 0)->count(),
            'missing_documents_workers' => $pending->where('document_action_count', '>', 0)->count(),
            'pending_contract_workers' => $pending->where('contract_pending', true)->count(),
            'ceased_workers' => $ceased->count(),
        ];

        $section = ($filters['section'] ?? 'pending') === 'ceased' ? 'ceased' : 'pending';
        $rows = $section === 'ceased' ? $ceased : $pending;
        $rows = $this->filterRows($rows, $filters, $section);

        return [
            'section' => $section,
            'metrics' => $metrics,
            'rows' => $this->paginate($rows, $perPage, (int) ($filters['page'] ?? 1)),
            'updated_at' => now(),
        ];
    }

    private function regularizationRow(Personal $personal): array
    {
        $ficha = $personal->fichaColaborador;
        $fieldCatalog = PersonalFichaCatalog::fields();
        $missingFieldKeys = $this->fichaService->missingRequiredFieldKeys($ficha);
        $missingFields = $ficha
            ? collect($missingFieldKeys)->map(fn (string $key): array => [
                'key' => $key,
                'label' => $fieldCatalog[$key]['label'] ?? Str::headline($key),
                'section' => $fieldCatalog[$key]['section'] ?? 'Ficha personal',
            ])->values()->all()
            : [[
                'key' => 'ficha_no_registrada',
                'label' => 'Ficha de datos personales no registrada',
                'section' => 'Ficha personal',
            ]];

        $documents = collect($this->fichaService->documentMatrix($ficha))
            ->filter(fn (array $document): bool => (bool) ($document['applies'] ?? true))
            ->filter(fn (array $document): bool => (bool) ($document['required'] ?? false))
            ->filter(fn (array $document): bool => ($document['missing_file'] ?? false)
                || ($document['observed'] ?? false)
                || ($document['pending_review'] ?? false))
            ->map(function (array $document): array {
                $status = ($document['observed'] ?? false)
                    ? 'observed'
                    : (($document['pending_review'] ?? false) ? 'review' : 'missing');

                return [
                    'key' => $document['key'],
                    'label' => $document['label'],
                    'status' => $status,
                    'status_label' => match ($status) {
                        'observed' => 'Observado',
                        'review' => 'Por revisar',
                        default => 'Falta cargar',
                    },
                ];
            })
            ->values();

        $contractPending = $this->needsSignedCurrentContract($personal);
        $missingFieldIssueCount = count($missingFields);

        return [
            'id' => $personal->id,
            'name' => $personal->nombre_completo,
            'document' => $personal->numero_documento ?: $personal->dni,
            'document_type' => $personal->tipo_documento ?: 'DNI',
            'position' => $personal->puesto ?: $personal->ocupacion ?: 'Puesto no registrado',
            'state' => strtoupper((string) $personal->estado),
            'missing_fields' => $missingFields,
            'missing_fields_count' => $missingFieldIssueCount,
            'documents' => $documents->all(),
            'missing_documents_count' => $documents->where('status', 'missing')->count(),
            'observed_documents_count' => $documents->where('status', 'observed')->count(),
            'pending_review_documents_count' => $documents->where('status', 'review')->count(),
            'document_action_count' => $documents->count(),
            'contract_pending' => $contractPending,
            'manual_regularization_pending' => (bool) $personal->pendiente_regularizacion,
            'total_issues' => $missingFieldIssueCount + $documents->count() + ($contractPending ? 1 : 0),
        ];
    }

    private function ceasedRow(Personal $personal): array
    {
        $contracts = $personal->contratosLaborales;
        $preparation = $contracts
            ->first(fn (PersonalContrato $contract): bool => strtoupper((string) $contract->estado) === PersonalContrato::ESTADO_PREPARACION);
        $latest = $contracts
            ->sortByDesc(fn (PersonalContrato $contract): string => ($contract->fecha_fin?->format('Y-m-d') ?? '')
                . '-' . str_pad((string) ($contract->contrato_numero ?? 0), 8, '0', STR_PAD_LEFT))
            ->first();

        return [
            'id' => $personal->id,
            'name' => $personal->nombre_completo,
            'document' => $personal->numero_documento ?: $personal->dni,
            'document_type' => $personal->tipo_documento ?: 'DNI',
            'position' => $personal->puesto ?: $personal->ocupacion ?: 'Puesto no registrado',
            'ceased_at' => $personal->fecha_cese?->format('d/m/Y') ?: $personal->cesado_at?->format('d/m/Y'),
            'cessation_reason' => $personal->motivo_cese,
            'latest_contract_end' => $latest?->fecha_fin?->format('d/m/Y'),
            'has_preparation' => $preparation !== null,
            'preparation_number' => $preparation?->contrato_numero,
        ];
    }

    private function needsSignedCurrentContract(Personal $personal): bool
    {
        $state = strtoupper((string) $personal->estado);
        if (!in_array($state, ['ACTIVO', 'FALTA_CONTRATO'], true) && !$personal->pendiente_contrato_firmado) {
            return false;
        }

        $today = now()->startOfDay();
        $hasCurrentSignedContract = $personal->contratosLaborales->contains(function (PersonalContrato $contract) use ($today): bool {
            return strtoupper((string) $contract->estado) === PersonalContrato::ESTADO_ACTIVO
                && $contract->hasSignedFile()
                && (!$contract->fecha_inicio || $contract->fecha_inicio->startOfDay()->lte($today))
                && (!$contract->fecha_fin || $contract->fecha_fin->endOfDay()->gte($today));
        });

        $legacy = $personal->contratoDatos;
        $hasCurrentLegacySignedContract = $legacy
            && $legacy->signed_at
            && filled($legacy->signed_contract_path)
            && (!$legacy->fecha_inicio_contrato || $legacy->fecha_inicio_contrato->startOfDay()->lte($today))
            && (!$legacy->fecha_fin_contrato || $legacy->fecha_fin_contrato->endOfDay()->gte($today));

        return !$hasCurrentSignedContract && !$hasCurrentLegacySignedContract;
    }

    private function filterRows(Collection $rows, array $filters, string $section): Collection
    {
        $query = Str::lower(trim((string) ($filters['q'] ?? '')));
        if ($query !== '') {
            $rows = $rows->filter(function (array $row) use ($query): bool {
                $haystack = Str::lower(implode(' ', [
                    $row['name'] ?? '',
                    $row['document'] ?? '',
                    $row['position'] ?? '',
                ]));

                return str_contains($haystack, $query);
            });
        }

        if ($section === 'pending') {
            $type = (string) ($filters['type'] ?? 'all');
            $rows = match ($type) {
                'fields' => $rows->where('missing_fields_count', '>', 0),
                'documents' => $rows->where('document_action_count', '>', 0),
                'contracts' => $rows->where('contract_pending', true),
                default => $rows,
            };
        }

        return $rows->values();
    }

    private function paginate(Collection $rows, int $perPage, int $page): LengthAwarePaginator
    {
        $page = max(1, $page);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => Arr::except(request()->query(), ['fragment']),
            ],
        );
    }
}
