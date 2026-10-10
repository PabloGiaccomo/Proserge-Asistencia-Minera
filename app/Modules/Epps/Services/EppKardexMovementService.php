<?php

namespace App\Modules\Epps\Services;

use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\Personal;
use App\Models\Usuario;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class EppKardexMovementService
{
    public function __construct(private readonly EppScopeService $scope, private readonly EppAcceptanceService $acceptances) {}

    public function events(Personal $worker, Usuario $actor, string $from, string $to, bool $withEvidence = false): array
    {
        $this->scope->assertPersonalAccess($actor, $worker);
        $events = [];
        $coveredClosures = [];
        $operations = EppOperacion::query()->with(['aceptacion', 'detalles.epp'])->where('personal_id', $worker->id)
            ->whereIn('estado', ['FIRMADO', 'FIRMADO_PARCIAL'])->where(fn ($query) => $query->whereIn('almacen_id', $this->scope->visibleWarehouseIds($actor))->orWhere(fn ($catalog) => $catalog->whereNull('almacen_id')->where('resumen_json->origin', 'ENTREGAS_CAMBIOS')))->lazy(100);
        foreach ($operations as $operation) {
            if ($withEvidence) {
                Gate::forUser($actor)->authorize('viewEvidence', $operation);
            }
            $acceptance = $operation->aceptacion;
            $payload = $acceptance?->contenido_json ?? $operation->resumen_json ?? [];
            $timestamp = $acceptance?->firmado_at_utc ?? $operation->firmado_at ?? $operation->created_at;
            $date = substr((string) ($payload['date'] ?? $timestamp?->copy()->setTimezone(config('app.display_timezone', 'America/Lima'))->toDateString()), 0, 10);
            $closingDate = data_get($payload, 'input._epp_replacement_fecha') ?: $date;
            $operationStart = count($events);
            foreach ($payload['details'] ?? [] as $line) {
                if (! empty($line['original_delivery_id'])) {
                    $coveredClosures[$line['original_delivery_id'].'|'.$closingDate] = true;
                }
            }
            if (($date < $from || $date > $to) && ($closingDate < $from || $closingDate > $to)) {
                continue;
            }
            $valid = $withEvidence && $acceptance && $this->acceptances->verify($acceptance)['valid'];
            $bytes = $valid && $acceptance->tipo_firmante === 'TRABAJADOR' ? Storage::disk('local')->get($acceptance->firma_path) : null;
            if ($bytes !== null && ! hash_equals($acceptance->firma_sha256, hash('sha256', $bytes))) {
                $bytes = null;
            }
            $lines = $payload['details'] ?? [];
            if (! $lines && in_array($operation->tipo, ['CORRECCION', 'ANULACION'], true)) {
                $lines = $operation->detalles->map(fn ($detail) => ['epp_id' => $detail->epp_id, 'epp_name' => $detail->epp?->nombre ?: 'EPP', 'processed_quantity' => $detail->cantidad_procesada, 'size' => $detail->talla, 'color' => $detail->color, 'attributes' => $detail->atributos_json ?: [], 'reason' => $detail->motivo ?: $operation->motivo])->all();
                if (! $lines && ($payload['accion'] ?? '') === 'PERDIDA_REGISTRADA') {
                    $source = EppEntrega::query()->with('epp')->find($payload['entrega_origen_id'] ?? '');
                    if ($source && $source->personal_id === $worker->id) {
                        $lines = [['epp_id' => $source->epp_id, 'epp_name' => $source->epp?->nombre ?: 'EPP', 'processed_quantity' => $payload['cantidad'] ?? 0, 'size' => $source->talla, 'color' => $source->color, 'attributes' => $source->atributos_json ?: [], 'reason' => $operation->motivo]];
                    }
                }
            }
            foreach ($lines as $line) {
                if (($line['processed_quantity'] ?? 0) < 1) {
                    continue;
                }
                $state = data_get($payload, 'input.estado', $operation->tipo === 'DEVOLUCION' ? 'DEVUELTO' : 'ENTREGADO');
                $code = match ($state) {
                    'DEVUELTO' => 'D', 'PERDIDA_OLVIDO' => 'P', 'USO_INCORRECTO' => 'I', 'CAMBIADO' => 'C', default => ! empty($payload['input']['_epp_replaces_entrega_id']) ? 'C' : 'N'
                };
                if (in_array($operation->tipo, ['CORRECCION', 'ANULACION'], true)) {
                    $code = ($payload['accion'] ?? '') === 'PERDIDA_REGISTRADA' ? 'P' : 'A';
                }
                $attrs = array_filter(['Talla' => $line['size'] ?? null, 'Color' => $line['color'] ?? null]) + $this->attributes($line['attributes'] ?? []);
                $description = collect($attrs)->map(fn ($value, $key) => $key.': '.$value)->implode(', ');
                $description .= '; '.collect([$line['reason'] ?? null, $payload['notes'] ?? null, data_get($payload, 'input.observacion'), $line['return_condition'] ?? null, $line['inventory_destination'] ?? null])->filter()->unique()->implode('; ');
                if (in_array($operation->tipo, ['CORRECCION', 'ANULACION'], true)) {
                    $description .= '; '.$operation->tipo.'; origen: '.$operation->operacion_origen_id;
                }
                if (isset($line['snapshot']['saldo_restante'])) {
                    $description .= '; saldo anterior: '.($line['snapshot']['saldo_anterior'] ?? '').'; saldo restante: '.$line['snapshot']['saldo_restante'];
                }
                $name = data_get($line, 'snapshot.real.nombre', $line['epp_name'] ?? $operation->detalles->firstWhere('epp_id', $line['epp_id'] ?? null)?->epp?->nombre ?? 'EPP');
                $events[] = ['date' => $date, 'name' => $name, 'quantity' => (int) $line['processed_quantity'], 'code' => $code, 'description' => $description, 'batch_id' => $operation->id, 'acceptance_id' => $acceptance?->id, 'signed_at' => $acceptance?->firmado_at_utc?->format('Y-m-d H:i:s.u') ?? '', 'signature' => $bytes, 'evidence' => $valid ? ($bytes ? 'FIRMA DEL TRABAJADOR ' : 'FIRMA DE TESTIGO ').$acceptance->codigo_verificacion : 'SIN FIRMA VALIDA'];
            }
            if (! empty($payload['input']['_epp_replaces_entrega_id']) && count($events) > $operationStart) {
                $snapshot = $payload['original_snapshot'] ?? [];
                $source = EppEntrega::query()->with('epp', 'operacion.aceptacion')->find($payload['input']['_epp_replaces_entrega_id']);
                if ($source && $source->personal_id === $worker->id) {
                    $oldLine = collect($source->operacion?->aceptacion?->contenido_json['details'] ?? [])->firstWhere('id', $source->operacion_detalle_id);
                    $event = end($events);
                    $event['date'] = $closingDate;
                    if ($closingDate !== $date) {
                        $event['signature'] = null;
                    }
                    $event['name'] = $oldLine['snapshot']['real']['nombre'] ?? $source->epp?->nombre ?? 'EPP';
                    $event['quantity'] = (int) ($snapshot['cantidad_pendiente_devolucion'] ?? $snapshot['cantidad'] ?? $source->cantidad);
                    $event['code'] = 'C';
                    $attributes = $snapshot['atributos_json'] ?? [];
                    if (is_string($attributes)) {
                        $attributes = json_decode($attributes, true) ?: [];
                    }
                    $event['description'] = 'Cierre por cambio; Talla: '.($snapshot['talla'] ?? '').'; Color: '.($snapshot['color'] ?? '').'; '.$this->attributeText($attributes).'; '.($payload['input']['_epp_replacement_motivo'] ?? '').'; '.($payload['input']['_epp_replacement_observacion'] ?? '');
                    $events[] = $event;
                }
            }
            if ($withEvidence) {
                $operation->eventos()->create(['actor_usuario_id' => $actor->id, 'accion' => 'EVIDENCIA_CONSULTADA', 'origen' => 'WEB', 'snapshot_json' => ['canal' => 'EXCEL_SGC_FOR_59', 'fecha_desde' => $from, 'fecha_hasta' => $to]]);
            }
        }
        $legacy = $this->scope->scopeDeliveries(EppEntrega::query(), $actor)->with('epp')->where('personal_id', $worker->id)->whereNull('operacion_id')->lazy(100);
        foreach ($legacy as $source) {
            $description = $this->attributeText($source->atributos_json ?? []);
            $description = 'Talla: '.($source->talla ?: '-').'; Color: '.($source->color ?: '-').'; '.$description.'; '.($source->observacion ?: '').'; '.($source->motivo_cambio ?: '');
            foreach (['N' => $source->fecha_entrega?->toDateString(), 'D' => $source->devuelto_at?->toDateString()] as $code => $date) {
                if (! $date || $date < $from || $date > $to || ($code === 'D' && isset($coveredClosures[$source->id.'|'.$date]))) {
                    continue;
                }
                if ($code === 'D') {
                    $code = match ($source->estado) {
                        'CAMBIADO' => 'C', 'PERDIDA_OLVIDO' => 'P', 'USO_INCORRECTO' => 'I', default => 'D'
                    };
                }
                $events[] = ['date' => $date, 'name' => $source->epp?->nombre ?: 'EPP', 'quantity' => $source->cantidad, 'code' => $code, 'description' => $description, 'batch_id' => 'legacy:'.$source->id, 'acceptance_id' => null, 'signed_at' => '', 'signature' => null, 'evidence' => 'SIN FIRMA (registro anterior)'];
            }
        }

        return collect($events)->filter(fn ($event) => $event['date'] >= $from && $event['date'] <= $to)->sortBy([['date', 'asc'], ['signed_at', 'asc'], ['name', 'asc']])->values()->all();
    }

    private function attributes(array $attributes): array
    {
        $normalized = [];
        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                $normalized[$value['nombre'] ?? $key] = $value['valor'] ?? '';
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function attributeText(array $attributes): string
    {
        return collect($this->attributes($attributes))->map(fn ($value, $key) => $key.': '.$value)->implode(', ');
    }
}
