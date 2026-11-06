<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalFichaAccess;
use App\Shared\Support\SearchTerms;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PersonalContratoSignedBatchService
{
    private const LOTES = 'personal_contrato_firmado_lotes';

    private const ARCHIVOS = 'personal_contrato_firmado_lote_archivos';

    private const CONFIRMADOS = ['GUARDADO', 'FIRMADO_FUTURO'];

    public function __construct(private readonly PersonalContratoService $contracts) {}

    public function start(Usuario $actor): string
    {
        $this->authorize($actor);
        $id = (string) Str::uuid();
        DB::table(self::LOTES)->insert([
            'id' => $id, 'usuario_id' => $actor->id, 'expires_at' => now()->addHours(2),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function recent(Usuario $actor): ?string
    {
        $this->authorize($actor);

        return DB::table(self::LOTES)->where('usuario_id', $actor->id)
            ->where('expires_at', '>', now())->latest('created_at')->value('id');
    }

    public function stage(Usuario $actor, string $lot, string $operation, UploadedFile $file): string
    {
        $this->lot($actor, $lot);
        try {
            $pageCount = (new Fpdi)->setSourceFile($file->getRealPath());
        } catch (CrossReferenceException $exception) {
            $this->invalid(match ($exception->getCode()) {
                CrossReferenceException::COMPRESSED_XREF => 'El lector de carga masiva no admite este formato de PDF. Adjunta el archivo original desde el contrato individual.',
                CrossReferenceException::ENCRYPTED => 'El PDF está protegido. Solicita el documento firmado que pueda abrirse sin contraseña.',
                default => 'No se puede abrir el PDF. Comprueba el archivo.',
            });
        } catch (\Throwable) {
            $this->invalid('No se puede abrir el PDF. Usa un PDF legible y sin contraseña.');
        }
        if ($pageCount < 1) {
            $this->invalid('El PDF no contiene páginas.');
        }
        $digest = hash_file('sha256', $file->getRealPath());
        if (! is_string($digest)) {
            $this->invalid('No se pudo leer el PDF. Selecciónalo nuevamente.');
        }
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $file->getClientOriginalName()) ?: 'Contrato.pdf';
        $path = null;

        try {
            return DB::transaction(function () use ($actor, $lot, $operation, $file, $digest, $name, &$path): string {
                $this->lot($actor, $lot, lock: true);
                $rows = $this->rows($lot);
                $existing = (clone $rows)->where('operacion_id', $operation)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->sha256, $digest), 409, 'El archivo cambió. Vuelve a seleccionarlo.');

                    return $existing->id;
                }
                if ((clone $rows)->where('sha256', $digest)->exists()) {
                    $this->invalid('Este PDF ya está en el lote.');
                }

                $id = (string) Str::uuid();
                $path = $file->storeAs('contratos-firmados-temporales/'.$lot, $id.'.pdf', 'local');
                if (! $path) {
                    $this->invalid('No se pudo guardar el archivo temporal. Intenta otra vez.');
                }
                $rows->insert([
                    'id' => $id, 'lote_id' => $lot, 'operacion_id' => $operation,
                    'orden' => ((clone $rows)->max('orden') ?? 0) + 1,
                    'nombre' => Str::limit($name, 185), 'path' => $path, 'sha256' => $digest,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $id;
            });
        } catch (\Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function discard(Usuario $actor, string $lot, string $id): void
    {
        DB::transaction(function () use ($actor, $lot, $id): void {
            $this->lot($actor, $lot, lock: true);
            $query = $this->rows($lot)->where('id', $id);
            $row = $query->first();
            abort_unless($row, 404);
            abort_if(in_array($row->estado, self::CONFIRMADOS, true), 409, 'El archivo ya fue confirmado.');
            $query->delete();
            if ($row->path) {
                DB::afterCommit(function () use ($row, $lot): void {
                    try {
                        if ($row->path === 'contratos-firmados-temporales/'.$lot.'/'.$row->id.'.pdf') {
                            Storage::disk('local')->delete($row->path);
                        }
                    } catch (\Throwable $exception) {
                        Log::warning('contratos.temporal_retiro_pendiente', ['archivo_id' => $row->id, 'exception' => $exception::class]);
                    }
                });
            }
        });
    }

    public function purgeExpiredFiles(): void
    {
        if (! Schema::hasTable(self::ARCHIVOS)) {
            return;
        }

        $disk = Storage::disk('local');
        $expired = DB::table(self::LOTES)->where('expires_at', '<=', now())->select('id');
        foreach (DB::table(self::ARCHIVOS)->whereIn('lote_id', $expired)->whereNotNull('path')->lazyById() as $row) {
            $path = 'contratos-firmados-temporales/'.$row->lote_id.'/'.$row->id.'.pdf';
            if ($row->path === $path && (! $disk->exists($path) || $disk->delete($path))) {
                DB::table(self::ARCHIVOS)->where('id', $row->id)->where('path', $path)->update(['path' => null]);
            }
        }
    }

    public function assign(Usuario $actor, string $lot, string $rowId, string $contractId): void
    {
        DB::transaction(function () use ($actor, $lot, $rowId, $contractId): void {
            $this->lot($actor, $lot, lock: true);
            $rows = $this->rows($lot);
            $row = (clone $rows)->where('id', $rowId)->first();
            abort_unless($row, 404);
            abort_if(in_array($row->estado, self::CONFIRMADOS, true), 409, 'El archivo ya fue confirmado.');
            $contract = PersonalContrato::query()->findOrFail($contractId);
            if (! $this->contracts->canUploadSignedFileForContract($contract)) {
                $this->invalid('Elige un contrato en preparación que aún no tenga firmado.');
            }
            if ((clone $rows)->where('contrato_id', $contract->id)->where('id', '<>', $row->id)->exists()) {
                $this->invalid('Ya hay otro archivo asociado a ese contrato en este lote.');
            }
            $rows->where('id', $rowId)->update([
                'contrato_id' => $contract->id, 'contrato_revision' => $this->contractRevision($contract),
                'estado' => 'PENDIENTE', 'mensaje' => null, 'updated_at' => now(),
            ]);
        });
    }

    public function candidates(Usuario $actor, string $search): array
    {
        $this->authorize($actor);

        return PersonalContrato::query()->with('personal:id,nombre_completo')
            ->where('estado', PersonalContrato::ESTADO_PREPARACION)
            ->where(fn ($q) => $q->whereNull('signed_at')->orWhereNull('signed_contract_path')->orWhere('signed_contract_path', ''))
            ->whereHas('personal', function ($query) use ($search): void {
                SearchTerms::apply($query, $search, ['nombre_completo', 'dni', 'numero_documento']);
            })
            ->orderByDesc('fecha_inicio')->limit(20)->get()
            ->map(fn (PersonalContrato $contract): array => $this->contractLabel($contract))->all();
    }

    public function readyToActivate(Usuario $actor, int $page): array
    {
        $this->authorize($actor);
        $today = Carbon::today(config('app.display_timezone', 'America/Lima'))->toDateString();
        $rows = PersonalContrato::query()->with('personal:id,nombre_completo')
            ->where('estado', PersonalContrato::ESTADO_PREPARACION)
            ->whereNotNull('signed_at')->whereNotNull('signed_contract_path')->where('signed_contract_path', '<>', '')
            ->whereDate('fecha_inicio', '<=', $today)
            ->where(fn ($query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $today))
            ->orderBy('fecha_inicio')->orderBy('id')->simplePaginate(30, ['*'], 'page', $page);

        return [
            'contratos' => $rows->getCollection()->map(fn (PersonalContrato $contract): array => [
                ...$this->contractLabel($contract),
                'revision' => $this->activationRevision($contract),
                'pdf_url' => PermissionMatrix::userCanDirect($actor, 'personal', 'descargar_documentos')
                    ? route('personal.contratos.signed.download', [$contract->personal_id, $contract->id]) : null,
            ])->all(),
            'mas' => $rows->hasMorePages(),
        ];
    }

    public function activateReady(Usuario $actor, array $selection): array
    {
        $results = [];
        foreach ($selection as $item) {
            $this->authorize($actor);
            $contract = PersonalContrato::query()->with('personal')->find($item['id']);
            $state = 'REVISAR';
            $message = 'El contrato cambió. Vuelve a revisar su periodo y PDF.';
            if ($contract?->personal && hash_equals($this->activationRevision($contract), $item['revision'])) {
                try {
                    $this->contracts->activatePreparedSignedContract($contract->personal, $contract, $actor, $item['revision']);
                    $state = 'ACTIVO';
                    $message = 'Contrato firmado activado.';
                } catch (ValidationException $exception) {
                    $message = (string) collect($exception->errors())->flatten()->first();
                } catch (\Throwable $exception) {
                    Log::warning('contratos.firmados_activacion_fallo', ['contrato_id' => $contract->id, 'exception' => $exception::class]);
                    $message = 'Consulta el estado del contrato y vuelve a intentar.';
                }
            }
            $results[] = ['id' => $item['id'], 'estado' => $state, 'mensaje' => $message];
        }

        return $results;
    }

    public function preview(Usuario $actor, string $lot): array
    {
        $batch = $this->lot($actor, $lot);
        $rows = $this->rows($lot)->orderBy('orden')->get();
        $contracts = PersonalContrato::query()->with('personal:id,nombre_completo')
            ->whereIn('id', $rows->pluck('contrato_id')->filter()->unique())->get()->keyBy('id');

        return [
            'id' => $lot, 'expires_at' => $batch->expires_at, 'revision' => $this->revision($rows),
            'archivos' => $rows->map(function ($row) use ($contracts): array {
                $contract = $contracts->get($row->contrato_id);

                return [
                    'id' => $row->id, 'nombre' => $row->nombre, 'estado' => $row->estado, 'mensaje' => $row->mensaje,
                    'contrato' => $contract ? $this->contractLabel($contract) : null,
                    'listo' => $row->estado === 'PENDIENTE' && $contract && $row->contrato_revision === $this->contractRevision($contract),
                ];
            })->all(),
        ];
    }

    public function confirm(Usuario $actor, string $lot, string $revision, array $ids): array
    {
        $this->lot($actor, $lot);
        abort_unless($this->rows($lot)->whereIn('id', $ids)->count() === count($ids), 404);
        $results = [];
        foreach ($ids as $id) {
            $results[] = $this->confirmOne($actor, $lot, $revision, $id);
        }

        return $results;
    }

    public function privatePdf(Usuario $actor, string $lot, string $id): object
    {
        $this->lot($actor, $lot);
        abort_unless(PermissionMatrix::userCanDirect($actor, 'personal', 'descargar_documentos'), 403);
        $row = $this->rows($lot)->where('id', $id)->first();
        abort_unless($row?->path && Storage::disk('local')->exists($row->path), 404);

        return $row;
    }

    private function confirmOne(Usuario $actor, string $lot, string $revision, string $id): array
    {
        $saved = null;
        $row = null;
        try {
            return DB::transaction(function () use ($actor, $lot, $revision, $id, &$saved, &$row): array {
                $this->lot($actor, $lot, lock: true);
                $rows = $this->rows($lot)->orderBy('orden')->get();
                $row = $rows->firstWhere('id', $id);
                abort_unless($row, 404);
                if (in_array($row->estado, self::CONFIRMADOS, true)) {
                    return ['id' => $id, 'estado' => $row->estado, 'mensaje' => $row->mensaje];
                }
                abort_unless(hash_equals($this->revision($rows), $revision), 409, 'El lote cambió. Vuelve a revisar las asociaciones.');

                $contract = PersonalContrato::query()->find($row->contrato_id);
                if (! $contract) {
                    $this->invalid('Asocia el archivo al contrato correcto antes de confirmar.');
                }
                $person = Personal::query()->lockForUpdate()->findOrFail($contract->personal_id);
                $contract = PersonalContrato::query()->where('personal_id', $person->id)->lockForUpdate()->findOrFail($contract->id);
                if ($row->contrato_revision !== $this->contractRevision($contract) || ! $this->contracts->canUploadSignedFileForContract($contract)) {
                    $this->invalid('El contrato cambió. Busca y revisa de nuevo su periodo.');
                }
                $disk = Storage::disk('local');
                if (! $row->path || ! $disk->exists($row->path) || ! hash_equals($row->sha256, (string) hash_file('sha256', $disk->path($row->path)))) {
                    $this->invalid('El archivo temporal no está disponible. Selecciona de nuevo el PDF.');
                }
                if (DB::table(self::ARCHIVOS)->where('sha256', $row->sha256)->whereIn('estado', self::CONFIRMADOS)->exists()) {
                    $this->invalid('El mismo PDF ya fue confirmado. Revisa el archivo y su destino.');
                }

                $file = new UploadedFile($disk->path($row->path), $row->nombre, 'application/pdf', null, true);
                $saved = $this->contracts->uploadSignedFileForContract($person, $contract, $file, $actor);
                $state = $saved->estado === PersonalContrato::ESTADO_PREPARACION ? 'FIRMADO_FUTURO' : 'GUARDADO';

                return $this->result($lot, $row, $state, $state === 'GUARDADO'
                    ? 'PDF guardado en el contrato confirmado.' : 'PDF guardado. Podrás activar el contrato al llegar su fecha de inicio.');
            });
        } catch (ValidationException $exception) {
            return $this->result($lot, $row, 'REVISAR', (string) collect($exception->errors())->flatten()->first());
        } catch (\Throwable $exception) {
            if ($saved && ! PersonalContrato::query()->where('signed_contract_path', $saved->signed_contract_path)->exists()) {
                Storage::disk('local')->delete($saved->signed_contract_path);
            }
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            if ($row && DB::table(self::ARCHIVOS)->where('confirmed_sha256', $row->sha256)->exists()) {
                return $this->result($lot, $row, 'REVISAR', 'El mismo PDF ya fue confirmado. Revisa el archivo y su destino.');
            }
            Log::warning('contratos.firmados_lote_fallo', ['lote_id' => $lot, 'archivo_id' => $id, 'exception' => $exception::class]);

            abort_unless($row, 503, 'El lote no está disponible. Intenta de nuevo.');

            return $this->result($lot, $row, 'PENDIENTE', 'No se pudo completar la carga. Revisa el estado y vuelve a intentar.');
        }
    }

    private function result(string $lot, object $expected, string $state, string $message): array
    {
        $query = $this->rows($lot)->where('id', $expected->id);
        (clone $query)->whereNotIn('estado', self::CONFIRMADOS)
            ->where('contrato_id', $expected->contrato_id)->where('contrato_revision', $expected->contrato_revision)
            ->update([
                'estado' => $state, 'mensaje' => Str::limit($message, 250), 'updated_at' => now(),
                'confirmed_sha256' => in_array($state, self::CONFIRMADOS, true) ? $expected->sha256 : null,
            ]);
        $row = $query->first();
        abort_unless($row, 409, 'El lote cambió. Vuelve a revisarlo.');

        return ['id' => $row->id, 'estado' => $row->estado, 'mensaje' => $row->mensaje];
    }

    private function authorize(Usuario $actor, bool $currentRead = false): void
    {
        abort_unless(PermissionMatrix::allowsDirect(PersonalFichaAccess::permissionsFor($actor, $currentRead), 'personal', 'subir_contrato_firmado'), 403);
    }

    private function lot(Usuario $actor, string $id, bool $lock = false): object
    {
        $this->authorize($actor);
        $lot = DB::table(self::LOTES)->where('id', $id)->where('usuario_id', $actor->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($lot, 404);
        if ($lock) {
            $this->authorize($actor, currentRead: true);
        }
        abort_if($lot->expires_at <= now()->toDateTimeString(), 410, 'El lote venció. Abre un lote nuevo.');

        return $lot;
    }

    private function rows(string $lot): Builder
    {
        return DB::table(self::ARCHIVOS)->where('lote_id', $lot);
    }

    private function revision(iterable $rows): string
    {
        return hash('sha256', json_encode(collect($rows)->map(fn ($row) => [
            $row->id, $row->sha256, $row->contrato_id, $row->contrato_revision,
        ])->values()->all(), JSON_THROW_ON_ERROR));
    }

    private function contractRevision(PersonalContrato $contract): string
    {
        return hash('sha256', json_encode([
            $this->contracts->signedTermsRevision($contract), $contract->estado, $contract->updated_at,
        ], JSON_THROW_ON_ERROR));
    }

    private function activationRevision(PersonalContrato $contract): string
    {
        return $this->contracts->signedTermsRevision($contract);
    }

    private function contractLabel(PersonalContrato $contract): array
    {
        return ['id' => $contract->id, 'trabajador' => $contract->personal?->nombre_completo,
            'numero' => $contract->contrato_numero, 'periodo' => $this->contracts->contractPeriodLabel($contract)];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['archivo' => $message]);
    }
}
