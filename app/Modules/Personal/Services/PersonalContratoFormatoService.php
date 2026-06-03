<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonalContratoFormatoService
{
    private const TEMPLATE_DIR = 'resources/contract-templates';

    private const TEMPLATE_DEFINITIONS = [
        [
            'id' => 'nuevos_inter_2026_06_02',
            'title' => 'NUEVOS INTER',
            'date' => '02/06/2026',
            'file' => 'nuevos_inter_2026_06_02.xlsx',
        ],
        [
            'id' => 'nuevo_se_2026_05',
            'title' => 'NUEVO SE',
            'date' => '05/2026',
            'file' => 'nuevo_se_2026_05.xlsx',
        ],
        [
            'id' => 'renovacion_se_indet_2026_05_01',
            'title' => 'RENOVACION SE INDET',
            'date' => '01/05/2026',
            'file' => 'renovacion_se_indet_2026_05_01.xlsx',
        ],
        [
            'id' => 'nuevo_inter_x_dia_2026_05_19',
            'title' => 'NUEVO INTER X DIA',
            'date' => '19/05/2026',
            'file' => 'nuevo_inter_x_dia_2026_05_19.xlsx',
        ],
        [
            'id' => 'se_jefatura_2026_05_05',
            'title' => 'SE JEFATURA',
            'date' => '05/05/2026',
            'file' => 'se_jefatura_2026_05_05.xlsx',
        ],
    ];

    public function __construct(
        private readonly PersonalService $personalService,
        private readonly PersonalContratoDatoService $contratoDatoService,
    )
    {
    }

    public function templates(): array
    {
        return collect(self::TEMPLATE_DEFINITIONS)
            ->map(function (array $definition): array {
                $worksheet = $this->loadTemplate($definition['id'])->getActiveSheet();
                $columns = $this->readHeaderColumns($worksheet);
                $sample = $this->readSampleRow($worksheet, count($columns));

                return [
                    ...$definition,
                    'label' => $definition['title'] . ' - ' . $definition['date'],
                    'columns' => $columns,
                    'sample' => $sample,
                ];
            })
            ->values()
            ->all();
    }

    public function template(string $id): array
    {
        $template = collect($this->templates())->firstWhere('id', $id);

        abort_if(!$template, 404, 'Formato de contrato no encontrado.');

        return $template;
    }

    public function searchWorkers(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        return $this->personalService
            ->buildFilteredQuery([
                'search' => $query,
                'limit' => $limit,
                'with_minas' => true,
            ])
            ->with(['fichaColaborador', 'contratoLaboralActual', 'contratoDatos'])
            ->limit(max(1, min(20, $limit)))
            ->get()
            ->map(fn (Personal $personal): array => $this->workerSummary($personal))
            ->values()
            ->all();
    }

    public function preview(string $templateId, array $personalIds): array
    {
        $template = $this->template($templateId);
        $workers = $this->workersByIds($personalIds);
        $rows = $this->buildPreviewRows($template, $workers);

        return [
            'template' => [
                'id' => $template['id'],
                'label' => $template['label'],
                'columns' => $template['columns'],
            ],
            'workers' => $workers->map(fn (Personal $personal): array => $this->workerSummary($personal))->values()->all(),
            'rows' => $rows,
        ];
    }

    public function download(string $templateId, array $personalIds): StreamedResponse
    {
        $template = $this->template($templateId);
        $workers = $this->workersByIds($personalIds);

        abort_if($workers->isEmpty(), 422, 'Selecciona al menos un trabajador.');

        $spreadsheet = $this->buildWorkbook($template, $workers);
        $writer = new Xlsx($spreadsheet);
        $downloadName = $this->downloadName($template);
        $this->contratoDatoService->markDownloaded($workers->pluck('id')->all());

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function buildWorkbook(array $template, Collection $workers): Spreadsheet
    {
        $spreadsheet = $this->loadTemplate($template['id']);
        $sheet = $spreadsheet->getActiveSheet();
        $columns = $this->readHeaderColumns($sheet);
        $sample = $this->readSampleRow($sheet, count($columns));
        $rowCount = $workers->count();
        $highestRow = max(2, $sheet->getHighestRow());

        if ($highestRow > 2) {
            $sheet->removeRow(3, $highestRow - 2);
        }

        if ($rowCount > 1) {
            $sheet->insertNewRowBefore(3, $rowCount - 1);
        }

        for ($row = 2; $row < 2 + $rowCount; $row++) {
            $sheet->duplicateStyle($sheet->getStyle('A2:' . $sheet->getHighestColumn() . '2'), 'A' . $row . ':' . $sheet->getHighestColumn() . $row);
            $sheet->getRowDimension($row)->setRowHeight($sheet->getRowDimension(2)->getRowHeight());
        }

        $workers->values()->each(function (Personal $personal, int $index) use ($sheet, $columns, $sample): void {
            $rowNumber = $index + 2;
            $values = $this->rowForWorker($columns, $sample, $personal);

            foreach ($values as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowNumber], $value);
            }
        });

        return $spreadsheet;
    }

    private function buildPreviewRows(array $template, Collection $workers): array
    {
        $columns = $template['columns'];
        $sample = $template['sample'];

        return $workers
            ->map(fn (Personal $personal): array => $this->rowForWorker($columns, $sample, $personal))
            ->values()
            ->all();
    }

    private function rowForWorker(array $columns, array $sample, Personal $personal): array
    {
        $ficha = $personal->fichaColaborador;
        $contractData = $personal->contratoDatos;
        $data = is_array($ficha?->datos_detectados_json ?? null) ? $ficha->datos_detectados_json : [];
        $data = array_merge($data, is_array($ficha?->datos_json ?? null) ? $ficha->datos_json : []);

        $fechaInicio = $this->dateValue($contractData?->fecha_inicio_contrato ?? $data['fecha_ingreso'] ?? $personal->fecha_ingreso ?? $personal->contratoLaboralActual?->fecha_inicio ?? null);
        $fechaFin = $this->dateValue($contractData?->fecha_fin_contrato ?? $data['fecha_fin_contrato'] ?? $personal->contratoLaboralActual?->fecha_fin ?? null);
        $pruebaInicio = $this->dateValue($contractData?->periodo_prueba_inicio ?? $data['periodo_prueba_inicio'] ?? $fechaInicio);
        $pruebaFin = $this->dateValue($contractData?->periodo_prueba_fin ?? $data['periodo_prueba_fin'] ?? null) ?: $this->trialEndDate($fechaInicio, $fechaFin);
        $domicilio = trim((string) ($data['domicilio_direccion'] ?? ''));
        $distrito = $this->districtValue($data);
        $correo = trim((string) ($data['correo'] ?? $personal->correo ?? ''));
        $documento = trim((string) ($data['numero_documento'] ?? $ficha?->numero_documento ?? $personal->numero_documento ?? $personal->dni ?? ''));
        $puesto = trim((string) ($contractData?->puesto ?? $data['puesto'] ?? $personal->puesto ?? ''));
        $nombre = trim((string) ($personal->nombre_completo ?? ''));

        return collect($columns)
            ->map(function (string $column, int $index) use ($sample, $documento, $nombre, $domicilio, $distrito, $correo, $fechaInicio, $fechaFin, $pruebaInicio, $pruebaFin, $puesto, $contractData): string {
                $fallback = (string) ($sample[$index] ?? '');
                $normalized = mb_strtoupper(trim($column), 'UTF-8');

                return match ($normalized) {
                    'IDENTIFICADOR' => $documento,
                    'COLABORADOR' => $nombre,
                    'DOMICILIO', 'DOMICILIO_COLABORADOR' => $domicilio !== '' ? $domicilio : $fallback,
                    'DISTRITO', 'DISTRITO_COLABORADOR' => $distrito !== '' ? $distrito : $fallback,
                    'CORREO', 'CORREO_ELECTRONICO' => $correo !== '' ? $correo : $fallback,
                    'FECHA_INICIO', 'FECHA_INICIO_CONTRATO' => $this->templateKeepsDash($fallback) ? '-' : ($this->formatSpanishDate($fechaInicio) ?: $fallback),
                    'FECHA_INICIO_PRUEBA', 'INICIO_PERIODO_PRUEBA' => $this->templateKeepsDash($fallback) ? '-' : ($this->formatSpanishDate($pruebaInicio) ?: $fallback),
                    'FECHA_FIN_CONTRATO' => $this->formatSpanishDate($fechaFin) ?: $fallback,
                    'FECHA_FIN_PRUEBA', 'FIN_PERIODO_PRUEBA' => $this->templateKeepsDash($fallback) ? '-' : ($this->formatSpanishDate($pruebaFin) ?: $fallback),
                    'PUESTO' => $puesto !== '' ? $puesto : $fallback,
                    'SUELDO_HORA_PARADAS' => $this->contractText($contractData?->sueldo_hora_paradas, $fallback),
                    'SUELDO_HORA_PARADAS_TEXTO' => $this->contractText($contractData?->sueldo_hora_paradas_texto, $fallback),
                    'SUELDO_DIA_TALLER' => $this->contractText($contractData?->sueldo_dia_taller, $fallback),
                    'SUELDO_DIA_TALLER_TEXTO' => $this->contractText($contractData?->sueldo_dia_taller_texto, $fallback),
                    'FUNCIONES' => $this->contractText($contractData?->funciones, $fallback),
                    'SUELDO_NUM' => $this->contractText($contractData?->sueldo_num, $fallback),
                    'SUELDO_TEXTO' => $this->contractText($contractData?->sueldo_texto, $fallback),
                    'FECHA_FIRMA', 'FECHA_DE_FIRMA' => $this->formatSpanishDate($this->dateValue($contractData?->fecha_firma ?? null)) ?: $fallback,
                    default => $fallback,
                };
            })
            ->values()
            ->all();
    }

    private function workerSummary(Personal $personal): array
    {
        $ficha = $personal->fichaColaborador;
        $contractData = $personal->contratoDatos;
        $data = is_array($ficha?->datos_detectados_json ?? null) ? $ficha->datos_detectados_json : [];
        $data = array_merge($data, is_array($ficha?->datos_json ?? null) ? $ficha->datos_json : []);

        return [
            'id' => (string) $personal->id,
            'nombre' => (string) $personal->nombre_completo,
            'documento' => (string) ($data['numero_documento'] ?? $ficha?->numero_documento ?? $personal->numero_documento ?? $personal->dni ?? ''),
            'puesto' => (string) ($contractData?->puesto ?? $data['puesto'] ?? $personal->puesto ?? ''),
            'correo' => (string) ($data['correo'] ?? $personal->correo ?? ''),
        ];
    }

    private function workersByIds(array $personalIds): Collection
    {
        $ids = collect($personalIds)
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $records = Personal::query()
            ->with(['fichaColaborador', 'contratoLaboralActual', 'contratoDatos'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (Personal $personal): string => (string) $personal->id);

        return $ids
            ->map(fn (string $id) => $records->get($id))
            ->filter()
            ->values();
    }

    private function loadTemplate(string $templateId): Spreadsheet
    {
        $definition = collect(self::TEMPLATE_DEFINITIONS)->firstWhere('id', $templateId);
        abort_if(!$definition, 404, 'Formato de contrato no encontrado.');

        $path = base_path(self::TEMPLATE_DIR . '/' . $definition['file']);
        abort_unless(is_file($path), 404, 'Archivo de formato no encontrado.');

        return IOFactory::load($path);
    }

    private function readHeaderColumns($sheet): array
    {
        $highestColumn = $sheet->getHighestDataColumn(1);
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        $columns = [];

        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $columns[] = trim((string) $sheet->getCell([$column, 1])->getValue());
        }

        return $columns;
    }

    private function readSampleRow($sheet, int $columnsCount): array
    {
        $sample = [];
        for ($column = 1; $column <= $columnsCount; $column++) {
            $sample[] = (string) ($sheet->getCell([$column, 2])->getValue() ?? '');
        }

        return $sample;
    }

    private function districtValue(array $data): string
    {
        return collect([
            $data['domicilio_distrito'] ?? null,
            $data['domicilio_provincia'] ?? null,
            $data['domicilio_departamento'] ?? null,
        ])
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->implode(' - ');
    }

    private function dateValue(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value)->startOfDay();
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function trialEndDate(?Carbon $start, ?Carbon $contractEnd): ?Carbon
    {
        if (!$start) {
            return null;
        }

        $trialEnd = $start->copy()->addMonthsNoOverflow(3)->subDay();

        if ($contractEnd && $contractEnd->lessThan($trialEnd)) {
            return $contractEnd->copy();
        }

        return $trialEnd;
    }

    private function formatSpanishDate(?Carbon $date): string
    {
        if (!$date) {
            return '';
        }

        $months = [
            1 => 'ENERO',
            2 => 'FEBRERO',
            3 => 'MARZO',
            4 => 'ABRIL',
            5 => 'MAYO',
            6 => 'JUNIO',
            7 => 'JULIO',
            8 => 'AGOSTO',
            9 => 'SETIEMBRE',
            10 => 'OCTUBRE',
            11 => 'NOVIEMBRE',
            12 => 'DICIEMBRE',
        ];

        return $date->format('d') . ' DE ' . $months[(int) $date->format('n')] . ' DEL ' . $date->format('Y');
    }

    private function contractText(mixed $value, string $fallback): string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : $fallback;
    }

    private function templateKeepsDash(string $value): bool
    {
        return trim($value) === '-';
    }

    private function downloadName(array $template): string
    {
        $slug = str($template['title'])->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');

        return 'formato_contrato_' . $slug . '_' . now()->format('Ymd_His') . '.xlsx';
    }
}
