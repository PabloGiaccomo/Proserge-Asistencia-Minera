<?php

namespace App\Modules\Personal\Services;

use Illuminate\Support\Facades\Storage;

class PersonalMinaImportPreviewStore
{
    private const DIRECTORY = 'habilitacion-minera/import-previews';

    private const MAX_AGE_SECONDS = 7200;

    public function store(array $preview, string $userId): array
    {
        $token = (string) ($preview['token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('La vista previa no tiene un identificador valido.');
        }

        $encoded = gzencode(serialize($preview), 6);
        if ($encoded === false || !Storage::disk('local')->put($this->path($userId, $token), $encoded)) {
            throw new \RuntimeException('No se pudo guardar la vista previa en el almacenamiento privado.');
        }

        $this->deleteExpired($userId, $token);

        return $this->forDisplay($preview);
    }

    public function retrieve(string $token, string $userId): ?array
    {
        $path = $this->path($userId, $token);
        $disk = Storage::disk('local');
        if (!$disk->exists($path) || time() - $disk->lastModified($path) > self::MAX_AGE_SECONDS) {
            $this->delete($token, $userId);

            return null;
        }

        $contents = $disk->get($path);
        $decoded = gzdecode($contents);
        if ($decoded === false) {
            return null;
        }

        $preview = @unserialize($decoded, ['allowed_classes' => false]);

        return is_array($preview) && ($preview['token'] ?? null) === $token ? $preview : null;
    }

    public function delete(string $token, string $userId): void
    {
        if ($token !== '') {
            Storage::disk('local')->delete($this->path($userId, $token));
        }
    }

    private function forDisplay(array $preview): array
    {
        unset($preview['rows'], $preview['catalogo_examenes']);

        return $preview;
    }

    private function deleteExpired(string $userId, string $currentToken): void
    {
        $disk = Storage::disk('local');
        $directory = self::DIRECTORY . '/' . hash('sha256', $userId);
        foreach ($disk->files($directory) as $file) {
            if ($file === $this->path($userId, $currentToken)) {
                continue;
            }
            if (time() - $disk->lastModified($file) > self::MAX_AGE_SECONDS) {
                $disk->delete($file);
            }
        }
    }

    private function path(string $userId, string $token): string
    {
        return self::DIRECTORY . '/' . hash('sha256', $userId) . '/' . hash('sha256', $token) . '.preview.gz';
    }
}
