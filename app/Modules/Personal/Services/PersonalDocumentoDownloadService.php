<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalDocumentoEstado;
use App\Models\PersonalFichaArchivo;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class PersonalDocumentoDownloadService
{
    private const ZIP_TYPE_NAMES = [
        'cv_documentado' => 'CV_DOCUMENTADO',
        'certificado_unico_laboral' => 'CERTIADULTO_CERTIJOVEN',
        'foto_carnet' => 'FOTO',
        'dni_vigente' => 'DNI',
        'matrimonio_union' => 'PARTIDA_MATRIMONIO',
        'dni_hijos_menores' => 'DNI_HIJOS_MENORES',
        'dni_hijos_mayores_estudiantes' => 'DNI_HIJOS_MAYORES_ESTUDIANTES',
        'constancia_estudios_hijos' => 'CONSTANCIA_ESTUDIOS_HIJOS',
        'recibo_servicio' => 'RECIBO_LUZ_AGUA',
        'retenciones_quinta' => 'RENTA_QUINTA',
        'vida_ley_notarial' => 'VIDA_LEY',
    ];

    public function documentTypeOptions(): array
    {
        return collect(PersonalFichaCatalog::documentRequirements())
            ->mapWithKeys(fn (array $requirement, string $key): array => [$key => (string) ($requirement['label'] ?? $key)])
            ->all();
    }

    public function validDocumentTypes(): array
    {
        return array_keys(PersonalFichaCatalog::documentRequirements());
    }

    /**
     * @return array{path:string,filename:string,included:int,skipped:array<int,array<string,string>>}
     */
    public function createZipForPersonalIds(array $personalIds, array $documentTypes): array
    {
        $personalIds = collect($personalIds)
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        $documentTypes = collect($documentTypes)
            ->map(fn ($type): string => trim((string) $type))
            ->filter()
            ->unique()
            ->values();

        if ($personalIds->isEmpty()) {
            throw ValidationException::withMessages([
                'personal_ids' => 'Selecciona al menos un trabajador.',
            ]);
        }

        if ($documentTypes->isEmpty()) {
            throw ValidationException::withMessages([
                'document_types' => 'Selecciona al menos un tipo de documento.',
            ]);
        }

        $invalidTypes = $documentTypes->diff($this->validDocumentTypes())->values();
        if ($invalidTypes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'document_types' => 'Hay tipos documentales no validos en la seleccion.',
            ]);
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extension ZipArchive no esta disponible en el servidor.');
        }

        $trabajadores = Personal::query()
            ->with([
                'fichaColaborador.archivos',
                'fichaColaborador.documentoEstados',
            ])
            ->whereIn('id', $personalIds)
            ->get()
            ->keyBy('id');

        $zipDirectory = storage_path('app/documentos_personal_zip');
        File::ensureDirectoryExists($zipDirectory);

        $filename = 'DOCUMENTOS_PERSONAL_' . now()->format('Y-m-d_His') . '.zip';
        $zipPath = $zipDirectory . DIRECTORY_SEPARATOR . Str::uuid() . '_' . $filename;

        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP de documentos.');
        }

        $included = 0;
        $skipped = [];
        $usedNames = [];

        foreach ($personalIds as $personalId) {
            /** @var Personal|null $trabajador */
            $trabajador = $trabajadores->get($personalId);
            if (!$trabajador || !$trabajador->fichaColaborador) {
                $skipped[] = [
                    'personal_id' => $personalId,
                    'tipo' => '',
                    'motivo' => 'Trabajador sin ficha documental.',
                ];
                continue;
            }

            $ficha = $trabajador->fichaColaborador;
            $folder = $this->workerFolderName($trabajador);
            $states = $ficha->documentoEstados->keyBy('tipo');
            $filesByType = $ficha->archivos->groupBy('tipo');

            foreach ($documentTypes as $type) {
                $state = $states->get($type);
                if ($state && $state->estado === PersonalDocumentoEstado::ESTADO_NO_APLICA) {
                    continue;
                }

                /** @var Collection<int, PersonalFichaArchivo> $files */
                $files = $filesByType->get($type, collect())->values();
                if ($files->isEmpty()) {
                    continue;
                }

                foreach ($files as $archivo) {
                    $path = (string) $archivo->path;
                    if ($path === '' || !Storage::disk('local')->exists($path)) {
                        $skipped[] = [
                            'personal_id' => (string) $trabajador->id,
                            'tipo' => $type,
                            'motivo' => 'Archivo fisico no encontrado.',
                        ];
                        continue;
                    }

                    $sourcePath = Storage::disk('local')->path($path);
                    if (!is_file($sourcePath)) {
                        $skipped[] = [
                            'personal_id' => (string) $trabajador->id,
                            'tipo' => $type,
                            'motivo' => 'Ruta fisica no disponible.',
                        ];
                        continue;
                    }

                    $zipName = $this->uniqueZipFileName(
                        $folder,
                        $this->documentZipBaseName($type),
                        $this->extensionFor($archivo),
                        $usedNames,
                    );

                    $zip->addFile($sourcePath, $zipName);
                    $included++;
                }
            }
        }

        $zip->close();

        if ($included === 0) {
            File::delete($zipPath);

            throw ValidationException::withMessages([
                'documentos' => 'No hay documentos disponibles para descargar con los filtros seleccionados.',
            ]);
        }

        return [
            'path' => $zipPath,
            'filename' => $filename,
            'included' => $included,
            'skipped' => $skipped,
        ];
    }

    private function workerFolderName(Personal $trabajador): string
    {
        $trabajador->loadMissing('fichaColaborador');
        $data = is_array($trabajador->fichaColaborador?->datos_json ?? null)
            ? $trabajador->fichaColaborador->datos_json
            : [];

        $parts = array_filter([
            $data['apellido_paterno'] ?? null,
            $data['apellido_materno'] ?? null,
            $data['nombres'] ?? null,
        ], fn ($value): bool => trim((string) $value) !== '');

        $name = trim(implode(' ', $parts));
        if ($name === '') {
            $name = (string) ($trabajador->nombre_completo ?: 'TRABAJADOR');
        }

        $document = trim((string) ($trabajador->numero_documento ?: $trabajador->dni ?: $trabajador->fichaColaborador?->numero_documento));
        $folder = $document !== '' ? $name . ' ' . $document : $name . ' ' . $trabajador->id;

        return $this->safeZipSegment($folder);
    }

    private function documentZipBaseName(string $type): string
    {
        return self::ZIP_TYPE_NAMES[$type] ?? $this->safeZipSegment($type);
    }

    private function uniqueZipFileName(string $folder, string $base, string $extension, array &$usedNames): string
    {
        $folder = $this->safeZipSegment($folder);
        $base = $this->safeZipSegment($base);
        $extension = $this->safeExtension($extension);
        $usedNames[$folder] ??= [];

        $name = $base . '.' . $extension;
        $counter = 2;
        while (isset($usedNames[$folder][$name])) {
            $name = $base . '_' . $counter . '.' . $extension;
            $counter++;
        }

        $usedNames[$folder][$name] = true;

        return $folder . '/' . $name;
    }

    private function extensionFor(PersonalFichaArchivo $archivo): string
    {
        $original = (string) ($archivo->nombre_original ?: '');
        $path = (string) $archivo->path;
        $extension = pathinfo($original, PATHINFO_EXTENSION) ?: pathinfo($path, PATHINFO_EXTENSION);

        return $extension !== '' ? $extension : 'bin';
    }

    private function safeZipSegment(string $value): string
    {
        $ascii = Str::ascii(Str::upper(trim($value)));
        $safe = preg_replace('/[^A-Z0-9]+/', '_', $ascii) ?: '';
        $safe = trim($safe, '_');

        return $safe !== '' ? $safe : 'SIN_NOMBRE';
    }

    private function safeExtension(string $extension): string
    {
        $extension = Str::lower(trim($extension));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: '';

        return $extension !== '' ? $extension : 'bin';
    }
}
