<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class MiAsistenciaDatabaseErrorTest extends TestCase
{
    public function test_un_error_sql_no_expone_consulta_ni_datos_en_json(): void
    {
        config(['app.debug' => true]);
        Route::post('/mi-asistencia/__test-database-error', function () {
            throw new QueryException('mysql', 'insert into asistencia_encabezado values (?)', ['sensitive-value'], new PDOException('Unknown column destino_tipo'));
        });
        $this->postJson('/mi-asistencia/__test-database-error')
            ->assertStatus(500)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'ASISTENCIA_DATABASE_ERROR')
            ->assertDontSee('sensitive-value')
            ->assertDontSee('asistencia_encabezado')
            ->assertDontSee('destino_tipo');
    }
}
