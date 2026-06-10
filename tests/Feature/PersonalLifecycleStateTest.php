<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalContratoDatoService;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalLifecycleStateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_new_worker_does_not_start_as_active_even_if_requested(): void
    {
        $personal = app(PersonalService::class)->create([
            'dni' => '70000001',
            'tipo_documento' => 'DNI',
            'numero_documento' => '70000001',
            'nombre_completo' => 'Nuevo Pendiente',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'ACTIVO',
        ]);

        $this->assertSame('PENDIENTE_COMPLETAR_FICHA', $personal->estado);
        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'PENDIENTE_COMPLETAR_FICHA',
        ]);
    }

    public function test_pending_contract_worker_cannot_be_updated_to_active_without_current_signed_contract(): void
    {
        $personal = $this->createPersonal('FALTA_CONTRATO');

        DB::table('personal_contrato_datos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'signed_at' => '2026-05-01 08:00:00',
            'signed_contract_path' => 'personal_contratos/' . $personal->id . '/contrato_antiguo.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => 2,
            'estado' => 'ACTIVO',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => null,
            'activado_at' => '2026-06-01 09:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $updated = app(PersonalService::class)->update($personal->fresh(), [
            'dni' => '70000002',
            'tipo_documento' => 'DNI',
            'numero_documento' => '70000002',
            'nombre_completo' => 'Falta Contrato',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'ACTIVO',
        ]);

        $this->assertSame('FALTA_CONTRATO', $updated->estado);
    }

    public function test_signed_contract_upload_activates_pending_contract_worker(): void
    {
        Storage::fake('local');

        $user = Usuario::query()->findOrFail($this->createUser());
        $personal = $this->createPersonal('FALTA_CONTRATO');

        app(PersonalContratoDatoService::class)->uploadSignedContract(
            $personal,
            UploadedFile::fake()->create('contrato-firmado.pdf', 24, 'application/pdf'),
            $user,
        );

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'ACTIVO',
        ]);
    }

    private function createPersonal(string $estado): Personal
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => '70000002',
            'tipo_documento' => 'DNI',
            'numero_documento' => '70000002',
            'nombre_completo' => 'Falta Contrato',
            'puesto' => 'Operario',
            'ocupacion' => null,
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => null,
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Personal::query()->findOrFail($id);
    }

    private function createUser(): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_LIFECYCLE_' . Str::upper(Str::random(6)),
            'permisos' => json_encode([]),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }
}
