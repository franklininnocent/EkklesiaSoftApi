<?php

namespace Modules\Sacraments\Services\Certificates;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

class CertificateQrEncoder
{
    public function dataUri(string $payload): string
    {
        $builder = new Builder(
            writer: new PngWriter,
            data: $payload,
            size: 180,
            margin: 8,
        );

        return $builder->build()->getDataUri();
    }

    public function verifyUrl(string $token): string
    {
        $base = rtrim((string) (config('sacraments.certificates.verify_url_base') ?: config('app.url')), '/');

        return $base.'/verify/certificate/'.$token;
    }

    public function mintToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
