<?php

namespace App\Modules\Personal\Support;

class PersonalExportConfig
{
    /** @var array<int, string> */
    private const VALID_SCOPE = [
        'all',
        'current',
        'active',
        'inactive',
        'supervisors',
        'workers',
        'mine',
        'mine_state',
    ];

    public function __construct(
        public readonly string $scope,
        public readonly ?string $search,
        public readonly ?string $estado,
        public readonly ?string $tipo,
        public readonly ?string $mina,
        public readonly ?string $minaEstado,
        public readonly ?string $contrato,
        public readonly ?string $sort,
        public readonly string $order,
        public readonly ?int $limit,
        /** @var array<int, string> */
        public readonly array $columns,
    ) {
    }

    public static function fromInput(array $input, array $allowedColumns, bool $useRecommendedWhenEmpty = true): self
    {
        $scope = strtolower(trim((string) ($input['scope'] ?? 'current')));
        if (!in_array($scope, self::VALID_SCOPE, true)) {
            $scope = 'current';
        }

        $rawColumns = $input['columns'] ?? [];
        if (!is_array($rawColumns)) {
            $rawColumns = [];
        }

        $columns = array_values(array_filter(array_unique(array_map(
            static fn ($value) => trim((string) $value),
            $rawColumns
        )), static fn (string $value) => in_array($value, $allowedColumns, true)));

        if ($useRecommendedWhenEmpty && count($columns) === 0) {
            $columns = self::recommendedColumns($allowedColumns);
        }

        $limit = self::resolveLimit($input['limit'] ?? null, $input['manual_limit'] ?? null);
        $sort = self::nullableText($input['sort'] ?? 'nombre');
        $order = strtolower(trim((string) ($input['order'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';

        $search = self::nullableText($input['search'] ?? null);
        $estado = self::nullableText($input['estado'] ?? null);
        $tipo = self::nullableText($input['tipo'] ?? null);
        $mina = self::nullableText($input['mina'] ?? null);
        $minaEstado = self::nullableText($input['mina_estado'] ?? null);
        $contrato = self::nullableText($input['contrato'] ?? null);

        // Scope presets override explicit filters where appropriate.
        if ($scope === 'all') {
            $search = null;
            $estado = null;
            $tipo = null;
            $mina = null;
            $minaEstado = null;
            $contrato = null;
        }

        if ($scope === 'active') {
            $estado = 'activo';
        }

        if ($scope === 'inactive') {
            $estado = 'inactivo';
        }

        if ($scope === 'supervisors') {
            $tipo = 'supervisor';
        }

        if ($scope === 'workers') {
            $tipo = 'trabajador';
        }

        if ($scope === 'mine_state' && !$minaEstado) {
            $minaEstado = 'habilitado';
        }

        return new self(
            scope: $scope,
            search: $search,
            estado: $estado,
            tipo: $tipo,
            mina: $mina,
            minaEstado: $minaEstado,
            contrato: $contrato,
            sort: $sort,
            order: $order,
            limit: $limit,
            columns: $columns,
        );
    }

    public function toFilters(): array
    {
        return array_filter([
            'search' => $this->search,
            'estado' => $this->estado,
            'tipo' => $this->tipo,
            'mina' => $this->mina,
            'mina_estado' => $this->minaEstado,
            'contrato' => $this->contrato,
            'sort' => $this->sort,
            'order' => $this->order,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    public function toInputArray(): array
    {
        return [
            'scope' => $this->scope,
            'search' => $this->search,
            'estado' => $this->estado,
            'tipo' => $this->tipo,
            'mina' => $this->mina,
            'mina_estado' => $this->minaEstado,
            'contrato' => $this->contrato,
            'sort' => $this->sort,
            'order' => $this->order,
            'limit' => $this->limit === null ? 'all' : (string) $this->limit,
            'manual_limit' => $this->limit === null ? null : (string) $this->limit,
            'columns' => $this->columns,
        ];
    }

    public static function recommendedColumns(array $allowedColumns): array
    {
        $preferred = [
            'dni',
            'nombre_completo',
            'puesto',
            'contrato',
            'ocupacion',
            'supervisor',
            'fecha_ingreso',
            'estado',
            'telefono_1',
            'telefono_2',
            'correo',
            'minas',
            'estado_mina',
        ];

        return array_values(array_filter($preferred, static fn ($key) => in_array($key, $allowedColumns, true)));
    }

    private static function resolveLimit(mixed $limitInput, mixed $manualLimit): ?int
    {
        $limitText = strtolower(trim((string) $limitInput));

        if ($limitText === '' || $limitText === 'all' || $limitText === 'sin_limite') {
            return null;
        }

        if ($limitText === 'manual') {
            $manual = (int) $manualLimit;
            return $manual > 0 ? $manual : null;
        }

        $parsed = (int) $limitText;

        return $parsed > 0 ? $parsed : null;
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }
}
