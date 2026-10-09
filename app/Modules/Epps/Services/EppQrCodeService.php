<?php

namespace App\Modules\Epps\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use InvalidArgumentException;

class EppQrCodeService
{
    public function svg(string $verificationUrl): string
    {
        $expectedHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = parse_url($verificationUrl, PHP_URL_HOST);
        $path = parse_url($verificationUrl, PHP_URL_PATH);
        if (! is_string($host) || ! in_array(parse_url($verificationUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            || ! preg_match('~/epps/evidencia/[A-Za-z0-9-]+$~', (string) $path)
            || parse_url($verificationUrl, PHP_URL_QUERY) !== null
            || ($expectedHost && ! hash_equals(strtolower((string) $expectedHost), strtolower($host)))) {
            throw new InvalidArgumentException('El QR solo admite una URL interna de verificacion.');
        }

        return (new SvgWriter)->write(QrCode::create($verificationUrl)->setSize(180)->setMargin(4))->getString();
    }
}
