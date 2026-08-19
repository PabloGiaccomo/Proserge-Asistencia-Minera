<?php

namespace App\Modules\RQMina\Services;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParadaDashboardExcelService
{
    public function download(array $dashboard): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dashboard');

        $rq = $dashboard['rq_mina'] ?? [];
        $plan = $dashboard['plan'] ?? [];

        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', 'DASHBOARD KPI DE PARADA');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->fromArray([
            ['Parada', $rq['destino_nombre'] ?? '-', 'Area', $rq['area'] ?? '-', 'Plan', $plan['nombre'] ?? '-', 'Generado', $dashboard['generated_at'] ?? '-'],
            ['Fechas', trim(($rq['fecha_inicio'] ?? '-').' al '.($rq['fecha_fin'] ?? '-')), 'Filtro fecha', $dashboard['filters']['fecha'] ?? '-', 'Turno', $dashboard['filters']['turno'] ?? '-', 'Estado', $rq['estado'] ?? '-'],
        ], null, 'A3');

        $row = 7;
        $sheet->setCellValue("A{$row}", 'Indicador');
        $sheet->setCellValue("B{$row}", 'Valor');
        $sheet->setCellValue("C{$row}", 'Detalle');
        $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:C{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $row++;

        foreach ($dashboard['kpis'] ?? [] as $kpi) {
            $sheet->setCellValue("A{$row}", $kpi['label'] ?? '');
            $sheet->setCellValue("B{$row}", $kpi['value'] ?? '');
            $sheet->setCellValue("C{$row}", $kpi['hint'] ?? '');
            $row++;
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Alertas operativas');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $alerts = $dashboard['alerts'] ?? [];
        if (empty($alerts)) {
            $sheet->setCellValue("A{$row}", 'Sin alertas criticas para los filtros seleccionados.');
            $row++;
        } else {
            foreach ($alerts as $alert) {
                $sheet->setCellValue("A{$row}", strtoupper((string) ($alert['tone'] ?? 'info')));
                $sheet->setCellValue("B{$row}", $alert['message'] ?? '');
                $row++;
            }
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Ejecucion por grupo / actividad');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $headers = ['Fecha', 'Turno', 'Grupo', 'Actividad', 'Planificado', 'Programado', 'Presentes', 'Ausentes', 'Brecha', '% real', 'Cerrada'];
        $sheet->fromArray($headers, null, "A{$row}");
        $sheet->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:K{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $row++;

        foreach (($dashboard['execution']['rows'] ?? []) as $item) {
            $sheet->fromArray([
                $item['fecha'] ?? '',
                $item['turno'] ?? '',
                $item['grupo'] ?? '',
                $item['actividad'] ?? '',
                $item['planificado'] ?? 0,
                $item['programado'] ?? 0,
                $item['presentes'] ?? 0,
                $item['ausentes'] ?? 0,
                $item['brecha_plan_real'] ?? 0,
                $item['porcentaje_cumplimiento_real'] ?? 0,
                !empty($item['asistencia_cerrada']) ? 'Si' : 'No',
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getStyle('A1:K'.max(1, $row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sheet->freezePane('A8');

        $filename = 'dashboard_parada_'.Str::slug((string) ($rq['destino_nombre'] ?? 'rq-mina'), '_').'_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
