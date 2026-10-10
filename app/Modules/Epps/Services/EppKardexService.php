<?php

namespace App\Modules\Epps\Services;

use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\EppOperacionEvento;
use App\Models\Personal;
use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EppKardexService
{
    public function __construct(private readonly EppAcceptanceService $acceptances, private readonly EppScopeService $scope) {}

    public function forWorker(Personal $worker, Usuario $viewer): array
    {
        if (! PermissionMatrix::userCanDirect($viewer, 'epps', 'ver_evidencia')) {
            throw new AuthorizationException('No tienes permiso para consultar evidencia EPP.');
        }
        $this->scope->assertPersonalAccess($viewer, $worker);

        $operations = EppOperacion::query()
            ->with(['aceptacion', 'detalles.epp', 'operacionOrigen.aceptacion'])
            ->where('personal_id', $worker->id)
            ->where(fn ($query) => $query->whereIn('almacen_id', $this->scope->visibleWarehouseIds($viewer))
                ->orWhere(fn ($catalog) => $catalog->whereNull('almacen_id')->where('resumen_json->origin', 'ENTREGAS_CAMBIOS')))
            ->whereIn('estado', [EppOperacion::ESTADO_FIRMADO, EppOperacion::ESTADO_FIRMADO_PARCIAL])
            ->orderByDesc('firmado_at')->orderByDesc('created_at')->get()
            ->map(function (EppOperacion $operation) use ($viewer): array {
                Gate::forUser($viewer)->authorize('viewEvidence', $operation);
                $acceptance = $operation->aceptacion;
                $verification = $acceptance ? $this->acceptances->verify($acceptance) : ['valid' => false, 'reasons' => ['EVIDENCIA_INVALIDA', 'ACEPTACION_AUSENTE']];
                $this->audit($operation, $viewer, 'KARDEX');

                return [
                    'id' => (string) $operation->id,
                    'type' => (string) $operation->tipo,
                    'state' => (string) $operation->estado,
                    'evidence_state' => $verification['valid'] ? 'FIRMADO_VALIDO' : 'EVIDENCIA_INVALIDA',
                    'verification_code' => $acceptance?->codigo_verificacion,
                    'evidence_url' => $acceptance ? route('epps.evidence.verify', $acceptance->codigo_verificacion, false) : null,
                    'receipt_url' => $acceptance && Gate::forUser($viewer)->allows('downloadReceipt', $operation) ? route('epps.evidence.receipt', $operation, false) : null,
                    'signed_at' => $acceptance?->firmado_at?->toIso8601String() ?: $operation->firmado_at?->toIso8601String(),
                    'original_operation_id' => $operation->operacion_origen_id,
                    'content_hash' => $acceptance ? Str::limit($acceptance->contenido_sha256, 16, '') : null,
                    'signature_hash' => $acceptance ? Str::limit($acceptance->firma_sha256, 16, '') : null,
                    'details' => $acceptance ? collect($acceptance->contenido_json['details'] ?? [])
                        ->concat(collect($acceptance->contenido_json['pending'] ?? [])->filter(fn ($line) => ($line['processed_quantity'] ?? 0) === 0))
                        ->concat($acceptance->contenido_json['rejected'] ?? [])
                        ->map(fn ($line) => ['epp' => data_get($line, 'snapshot.real.nombre', data_get($line, 'snapshot.solicitado.nombre', $line['epp_name'] ?? 'EPP')), 'processed' => $line['processed_quantity'] ?? 0, 'pending' => $line['pending_quantity'] ?? 0, 'attributes' => $line['attributes'] ?? [], 'condition' => $line['return_condition'] ?? null, 'destination' => $line['inventory_destination'] ?? null, 'reason' => $line['reason'] ?? null])->all()
                        : $operation->detalles->map(fn ($detail) => ['epp' => data_get($detail->snapshot_json, 'real.nombre', $detail->epp?->nombre ?: 'EPP'), 'processed' => $detail->cantidad_procesada, 'pending' => $detail->cantidad_pendiente])->all(),
                ];
            });

        $legacy = EppEntrega::query()->with('epp')->where('personal_id', $worker->id)->whereNull('operacion_id')->orderByDesc('fecha_entrega')->get()->map(fn (EppEntrega $delivery): array => [
            'id' => (string) $delivery->id,
            'type' => 'LEGACY',
            'state' => (string) $delivery->estado,
            'evidence_state' => 'LEGACY_SIN_FIRMA',
            'verification_code' => null,
            'signed_at' => null,
            'original_operation_id' => null,
            'content_hash' => null,
            'signature_hash' => null,
            'details' => [['epp' => $delivery->epp?->nombre ?: 'EPP', 'processed' => $delivery->cantidad, 'pending' => null]],
        ]);

        return ['worker' => ['id' => $worker->id, 'name' => $worker->nombre_completo, 'document' => $worker->dni], 'operations' => $operations->concat($legacy)->values()->all()];
    }

    public function downloadExcel(Personal $worker, Usuario $viewer): StreamedResponse
    {
        if (! PermissionMatrix::userCanDirect($viewer, 'epps', 'descargar_constancia')) {
            throw new AuthorizationException('No tienes permiso para descargar el Kardex EPP.');
        }
        $data = $this->forWorker($worker, $viewer);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setTitle('Kardex verificable');
        $sheet->fromArray(['ID operacion', 'Tipo', 'Estado', 'Estado evidencia', 'Fecha firma', 'Codigo verificacion', 'Operacion origen', 'Detalle'], null, 'A1');
        $row = 2;
        foreach ($data['operations'] as $operation) {
            $details = collect($operation['details'])->map(fn ($detail) => $detail['epp'].' ('.$detail['processed'].') Pendiente: '.($detail['pending'] ?? 0).' '.collect($detail['attributes'] ?? [])->map(fn ($value, $key) => $key.': '.$value)->implode(', ').' '.($detail['condition'] ?? '').' '.($detail['destination'] ?? '').' '.($detail['reason'] ?? ''))->implode('; ');
            foreach ([$operation['id'], $operation['type'], $operation['state'], $operation['evidence_state'], $operation['signed_at'], $operation['verification_code'], $operation['original_operation_id'], $details] as $index => $value) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).$row, (string) $value, DataType::TYPE_STRING);
            }
            $row++;
        }
        $sheet->setCellValue('A'.($row + 1), 'La verificacion del servidor es la fuente autoritativa de integridad.');
        $writer = new Xlsx($sheet->getParent());

        return response()->streamDownload(fn () => $writer->save('php://output'), 'kardex_epp_'.Str::slug($worker->nombre_completo, '_').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function audit(EppOperacion $operation, Usuario $viewer, string $channel): void
    {
        EppOperacionEvento::query()->create(['operacion_id' => $operation->id, 'actor_usuario_id' => $viewer->id, 'accion' => 'EVIDENCIA_CONSULTADA', 'origen' => 'WEB', 'snapshot_json' => ['canal' => $channel]]);
    }
}
