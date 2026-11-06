<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Models\PersonalIngreso;
use App\Models\PersonalIngresoArchivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalArchivoVersionService
{
    public function store(UploadedFile $file, string $directory, string $name, string $disk): string
    {
        $this->trackNew(trim($directory, '/').'/'.$name);
        $path = $file->storeAs($directory, $name, $disk);
        if (! $path || ! Storage::disk($disk)->exists($path)) {
            throw ValidationException::withMessages(['documentos' => 'No se pudo guardar el archivo. Selecciónalo nuevamente.']);
        }

        return $path;
    }

    public function trackNew(string $path): void
    {
        // Register against each open transaction so an outer rollback also cleans
        // files created inside a savepoint that has already succeeded.
        foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback(fn () => $this->discardUnreferenced($path));
            }
        }
    }

    public function archiveCurrent(Model $owner, string $type, ?string $actor, string $origin): void
    {
        $isFicha = $owner instanceof PersonalFicha;
        $model = $isFicha ? PersonalFichaArchivo::class : PersonalIngresoArchivo::class;
        $foreignKey = $isFicha ? 'personal_ficha_id' : 'personal_ingreso_id';
        $files = $model::query()->where($foreignKey, $owner->id)->where('tipo', $type)->lockForUpdate()->get();
        $pointer = $type === 'huella' ? $owner->getRawOriginal('huella_path') : null;
        if ($pointer && ! $files->contains('path', $pointer)) {
            $files->push(new $model(['tipo' => $type, 'path' => $pointer]));
        }
        foreach ($files as $file) {
            $disk = Storage::disk('local');
            $exists = $file->path && $disk->exists($file->path);
            $digest = $exists ? hash_file('sha256', $disk->path($file->path)) : null;
            DB::table('personal_archivo_versiones')->insert([
                'id' => (string) Str::uuid(), 'owner_type' => $isFicha ? 'FICHA' : 'INGRESO', 'owner_id' => $owner->id,
                'archivo_id' => $file->id, 'tipo' => $type, 'path' => $file->path,
                'sha256' => $digest ?: null, 'metadata' => Crypt::encryptString(json_encode([
                    'nombre_original' => $file->nombre_original, 'mime' => $file->mime, 'size' => $file->size,
                    'uploaded_by_usuario_id' => $file->uploaded_by_usuario_id,
                    'uploaded_by_public' => $file->uploaded_by_public, 'missing_file' => ! $exists,
                ], JSON_THROW_ON_ERROR)),
                'actor_usuario_id' => $actor, 'origen' => $origin, 'created_at' => now(),
            ]);
            if ($file->exists) {
                $file->delete();
            }
        }
    }

    private function discardUnreferenced(string $path): void
    {
        try {
            $referenced = PersonalFichaArchivo::query()->where('path', $path)->exists()
                || PersonalIngresoArchivo::query()->where('path', $path)->exists()
                || PersonalFicha::query()->where('huella_path', $path)->exists()
                || PersonalIngreso::query()->where('huella_path', $path)->exists()
                || DB::table('personal_archivo_versiones')->where('path', $path)->exists();
            if (! $referenced) {
                Storage::disk('local')->delete($path);
            }
        } catch (\Throwable $exception) {
            Log::warning('personal.archivo_limpieza_pendiente', ['archivo_hash' => hash('sha256', $path), 'exception' => $exception::class]);
        }
    }
}
