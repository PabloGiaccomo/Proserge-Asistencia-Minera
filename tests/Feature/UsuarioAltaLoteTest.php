<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\Seguridad\Services\UsuarioAltaLoteService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsuarioAltaLoteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_operator_can_submit_a_batch_from_the_private_form(): void
    {
        $this->signIn($this->operator());

        $this->get('/usuarios/crear/varios')->assertOk();

        $payload = $this->batch($this->role(), [$this->row($this->worker())]);
        $this->post('/usuarios/lotes', $payload)
            ->assertRedirect('/usuarios/lotes/'.$payload['lote_id']);
    }

    public function test_batch_creates_accounts_and_keeps_the_credentials_private_for_fifteen_minutes(): void
    {
        $operator = $this->operator();
        $this->signIn($operator);
        $role = $this->role();
        $workers = [$this->worker(), $this->worker()];
        $payload = $this->batch($role, array_map($this->row(...), $workers));
        $payload['estado'] = 'INACTIVO';
        $resultUrl = route('usuarios.lotes.show', $payload['lote_id']);

        $this->post(route('usuarios.lotes.store'), $payload)->assertRedirect($resultUrl);
        $batch = $this->savedBatch($payload['lote_id']);
        self::assertSame($operator->id, $batch->actor_id);
        self::assertSame($role->id, $batch->rol_id);
        self::assertSame(900, Carbon::parse($batch->expires_at)->timestamp - Carbon::parse($batch->created_at)->timestamp);
        $credentials = $this->credentials($batch);
        self::assertCount(2, $credentials);
        $expectedExpiry = Carbon::parse($batch->expires_at)->timezone(config('app.display_timezone'))->format('d/m/Y H:i');
        self::assertSame($expectedExpiry, app(UsuarioAltaLoteService::class)->result($operator, $payload['lote_id'])['vence']);
        self::assertSame($expectedExpiry, app(UsuarioAltaLoteService::class)->recent($operator)[0]['vence']);

        $result = $this->get($resultUrl)->assertOk()->assertSee($payload['lote_id']);
        self::assertTrue($result->headers->hasCacheControlDirective('no-store'));
        $body = (string) $result->getContent();
        $ids = [];
        $passwords = [];
        foreach ($workers as $index => $worker) {
            $email = $payload['filas'][$index]['email'];
            $user = Usuario::query()->where('personal_id', $worker->id)->sole();
            $row = collect($credentials)->firstWhere('usuario', $email);
            self::assertIsArray($row, 'No hay una fila de credenciales para la cuenta creada.');
            $password = $row['contrasena'];

            self::assertSame($email, $user->email);
            self::assertSame($role->id, $user->rol_id);
            self::assertSame('INACTIVO', $user->estado);
            self::assertSame($worker->estado, $worker->fresh()->estado);
            self::assertSame($worker->nombre_completo, $row['trabajador']);
            self::assertTrue(is_string($password) && strlen($password) >= 12, 'La clave temporal es demasiado corta.');
            self::assertTrue(Hash::check($password, $user->password), 'La clave temporal no verifica contra la cuenta.');
            self::assertFalse(str_contains($password, $worker->dni), 'La clave temporal contiene el DNI.');
            self::assertFalse(str_contains($batch->credenciales, $password), 'El lote contiene una clave sin cifrar.');
            self::assertFalse(str_contains(serialize(session()->all()), $password), 'La sesión contiene una clave temporal.');
            self::assertFalse(str_contains($body, $worker->dni), 'El resultado muestra el DNI.');
            self::assertTrue(str_contains($body, e($worker->nombre_completo)));
            self::assertTrue(str_contains($body, e($email)));
            self::assertTrue(str_contains($body, e($password)), 'El titular no puede ver la clave temporal.');
            $ids[] = $user->id;
            $passwords[] = $password;
        }

        self::assertCount(2, array_unique($passwords), 'Las cuentas comparten una clave temporal.');
        self::assertEqualsCanonicalizing($ids, json_decode($batch->usuario_ids, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_export_is_post_only_and_writes_credential_cells_as_literal_strings(): void
    {
        $this->signIn($this->operator());
        $worker = $this->worker('=1+2');
        $payload = $this->batch($this->role(), [$this->row($worker)]);
        $this->post(route('usuarios.lotes.store'), $payload)->assertRedirect();
        $credentials = $this->credentials($this->savedBatch($payload['lote_id']))[0];
        $exportUrl = route('usuarios.lotes.export', $payload['lote_id']);

        $this->get($exportUrl)->assertMethodNotAllowed();
        $export = $this->post($exportUrl)->assertOk()->assertDownload();
        self::assertTrue($export->headers->hasCacheControlDirective('no-store'));
        $path = tempnam(sys_get_temp_dir(), 'alta-lote-test-');
        try {
            file_put_contents($path, $export->streamedContent());
            $workbook = (new Xlsx)->load($path);
            $sheet = $workbook->getActiveSheet();
            self::assertSame(2, $sheet->getHighestDataRow());
            self::assertSame('C', $sheet->getHighestDataColumn());
            foreach (['A2' => 'trabajador', 'B2' => 'usuario', 'C2' => 'contrasena'] as $address => $field) {
                $cell = $sheet->getCell($address);
                self::assertSame(DataType::TYPE_STRING, $cell->getDataType());
                self::assertTrue($cell->getValue() === $credentials[$field], 'La celda no conserva el texto original.');
            }
            $workbook->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_a_repeat_submission_reuses_the_batch_but_a_changed_payload_conflicts(): void
    {
        $this->signIn($this->operator());
        $worker = $this->worker();
        $payload = $this->batch($this->role(), [$this->row($worker)]);
        $storeUrl = route('usuarios.lotes.store');
        $resultUrl = route('usuarios.lotes.show', $payload['lote_id']);

        $this->post($storeUrl, $payload)->assertRedirect($resultUrl);
        $first = $this->savedBatch($payload['lote_id']);
        $this->post($storeUrl, $payload)->assertRedirect($resultUrl);
        $replayed = $this->savedBatch($payload['lote_id']);

        self::assertSame($first->usuario_ids, $replayed->usuario_ids);
        self::assertSame($first->expires_at, $replayed->expires_at);
        self::assertTrue(hash_equals($first->credenciales, $replayed->credenciales), 'El reintento reemplazó las credenciales.');
        $sameData = $payload;
        $sameData['filas'][0] = array_reverse($sameData['filas'][0], true);
        $this->post($storeUrl, $sameData)->assertRedirect($resultUrl);
        $payload['filas'][0]['email'] = Str::uuid().'@test.local';
        $this->post($storeUrl, $payload)->assertConflict();
        self::assertSame(1, Usuario::query()->where('personal_id', $worker->id)->count());
        $this->assertDatabaseMissing('usuarios', ['email' => $payload['filas'][0]['email']]);
    }

    public function test_only_the_originating_operator_can_reveal_or_export_the_batch(): void
    {
        $originator = $this->operator();
        $this->signIn($originator);
        $payload = $this->batch($this->role(), [$this->row($this->worker())]);
        $this->post(route('usuarios.lotes.store'), $payload)->assertRedirect();
        $resultUrl = route('usuarios.lotes.show', $payload['lote_id']);
        $exportUrl = route('usuarios.lotes.export', $payload['lote_id']);

        $this->signIn($this->operator());
        self::assertContains($this->get($resultUrl)->status(), [403, 404]);
        self::assertContains($this->post($exportUrl)->status(), [403, 404]);

        $this->signIn($originator);
        $originator->rol->update(['permisos' => PermissionCatalog::matrixFromSelections(['usuarios' => ['crear']])]);
        $this->get($resultUrl)->assertForbidden();
        $this->post($exportUrl)->assertForbidden();
    }

    public function test_a_batch_is_expired_at_fifteen_minutes_and_the_account_remains(): void
    {
        $this->freezeSecond();
        $this->signIn($this->operator());
        $payload = $this->batch($this->role(), [$this->row($this->worker())]);
        $this->post(route('usuarios.lotes.store'), $payload)->assertRedirect();
        $resultUrl = route('usuarios.lotes.show', $payload['lote_id']);
        $exportUrl = route('usuarios.lotes.export', $payload['lote_id']);

        $this->travel(15)->minutes();
        $this->get($resultUrl)->assertGone();
        $this->post($exportUrl)->assertGone();
        app(UsuarioAltaLoteService::class)->purgeExpiredCredentials();
        self::assertNull($this->savedBatch($payload['lote_id'])->credenciales);
        $this->assertDatabaseHas('usuarios', ['personal_id' => $payload['filas'][0]['personal_id']]);
    }

    public function test_new_accounts_notify_security_once_without_sending_passwords(): void
    {
        $actor = $this->operator();
        $this->signIn($actor);
        $payload = $this->batch($this->role(), [$this->row($this->worker())]);
        $contexts = [];
        $levels = [];
        $transactionLevel = DB::transactionLevel();
        $this->mock(NotificationService::class)->shouldReceive('emit')->once()
            ->withArgs(function (string $type, array $context) use (&$contexts, &$levels): bool {
                $levels[] = DB::transactionLevel();
                $contexts[] = $context;

                return $type === 'usuario_creado' && $context['entity_type'] === 'usuario';
            })->andReturnNull();

        $this->post(route('usuarios.lotes.store'), $payload)->assertRedirect();
        $this->post(route('usuarios.lotes.store'), $payload)->assertRedirect();
        self::assertSame([$transactionLevel], $levels, 'Las cuentas se deben guardar antes de emitir el aviso.');
        $password = $this->credentials($this->savedBatch($payload['lote_id']))[0]['contrasena'];
        self::assertSame($actor->id, $contexts[0]['actor_user_id']);
        self::assertFalse(str_contains(json_encode($contexts, JSON_THROW_ON_ERROR), $password));
    }

    public function test_notification_failure_keeps_the_created_accounts_and_result(): void
    {
        $this->signIn($this->operator());
        $payload = $this->batch($this->role(), [$this->row($this->worker())]);
        $this->mock(NotificationService::class)->shouldReceive('emit')->once()->andThrow(new \RuntimeException('Synthetic notification failure'));

        $this->post(route('usuarios.lotes.store'), $payload)->assertRedirect();

        $this->assertDatabaseHas('usuarios', ['personal_id' => $payload['filas'][0]['personal_id']]);
        self::assertCount(1, $this->credentials($this->savedBatch($payload['lote_id'])));
    }

    #[DataProvider('singlePermissions')]
    public function test_create_and_assign_permissions_are_both_required(string $permission): void
    {
        $this->signIn($this->operator([$permission]));
        $payload = $this->batch($this->role(), [$this->row($this->worker())]);

        $this->get(route('usuarios.lotes.create'))->assertForbidden();
        $this->postJson(route('usuarios.lotes.store'), $payload)->assertForbidden();
        $this->assertDatabaseMissing('usuarios', ['personal_id' => $payload['filas'][0]['personal_id']]);
        $this->assertDatabaseMissing('usuario_alta_lotes', ['id' => $payload['lote_id']]);
    }

    public static function singlePermissions(): array
    {
        return ['solo crear' => ['crear'], 'solo asignar' => ['asignar']];
    }

    #[DataProvider('invalidBatches')]
    public function test_an_invalid_row_or_role_rejects_the_entire_batch(string $problem): void
    {
        $operator = $this->operator();
        $this->signIn($operator);
        $role = $this->role(state: $problem === 'inactive role' ? 'INACTIVO' : 'ACTIVO');
        $valid = $this->row($this->worker());
        $invalid = $this->row($this->worker());

        match ($problem) {
            'linked worker' => $operator->update(['personal_id' => $invalid['personal_id']]),
            'existing email' => $invalid['email'] = strtoupper($operator->email),
            'repeated email' => $invalid['email'] = strtoupper($valid['email']),
            'repeated worker' => $invalid['personal_id'] = $valid['personal_id'],
            default => null,
        };
        $payload = $this->batch($role, [$valid, $invalid]);

        $this->postJson(route('usuarios.lotes.store'), $payload)->assertUnprocessable();
        $this->assertDatabaseMissing('usuarios', ['personal_id' => $valid['personal_id']]);
        $this->assertDatabaseMissing('usuario_alta_lotes', ['id' => $payload['lote_id']]);
    }

    public static function invalidBatches(): array
    {
        return [
            'trabajador vinculado' => ['linked worker'],
            'correo existente' => ['existing email'],
            'correo repetido' => ['repeated email'],
            'trabajador repetido' => ['repeated worker'],
            'rol inactivo' => ['inactive role'],
        ];
    }

    public function test_batch_requires_between_one_and_twenty_five_rows(): void
    {
        $this->signIn($this->operator());
        $role = $this->role();
        $storeUrl = route('usuarios.lotes.store');
        $this->postJson($storeUrl, $this->batch($role, []))->assertUnprocessable()->assertJsonValidationErrors('filas');

        $rows = [];
        for ($i = 0; $i < 26; $i++) {
            $rows[] = $this->row($this->worker());
        }
        $payload = $this->batch($role, $rows);
        $this->postJson($storeUrl, $payload)->assertUnprocessable()->assertJsonValidationErrors('filas');
        $this->assertDatabaseMissing('usuarios', ['personal_id' => $rows[0]['personal_id']]);
        $this->assertDatabaseMissing('usuario_alta_lotes', ['id' => $payload['lote_id']]);
    }

    private function savedBatch(string $id): object
    {
        return DB::table('usuario_alta_lotes')->where('id', $id)->sole();
    }

    private function credentials(object $batch): array
    {
        return json_decode(Crypt::decryptString($batch->credenciales), true, flags: JSON_THROW_ON_ERROR);
    }

    private function operator(array $actions = ['crear', 'asignar']): Usuario
    {
        return Usuario::query()->create([
            'id' => (string) Str::uuid(),
            'rol_id' => $this->role($actions)->id,
            'email' => Str::uuid().'@test.local',
            'password' => 'synthetic-password',
            'estado' => 'ACTIVO',
        ]);
    }

    private function role(array $actions = [], string $state = 'ACTIVO'): Rol
    {
        return Rol::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => 'PRUEBA_LOTE_'.Str::upper(Str::random(12)),
            'permisos' => PermissionCatalog::matrixFromSelections(['usuarios' => $actions]),
            'estado' => $state,
        ]);
    }

    private function worker(?string $name = null): Personal
    {
        return Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => $name ?? 'Trabajador sintético '.Str::upper(Str::random(8)),
            'puesto' => 'Operario',
            'qr_code' => (string) Str::uuid(),
            'estado' => 'PENDIENTE',
        ]);
    }

    private function row(Personal $worker): array
    {
        return ['personal_id' => $worker->id, 'email' => Str::uuid().'@test.local'];
    }

    private function batch(Rol $role, array $rows): array
    {
        return ['lote_id' => (string) Str::uuid(), 'rol_id' => $role->id, 'filas' => $rows];
    }

    private function signIn(Usuario $operator): void
    {
        $this->withSession([
            'auth_token' => 'test-token',
            'user_id' => $operator->id,
            'user' => ['id' => $operator->id],
        ]);
    }
}
