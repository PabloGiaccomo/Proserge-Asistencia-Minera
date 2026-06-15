<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_puestos')) {
            Schema::create('personal_puestos', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('nombre', 191)->unique();
                $table->text('funciones')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('personal') && !Schema::hasColumn('personal', 'puesto_id')) {
            Schema::table('personal', function (Blueprint $table): void {
                $table->uuid('puesto_id')->nullable()->after('puesto');
                $table->foreign('puesto_id')->references('id')->on('personal_puestos')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('personal') || !Schema::hasColumn('personal', 'puesto_id')) {
            return;
        }

        DB::table('personal')
            ->whereNotNull('puesto')
            ->where('puesto', '!=', '')
            ->select('puesto')
            ->distinct()
            ->orderBy('puesto')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $nombre = mb_substr(trim((string) $row->puesto), 0, 191);
                    if ($nombre === '') {
                        continue;
                    }

                    $existingId = DB::table('personal_puestos')->where('nombre', $nombre)->value('id');
                    $puestoId = $existingId ?: (string) Str::uuid();

                    if (!$existingId) {
                        DB::table('personal_puestos')->insert([
                            'id' => $puestoId,
                            'nombre' => $nombre,
                            'funciones' => null,
                            'activo' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('personal')
                        ->where('puesto', $row->puesto)
                        ->whereNull('puesto_id')
                        ->update([
                            'puesto_id' => $puestoId,
                            'puesto' => $nombre,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('personal') && Schema::hasColumn('personal', 'puesto_id')) {
            Schema::table('personal', function (Blueprint $table): void {
                try {
                    $table->dropForeign(['puesto_id']);
                } catch (Throwable) {
                    //
                }
                $table->dropColumn('puesto_id');
            });
        }

        Schema::dropIfExists('personal_puestos');
    }
};
