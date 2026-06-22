<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalContratoDato;
use App\Models\PersonalPuesto;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalContratoDatoService
{
    public const PENDING_STATE = 'FALTA_CONTRATO';

    public function ensureForPersonal(Personal $personal, array $defaults = [], ?Usuario $user = null): PersonalContratoDato
    {
        $data = $this->normalizePayload($defaults);

        $payload = [
            'id' => PersonalContratoDato::query()->where('personal_id', $personal->id)->value('id') ?? (string) Str::uuid(),
            'personal_id' => $personal->id,
            'updated_by_usuario_id' => $user?->id,
        ];

        foreach ($data as $key => $value) {
            if ($value !== null && $value !== '') {
                $payload[$key] = $value;
            }
        }

        return PersonalContratoDato::query()->updateOrCreate(
            ['personal_id' => $personal->id],
            $payload,
        );
    }

    public function update(Personal $personal, array $payload, Usuario $user): PersonalContratoDato
    {
        return DB::transaction(function () use ($personal, $payload, $user): PersonalContratoDato {
            app(PersonalContratoService::class)->assertContractEditable($personal, $user);

            $data = $this->normalizePayload($payload);
            $data['updated_by_usuario_id'] = $user->id;

            $record = $this->ensureForPersonal($personal, [], $user);
            $record->forceFill($data)->save();

            if (array_key_exists('puesto', $data) && trim((string) $data['puesto']) !== '') {
                $puestoCatalogo = $this->resolvePuestoCatalogo((string) $data['puesto']);
                $personalData = ['puesto' => $puestoCatalogo?->nombre ?: trim((string) $data['puesto'])];
                if ($puestoCatalogo && Schema::hasColumn('personal', 'puesto_id')) {
                    $personalData['puesto_id'] = $puestoCatalogo->id;
                }
                $personal->forceFill($personalData)->save();
            }

            $record = $record->fresh();
            app(PersonalContratoService::class)->syncEditableContractData($personal->fresh(['fichaColaborador', 'minas']) ?: $personal, $record, $user);

            return $record;
        });
    }

    public function markDownloaded(array $personalIds): void
    {
        $ids = collect($personalIds)->map(fn ($id): string => trim((string) $id))->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        Personal::query()
            ->whereIn('id', $ids->all())
            ->get()
            ->each(fn (Personal $personal) => $this->ensureForPersonal($personal)->forceFill(['downloaded_at' => now()])->save());
    }

    public function uploadSignedContract(Personal $personal, UploadedFile $file, Usuario $user): PersonalContratoDato
    {
        return DB::transaction(function () use ($personal, $file, $user): PersonalContratoDato {
            app(PersonalContratoService::class)->assertContractEditable($personal, $user);
            $record = $this->ensureForPersonal($personal, [], $user);

            if (
                $record->signed_contract_path
                && Storage::disk('local')->exists($record->signed_contract_path)
                && !$this->isSignedPathReferencedByContract($record->signed_contract_path)
            ) {
                Storage::disk('local')->delete($record->signed_contract_path);
            }

            $path = $file->storeAs(
                'personal_contratos/' . $personal->id,
                'contrato_firmado_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.pdf',
                'local',
            );

            $record->forceFill([
                'signed_at' => now(),
                'signed_contract_path' => $path,
                'signed_contract_original_name' => $file->getClientOriginalName(),
                'signed_contract_mime' => $file->getMimeType(),
                'signed_contract_size' => $file->getSize(),
                'updated_by_usuario_id' => $user->id,
            ])->save();

            $record = $record->fresh();
            app(PersonalContratoService::class)->markEditableContractSigned($personal, $record, $user);
            if (Schema::hasColumn('personal', 'pendiente_contrato_firmado')) {
                $personal->forceFill(['pendiente_contrato_firmado' => false])->save();
            }

            return $record;
        });
    }

    public function normalizePayload(array $payload): array
    {
        return [
            'fecha_inicio_contrato' => PersonalNormalizer::isoDate($payload['fecha_inicio_contrato'] ?? $payload['fecha_ingreso'] ?? null),
            'fecha_fin_contrato' => PersonalNormalizer::isoDate($payload['fecha_fin_contrato'] ?? null),
            'periodo_prueba_inicio' => PersonalNormalizer::isoDate($payload['periodo_prueba_inicio'] ?? null),
            'periodo_prueba_fin' => PersonalNormalizer::isoDate($payload['periodo_prueba_fin'] ?? null),
            'sueldo_hora_paradas' => $this->text($payload['sueldo_hora_paradas'] ?? null, 80),
            'sueldo_hora_paradas_texto' => $this->text($payload['sueldo_hora_paradas_texto'] ?? null, 191),
            'sueldo_dia_taller' => $this->text($payload['sueldo_dia_taller'] ?? null, 80),
            'sueldo_dia_taller_texto' => $this->text($payload['sueldo_dia_taller_texto'] ?? null, 191),
            'funciones' => $this->text($payload['funciones'] ?? null, 5000),
            'sueldo_num' => $this->text($payload['sueldo_num'] ?? null, 80),
            'sueldo_texto' => $this->text($payload['sueldo_texto'] ?? null, 191),
            'puesto' => $this->text($payload['puesto'] ?? null, 191),
            'fecha_firma' => PersonalNormalizer::isoDate($payload['fecha_firma'] ?? null),
        ];
    }

    private function text(mixed $value, int $limit): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private function resolvePuestoCatalogo(string $puesto): ?PersonalPuesto
    {
        if (!Schema::hasTable('personal_puestos') || !Schema::hasColumn('personal', 'puesto_id')) {
            return null;
        }

        $nombre = mb_substr(trim($puesto), 0, 191);
        if ($nombre === '') {
            return null;
        }

        return PersonalPuesto::query()
            ->where('nombre', $nombre)
            ->where('activo', true)
            ->first();
    }

    private function isSignedPathReferencedByContract(string $path): bool
    {
        return Schema::hasTable('personal_contratos')
            && Schema::hasColumn('personal_contratos', 'signed_contract_path')
            && PersonalContrato::query()
                ->where('signed_contract_path', $path)
                ->exists();
    }
}
