<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalFichaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalArchivoReplacementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_replacement_preserves_private_previous_version_and_records_actor(): void
    {
        [$worker, $ficha, $old, $actor] = $this->fixture();
        $updated = app(PersonalFichaService::class)->updateDocuments($worker, [
            'dni_vigente' => UploadedFile::fake()->createWithContent('nuevo.pdf', 'Documento nuevo de prueba'),
        ], $actor);
        $new = $updated->archivos->firstWhere('tipo', 'dni_vigente');
        self::assertNotSame($old->path, $new->path);
        Storage::disk('local')->assertExists($old->path);
        Storage::disk('local')->assertExists($new->path);
        $this->assertDatabaseHas('personal_archivo_versiones', [
            'owner_type' => 'FICHA', 'owner_id' => $ficha->id, 'archivo_id' => $old->id, 'path' => $old->path,
            'actor_usuario_id' => $actor->id, 'sha256' => hash('sha256', 'Documento anterior de prueba'),
        ]);
    }

    public function test_replacement_keeps_supported_legacy_paths_longer_than_255_characters(): void
    {
        [$worker, $ficha, $old, $actor] = $this->fixture();
        $longPath = 'personal_fichas/'.$ficha->id.'/'.str_repeat('a', 120).'/'.str_repeat('b', 120).'/previo.pdf';
        Storage::disk('local')->move($old->path, $longPath);
        $old->forceFill(['path' => $longPath])->save();
        app(PersonalFichaService::class)->updateDocuments($worker, [
            'dni_vigente' => UploadedFile::fake()->createWithContent('nuevo.pdf', 'Documento nuevo de prueba'),
        ], $actor);
        Storage::disk('local')->assertExists($longPath);
        $this->assertDatabaseHas('personal_archivo_versiones', ['owner_id' => $ficha->id, 'path' => $longPath]);
    }

    public function test_outer_rollback_restores_reference_and_cleans_only_new_bytes(): void
    {
        [$worker, $ficha, $old, $actor] = $this->fixture();
        try {
            DB::transaction(function () use ($worker, $actor): void {
                app(PersonalFichaService::class)->updateDocuments($worker, [
                    'dni_vigente' => UploadedFile::fake()->createWithContent('nuevo.pdf', 'Documento nuevo de prueba'),
                ], $actor);
                throw new \RuntimeException('Rollback sintético exterior');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('Rollback sintético exterior', $exception->getMessage());
        }
        self::assertSame($old->path, $ficha->archivos()->sole()->path);
        self::assertSame('Documento anterior de prueba', Storage::disk('local')->get($old->path));
        self::assertSame([$old->path], Storage::disk('local')->allFiles('personal_fichas/'.$ficha->id));
    }

    public function test_failed_reference_insert_preserves_original_and_cleans_new_upload(): void
    {
        [$worker, $ficha, $old, $actor] = $this->fixture();
        PersonalFichaArchivo::creating(fn () => throw new \RuntimeException('Fallo sintético INSERT'));
        try {
            app(PersonalFichaService::class)->updateDocuments($worker, [
                'dni_vigente' => UploadedFile::fake()->createWithContent('nuevo.pdf', 'Documento nuevo de prueba'),
            ], $actor);
            self::fail('El fallo debe revertir el reemplazo.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Fallo sintético INSERT', $exception->getMessage());
            self::assertSame($old->path, $ficha->archivos()->sole()->path);
            Storage::disk('local')->assertExists($old->path);
            self::assertSame([$old->path], Storage::disk('local')->allFiles('personal_fichas/'.$ficha->id));
        } finally {
            PersonalFichaArchivo::flushEventListeners();
        }
    }

    private function fixture(): array
    {
        Storage::fake('local');
        $role = Rol::query()->create([
            'id' => (string) Str::uuid(), 'nombre' => 'ARCHIVOS_'.Str::random(8), 'estado' => 'ACTIVO', 'permisos' => [],
        ]);
        $actor = Usuario::query()->create([
            'id' => (string) Str::uuid(), 'rol_id' => $role->id, 'email' => Str::uuid().'@test.local', 'password' => 'synthetic-password', 'estado' => 'ACTIVO',
        ]);
        $worker = Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'estado' => 'CESADO',
            'nombre_completo' => 'Trabajador de prueba', 'puesto' => 'Operario', 'qr_code' => (string) Str::uuid(),
        ]);
        $ficha = PersonalFicha::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $worker->id, 'estado' => 'APROBADO',
            'tipo_documento' => 'DNI', 'numero_documento' => $worker->dni, 'datos_json' => [],
        ]);
        $path = 'personal_fichas/'.$ficha->id.'/documentos/previo.pdf';
        Storage::disk('local')->put($path, 'Documento anterior de prueba');
        $old = PersonalFichaArchivo::query()->create([
            'id' => (string) Str::uuid(), 'personal_ficha_id' => $ficha->id, 'tipo' => 'dni_vigente',
            'path' => $path, 'nombre_original' => 'previo.pdf', 'mime' => 'application/pdf', 'size' => 28,
        ]);

        return [$worker, $ficha, $old, $actor];
    }
}
