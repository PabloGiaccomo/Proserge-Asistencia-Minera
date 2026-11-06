<?php

namespace App\Modules\Seguridad\Services;

use App\Models\Personal;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UsuarioAltaLoteService
{
    public const MAX_ROWS = 25;

    private const RESULT_MINUTES = 15;

    private const TABLE = 'usuario_alta_lotes';

    public function __construct(private readonly NotificationService $notifications) {}

    public static function canCreate(?Usuario $actor): bool
    {
        return $actor !== null
            && strtoupper((string) ($actor->estado ?? 'ACTIVO')) === 'ACTIVO'
            && PermissionMatrix::userCanDirect($actor, 'usuarios', 'crear')
            && PermissionMatrix::userCanDirect($actor, 'usuarios', 'asignar');
    }

    public function create(Usuario $actor, array $data): string
    {
        $this->assertCurrentAccess($actor);
        $lotId = strtolower($data['lote_id']);
        $roleId = strtolower($data['rol_id']);
        $state = $data['estado'] ?? 'ACTIVO';
        $rows = array_map(fn (array $row): array => [
            'personal_id' => $row['personal_id'],
            'email' => $row['email'],
        ], $data['filas']);
        $fingerprint = hash('sha256', json_encode([$roleId, $state, $rows], JSON_THROW_ON_ERROR));
        $hasState = Schema::hasColumn('usuarios', 'estado');

        try {
            $result = DB::transaction(function () use ($actor, $lotId, $roleId, $state, $rows, $fingerprint, $hasState): array {
                DB::table(self::TABLE)->insertOrIgnore([
                    'id' => $lotId,
                    'actor_id' => $actor->id,
                    'rol_id' => $roleId,
                    'estado' => $state,
                    'solicitud_hash' => $fingerprint,
                    'created_at' => now(),
                    'expires_at' => now()->addMinutes(self::RESULT_MINUTES),
                ]);

                $lot = DB::table(self::TABLE)->where('id', $lotId)->where('actor_id', $actor->id)->lockForUpdate()->first();
                abort_unless($lot, 404);
                if ($lot->usuario_ids !== null) {
                    $this->assertCurrentAccess($actor, lock: true);
                    abort_unless(hash_equals($lot->solicitud_hash, $fingerprint), 409, 'Este lote ya se guardó con otros datos. Abre un lote nuevo.');
                    $this->assertAvailable($lot);

                    return ['id' => $lotId, 'created' => []];
                }

                $workerIds = array_column($rows, 'personal_id');
                $workers = Personal::query()->whereKey($workerIds)->orderBy('id')->lockForUpdate()
                    ->get(['id', 'nombre_completo', 'dni', 'numero_documento'])->keyBy('id');
                $this->assertCurrentAccess($actor, lock: true);
                $role = Rol::query()->sharedLock()->find($roleId);
                if (! $role || strtoupper((string) $role->estado) !== 'ACTIVO') {
                    throw ValidationException::withMessages(['rol_id' => 'Elige un rol activo para el lote.']);
                }
                if (! $hasState && $state !== 'ACTIVO') {
                    throw ValidationException::withMessages(['estado' => 'Elige Activo para crear estas cuentas.']);
                }

                $existing = Usuario::query()->where(function ($query) use ($workerIds, $rows): void {
                    $query->whereIn('personal_id', $workerIds)->orWhereIn('email', array_column($rows, 'email'));
                })->get(['personal_id', 'email']);
                $linked = $existing->pluck('personal_id')->filter()->flip();
                $emails = $existing->pluck('email')->map(fn (string $email): string => mb_strtolower($email))->flip();
                $errors = [];
                foreach ($rows as $index => $row) {
                    if (! $workers->has($row['personal_id'])) {
                        $errors["filas.$index.personal_id"] = 'El trabajador ya no está disponible en Personal.';
                    } elseif ($linked->has($row['personal_id'])) {
                        $errors["filas.$index.personal_id"] = 'Este trabajador ya tiene usuario.';
                    }
                    if ($emails->has($row['email'])) {
                        $errors["filas.$index.email"] = 'Este correo ya está registrado.';
                    }
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                $credentials = [];
                $userIds = [];
                foreach ($rows as $row) {
                    $worker = $workers->get($row['personal_id']);
                    $password = $this->generatePassword($worker);
                    $user = Usuario::query()->create([
                        'id' => (string) Str::uuid(),
                        'personal_id' => $worker->id,
                        'email' => $row['email'],
                        'password' => $password,
                        'rol_id' => $roleId,
                        ...($hasState ? ['estado' => $state] : []),
                    ]);
                    $userIds[] = $user->id;
                    $credentials[] = [
                        'trabajador' => $worker->nombre_completo,
                        'usuario' => $user->email,
                        'contrasena' => $password,
                    ];
                }

                $createdAt = now();
                DB::table(self::TABLE)->where('id', $lotId)->update([
                    'usuario_ids' => json_encode($userIds, JSON_THROW_ON_ERROR),
                    'credenciales' => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),
                    'created_at' => $createdAt,
                    'expires_at' => $createdAt->copy()->addMinutes(self::RESULT_MINUTES),
                ]);

                return ['id' => $lotId, 'created' => $userIds];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['filas' => 'Un correo o trabajador cambió mientras guardabas. Revisa las filas del lote.']);
        }

        if ($result['created'] !== []) {
            $this->notifyCreated($actor, $result['created'], $lotId);
        }

        return $result['id'];
    }

    public function result(Usuario $actor, string $lotId): array
    {
        $this->assertCurrentAccess($actor);
        $lot = DB::table(self::TABLE)->where('id', $lotId)->where('actor_id', $actor->id)->first();
        abort_unless($lot, 404);
        $this->assertAvailable($lot);

        return [
            'id' => $lot->id,
            'vence' => Carbon::parse($lot->expires_at)->timezone(config('app.display_timezone'))->format('d/m/Y H:i'),
            'filas' => json_decode(Crypt::decryptString($lot->credenciales), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    public function recent(Usuario $actor): array
    {
        return DB::table(self::TABLE)->where('actor_id', $actor->id)->where('expires_at', '>', now())
            ->whereNotNull('credenciales')->orderByDesc('created_at')->limit(5)
            ->get(['id', 'usuario_ids', 'created_at', 'expires_at'])
            ->map(fn ($lot): array => [
                'id' => $lot->id,
                'cantidad' => count(json_decode($lot->usuario_ids, true, flags: JSON_THROW_ON_ERROR)),
                'creado' => Carbon::parse($lot->created_at)->timezone(config('app.display_timezone'))->format('d/m/Y H:i'),
                'vence' => Carbon::parse($lot->expires_at)->timezone(config('app.display_timezone'))->format('d/m/Y H:i'),
            ])->all();
    }

    public function purgeExpiredCredentials(): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            return 0;
        }

        return DB::table(self::TABLE)->where('expires_at', '<=', now())->whereNotNull('credenciales')
            ->update(['credenciales' => null]);
    }

    private function assertCurrentAccess(Usuario $actor, bool $lock = false): void
    {
        $current = Usuario::query()->with([
            'rol' => fn ($query) => $query->when($lock, fn ($query) => $query->sharedLock()),
            'rolesAdicionales' => fn ($query) => $query->when($lock, fn ($query) => $query->sharedLock()),
        ])->when($lock, fn ($query) => $query->sharedLock())->find($actor->id);

        abort_unless(self::canCreate($current), 403);
    }

    private function notifyCreated(Usuario $actor, array $userIds, string $lotId): void
    {
        try {
            $audience = Usuario::query()->with(['rol', 'rolesAdicionales'])->get()
                ->filter(fn (Usuario $user): bool => strtoupper((string) ($user->estado ?? 'ACTIVO')) === 'ACTIVO'
                    && PermissionMatrix::userCanDirectAny($user, 'usuarios', ['crear', 'administrar']))
                ->pluck('id')->all();
            foreach ($userIds as $id) {
                $this->notifications->emit('usuario_creado', [
                    'actor_user_id' => $actor->id,
                    'entity_type' => 'usuario',
                    'entity_id' => $id,
                    'title' => 'Nuevo usuario creado',
                    'message' => 'Se creó una cuenta de usuario mediante alta múltiple.',
                    'target_user_ids' => $audience,
                    'dedupe_key' => 'usuario_creado:'.$id,
                    'priority' => 'high',
                    'category' => 'seguridad',
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('usuarios.alta_lote.aviso_fallido', [
                'lote_id' => $lotId,
                'actor_id' => $actor->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function assertAvailable(object $lot): void
    {
        abort_if(! $lot->credenciales || $lot->expires_at <= now()->toDateTimeString(), 410, 'Las credenciales de este lote vencieron.');
    }

    private function generatePassword(Personal $worker): string
    {
        $identity = $worker->nombre_completo."\0".($worker->numero_documento ?: $worker->dni);
        $digest = hash_hmac('sha256', $identity, random_bytes(32), true);

        return strtr(base64_encode(substr($digest, 0, 18)), '+/', '-_');
    }
}
