<?php

namespace App\Modules\Epps\Support;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final readonly class EppSignaturePayload
{
    public const MAX_BYTES = 1048576;

    private function __construct(
        public array $data,
        public ?string $signaturePath = null,
        public ?string $signatureSha256 = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $validator = Validator::make($data, self::rules());
        if ($validator->fails()) {
            throw new InvalidArgumentException($validator->errors()->first());
        }

        return new self($validator->validated() + ['tipo_firmante' => 'TRABAJADOR']);
    }

    public static function rules(): array
    {
        return [
            'submission_uuid' => ['required', 'uuid'],
            'personal_id' => ['required', 'uuid'],
            'review_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'review_version' => ['required', 'integer', 'min:1'],
            'firma_data_url' => ['required', 'string', 'max:1398126'],
            'tipo_firmante' => ['sometimes', 'in:TRABAJADOR,TESTIGO_AUTORIZADO'],
            'testigo_usuario_id' => ['nullable', 'uuid', 'required_if:tipo_firmante,TESTIGO_AUTORIZADO'],
            'motivo_excepcion' => ['nullable', 'string', 'max:1000', 'required_if:tipo_firmante,TESTIGO_AUTORIZADO'],
            'ink' => ['required', 'array:point_count,bounds'],
            'ink.point_count' => ['required', 'integer', 'min:10', 'max:1000000'],
            'ink.bounds' => ['required', 'array:min_x,min_y,max_x,max_y'],
            'ink.bounds.min_x' => ['required', 'numeric', 'min:0'],
            'ink.bounds.min_y' => ['required', 'numeric', 'min:0'],
            'ink.bounds.max_x' => ['required', 'numeric', 'max:2400'],
            'ink.bounds.max_y' => ['required', 'numeric', 'max:2400'],
        ];
    }

    public function withPreparedSignature(string $path, string $sha256): self
    {
        return new self($this->data, $path, $sha256);
    }

    /** Decode the real image, check ink and strip ancillary/trailing data on normalization. */
    public function pngBytes(): string
    {
        $url = $this->data['firma_data_url'];
        if (! str_starts_with($url, 'data:image/png;base64,')) {
            throw new InvalidArgumentException('La firma debe ser una imagen PNG.');
        }
        $bytes = base64_decode(substr($url, 22), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('La firma PNG admite hasta 1 MiB.');
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] < 300 || $size[1] < 300 || $size[0] > 2400 || $size[1] > 2400) {
            throw new InvalidArgumentException('La firma PNG debe medir entre 300 y 2400 pixeles por lado.');
        }
        $bounds = $this->data['ink']['bounds'];
        if ($bounds['max_x'] > $size[0] || $bounds['max_y'] > $size[1] || $bounds['max_x'] - $bounds['min_x'] < 20 || $bounds['max_y'] - $bounds['min_y'] < 10) {
            throw new InvalidArgumentException('Dibuja la firma completa dentro del recuadro.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new InvalidArgumentException('No se pudo leer la imagen PNG de la firma.');
        }
        try {
            $points = 0;
            $minX = $size[0];
            $minY = $size[1];
            $maxX = $maxY = 0;
            for ($y = 0; $y < $size[1]; $y++) {
                for ($x = 0; $x < $size[0]; $x++) {
                    $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    if ($pixel['alpha'] < 120 && min($pixel['red'], $pixel['green'], $pixel['blue']) < 235) {
                        $points++;
                        $minX = min($minX, $x);
                        $maxX = max($maxX, $x);
                        $minY = min($minY, $y);
                        $maxY = max($maxY, $y);
                    }
                }
            }
            if ($points < 20 || $maxX - $minX < 20 || $maxY - $minY < 10) {
                throw new InvalidArgumentException('La imagen no contiene una firma suficiente.');
            }
            imagesavealpha($image, true);
            ob_start();
            try {
                imagepng($image);
                $normalized = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if ($normalized === false || $normalized === '' || strlen($normalized) > self::MAX_BYTES) {
                throw new InvalidArgumentException('La firma normalizada admite hasta 1 MiB.');
            }

            return $normalized;
        } finally {
            imagedestroy($image);
        }
    }
}
